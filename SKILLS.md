# Mindbase — Skills & Tech Stack

Multi-tenant knowledge base with retrieval-augmented Q&A. Three application
services — Next.js frontend, Laravel API, Python/FastAPI AI service — plus
Postgres/pgvector, Redis, optional Ollama and Mailpit, orchestrated with Docker
Compose.

---

## Core stack

| Layer | Technologies |
| --- | --- |
| Frontend | **Next.js 16** (App Router), **React 19**, **TypeScript 5.9**, **Tailwind CSS 4**, **Tiptap 3** (rich-text editor), **Axios**, ESLint |
| Backend | **PHP 8.2**, **Laravel 12**, **Laravel Sanctum** (API tokens), **Laravel Socialite** (Google OAuth), **Dompdf** (PDF export), **API Resources**, **Form Requests**, **Policies**, **Observers**, **Database queue**, PHPUnit 11, Pint, Faker |
| AI service | **Python 3.11**, **FastAPI**, **Uvicorn**, **PyTorch** (CPU wheels), **HuggingFace Transformers 5**, **Pydantic 2**, **BeautifulSoup4**, **PaddleOCR**, Pytest |
| Models | **bge-m3** embeddings (1024-dim), **Qwen3-0.6B** generation (local HF or optional Ollama), **T5-small** / Qwen summarization, **ms-marco-MiniLM-L-6-v2** cross-encoder reranker, **PaddleOCR** |
| Database | **PostgreSQL 18**, **pgvector** (HNSW index, 1024-dim), **full-text search** (`tsvector` / `plainto_tsquery` / `ts_rank`, GIN) |
| Infrastructure | **Docker Compose** (7 services), multi-stage Dockerfiles, **nginx + PHP-FPM**, **Supervisor**, OPcache, Docker healthchecks, **Redis** (cache), **Ollama** (optional), **Mailpit** (dev SMTP/UI), **Vite** + Laravel plugin |

---

## Skills demonstrated

### AI / Machine Learning
- **RAG (Retrieval-Augmented Generation)** — answers are generated only from
  retrieved workspace passages, never from model priors. Sources are persisted
  with the answer, so every citation points at text the model actually read
  (`backend/app/Services/AI/RagService.php`).
- **Vector embeddings** — `BAAI/bge-m3` (1024 dimensions), CLS pooling + L2
  normalization, batched forward passes so an entry costs one model load, not
  one per chunk (`ai-service/app/services/embeddings.py`).
- **Semantic + lexical hybrid search with Reciprocal Rank Fusion** — keyword and
  vector retrieval run together and merge by *rank*, not score, because a
  `ts_rank` and a cosine distance aren't comparable units
  (`backend/app/Services/Search/HybridSearchService.php`).
- **Cross-encoder reranking** — fused candidates are reordered by
  `cross-encoder/ms-marco-MiniLM-L-6-v2`, which reads question and passage
  together; a reranker failure falls back to RRF order instead of losing results
  (`backend/app/Services/AI/RerankService.php`, `ai-service/app/services/rerank.py`).
- **Query embedding cache** — search-box vectors are cached by text and model
  width, so a debounced query does not re-embed on every keystroke
  (`backend/app/Services/Search/QueryEmbeddingCache.php`).
- **Instruction-tuned local LLM inference** — `Qwen3-0.6B` with `transformers`
  (`AutoModelForCausalLM`, `dtype=bfloat16`), or an optional Ollama backend for
  larger/pulled models. Greedy decoding, repetition penalty, generation budgets,
  hidden `think` blocks (`ai-service/app/services/llm.py`).
- **Summarization** — T5-small seq2seq (fast, extractive) or Qwen (abstractive),
  selectable per request, with repetition controls and on-demand regeneration
  (`ai-service/app/services/summarizer.py`,
  `backend/app/Services/EntrySummaryService.php`).
- **OCR and attachment extraction** — PaddleOCR reads text inside inline images
  and raster attachments; PDF text layers and OpenXML (`.docx`, `.xlsx`,
  `.pptx`) are extracted natively; all extracted text is chunked and indexed
  (`backend/app/Services/AI/OcrService.php`,
  `backend/app/Services/Extraction/`).
- **Text chunking for retrieval** — semantic chunking with overlap (1800 target,
  300 overlap chars), hard cap of 60 chunks/entry, HTML flattening that strips
  editor markup before embedding (`backend/app/Services/ChunkingService.php`).
- **Measured thresholds, not guesses** — cosine distance floor of 0.7 chosen from
  the observed gap between paraphrases (0.25–0.39) and unrelated questions
  (0.72–0.75); a separate fused-score floor of 0.008 filters weak RRF hits.

### Backend engineering
- **Laravel 12 architecture** — Action classes for write paths, dedicated Services
  for domain logic (`app/Actions`, `app/Services`), thin controllers.
- **Multi-tenancy & authorization** — owner/member/viewer roles, invite codes,
  request-to-join + approval flow. Access enforced by **model policies**, not just
  controllers (`app/Policies/`), with dedicated authorization tests.
- **Custom Eloquent cast** — `AsVector` serializes vectors to pgvector's literal
  format, handling Postgres' `{}` output vs. JSON, float formatting edge cases,
  and NaN/Inf rejection (`app/Casts/AsVector.php`).
- **Authentication** — session/cookie auth via Sanctum, email verification,
  password reset, Google OAuth through Socialite.
- **Profiles** — unique usernames, bios, location, photo/cover uploads, password
  change and account deletion, plus a user directory and public profiles
  (`ProfileController`, `UserController`).
- **Discussions & notifications** — per-entry comments with one level of replies,
  and in-app notifications on comment/reply (`CommentController`,
  `ReplyController`, `NotificationController`).
- **Audit logging** — model observers record workspace-scoped changes behind a
  policy-protected read endpoint (`app/Observers`, `AuditLogController`).
- **Attachments and entry images** — metadata in Postgres, bytes on a private
  disk, UUID-served images, previews and downloads (`DocumentController`,
  `EntryImageController`).
- **Exports** — Markdown, HTML, JSON and PDF (Dompdf) from one entry
  (`EntryExportService`).
- **Tags and bulk actions** — nested workspace tags, tag suggestions from the
  workspace's own vocabulary, pinning and bulk delete/tag/untag
  (`TagController`, `BulkEntryActionsTest`).
- **Raw SQL where the ORM falls short** — hand-written `ts_rank` / vector-distance
  queries with bound parameters for retrieval paths.
- **API Resources** as an output contract, verified by a `ResourceContractTest`.

### Async & performance
- **Database-backed queue** — long LLM calls (minutes on CPU) moved off the
  request. `POST /ask` returns `202` with a pending message; the client polls or
  consumes server-sent events (`GenerateAssistantAnswer`, `stream` route).
- **Timeout ordering discipline** — `DB_QUEUE_RETRY_AFTER (3000) > answer job
  $timeout (2700) > ai.answer_timeout (2400) > ai.background_timeout (600) >
  ai.timeout (120)`, with OCR (300) and extraction/summary jobs (600/900) also
  below the retry window, so a slow job is never killed or double-claimed.
- **Up-front budget refusal** — `BackendBudget` rejects a model that can't finish
  inside its budget *before* work starts (422 with the numbers), instead of dying
  as an opaque transport error (`app/Services/AI/BackendBudget.php`).
- **Cache invalidation by version key** — workspace index caches are flushed by
  bumping a version UUID rather than hunting individual keys
  (`app/Services/WorkspaceIndexCache.php`), backed by Redis in Compose.
- **Container CPU affinity** — torch thread count pinned from the cgroup, which
  fixed ~12s of over-subscription per search (`ai-service/app/main.py`).
- **Lazy model loading + cold-start profiling** — documented cold/warm timings
  (~210s → ~0.9s embeddings, ~290s → ~7.6s generation).
- **Streaming** — generation deltas are flushed to an SSE endpoint as the job
  writes them (`WorkspaceController::stream`), with the same row available to
  pollers.

### Frontend
- **Next.js App Router** — server components, dynamic routes for
  workspaces/entries/profiles, route groups for auth and join flows.
- **Typed API client layer** — 17 per-domain modules (`lib/entries.ts`,
  `lib/search.ts`, `lib/workspaces.ts`, `lib/documents.ts`, `lib/llm.ts`,
  `lib/comments.ts`, `lib/notifications.ts`, `lib/auditLogs.ts`, and more) over a
  shared Axios instance.
- **Async answer UX** — polling and SSE streaming for pending → resolved
  assistant messages, plus regenerate and thumbs up/down feedback.
- **Tiptap rich-text editing** with inline images, sanitization, and HTML-to-text
  flattening for indexing.
- **Tailwind CSS 4** design system with light/dark themes following the system
  preference.

### Database & search
- **pgvector** — `vector(1024)` column, **HNSW** index, cosine distance operator.
- **Postgres full-text search** — generated `tsvector` columns, GIN indexes,
  weighted `ts_rank`, with migrations that add missing foreign-key indexes.
- **Fluent schema design** — 34 migrations covering workspaces, members,
  membership requests, entries, tags, pivot tables, chunks, documents, images,
  summaries, comments, replies, notifications, audit logs, assistant messages and
  the query-embedding cache.

### DevOps
- **Docker Compose** for 7 services (Postgres, Redis, backend, frontend, AI
  service, Ollama, Mailpit) with dependency healthchecks and named volumes.
- **Process supervision** — Supervisor running nginx, PHP-FPM, and the queue
  worker.
- **Optional backends kept optional** — Redis is the default cache but the stack
  falls back to the database; Ollama is opt-in via `LLM_PROVIDER=ollama` and
  ships no model weights.
- **Dependency-version pinning with documented rationale** — torch installed from
  the CPU wheel index to avoid gigabytes of useless CUDA; transformers capped at
  5.x because the v5 `dtype=` keyword doesn't exist on 4.x; PaddleOCR pinned to
  the 3.x line.
- **Environment config** — `.env.example` documenting every service; timeout,
  model and OCR settings wired through Compose.

### Testing
- **399 backend tests** (PHPUnit 11, Feature + Unit + Postgres, in-memory SQLite
  with a documented keyword-only fallback; pgvector tests skip unless a Postgres
  test database is present).
- **107 Python tests** (Pytest) across embeddings, LLM, summarizer, reranker,
  OCR and text utilities — **506 tests total**.
- **Frontend typecheck + lint** as the test gate (`tsc --noEmit`, ESLint).
- **Authorization tests** as a first-class suite for every workspace-scoped
  resource.
- **Mocked boundaries** — Fake/Mockery for external AI service calls.

---

## Quick reference — one-line pitch

> Full-stack RAG knowledge base: Next.js 16 + Laravel 12 + FastAPI over local
> HuggingFace models, with pgvector HNSW retrieval, Reciprocal Rank Fusion,
> cross-encoder reranking, OCR and attachment indexing, queued + streamed LLM
> generation, policy-based authorization, and Docker Compose infrastructure —
> 506 tests.
