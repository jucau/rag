// Shared between the chat (sources carry the chunk content, expandable)
// and the history page (stored sources: title/url/distance only).
export default function Sources({ sources }) {
    if (!sources || sources.length === 0) {
        return null;
    }

    const documents = [...new Map(sources.map((s) => [s.url, s])).values()];
    const withContent = sources.some((s) => s.content);

    return (
        <div className="mt-3 border-t border-slate-700 pt-2 text-xs text-slate-400">
            <span className="font-semibold">Articles :</span>
            <ul className="mt-1 space-y-0.5">
                {documents.map((source) => (
                    <li key={source.url}>
                        <a
                            href={source.url}
                            target="_blank"
                            rel="noreferrer"
                            className="hover:text-emerald-400 underline decoration-slate-600 underline-offset-2"
                        >
                            {source.title}
                        </a>
                    </li>
                ))}
            </ul>

            <div className="mt-2">
                <span className="font-semibold">
                    Passages utilisés (les « Source N » de la réponse) :
                </span>
                <ul className="mt-1 space-y-1">
                    {sources.map((source) => (
                        <li key={source.chunk_id}>
                            {withContent ? (
                                <details className="group rounded-lg border border-slate-700/60 bg-slate-900/60">
                                    <summary className="cursor-pointer select-none px-2.5 py-1.5 text-slate-300 hover:text-emerald-400">
                                        <span className="font-medium">Source {source.position}</span>
                                        {' — '}
                                        {source.title}
                                        <span className="ml-1 text-slate-500">
                                            · distance {source.distance.toFixed(2)}
                                        </span>
                                    </summary>
                                    <pre className="max-h-56 overflow-y-auto whitespace-pre-wrap border-t border-slate-700/60 px-2.5 py-2 font-sans text-[11px] leading-relaxed text-slate-400">
                                        {source.content}
                                    </pre>
                                </details>
                            ) : (
                                <span className="block rounded-lg border border-slate-700/60 bg-slate-900/60 px-2.5 py-1.5 text-slate-300">
                                    <span className="font-medium">
                                        Source {source.position ?? '?'}
                                    </span>
                                    {' — '}
                                    {source.title}
                                    <span className="ml-1 text-slate-500">
                                        · chunk #{source.chunk_id} · distance{' '}
                                        {source.distance.toFixed(2)}
                                    </span>
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}
