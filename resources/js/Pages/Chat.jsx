import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// Parses one SSE block ("event: x\ndata: {…}") into { event, data }.
const parseSseBlock = (block) => {
    let event = 'message';
    let data = '';

    for (const line of block.split('\n')) {
        if (line.startsWith('event:')) {
            event = line.slice(6).trim();
        } else if (line.startsWith('data:')) {
            data += line.slice(5).trim();
        }
    }

    return { event, data: data === '' ? null : JSON.parse(data) };
};

function TypingIndicator() {
    return (
        <div className="flex items-center gap-1.5 rounded-2xl rounded-bl-sm bg-slate-800 px-5 py-4 w-fit">
            {[0, 1, 2].map((i) => (
                <span
                    key={i}
                    className="h-2 w-2 rounded-full bg-emerald-400 animate-bounce"
                    style={{ animationDelay: `${i * 150}ms` }}
                />
            ))}
        </div>
    );
}

function Sources({ sources }) {
    const documents = [...new Map(sources.map((s) => [s.url, s])).values()];

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

            {sources.some((s) => s.content) && (
                <div className="mt-2">
                    <span className="font-semibold">
                        Passages utilisés (les « Source N » de la réponse) :
                    </span>
                    <ul className="mt-1 space-y-1">
                        {sources.map((source) => (
                            <li key={source.chunk_id}>
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
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

function Message({ message }) {
    if (message.role === 'user') {
        return (
            <div className="flex justify-end">
                <div className="max-w-[80%] rounded-2xl rounded-br-sm bg-emerald-600 px-4 py-3 text-white">
                    {message.content}
                </div>
            </div>
        );
    }

    if (message.role === 'error') {
        return (
            <div className="flex justify-start">
                <div className="max-w-[80%] rounded-2xl border border-red-500/40 bg-red-950/40 px-4 py-3 text-red-300">
                    {message.content}
                </div>
            </div>
        );
    }

    return (
        <div className="flex justify-start">
            <div className="max-w-[85%] rounded-2xl rounded-bl-sm bg-slate-800 px-4 py-3 text-slate-100">
                <p className="whitespace-pre-wrap">
                    {message.content}
                    {message.streaming && (
                        <span className="ml-0.5 animate-pulse text-emerald-400">▍</span>
                    )}
                </p>
                {message.sources?.length > 0 && <Sources sources={message.sources} />}
                {message.meta && (
                    <p className="mt-2 text-[11px] text-slate-500">{message.meta}</p>
                )}
            </div>
        </div>
    );
}

export default function Chat({ models }) {
    const [messages, setMessages] = useState([]);
    const [question, setQuestion] = useState('');
    const [selectedModel, setSelectedModel] = useState(0);
    const [loading, setLoading] = useState(false);
    const bottomRef = useRef(null);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages, loading]);

    const ask = async (event) => {
        event.preventDefault();

        const trimmed = question.trim();
        if (trimmed === '' || loading) {
            return;
        }

        setMessages((current) => [...current, { role: 'user', content: trimmed }]);
        setQuestion('');
        setLoading(true);

        try {
            const response = await fetch('/ask-stream', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    Accept: 'text/event-stream',
                },
                body: JSON.stringify({
                    question: trimmed,
                    provider: models[selectedModel].provider,
                    model: models[selectedModel].model,
                }),
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            // Consume the SSE stream: grow the assistant bubble with each
            // delta — perceived latency becomes "time to first token".
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let started = false;
            let pendingSources = [];

            const updateLast = (patch) =>
                setMessages((current) => {
                    const next = [...current];
                    next[next.length - 1] = { ...next[next.length - 1], ...patch(next[next.length - 1]) };
                    return next;
                });

            const handleEvent = ({ event, data }) => {
                if (event === 'delta') {
                    if (!started) {
                        started = true;
                        setLoading(false);
                        setMessages((current) => [
                            ...current,
                            { role: 'assistant', content: '', streaming: true },
                        ]);
                    }
                    updateLast((last) => ({ content: last.content + data.text }));
                } else if (event === 'sources') {
                    pendingSources = data.sources;
                } else if (event === 'done') {
                    updateLast(() => ({
                        streaming: false,
                        sources: pendingSources,
                        meta: `${data.provider} — ${(data.timings.total_ms / 1000).toFixed(1)}s`,
                    }));
                }
            };

            for (;;) {
                const { done, value } = await reader.read();
                if (done) {
                    break;
                }

                buffer += decoder.decode(value, { stream: true });

                let separator;
                while ((separator = buffer.indexOf('\n\n')) !== -1) {
                    const block = buffer.slice(0, separator);
                    buffer = buffer.slice(separator + 2);
                    handleEvent(parseSseBlock(block));
                }
            }
        } catch {
            setMessages((current) => [
                ...current,
                {
                    role: 'error',
                    content:
                        'Une erreur est survenue pendant la génération. Vérifie que la stack tourne (make start) et réessaie.',
                },
            ]);
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <Head title="RAG Football" />
            <div className="flex min-h-screen flex-col bg-gradient-to-b from-slate-950 via-slate-900 to-slate-950 text-slate-100">
                <header className="border-b border-slate-800 px-6 py-4">
                    <div className="mx-auto flex max-w-3xl items-center gap-3">
                        <span className="text-2xl">⚽</span>
                        <div>
                            <h1 className="text-lg font-semibold">RAG Football</h1>
                            <p className="text-xs text-slate-400">
                                Posez vos questions sur le corpus (Ligue 1, OL, OM, PSG…) —
                                réponses sourcées depuis Wikipédia
                            </p>
                        </div>
                    </div>
                </header>

                <main className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 overflow-y-auto px-6 py-6">
                    {messages.length === 0 && !loading && (
                        <div className="m-auto text-center text-slate-500">
                            <p className="text-4xl">🥅</p>
                            <p className="mt-2">
                                Essayez : « Combien de titres de champion de France l'équipe
                                masculine de l'OL a-t-elle remportés ? »
                            </p>
                        </div>
                    )}

                    {messages.map((message, index) => (
                        <Message key={index} message={message} />
                    ))}

                    {loading && <TypingIndicator />}
                    <div ref={bottomRef} />
                </main>

                <footer className="border-t border-slate-800 px-6 py-4">
                    <form onSubmit={ask} className="mx-auto flex max-w-3xl gap-2">
                        <select
                            value={selectedModel}
                            onChange={(e) => setSelectedModel(Number(e.target.value))}
                            className="rounded-xl border border-slate-700 bg-slate-900 px-3 text-sm text-slate-300 focus:border-emerald-500 focus:outline-none"
                            title="Modèle de génération — le premier appel à un modèle non chargé peut être plus lent"
                        >
                            {models.map((entry, index) => (
                                <option key={entry.label} value={index}>
                                    {entry.label}
                                </option>
                            ))}
                        </select>
                        <input
                            type="text"
                            value={question}
                            onChange={(e) => setQuestion(e.target.value)}
                            placeholder="Votre question…"
                            className="flex-1 rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-slate-100 placeholder-slate-500 focus:border-emerald-500 focus:outline-none"
                        />
                        <button
                            type="submit"
                            disabled={loading || question.trim() === ''}
                            className="rounded-xl bg-emerald-600 px-5 py-3 font-medium text-white transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {loading ? '…' : 'Envoyer'}
                        </button>
                    </form>
                </footer>
            </div>
        </>
    );
}
