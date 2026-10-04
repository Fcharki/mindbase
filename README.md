# Knowledge Manager

A multi-tenant knowledge base where each workspace keeps its own entries, and an
assistant answers questions from those entries rather than from a general model.

- **Frontend** — Next.js (App Router), TypeScript, Tailwind
- **Backend** — Laravel 12, Sanctum, PostgreSQL + pgvector
- **AI service** — Python, FastAPI, local HuggingFace models on CPU
- **Infra** — Docker Compose, database-backed queue

## Layout

```
backend/       Laravel API, queue worker, migrations, tests
frontend/      Next.js app and typed API clients
ai-service/    FastAPI wrapper around the local models
docker/        nginx config
docker-compose.yml
```

Models are downloaded at runtime into `ai-service/app/models/` and are not in
the image.

## Running it

```bash
docker compose up -d --build
docker compose exec backend php artisan migrate
```

| Service    | URL                     |
| ---------- | ----------------------- |
| Frontend   | http://localhost:3001   |
| API        | http://localhost:8001   |
| AI service | http://localhost:8002   |
| Mailpit    | http://localhost:8025   |

First start pulls roughly 3 GB of model weights and takes a few minutes.

## How it works

### Entries and search

Entries are rich text, chunked and embedded with bge-m3 (1024 dimensions) on save.
Search runs keyword (Postgres full-text) and vector (HNSW) retrieval together and
fuses them by rank rather than by score, since a `ts_rank` and a cosine distance
are not comparable. Anything found is also offered to a summarizer, so entries
carry a short stored summary alongside the full text.

### Asking a workspace

`POST /workspaces/{id}/ask` retrieves passages and returns `202` with a pending
message. A queued job generates the answer from exactly those passages and the
client polls `GET /workspaces/{id}/ask/{message}` until the status flips.

Generation is queued because the instruction-tuned model needs minutes on CPU,
which is longer than a request may hold a worker. Retrieval stays inline so a
question with nothing to match is refused immediately instead of after a
multi-minute call. Sources are stored with the answer, so a citation always
refers to the passages the model actually read.

### Workspaces and access

Every workspace has an owner, members, and an invite code. Roles are `owner`,
`member` and `viewer`. Membership can also be requested and approved. All
workspace data is scoped by policy on the model, not only in the controllers.

## Performance notes

Measured on a 4-core CPU container, no GPU:

| Operation                        | Cold    | Warm   |
| -------------------------------- | ------- | ------ |
| Embedding (bge-m3), one sentence | ~210s   | ~0.9s  |
| Answer generation, short         | ~290s   | ~7.6s  |

Two things dominate, and both are avoidable:

- **Model load** happens once per process and is paid again on every restart.
- **Torch thread count** is pinned in `ai-service/app/main.py` from the cgroup CPU
  affinity. Left to infer it from the host's core count it over-subscribed the
  container and cost roughly twelve seconds per search.

Timeout ordering must hold, or a slow job is killed or double-claimed:

```
DB_QUEUE_RETRY_AFTER (3000) > job $timeout (2700) > ai.answer_timeout (2400)
                        > ai.background_timeout (600) > ai.timeout (120)
```

nginx's `fastcgi_read_timeout` sits at 600s, above the embedding model's first
load, which otherwise killed the first request of a session as a bare 504.

## Tests

```bash
cd backend    && php artisan test      # 192 tests
cd ai-service && python -m pytest -q   # 85 tests
cd frontend   && npx tsc --noEmit && npm run lint
```

Backend tests run on in-memory SQLite. Vector search only exists on Postgres, so
retrieval falls back to a keyword match there and the tests exercise that path
rather than pretending otherwise.

## Configuration

`backend/.env.example` documents the backend. The AI service reads its own
settings in `ai-service`; see `AI_SERVICE.md` for the model details and
`docker-compose.yml` for the values passed into the containers.