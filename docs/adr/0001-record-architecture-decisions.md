# ADR-0001 — Record architecture decisions

**Status:** Accepted — 2026-06-10

## Context

This project is pedagogical: the *reasoning* behind each choice matters as
much as the result. Decisions explained only in chat sessions or commit
messages are hard to find back months later.

## Decision

Record every structural decision as an Architecture Decision Record in
`docs/adr/`, using the classic Nygard format: Context, Decision,
Consequences. ADRs are numbered, immutable, written in English, and a new
ADR supersedes an old one when a decision is revisited.

## Consequences

- The "why" of the project survives the chat sessions that produced it.
- Slight writing overhead on every significant choice.
- The ADR index doubles as a history of the project's architecture.
