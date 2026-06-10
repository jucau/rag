# The RAG pipeline

A RAG system has two distinct phases that run at different times. Keeping
them mentally separate is the single most useful thing to understand.

## Phase 1 — Ingestion (offline)

Ingestion prepares documents so they can be searched *by meaning*. It runs
ahead of time, every time documents are added or updated.

### 1. Chunking — cutting documents into pieces

Documents are split into **chunks**: passages of a few hundred tokens.
Why not embed whole documents?

- An embedding compresses a text into a *single* vector. The longer the
  text, the blurrier the vector — a 50-page document averaged into one
  point loses all nuance.
- At query time we inject retrieved passages into the prompt. Whole
  documents would blow the context budget; focused passages keep the
  prompt small and relevant.

Chunking is the most *artisanal* part of a RAG: chunk size, overlap between
chunks, and respecting semantic boundaries (paragraphs, sections) all
affect retrieval quality. This is typically tuned by trial and error.

### 2. Embeddings — turning meaning into numbers

An **embedding model** maps a text to a **vector** (an array of floats,
768 of them in our setup). The key property: texts with similar *meaning*
get vectors that are *close together* in space. "How do I reset my
password?" and "I lost my credentials" end up neighbours, even though they
share no words. This is what makes **semantic search** possible, as opposed
to keyword search.

Two consequences worth internalising:

- **Vectors from different models are never comparable.** Each model
  defines its own vector space. The same model must embed the chunks
  (ingestion) and the question (query time).
- **The vector dimension is structural.** Our database column is
  `vector(768)` — fixed at migration time, matching `embeddinggemma`.
  Switching to a model with another dimension means a new column and a
  full re-ingestion of the corpus. This is a real-world RAG constraint,
  not a quirk of our setup.

#### Is 768 dimensions a standard?

It is a **frequent convention, not an interoperability standard**. 768 is
inherited from BERT's hidden size; models commonly ship with 384, 512,
768, 1024, 1536 or 3072 dimensions. But dimension equality buys you
almost nothing:

> **Changing the embedding model means re-embedding everything, with
> ~100% probability — even if the new model has the same dimension.**

Two different models produce incompatible vector spaces: a 768-dim vector
from `embeddinggemma` and a 768-dim vector from another model are not
comparable — their axes simply don't carry the same "meaning". A matching
dimension only spares you the schema migration (`vector(768)` stays
valid), never the re-ingestion. This makes the embedding model one of the
*stickiest* choices in a RAG system.

One nuance: some recent models (including `embeddinggemma`) are trained
with **Matryoshka Representation Learning (MRL)** — their vectors can be
*truncated* (768 → 512 → 256 → 128) with little quality loss, because the
most important information is packed into the first components. This is
the exception that proves the rule: truncation only works *within the
same model*, never across models.

In this project embeddings are computed **locally** by Ollama serving
`embeddinggemma` (Google, ~300M parameters, multilingual — our documents
will often be French). See [ADR-0006](adr/0006-local-embeddings-with-ollama.md).

### 3. Storage — pgvector

Chunks and their vectors are stored in PostgreSQL thanks to the
**pgvector** extension, which adds a `vector` column type and distance
operators usable in plain SQL. We get vector search inside the database we
already know, with Eloquent on top.

## Phase 2 — Query time (online)

When a user asks a question:

1. **Embed the question** — with the *same* model used at ingestion.
2. **Similarity search** — find the **top-k** chunks whose vectors are
   closest to the question's vector. The standard metric is **cosine
   similarity**: it measures the angle between two vectors, ignoring their
   length; 1 means same direction (same meaning), 0 means unrelated. In
   pgvector this is the `<=>` operator (cosine *distance*, i.e.
   `1 - similarity`).
3. **Generation** — build a prompt containing the retrieved chunks and the
   question, and ask Claude to answer *using those passages*. The model is
   instructed to ground its answer in the provided context rather than its
   own (possibly outdated or hallucinated) knowledge.

```
question ──embed──▶ vector ──search──▶ top-k chunks ─┐
                                                     ├──prompt──▶ Claude ──▶ answer
question ────────────────────────────────────────────┘
```

## Asking a question

```bash
docker compose exec app php artisan rag:ask "Qui est l'entraîneur de l'OM ?"
docker compose exec app php artisan rag:ask "..." --provider=claude   # compare
```

The generation prompt is built by `app/Rag/AnswerQuestion.php`: a system
prompt with the **grounding rules** (answer only from the context, admit
ignorance, cite sources) and a user message containing the top-k chunks —
each labelled with its source title and URL — followed by the question.

### Enabling the Claude provider

A claude.ai subscription (Pro/Max) does **not** include API access — the
Anthropic API is a separate, pay-as-you-go product. To use
`--provider=claude`:

1. Create an account on [console.anthropic.com](https://console.anthropic.com)
   and buy credits (Billing — $5 minimum).
2. Create a key under *API Keys* and set it in `.env` as
   `ANTHROPIC_API_KEY` (gitignored).

Order of magnitude with `claude-opus-4-8` ($5/M input, $25/M output
tokens): one `rag:ask` call ≈ 2,500 prompt tokens + a few hundred output
tokens ≈ **$0.02–0.03 per question**. `CLAUDE_MODEL` in `.env` can point
to a cheaper model (e.g. `claude-sonnet-4-6`).

### Observed first results (gemma3:4b, 2026-06-10)

A side-by-side worth recording. Asked *"Qui est l'entraîneur de
l'Olympique de Marseille actuellement ?"*:

- **Without RAG** (bare model): *"Pierre Saint-Maximin"* — a textbook
  **hallucination** (no such coach; the model blended a player's name).
- **With RAG**: a grounded answer citing the OM article, with sources —
  but describing the 2023 coaching changes rather than today's coach.

The second result is instructive twice over: the *current* coach sits in
the article's **infobox**, which is a template stripped by
`prop=extracts` (see the limitation below); and the best-ranked chunk
came from a historical section — retrieval ranks by similarity, not by
**recency**. Grounded ≠ up to date: a RAG is only as fresh as what
retrieval surfaces.

### Ambiguous questions on a heterogeneous corpus (2026-06-10)

After enriching the corpus with ~15 Olympique lyonnais articles, asked
*"Combien de titres de champion de France l'OL a-t-il remportés ?"*:

- the answer came back *"quatorze titres consécutifs entre 2007 et
  2020"* — which is the **women's team** record (OL Lyonnes); the men's
  team has 7 (2002–2008). Retrieval surfaced chunks about both squads
  (similar vocabulary, same club names), and the small model blended
  them without flagging the ambiguity.
- Re-asked with *"l'équipe masculine… de Ligue 1"*: correct answer,
  *"sept titres consécutifs entre 2002 et 2008"*.

Lesson: **a grounded answer can still attribute facts to the wrong
entity** when the corpus contains near-duplicate vocabulary (men's vs
women's teams, club vs subsidiary). Mitigations to explore: question
rewriting, chunk metadata filters (per-document scoping), or a stronger
generation model that flags the ambiguity itself.

## Performance: where does the time go?

Every query is timed per phase and logged to the `rag_queries` table
(ADR-0011) — question, answer, grounding chunk ids, and millisecond
timings. `rag:ask` prints the same breakdown.

Measured on 2026-06-10 (CPU-only Docker on macOS, 809 chunks,
gemma3:4b):

| Phase | Time | Share |
|---|---|---|
| Embedding the question (Ollama) | ~190–270ms | ~2% |
| Similarity search (pgvector, HNSW) | **~14ms** | ~0.1% |
| Generation (gemma3:4b on CPU) | **~10–12s** | **~98%** |

Takeaways:

- **The vector search is essentially free.** Even without the HNSW index
  it would be fast at this corpus size; the database is nowhere near
  being the bottleneck.
- **Generation on CPU dominates everything.** Two costs add up: reading
  the prompt (our 5 chunks ≈ a few thousand tokens of *prompt
  processing*) and writing the answer at ~5–15 tokens/s.
- Levers, in order of impact: a faster generation provider
  (`--provider=claude` — the API is typically 5–10× faster here), a
  smaller local model, fewer/shorter chunks (`RAG_TOP_K`), or running
  Ollama natively on the host to use the Apple GPU (breaks the
  Docker-only rule, ADR-0002).

The log table doubles as an **evaluation dataset**: question + retrieved
chunks + answer is exactly what RAG evaluation frameworks consume.

## Where the code lives

| Concern | Location |
|---|---|
| Embedding contract | `app/Rag/Embeddings/EmbeddingProvider.php` |
| Ollama implementation | `app/Rag/Embeddings/OllamaEmbeddingProvider.php` |
| Chunking | `app/Rag/Chunking/TextChunker.php` ([ADR-0009](adr/0009-paragraph-aware-chunking-with-overlap.md)) |
| Corpus fetching | `app/Rag/Corpus/WikipediaClient.php` ([ADR-0008](adr/0008-wikipedia-football-corpus.md)) |
| Ingestion command | `app/Console/Commands/IngestWikipedia.php` |
| Documents / chunks | `app/Models/Document.php`, `app/Models/DocumentChunk.php` |
| Chat contract + providers | `app/Rag/Chat/` ([ADR-0010](adr/0010-ollama-default-generation-claude-alternative.md)) |
| Online pipeline (ask) | `app/Rag/AnswerQuestion.php`, `app/Console/Commands/Ask.php` |
| Query log | `app/Models/RagQuery.php` ([ADR-0011](adr/0011-log-rag-queries-with-timings.md)) |
| RAG configuration | `config/rag.php` |

### Running an ingestion

```bash
docker compose exec app php artisan rag:ingest-wikipedia \
  --title="Championnat de France de football 2025-2026" \
  --category="Catégorie:Club de Ligue 1"
```

Ingestion is **idempotent**: a document is identified by its
`(source, external_id)` pair, so re-running the command refreshes content
and rebuilds chunks instead of duplicating them. Re-ingesting regularly is
how the corpus stays *recent* — the whole point of ADR-0008.

### Known limitation: Wikipedia extracts strip tables

`prop=extracts` returns clean plain text but **drops wiki tables** — and
on Wikipedia, the most "factual" data (standings, results grids, squad
lists) lives in tables. A retrieval test on *"Quel club est en tête du
championnat ?"* correctly surfaces the season article's « Classement »
section… whose table content is missing from the extract.

Lesson: **what you extract bounds what your RAG can answer** — the
extraction format matters as much as chunking or the embedding model.
Possible future improvements: parse the wikitext tables, or complement
the corpus with openfootball results (public domain, plain text).

The application depends on the `EmbeddingProvider` *contract*, never on the
Ollama class directly — the concrete provider is bound in
`AppServiceProvider` from configuration (the same "driver" pattern Laravel
uses for mailers and filesystem disks). Swapping Ollama for a hosted API
(Voyage AI, OpenAI) would be a new implementation plus one config change.
See [ADR-0007](adr/0007-embedding-provider-contract.md).
