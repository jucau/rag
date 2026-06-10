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

## Where the code lives

| Concern | Location |
|---|---|
| Embedding contract | `app/Rag/Embeddings/EmbeddingProvider.php` |
| Ollama implementation | `app/Rag/Embeddings/OllamaEmbeddingProvider.php` |
| RAG configuration | `config/rag.php` |

The application depends on the `EmbeddingProvider` *contract*, never on the
Ollama class directly — the concrete provider is bound in
`AppServiceProvider` from configuration (the same "driver" pattern Laravel
uses for mailers and filesystem disks). Swapping Ollama for a hosted API
(Voyage AI, OpenAI) would be a new implementation plus one config change.
See [ADR-0007](adr/0007-embedding-provider-contract.md).
