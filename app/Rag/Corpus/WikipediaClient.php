<?php

declare(strict_types=1);

namespace App\Rag\Corpus;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

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
     * Raw wikitext of an article — the source the structured-data
     * extractor parses (prop=extracts strips tables and infoboxes).
     */
    public function fetchWikitext(string $title): ?string
    {
        /** @var array{query?: array{pages?: array<int|string, array{revisions?: list<array{slots?: array{main?: array{content?: string}}}>}>}} $data */
        $data = $this->request()->get('', [
            'action' => 'query',
            'format' => 'json',
            'prop' => 'revisions',
            'rvprop' => 'content',
            'rvslots' => 'main',
            'redirects' => '1',
            'titles' => $title,
        ])->throw()->json();

        foreach ($data['query']['pages'] ?? [] as $page) {
            $content = $page['revisions'][0]['slots']['main']['content'] ?? '';

            if ($content !== '') {
                return $content;
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
        return Http::baseUrl($this->apiUrl)
            ->timeout(30)
            // Wikimedia etiquette: identify the client.
            ->withUserAgent('rag-pedagogical-project/0.1 (Laravel; educational use)');
    }
}
