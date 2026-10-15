# AI Service — Implementation Notes

Three local models behind one FastAPI container (`ai-service`), proxied to by the Laravel backend. Nothing leaves the machine.

| Concern | Model | Endpoint |
|---|---|---|
| Summarization (default) | `Falconsai/text_summarization` (T5-small, 242 MB) | `POST /summarize` |
| Summarization (alt) | `Qwen3-0.6B` (instruction-tuned, 1.5 GB) | `POST /summarize` with `backend: "qwen"` |
| Embeddings / retrieval | `BAAI/bge-m3` (2.2 GB) | `POST /embed`, `POST /embed/batch` |
| Free-form chat | `Qwen3-0.6B` | `POST /rag` |

Weights live in `ai-service/app/models/` (git-ignored, mounted as a host volume rather than baked into the image). All three load **lazily** on first use, which is why `/health` reports `*_loaded: false` on a fresh container.

Request chain, end to end:

```
Frontend → Laravel API → Guzzle → FastAPI → local model → string/vector → back up
```

---

## 1. Layout

```
ai-service/app/
  main.py                     assembles the FastAPI app
  util.py                     markup flattening + degenerate-output detection
  api/routes/
    health.py                 GET  /health
    summarization.py          POST /summarize
    embeddings.py             POST /embed, /embed/batch
    rag.py                    POST /rag
  services/
    summarizer.py             T5Summarizer + the Summarizer dispatcher
    llm.py                    LLMService (Qwen3-0.6B), backs /rag and the qwen backend
    embeddings.py             EmbeddingService (bge-m3)
```

`app/main.py` is 32 lines: it pins the torch thread count, then mounts the four routers. Each service file exposes a module-level singleton (`summary_service`, `llm_service`, `embedding_service`) so a model is loaded once per process.

**Thread pinning is the single biggest win in this service.** Left alone, torch sizes its pool from the *host's* core count, which over-subscribes inside a container. Measured here, encoding one sentence took 13 s on a single thread against 1.1 s on two — about twelve seconds added to every search. `main.py` reads `os.sched_getaffinity` (clamped to 4) rather than `os.cpu_count()`, because a limited container can see cores it is not allowed to use. It has to run before any model is built, since torch reads the value once at import.

## 2. The models

### `services/summarizer.py` — the default path

`T5Summarizer` prefixes `"summarize: "` (the checkpoint was fine-tuned on it), tokenizes truncated to `AI_MAX_INPUT_LENGTH`, and generates under `torch.inference_mode()`.

Two guards, both because greedy search on this checkpoint loops on short or repetitive input and emits one phrase until the token budget runs out:

- `no_repeat_ngram_size=3` bars any trigram from repeating.
- `is_degenerate_output()` (`util.py`) then catches what that misses, and a degenerate summary triggers **one retry with beam search** before giving up and returning `""`.

`Summarizer` is the dispatcher in front of it. `AI_SUMMARY_BACKEND` picks `t5` or `qwen`; an unknown name falls back to `t5` and says so in the response rather than failing.

### `services/llm.py` — Qwen3-0.6B

Serves `/rag`, and backs `backend: "qwen"` for summarization. **This backend is not viable on a CPU-only box.** Measured on the 4-core container this project ships on:

- load: ~535 s for the 1.5 GB checkpoint
- decode: ~0.10 tokens/second
- a 200 token answer: ~33 minutes

`LLM_DTYPE` defaults to `bfloat16`. That is the prime suspect for the decode speed — bfloat16 is native only on AVX512-BF16 CPUs and emulated elsewhere, which is why it can lose to float32 outright. It is left alone because the accuracy difference is real and the alternative has not been benchmarked here.

Output is passed through `is_degenerate_output()` and discarded if it is a repetition loop, since a fluent-but-looping answer is worse than no answer: it would be stored as the entry's summary and then indexed.

### `services/embeddings.py` — bge-m3

Built on plain `transformers`, **not** `SentenceTransformer`. bge-m3's published snapshot lists a `2_Normalize` module in `modules.json` but never ships the directory, so `SentenceTransformer` raises on load. The dense recipe the model documents is two steps, reproduced directly:

1. CLS pooling (per the model's own `1_Pooling/config.json`)
2. L2 normalise, so a dot product *is* cosine similarity

Vectors come out 1024-dim and unit-length.

**Code-only chunks.** `clean_text` drops code blocks — correct for a summariser, wrong here, because a chunk given no vector is not indexed and so is not findable by keyword search either. One pasted SQL dump would otherwise cost the entry its whole index. So when flattening leaves nothing, the fallback re-flattens with `drop_code=False`. bge-m3 handles code perfectly well.

### `util.py` — shared input cleaning

`clean_text()` flattens editor HTML *and* markdown to prose. Two guards keep it honest:

- A payload is only treated as markup if it actually contains a tag, otherwise prose like `if x < 5 and y > 2` gets parsed as HTML and silently loses text.
- Underscore emphasis forms are deliberately **not** stripped. `_italic_` is indistinguishable from a snake_case name, and `__init__` would come back mangled.

`is_degenerate_output()` flags repetition loops two ways: a unique-word ratio below 0.30 (only consulted past 12 words, because short summaries repeat vocabulary legitimately) and an immediately repeated phrase of up to 5 words.

## 3. HTTP surface

| Route | Request | Response |
|---|---|---|
| `GET /health` | — | `{status, summarization_backend, summarizer_loaded, embedder_loaded, llm_loaded, llm_max_new_tokens, llm_known_slow}` |
| `POST /summarize` | `{prompt, backend?}` | `{response, backend}` |
| `POST /embed` | `{text}` | `{embedding, dimension}` |
| `POST /embed/batch` | `{texts}` | `{embeddings, dimension}` |
| `POST /rag` | `{prompt, system?}` | `{response}` |

`/health` is cheap — it reports load state without loading anything — so it is safe as the Docker healthcheck and as a `depends_on: service_healthy` gate.

Two contract details the backend depends on:

- `backend` in the `/summarize` response is **the model that actually answered**, not the one asked for. An unknown name falls back to the configured default.
- `/embed/batch` preserves input order and length. A text with no prose still returns an empty vector rather than being dropped, so the caller can zip chunks to vectors positionally.
- `system` on `/rag` is optional. Omitted, the model gets a bare user turn; `llm.summarize()` supplies its own summarization instruction, and `RagService` supplies the retrieval-grounding one.

## 4. Environment variables

Read in the FastAPI container; all optional, all defaulted in `docker-compose.yml`.

| Var | Default | Read by |
|---|---|---|
| `AI_SUMMARY_BACKEND` | `t5` | `summarizer.py` — which model `/summarize` uses |
| `AI_MAX_INPUT_LENGTH` | `512` | `summarizer.py` — T5 input truncation |
| `AI_MAX_NEW_TOKENS` | `150` | `summarizer.py` — summary length cap |
| `AI_NUM_BEAMS` | `1` | `summarizer.py` — 1 = greedy; >1 = beam + early stopping |
| `AI_NO_REPEAT_NGRAM_SIZE` | `3` | `summarizer.py` |
| `AI_REPETITION_PENALTY` | `1.15` | `summarizer.py` |
| `AI_STRIP_FORMATTING` | `true` | `util.py` — strip HTML/markdown |
| `AI_DROP_CODE_BLOCKS` | `true` | `util.py` — drop code; see the embeddings note above |
| `EMBEDDING_MAX_INPUT_LENGTH` | `8192` | `embeddings.py` — bge-m3's trained maximum |
| `LLM_DTYPE` | `bfloat16` | `llm.py` |
| `LLM_MAX_NEW_TOKENS` | `200` | `llm.py` |
| `LLM_THINKING` | `false` | `llm.py` — Qwen3's `<think>` block costs hundreds of tokens |
| `LLM_REPETITION_PENALTY` | `1.1` | `llm.py` |

## 5. The Laravel side

`backend/config/ai.php` holds the backend's half of the configuration.

| Var | Default | Purpose |
|---|---|---|
| `AI_SERVICE_URL` | `http://ai-service:8002` | Base URL (`http://localhost:8002` in `backend/.env`) |
| `AI_SERVICE_TIMEOUT` | `120` | Synchronous Guzzle budget |
| `AI_SERVICE_BACKGROUND_TIMEOUT` | `600` | Queued summary / chunk indexing budget |
| `AI_SERVICE_ANSWER_TIMEOUT` | `2400` | Queued assistant answer budget |
| `AI_ALLOW_SLOW_BACKENDS` | `false` | Queue a backend that exceeds the budget anyway |
| `AI_EMBEDDING_DIMENSION` | `1024` | Width of the `entry_chunks` vector column |

`AI_SERVICE_ANSWER_TIMEOUT` exists separately from the background timeout on purpose: raising the background budget to accommodate a 33-minute answer would also let an ordinary *summary* pick `qwen`, which is a different decision with a different cost.

**Timeout ordering is load-bearing** and must hold:

```
DB_QUEUE_RETRY_AFTER (3000) > job $timeout (2700) > answer (2400) > background (600) > timeout (120)
```

If `DB_QUEUE_RETRY_AFTER` falls below a job's own `$timeout`, a worker re-reserves a job that is still running. The assistant job carries the longest `$timeout` because it waits on `answer`, which is deliberately larger than `background` — raising the background budget would also let a *summary* pick the instruction-tuned backend, which is a separate decision.

### Clients and jobs

- `app/Services/AI/SummarizationService.php` — Guzzle wrapper. `summarize()` returns the string; `summarizeWithMetadata()` also returns which backend answered, so a stored `Summary` records the model that wrote it. Nothing is cached here — each call hits the model — so after a backend swap the older stored rows are the ones worth regenerating.
- `app/Services/AI/EmbeddingService.php` — Guzzle wrapper. `embedMany()` enforces the ordering contract and checks every returned vector against `AI_EMBEDDING_DIMENSION`; a mismatch names the cause instead of failing at the database with a column error.
- `app/Services/AI/BackendBudget.php` — refuses a backend that cannot finish inside its budget, **before** any work starts. `config/ai.php` carries the measured cost of each (`t5` ≈ 15 s, `qwen` ≈ 2000 s). The request fails with 422 and the two numbers involved, rather than occupying a worker for the full budget and then dying as an opaque transport error.
- `app/Jobs/GenerateEntrySummary.php` — stores the summary. Re-asks `BackendBudget` on arrival, because a job queued before a config change can land after it. An empty summary leaves any existing one in place.
- `app/Jobs/IndexEntryChunks.php` — embeds each chunk and replaces the entry's rows wholesale. Replaced rather than merged, so leftover rows from a longer revision cannot keep answering searches with text the entry no longer contains.

Those two carry a `content_hash` of the entry content and **discard themselves** if the entry has since been edited. A summary of a revision the user has already moved past is worse than none.

### Asking a workspace

- `app/Services/AI/PassageFormatter.php` — renders stored `source` rows into the numbered passage block (`[1] Title\nbody`) the model is shown. The numbers are what let a reader trace an answer back to its entry.
- `app/Services/AI/RagService.php` — calls `/rag`. `withContext()` wraps the question in a grounding instruction pasted into the **prompt**, not the `system` field: "answer using only these passages, and if they do not contain the answer, say so plainly". Without it the model answers from its weights, which for a question about somebody's own notes means inventing plausible ones.
- `app/Jobs/GenerateAssistantAnswer.php` — `$timeout = 2700`, the largest in the project, because it waits on `ai.answer_timeout` (2400).

`GenerateAssistantAnswer` is the one AI job with **no** `content_hash`, and it deliberately does not re-run retrieval. Retrieval happens inline in `AskWorkspace`, before the job is dispatched, and the passages it found are stored on the message; re-retrieving inside the job would cite whatever happens to be indexed *now*, which is not necessarily what the model answered from. An edit landing during the wait therefore changes what a citation points at, and the fix is to re-ask rather than re-index silently.

That split — retrieval inline, generation queued — is the reason a question with nothing to match is refused in milliseconds rather than after a multi-minute model call. `AskWorkspace` takes 8 passages: retrieval is cheap next to generation, and more passages ground the answer better.

### Chunks that get left out

A chunk the model had nothing to say about gets no row rather than a placeholder vector, which would rank it against every other entry. Positions are assigned before embedding, so skipping one leaves a harmless gap — the unique index on `(knowledge_entry_id, source, position)` does not require contiguity. The job logs how many were skipped.

### Routes

| Route | Behaviour |
|---|---|
| `POST workspaces/{workspace}/entries/{entry}/summarize` | Answers immediately, stores nothing |
| `POST workspaces/{workspace}/entries/{entry}/summary/regenerate` | Queues a regeneration, optionally naming the backend |
| `GET workspaces/{workspace}/search` | Entries + tags |
| `GET workspaces/{workspace}/search/chunks` | Hybrid search over chunks |
| `POST workspaces/{workspace}/ask` | Retrieves inline, then returns `202` with a pending message |
| `GET workspaces/{workspace}/ask/{message}` | Polls that message until its status flips |

`regenerate` validates the requested backend against `config('ai.backends')`, which is why that list lives in config rather than in the request: one place to extend when a model is added.

### Retrieval

`app/Services/Search/HybridSearchService.php` runs full-text (`ts_rank`) and vector (`<=>`) search together and fuses them by **rank**, not by value — a `ts_rank` of 0.08 and a cosine distance of 0.31 are not comparable, and neither is calibrated across queries, so weighting them would mostly measure the two scoring functions. Reciprocal Rank Fusion (`k=60`) is scale-free and has nothing to tune.

Vector hits past a cosine distance of `0.7` are dropped entirely: that is a floor for "not a match", not a weak match. The threshold is measured, not guessed — bge-m3 does not spread its vectors over the full range, and 0.7 sits inside the observed gap between paraphrases (0.25–0.39) and unrelated questions (0.72–0.75).

Each engine gets one vote per chunk, so a chunk repeated within a single list cannot float to the top.

## 6. Container

`ai-service/Dockerfile` is `python:3.11-slim` + `libgomp1` (torch's parallelism) + `curl` (healthcheck).

`torch` is **not** in `requirements.txt`. It is installed separately and pinned to a `+cpu` build from the PyTorch index, because PyPI's plain `torch` resolves to a CUDA build that adds several gigabytes and buys nothing here. Note that `--extra-index-url` does not prevent this — pip treats both indexes as equal candidates, so the install uses `--index-url` for torch alone.

`requirements.txt` is range-pinned. `transformers>=5.0,<6` is a real constraint, not caution: `llm.py` loads the model with the v5 `dtype=` keyword, which does not exist on 4.x, so an unpinned resolver is a coin flip on whether the LLM still imports.

Compose mounts `./ai-service:/srv` so code and the git-ignored `models/` come from the host rather than the image (`.dockerignore` excludes `app/models` from the build context). Healthcheck is `GET /health`, and the backend waits on `service_healthy`.

## 7. Troubleshooting

| Symptom | Cause |
|---|---|
| `/health` says `*_loaded: false` | Not called yet. Everything is lazy. |
| First `/summarize` times out | Paying the T5 lazy load (~10 s). Subsequent calls are seconds. |
| First `/embed` times out | Paying the bge-m3 lazy load (~45 s). `AI_SERVICE_TIMEOUT` will not cover a cold start on a busy box. |
| `/rag` or `backend: "qwen"` hangs | Expected. ~33 minutes for a 200-token answer on CPU. |
| `422` from regenerate | `BackendBudget` refused it. See the error message for the two numbers. |
| Dimension mismatch error | `AI_EMBEDDING_DIMENSION` and the vector column disagree. Rebuild the column and reindex. |
| Job killed at `timeout` | Raise `AI_SERVICE_BACKGROUND_TIMEOUT`, or use a faster backend. |