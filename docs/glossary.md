# Glossary

Key concepts used throughout this project, in alphabetical order.
Maintained continuously: any new term introduced in the code or the docs
gets an entry here.

**ADR (Architecture Decision Record)** — A short document recording one
structural decision: its context, the decision itself, and its
consequences. Lives in [docs/adr](adr/README.md).

**Chunk / chunking** — A passage of a document (typically a few hundred
tokens), and the act of splitting documents into such passages. Each chunk
is embedded and stored individually; retrieval returns chunks, not whole
documents.

**Context window** — The maximum amount of text (in tokens) an LLM can
process in a single request, prompt and answer included. One reason we
retrieve a few relevant chunks instead of sending entire documents.

**Contextual chunking** — Prefixing every chunk with its origin
(article and section titles) before embedding it, so the passage stays
attached to the entity it describes. Cures cross-entity confusion in
retrieval and gives the generation model grounds for arbitration.

**Cosine similarity** — A measure of how close two vectors point in the
same direction, ignoring their length. 1 = same meaning, 0 = unrelated.
pgvector exposes the cosine *distance* operator `<=>` (distance =
1 − similarity).

**Dimension** — The number of components in a vector (768 in this
project). Fixed by the embedding model, and frozen into the database
column type `vector(768)`. Changing models with a different dimension
requires re-ingesting the whole corpus.

**Embedding** — A vector representing the *meaning* of a text, produced
by an embedding model. Texts with similar meaning get nearby vectors.
Embeddings from different models live in different vector spaces and are
never comparable.

**Embedding model** — A small neural network (here `embeddinggemma`,
~300M parameters) specialised in producing embeddings. Distinct from a
generative LLM: it outputs vectors, not text.

**Generation** — The phase where an LLM (gemma3:4b locally by default,
Claude as the alternative — ADR-0010) writes the final answer, using the
retrieved chunks injected into its prompt.

**Grounding** — Constraining an LLM to answer from supplied context
(our retrieved chunks) rather than from its training data. Enforced by
the system prompt; the antidote to hallucination — though a grounded
answer is only as fresh and complete as the retrieved context.

**Ground truth (relevance judgments / qrels)** — The hand-labelled
"right answer" an evaluation is scored against. For *retrieval* it is,
for each question, the document(s) that actually hold the answer
(`benchmark/retrieval-gold.json`, judged at the document level so it
survives re-chunking). Without it you cannot compute [recall@k](#) or
[MRR](#) — you can only eyeball the answer text (ADR-0025).

**Hallucination** — A confident but fabricated LLM answer (e.g. our
local model inventing coach "Pierre Saint-Maximin"). Frequent on small
models and on facts past the training cutoff; the problem RAG exists to
mitigate.

**HNSW (Hierarchical Navigable Small World)** — An index structure for
*approximate* nearest-neighbour search. Exact search compares the query
vector to every stored vector (fine at small scale, linear cost); an HNSW
index keeps similarity queries fast as the corpus grows, at the price of
occasionally missing the true closest neighbour. Created on our
`document_chunks.embedding` column.

**Ingestion** — The offline phase of a RAG: chunking documents, embedding
each chunk, and storing chunks + vectors in the vector store.

**Inference** — Running a trained model to get an output (an embedding, a
completion). What Ollama does locally for embeddings, and the Anthropic
API does remotely for generation.

**LLM (Large Language Model)** — A generative model (Claude, GPT, Llama…)
that produces text from a prompt. Knows only its training data — hence
RAG to ground it in private, up-to-date documents.

**Matryoshka (MRL, Matryoshka Representation Learning)** — A training
technique that packs the most important information into the first
components of a vector, so it can be truncated (e.g. 768 → 256
dimensions) with little quality loss. Only valid within the same model.
`embeddinggemma` supports it.

**MRR (Mean Reciprocal Rank)** — A retrieval metric: for each question,
take `1 / rank` of the *first* relevant chunk (rank 1 → 1.0, rank 5 →
0.2, never found → 0), then average over all questions. Unlike
[recall@k](#) it rewards ranking the right chunk *high*, not merely
having it somewhere in the top-k — so it is the metric a reranker tries
to lift. Our corpus scores ≈ 0.89 (ADR-0025).

**Ollama** — A local inference server. Runs open-weight models on your
machine and exposes them over a simple REST API (`/api/embed` for
embeddings). Runs as a Docker service in this project.

**pgvector** — A PostgreSQL extension adding a `vector` column type,
distance operators (`<=>`, `<->`, `<#>`) and vector indexes. Turns the
relational database we already use into a vector store.

**Prompt** — The full input sent to an LLM: instructions, context
(retrieved chunks in our case) and the user's question.

**RAG (Retrieval-Augmented Generation)** — The technique this project
implements: retrieve passages relevant to a question from a document
store, then have an LLM generate an answer grounded in those passages.

**Over-refusal / misread** — The two generation failure modes when the
answer *was* in the context (ADR-0026). **Over-refusal**: the model
declines a question it could have answered. **Misread**: it answers
wrong despite the fact being present — usually grabbing a *competing*
fact from the context. In this project misread dominates (≈ 10/70) and
over-refusal is rare (≈ 1/70), overturning the v3 guess.

**Recall@k** — A retrieval metric: the share of questions for which at
least one relevant document appears in the top-k retrieved chunks.
`recall@5` is what the generator actually sees in this project;
`recall@20` is the ceiling a reranker pulling from a deeper pool could
reach. It measures *coverage* (is the answer there at all?), not
*ranking quality* — see [MRR](#). Ours is ≈ 95 % at k=5 (ADR-0025).
Reported at two granularities: **document-level** (right article
retrieved) and **fact-level** (a retrieved chunk's content states the
answer); the gap measures how lenient document-level judging is — large
at rank 1 (−17.6 pts), zero at the top-5 here (ADR-0026).

**Retrieval** — The query-time step that finds the top-k chunks closest
to the question's embedding.

**SSE (Server-Sent Events)** — A simple HTTP streaming format
(`text/event-stream`): the server pushes named events over a kept-open
connection. Used to stream the answer token by token to the chat UI —
perceived latency becomes "time to first token" instead of "full
generation time" (ADR-0017).

**Semantic search** — Searching by *meaning* (via embeddings and vector
distance) rather than by keywords. Finds "I lost my credentials" when you
ask about "password reset".

**Token** — The unit LLMs read and write (roughly ¾ of a word in
English). Context windows, costs and chunk sizes are all measured in
tokens.

**Top-k** — The k most similar chunks returned by retrieval (k is
typically 3–10). The k chunks are injected into the generation prompt.

**Vector** — An array of floats; a point in a high-dimensional space. The
output of an embedding model.

**Vector store** — A database able to store vectors and search them by
distance. Here: PostgreSQL + pgvector.
