# Knowledge Manager — Skills & Tech Stack

Multi-tenant knowledge base with retrieval-augmented Q&A. Three services: Next.js
frontend, Laravel API, Python/FastAPI AI service running local HuggingFace models,
orchestrated with Docker Compose.

---

## Core stack

| Layer | Technologies |
| --- | --- |
| Frontend | **Next.js 16** (App Router), **React 19**, **TypeScript 5.9**, **Tailwind CSS 4**, **Tiptap 3** (rich-text editor), **Axios**, ESLint |
| Backend | **PHP 8.2**, **Laravel 12**, **Laravel Sanctum** (API tokens), **Laravel Socialite** (Google OAuth), **API Resources**, **Form Requests**, **Policies**, **Database queue**, PHPUnit 11, Pint, Faker |
| AI service | **Python 3.11**, **FastAPI**, **Uvicorn**, **PyTorch** (CPU wheels), **HuggingFace Transformers 5**, **Pydantic 2**, **BeautifulSoup4**, Pytest |
| Database | **PostgreSQL 18**, **pgvector** (HNSW index, 1024-dim), **full-text search** (`tsvector` / `plainto_tsquery` / `ts_rank`, GIN) |
| Infrastructure | **Docker Compose**, multi-stage Dockerfiles, **nginx + PHP-FPM**, **Supervisor**, OPcache, Docker healthchecks, **Mailpit** (dev SMTP/UI), **Vite** + Laravel plugin |

---

## Skills demonstrated

### AI / Machine Learning
- **RAG (Retrieval-Augmented Generation)** — answers are generated only from retrieved
  workspace passages, never from model priors. Sources are persisted with the answer,
  so every citation points at text the model actually read (`backend/app/Services/AI/RagService.php`).
- **Vector embeddings** — `BAAI/bge-m3` (1024 dimensions), CLS pooling + L2
  normalization, batched forward passes so an entry costs one model load, not one per
  chunk (`ai-service/app/services/embeddings.py`).
- **Semantic + lexical hybrid search with Reciprocal Rank Fusion** — keyword and vector
  retrieval run together and merge by *rank*, not score, because a `ts_rank` and a cosine
  distance aren't comparable units (`backend/app/Services/Search/HybridSearchService.php`).
- **Instruction-tuned local LLM inference** — `Qwen3-0.6B` with `transformers`
  (`AutoModelForCausalLM`, `dtype=bfloat16`), plus seq2seq summarization
  (`AutoModelForSeq2SeqLM`). Greedy decoding, repetition penalty, generation budgets
  (`ai-service/app/services/llm.py`, `summarizer.py`).
- **Text chunking for retrieval** — semantic chunking with overlap, hard cap of 60
  chunks/entry, HTML flattening that strips editor markup before embedding
  (`backend/app/Services/ChunkingService.php`).
- **Measured thresholds, not guesses** — cosine distance floor of 0.7 chosen from the
  observed gap between paraphrases (0.25–0.39) and unrelated questions (0.72–0.75).

### Backend engineering
- **Laravel 12 architecture** — Action classes for write paths, dedicated Services for
  domain logic (`app/Actions`, `app/Services`), thin controllers.
- **Multi-tenancy & authorization** — owner/member/viewer roles, invite codes,
  request-to-join + approval flow. Access enforced by **model policies**, not just
  controllers (`app/Policies/`), with dedicated authorization tests.
- **Custom Eloquent cast** — `AsVector` serializes vectors to pgvector's literal format,
  handling Postgres' `{}` output vs. JSON, float formatting edge cases, and NaN/Inf
  rejection (`app/Casts/AsVector.php`).
- **Authentication** — session/cookie auth via Sanctum, email verification, password
  reset, Google OAuth through Socialite.
- **Raw SQL where the ORM falls short** — hand-written `ts_rank` / vector-distance
  queries with bound parameters for retrieval paths.
- **API Resources** as an output contract, verified by a `ResourceContractTest`.

### Async & performance
- **Database-backed queue** — long LLM calls (minutes on CPU) moved off the request.
  `POST /ask` returns `202` with a pending message; client polls for the result.
- **Timeout ordering discipline** — `DB_QUEUE_RETRY_AFTER (3000) > job $timeout (2700)
  > ai.answer_timeout (2400) > ai.background_timeout (600) > ai.timeout (120)`, so a slow
  job is never killed or double-claimed.
- **Up-front budget refusal** — `BackendBudget` rejects a model that can't finish inside
  its budget *before* work starts (422 with the numbers), instead of dying as an opaque
  transport error (`app/Services/AI/BackendBudget.php`).
- **Container CPU affinity** — torch thread count pinned from the cgroup, which fixed
  ~12s of over-subscription per search (`ai-service/app/main.py`).
- **Lazy model loading + cold-start profiling** — documented cold/warm timings
  (~210s → ~0.9s embeddings, ~290s → ~7.6s generation).

### Frontend
- **Next.js App Router** — server components, dynamic routes for workspaces/entries,
  route groups for auth flows.
- **Typed API client layer** — per-domain modules (`lib/entries.ts`, `lib/search.ts`,
  `lib/workspaces.ts`, `lib/documents.ts`, `lib/llm.ts`) with a shared Axios instance.
- **Polling UX for async answers** — pending → resolved assistant message lifecycle.
- **Tiptap rich-text editing** with sanitization and HTML-to-text flattening for indexing.
- **Tailwind CSS 4** design system.

### Database & search
- **pgvector** — `vector(1024)` column, **HNSW** index, cosine distance operator.
- **Postgres full-text search** — generated `tsvector` columns, GIN indexes, weighted
  `ts_rank`, with migrations that add missing foreign-key indexes.
- **Fluent schema design** — 20+ migrations covering workspaces, members, membership
  requests, entries, tags, pivot tables, chunks, documents, summaries, assistant messages.

### DevOps
- **Docker Compose** for 5 services (Postgres, backend, frontend, AI service, Mailpit)
  with dependency healthchecks and named volumes.
- **Process supervision** — Supervisor running nginx, PHP-FPM, and the queue worker.
- **Dependency-version pinning with documented rationale** — torch installed from the CPU
  wheel index to avoid gigabytes of useless CUDA; transformers capped at 5.x because the
  v5 `dtype=` keyword doesn't exist on 4.x.
- **Environment config** — `.env.example` documenting every service; timeout and model
  settings wired through Compose.

### Testing
- **192 backend tests** (PHPUnit, Feature + Unit, in-memory SQLite with a documented
  keyword-only fallback where vector search needs Postgres).
- **85 Python tests** (Pytest) across embeddings, LLM, summarizer, and text utilities.
- **Frontend typecheck + lint** as the test gate (`tsc --noEmit`, ESLint).
- **Authorization tests** as a first-class suite for every workspace-scoped resource.
- **Mocked boundaries** — Fake/Mockery for external AI service calls.

---

## Quick reference — one-line pitch

> Full-stack RAG knowledge base: Next.js 16 + Laravel 12 + FastAPI over local HuggingFace
> models, with pgvector HNSW retrieval, Reciprocal Rank Fusion hybrid search, queued LLM
> generation, model-policy authorization, and Docker Compose infrastructure — 277 tests.