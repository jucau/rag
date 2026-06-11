# ADR-0015 — Recover structured data (infobox, tables) from wikitext

**Status:** Accepted — 2026-06-11. Resolves the limitation documented in
[the pipeline doc](../02-rag-pipeline.md) since the first ingestion.

## Context

`prop=extracts` returns clean prose but **strips infoboxes and
wikitables** — which is where Wikipedia keeps its most factual data:
current coach, stadium capacity, standings, season results. Questions
like *"qui est en tête de la Ligue 1 ?"* were unanswerable by design.

Options: parse the rendered HTML (heavy, layout-dependent), use
Wikidata (clean but a different source and shape), or parse the raw
**wikitext** (one extra API call per article, data at the source).

## Decision

Fetch the raw wikitext (`prop=revisions`) alongside the extract, and
linearise its structured parts with a pragmatic parser
(`WikitextStructuredExtractor`):

- **Infobox** → one `key : value.` line per field (images, logos and
  similar presentation fields skipped):
  `Entraîneur : Paulo Fonseca.`
- **Wikitables** → one line per row, cells paired with their column
  headers: `Rang : 1 — Club : Paris SG — Points : 45`.

The linearised blocks are appended to the stored document content as
regular `== … ==` sections (`== Fiche du club (infobox) ==`,
`== Tableau : Classement ==`), so the SectionAwareChunker (ADR-0013)
chunks and contextualises them like any prose — no schema change, no
special retrieval path.

Wikitext templates are a full language: the parser cleans the common
constructs (links, refs, simple templates, cell attributes) and accepts
imperfect output on exotic ones. Unit-tested on realistic samples.

## Consequences

- Factual questions over tabular data (standings, current staff,
  capacities) become answerable; the original "killer demo" is unlocked.
- One extra API call and a larger document per article; chunk counts
  grow accordingly. Full re-ingestion required (embeddings change).
- Parsing is best-effort: heavily templated tables may linearise
  oddly. The pragmatic stance is documented and revisitable (Wikidata
  would be the clean upgrade path).
