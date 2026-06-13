# ADR-0024 — Drop boilerplate end-matter sections at chunking

**Status:** Accepted — 2026-06-13. Refines the chunking strategy
([ADR-0013](0013-section-aware-contextual-chunking.md)).

## Context

Inspecting chunks, the owner flagged some as pure noise — e.g. chunk
24355 (*"Cet article est partiellement issu de l'article Maillot du
PSG…"*, an attribution note) and 24357 (a list of cross-reference
article titles). Both came from Wikipedia **end-matter sections**:
*Notes et références* and *Articles connexes*.

Counting the corpus: **217 chunks (~4.5%)** live in such boilerplate
sections — *Liens externes* (134), *Bibliographie* (43), *Articles
connexes* (18), *Notes et références*, *Sources*, *Voir aussi*… These
carry external links, bibliographies, navigation and licensing notes:
zero value for a football RAG.

A heavier idea was floated — an **LLM judge** scoring each chunk's
usefulness at ingestion. Rejected as the *first* tool: it would cost
one LLM call per chunk on every re-index (thousands), and our small
local models are unreliable judges (the benchmark showed they
fabricate and misjudge) — a wrong "drop" silently removes a real fact
(recall loss, the worst RAG failure). The flagged noise is **structural
and deterministic**, so a section denylist handles it for free, with no
false-positive risk. The LLM judge stays in reserve for non-structural
noise, ideally with a strong model and measured against the benchmark.

## Decision

`SectionAwareChunker` skips sections whose heading matches a denylist
of standard Wikipedia end-matter, as a normalised prefix (so
"Bibliographie sur Le Havre AC" and "Références extraites d'ouvrages"
are caught): *liens externes, bibliographie, articles connexes, notes
et références, références, sources, voir aussi, annexes, notes*.

The **introduction (null section) is never dropped** — it usually holds
the densest facts (e.g. a player's summary), so filtering intros (also
considered) would risk removing signal.

This is a chunking-strategy change → `DocumentIndexer::VERSION` bumped
to `2026-06-13.1`, so `rag:index` re-embeds the whole corpus.

## Consequences

- ~4.5% fewer chunks — less storage, less chance a noise chunk crowds
  out a real one in the top-k. Retrieval quality can only improve or
  stay flat (no real content removed).
- Deterministic and free: no per-chunk LLM cost, no non-determinism.
- The denylist is a maintained list; an unusual real section starting
  with one of these prefixes would be wrongly dropped, but in football
  articles that risk is negligible.
- Effect should be measured at the next benchmark run (does dropping
  noise nudge any retrieval-miss case, e.g. "les Gones"?).
