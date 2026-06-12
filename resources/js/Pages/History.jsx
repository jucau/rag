import { Head, Link } from '@inertiajs/react';
import Sources from '../Components/Sources';

function HistoryItem({ query }) {
    return (
        <details className="group rounded-xl border border-slate-800 bg-slate-900/60">
            <summary className="flex cursor-pointer select-none items-baseline gap-3 px-4 py-3 hover:bg-slate-800/40">
                <span className="shrink-0 font-mono text-xs text-slate-500">#{query.id}</span>
                <span className="min-w-0 flex-1 truncate text-slate-200">{query.question}</span>
                <span className="hidden shrink-0 text-xs text-slate-500 sm:block">
                    {query.provider}
                </span>
                <span className="shrink-0 text-xs text-slate-500">
                    {(query.timings.total_ms / 1000).toFixed(1)}s
                </span>
                <span className="hidden shrink-0 text-xs text-slate-600 md:block">
                    {query.created_at}
                </span>
            </summary>

            <div className="border-t border-slate-800 px-4 py-3">
                <p className="whitespace-pre-wrap text-sm text-slate-100">{query.answer}</p>
                <p className="mt-2 text-[11px] text-slate-500">
                    {query.provider} — embedding {query.timings.embedding_ms}ms · retrieval{' '}
                    {query.timings.retrieval_ms}ms · génération {query.timings.generation_ms}ms
                </p>
                <Sources sources={query.sources} />
            </div>
        </details>
    );
}

export default function History({ queries }) {
    return (
        <>
            <Head title="Historique — RAG Football" />
            <div className="flex min-h-screen flex-col bg-gradient-to-b from-slate-950 via-slate-900 to-slate-950 text-slate-100">
                <header className="border-b border-slate-800 px-6 py-4">
                    <div className="mx-auto flex max-w-3xl items-center justify-between">
                        <div className="flex items-center gap-3">
                            <span className="text-2xl">⚽</span>
                            <div>
                                <h1 className="text-lg font-semibold">Historique des questions</h1>
                                <p className="text-xs text-slate-400">
                                    {queries.total} question(s) loggée(s) dans rag_queries
                                </p>
                            </div>
                        </div>
                        <Link
                            href="/"
                            className="rounded-xl border border-slate-700 px-3 py-1.5 text-sm text-slate-300 transition hover:border-emerald-500 hover:text-emerald-400"
                        >
                            ← Chat
                        </Link>
                    </div>
                </header>

                <main className="mx-auto w-full max-w-3xl flex-1 space-y-2 px-6 py-6">
                    {queries.data.length === 0 && (
                        <p className="text-center text-slate-500">
                            Aucune question pour l'instant — posez-en une depuis le chat !
                        </p>
                    )}

                    {queries.data.map((query) => (
                        <HistoryItem key={query.id} query={query} />
                    ))}

                    <div className="flex items-center justify-between pt-4 text-sm">
                        {queries.prev_page_url ? (
                            <Link
                                href={queries.prev_page_url}
                                className="rounded-xl border border-slate-700 px-3 py-1.5 text-slate-300 hover:border-emerald-500 hover:text-emerald-400"
                            >
                                ← Plus récentes
                            </Link>
                        ) : (
                            <span />
                        )}
                        <span className="text-xs text-slate-500">
                            Page {queries.current_page} / {queries.last_page}
                        </span>
                        {queries.next_page_url ? (
                            <Link
                                href={queries.next_page_url}
                                className="rounded-xl border border-slate-700 px-3 py-1.5 text-slate-300 hover:border-emerald-500 hover:text-emerald-400"
                            >
                                Plus anciennes →
                            </Link>
                        ) : (
                            <span />
                        )}
                    </div>
                </main>
            </div>
        </>
    );
}
