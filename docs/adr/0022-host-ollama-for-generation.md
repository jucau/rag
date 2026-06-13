# ADR-0022 — Host-native Ollama for generation (Metal GPU)

**Status:** Accepted — 2026-06-13. Exercises the escape hatch documented
in [ADR-0002](0002-docker-only-development-environment.md) and the
[project overview](../01-project-overview.md).

## Context

Generation is the pipeline's bottleneck (~99% of query latency,
ADR-0011) because Docker on macOS runs in a VM with **no access to the
Apple Metal GPU** — the dockerised Ollama runs the chat model on CPU
(~8.6s median on `llama3.2:3b`, benchmark v2). The project owner
installed Ollama natively on the host, where it *does* get the GPU.

The macOS-GPU limitation was anticipated from day one: ADR-0002 and the
overview both named "run Ollama natively on the host (with Metal)" as
the escape hatch for local generation, "at the cost of the Docker-only
rule".

## Decision

**Split the two Ollama roles by URL:**

- **Generation → host-native Ollama** at
  `http://host.docker.internal:11434` (new config `rag.chat.ollama.url`
  / env `OLLAMA_CHAT_URL`). Gets the Metal GPU.
- **Embeddings → dockerised Ollama** at `http://ollama:11434`
  (unchanged). Stays pinned and reproducible — and, crucially, must
  keep producing the *same* vectors as those already stored for the
  corpus (ADR-0006); moving it would risk silent retrieval drift.

`rag.chat.ollama.url` falls back to the embeddings URL when unset, so
the default Docker-only setup keeps working with no env change. The
app service gets `extra_hosts: host.docker.internal:host-gateway` for
Docker Desktop portability (OrbStack provides the mapping natively).

The host must have the chat model pulled (`ollama pull llama3.2:3b`).

## Consequences

- **~3× faster generation**: warm `llama3.2:3b` drops from ~8.6s (CPU)
  to ~2.5s (Metal GPU); end-to-end `rag:ask` ~2.9s.
- **Partial retreat from Docker-only (ADR-0002)**: the host now needs
  Ollama installed and the chat model pulled. This is opt-in via
  `OLLAMA_CHAT_URL`; cloning the repo and running `make setup` still
  yields a fully-dockerised, GPU-less but self-contained stack.
- **ADR-0016's reload-thrashing rationale partly dissolves**: chat and
  embedding models no longer share one Ollama instance, so they can't
  evict each other. The dockerised Ollama now serves only
  `embeddinggemma`; its `OLLAMA_MAX_LOADED_MODELS=2` is now harmless
  overkill.
- The host Ollama is unpinned (whatever version the owner installed),
  reintroducing the version-drift the project otherwise avoids — an
  accepted trade-off for the GPU, isolated to generation.
- Benchmarks (`rag:benchmark`) run against whatever `OLLAMA_CHAT_URL`
  points at; the v2 figures (docs/05) were CPU — re-running on the host
  GPU would measure speed, not quality (same models, same answers).
