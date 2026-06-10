# ADR-0003 — PostgreSQL + pgvector as the vector store

**Status:** Accepted — 2026-06-10

## Context

A RAG needs to store embeddings and search them by similarity. Dedicated
vector databases exist (Qdrant, Weaviate, Pinecone, Milvus…), but they add
a new technology to learn and operate. The author's daily stack is Laravel
with a relational database.

## Decision

Use PostgreSQL 17 with the **pgvector** extension (image
`pgvector/pgvector:pg17`) as both the relational database and the vector
store. pgvector adds a `vector(n)` column type, distance operators
(`<=>` cosine, `<->` L2, `<#>` inner product) and ANN indexes (HNSW,
IVFFlat).

## Consequences

- Vector search is plain SQL: learnable, debuggable with `psql`, usable
  through Eloquent — chunks are regular Eloquent models.
- One database for everything: relational data and vectors live together,
  no synchronisation between two stores.
- The vector column dimension is frozen at migration time and must match
  the embedding model (see ADR-0006).
- At very large scale a dedicated vector database might outperform
  pgvector — irrelevant at this project's size, and a good future ADR if
  it ever changes.
