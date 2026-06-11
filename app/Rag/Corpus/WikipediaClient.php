<?php

declare(strict_types=1);

namespace App\Rag\Corpus;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Minimal MediaWiki API client: fetches plain-text article content
 * (prop=extracts) and lists the pages of a category. Article by article —
 * no multi-GB dump involved (ADR-0008).
 */
final readonly class WikipediaClient
{
    public function __construct(private string $apiUrl)
    {
    }

    /**
     * Page titles belonging to a category (e.g. "Catégorie:…"), following
     * API pagination. With $depth > 0, subcategories are walked
     * recursively (visited categories are tracked to survive cycles).
     *
     * @param array<string, true> $visited
     * @return list<string>
     */
    public function categoryMembers(string $category, int $depth = 0, array &$visited = []): array
    {
        if (isset($visited[$category])) {
            return [];
        }

        $visited[$category] = true;
        $titles = [];
        $continue = null;

        do {
            $query = [
                'action' => 'query',
                'format' => 'json',
                'list' => 'categorymembers',
                'cmtitle' => $category,
                'cmtype' => 'page',
                'cmlimit' => '500',
            ];

            if ($continue !== null) {
                $query['cmcontinue'] = $continue;
            }

            /** @var array{query?: array{categorymembers?: list<array{title: string}>}, continue?: array{cmcontinue: string}} $data */
            $data = $this->request()->get('', $query)->throw()->json();

            foreach ($data['query']['categorymembers'] ?? [] as $member) {
                $titles[] = $member['title'];
            }

            $continue = $data['continue']['cmcontinue'] ?? null;
        } while ($continue !== null);

        if ($depth > 0) {
            foreach ($this->subcategories($category) as $subcategory) {
                $titles = array_merge($titles, $this->categoryMembers($subcategory, $depth - 1, $visited));
            }
        }

        return $titles;
    }

    /**
     * @return list<string>
     */
    private function subcategories(string $category): array
    {
        /** @var array{query?: array{categorymembers?: list<array{title: string}>}} $data */
        $data = $this->request()->get('', [
            'action' => 'query',
            'format' => 'json',
            'list' => 'categorymembers',
            'cmtitle' => $category,
            'cmtype' => 'subcat',
            'cmlimit' => '500',
        ])->throw()->json();

        return array_map(
            fn (array $member): string => $member['title'],
            $data['query']['categorymembers'] ?? [],
        );
    }

    /**
     * Lightweight change detection (ADR-0020): the latest revision id of
     * an article, without downloading its content.
     */
    public function latestRevisionId(string $title): ?int
    {
        /** @var array{query?: array{pages?: array<int|string, array{revisions?: list<array{revid?: int}>}>}} $data */
        $data = $this->request()->get('', [
            'action' => 'query',
            'format' => 'json',
            'formatversion' => '2',
            'prop' => 'revisions',
            'rvprop' => 'ids',
            'redirects' => '1',
            'titles' => $title,
        ])->throw()->json();

        foreach ($data['query']['pages'] ?? [] as $page) {
            $revisionId = $page['revisions'][0]['revid'] ?? null;

            if ($revisionId !== null) {
                return $revisionId;
            }
        }

        return null;
    }

    /**
     * Raw wikitext of an article with its revision id — the source the
     * structured-data extractor parses (prop=extracts strips tables and
     * infoboxes).
     */
    public function fetchRevision(string $title): ?WikipediaRevision
    {
        /** @var array{query?: array{pages?: array<int|string, array{revisions?: list<array{revid?: int, slots?: array{main?: array{content?: string}}}>}>}} $data */
        $data = $this->request()->get('', [
            'action' => 'query',
            'format' => 'json',
            // formatversion 2: revision content arrives under "content"
            // (version 1 uses the awkward "*" key).
            'formatversion' => '2',
            'prop' => 'revisions',
            'rvprop' => 'ids|content',
            'rvslots' => 'main',
            'redirects' => '1',
            'titles' => $title,
        ])->throw()->json();

        foreach ($data['query']['pages'] ?? [] as $page) {
            $revision = $page['revisions'][0] ?? [];
            $content = $revision['slots']['main']['content'] ?? '';
            $revisionId = $revision['revid'] ?? null;

            if ($content !== '' && $revisionId !== null) {
                return new WikipediaRevision(revisionId: $revisionId, wikitext: $content);
            }
        }

        return null;
    }

    /**
     * Full plain-text content of one article (null when the page does not
     * exist or has no text). The API limits full-text extracts to one page
     * per request.
     */
    public function fetchPage(string $title): ?WikipediaPage
    {
        /** @var array{query?: array{pages?: array<int|string, array{pageid?: int, title?: string, fullurl?: string, extract?: string}>}} $data */
        $data = $this->request()->get('', [
            'action' => 'query',
            'format' => 'json',
            'prop' => 'extracts|info',
            'inprop' => 'url',
            'explaintext' => '1',
            'redirects' => '1',
            'titles' => $title,
        ])->throw()->json();

        foreach ($data['query']['pages'] ?? [] as $page) {
            $extract = $page['extract'] ?? '';
            if (! isset($page['pageid'], $page['title'], $page['fullurl'])) {
                continue;
            }

            if ($extract === '') {
                continue;
            }

            return new WikipediaPage(
                pageId: $page['pageid'],
                title: $page['title'],
                url: $page['fullurl'],
                content: $extract,
            );
        }

        return null;
    }

    private function request(): PendingRequest
    {
        // Wikimedia etiquette: identify the client, pace the calls
        // (each article costs up to three requests since ADR-0020), and
        // back off on 429 rather than failing a long ingestion run.
        Sleep::usleep(400_000);

        return Http::baseUrl($this->apiUrl)
            ->timeout(30)
            ->withUserAgent('rag-pedagogical-project/0.1 (Laravel; educational use)')
            ->retry(
                times: 6,
                sleepMilliseconds: function (int $attempt, mixed $exception): int {
                    // Honour the server's Retry-After when provided; the
                    // throttling window can exceed any fixed backoff.
                    if ($exception instanceof RequestException) {
                        $retryAfter = (int) $exception->response->header('Retry-After');

                        if ($retryAfter > 0) {
                            return ($retryAfter + 1) * 1_000;
                        }
                    }

                    return $attempt * 15_000;
                },
                when: fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->status() === 429),
            );
    }
}
