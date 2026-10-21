# Image OCR — Implementation Notes

Entries can contain screenshots, photos of receipts and whiteboards, and image
attachments. This is how the text inside those images becomes searchable and
available to the assistant: PaddleOCR runs in the AI service, the Laravel
backend stores what it read, and the normal chunk-and-embed pipeline indexes it.

The model never runs in the browser or in Laravel. Every image goes through the
AI service's `POST /ocr` endpoint.

---

## 1. At a glance

| Concern | Implementation |
|---|---|
| OCR engine | PaddleOCR 3.x (`paddleocr` + `paddlepaddle`), CPU only |
| AI endpoint | `POST /ocr` in `ai-service/app/api/routes/ocr.py` |
| AI engine wrapper | `ai-service/app/ocr.py` |
| Backend client | `App\Services\AI\OcrService` |
| Inline entry images | `App\Jobs\ExtractEntryImageText` (queued) |
| Image document uploads | `App\Services\Extraction\TextExtractionService` → `OcrService` (queued by `ExtractDocumentText`) |
| Stored text | `entry_images.extracted_text` or `documents.extracted_text` |
| Indexed as | `entry_chunks.source = "image:<id>"` or `"document:<id>"` |
| Frontend | Uploads images only; it never calls `/ocr` directly |

---

## 2. End-to-end flow

### Inline image in an entry

```
editor ──► POST /api/v1/workspaces/{workspace}/images
              │
              ▼
         EntryImageController@store
              │  stores bytes, creates entry_images row (pending)
              ├──► 201 with { id, url, ... } immediately
              │
              ▼
         ExtractEntryImageText (queued)
              │
              ▼
         OcrService::extract(bytes, original_name, mime_type)
              │  POST multipart /ocr
              ▼
         FastAPI ──► PaddleOCR
              │
              ▼
         entry_images.extracted_text + extraction_status
              │
              ▼
         IndexEntryChunks (queued)
              │
              ▼
         ChunkingService adds source "image:<id>"
              │
              ▼
         entry_chunks ──► search and RAG passages
```

An image is uploaded **before** the entry exists (the new-entry screen). That
race is handled from both sides:

- Saving the entry indexes any OCR text that already exists.
- If OCR finishes after the image has been linked to an entry, the job
  re-indexes that entry.

### Image uploaded as a document attachment

```
POST /api/v1/workspaces/{workspace}/entries/{entry}/documents
    │
    ▼
ExtractDocumentText (queued)
    │
    ▼
TextExtractionService
    │
    ├── pdf/docx/xlsx/pptx/txt/...  (not OCR)
    └── jpg/jpeg/png/gif/webp ──► OcrService ──► POST /ocr
    │
    ▼
documents.extracted_text + extraction_status
    │
    ▼
IndexEntryChunks ──► source "document:<id>"
```

Both upload endpoints return before OCR runs. The text appears on the row once
the queued job completes.

---

## 3. The AI service endpoint

`ai-service/app/api/routes/ocr.py`

```
POST /ocr
Content-Type: multipart/form-data

file: <image bytes>
```

Response:

```json
{"text": "Invoice 42\nTotal 10.00"}
```

`text` is the recognised lines joined with `\n`. A readable image with no text
returns `{"text": ""}`. That is a successful result, not an error.

The handler is synchronous on purpose. PaddleOCR is CPU-bound, and FastAPI runs
a sync handler in its thread pool, so the event loop stays free for health
checks and other requests while the image is decoded.

### Status codes

| Status | When |
|---|---|
| `200` | OCR ran. `text` may be empty. |
| `400` | The upload has no bytes. |
| `413` | The image exceeds `OCR_MAX_IMAGE_BYTES` (default 20 MB). |
| `415` | The filename suffix is not an accepted image format. |
| `500` | PaddleOCR could not read the image; `detail` carries the reason. |

Accepted suffixes at this boundary are `.jpg`, `.jpeg`, `.png`, `.gif`,
`.webp`, `.bmp`, `.tif` and `.tiff`. The Laravel upload rules are stricter and
only allow `.jpg`, `.jpeg`, `.png`, `.gif` and `.webp`.

The size check reads one byte past the limit:
`file.file.read(MAX_IMAGE_BYTES + 1)`. This detects an oversized upload without
buffering the whole thing, and is a second line of defence for direct callers.
The normal client-facing limits are enforced by Laravel.

### Try it directly

```bash
curl -F "file=@receipt.png;type=image/png" http://localhost:8002/ocr
curl -s http://localhost:8002/health
```

The health response includes `ocr_loaded`. It is `false` until the first OCR
call, because the model is loaded lazily.

---

## 4. How PaddleOCR is configured

`ai-service/app/ocr.py` creates one engine per process, on first use:

```python
_ocr = PaddleOCR(
    lang=os.getenv("OCR_LANG", "en"),
    device="cpu",
    use_doc_orientation_classify=False,
    use_doc_unwarping=False,
    use_textline_orientation=False,
)
```

Why each setting is there:

| Setting | Reason |
|---|---|
| Lazy load | Building the engine downloads and loads the models. Doing it at import would delay every start, including `/health`, for a feature most requests do not use. |
| `device="cpu"` | The deployment is CPU-only. |
| `OCR_LANG`, default `en` | Selects the recognition model. Changing it makes PaddleOCR download a different model on the next start. |
| `use_doc_orientation_classify=False` | Extra model and latency for whole-page rotation. Phone photos are already corrected from EXIF, see below. |
| `use_doc_unwarping=False` | Extra model and latency for curved-page correction. |
| `use_textline_orientation=False` | Skips the line-rotation classifier. Text that is rotated inside an otherwise upright image may not be read correctly. |

The engine is built behind an `RLock`, and `predict()` also runs under that
lock. Building the predictor twice, or entering one instance from two uploads
at once, is not safe; the lock serialises OCR calls within a single AI service
process. Running several AI service replicas would give parallelism across
processes, at the cost of more memory.

### First call is slow

The first `/ocr` call downloads the detection and recognition models and builds
the predictor. Until that finishes, `/health` still reports the service as up
but `ocr_loaded: false`. The backend budgets for this with
`AI_SERVICE_OCR_TIMEOUT` (default 300 seconds) and a queued job timeout of 600
seconds.

The model cache is controlled by `PADDLE_PDX_CACHE_HOME`. In `docker-compose.yml`
it points at `/srv/app/models/paddlex`, which is inside the bind-mounted
`./ai-service` directory, so the models survive a container recreation.
Without that setting PaddleOCR would cache under the container's home directory
and re-download after the container is replaced.

---

## 5. Preprocessing and result parsing

### Normalising the image

PaddleOCR reliably decodes JPEG and PNG. WebP and GIF are allowed uploads but
are not decoded consistently, and an animated GIF could otherwise be read from
an arbitrary frame. So `extract_text()` passes every upload through Pillow:

```python
ImageOps.exif_transpose(image).convert("RGB").save(buffer, format="PNG")
```

This does three jobs:

1. Applies the EXIF orientation, so a phone photo is not fed to the model
   sideways.
2. Reads the first frame of an animated GIF.
3. Converts WebP, GIF, BMP and TIFF into a PNG PaddleOCR can read.

If Pillow cannot open the bytes (or is unavailable), the original bytes are
passed through with their original suffix and PaddleOCR gets the chance to
decode them itself.

### The temporary file

PaddleOCR is given a local path. The extractor writes a temporary file with
`delete=False`, closes it, runs `predict()`, and removes it in a `finally`
block. The file must be closed before the engine opens it: Windows will not let
a process reopen a `NamedTemporaryFile` that is still held open.

### Reading the result

PaddleOCR 3.x returns result objects. Their content is available as `.json`,
shaped like:

```python
{
    "res": {
        "rec_texts": ["Invoice 42", "Total 10.00"],
        ...
    }
}
```

`_lines()` accepts the forms a PaddleOCR build may return:

- an object with a `.json` property holding the mapping;
- an object with a `.json()` method;
- a JSON string;
- the mapping itself, with or without the outer `"res"` key.

It reads `rec_texts`, strips each line, drops blanks, and preserves the order
PaddleOCR detected the lines in. There is no column or table reconstruction:
the text is a flat list of recognised lines, not the original layout.

---

## 6. Backend integration

### Inline entry images

`App\Http\Controllers\EntryImageController@store`

1. Validates the upload (`jpg`, `jpeg`, `png`, `gif`, `webp`, maximum 10 MB,
   real MIME checked against the bytes).
2. Stores the file and creates an `entry_images` row with status `pending`.
3. Dispatches `ExtractEntryImageText`.
4. Returns `201` with the image URL.

`App\Jobs\ExtractEntryImageText` then:

1. Reloads the row. If it is gone, it returns; if the stored file is missing, it
   marks the row `failed`.
2. Calls `OcrService::extract()` with the bytes, original filename and MIME type.
3. Caps the text at `EXTRACTION_MAX_CHARACTERS` (default 200,000).
4. Writes `extracted_text`, `extraction_status`, `extraction_error` and
   `extracted_at`.
5. Re-dispatches `IndexEntryChunks` if there is text and the image belongs to an
   entry.

Statuses:

| Status | Meaning |
|---|---|
| `pending` | Uploaded; the job has not run yet. |
| `ready` | Text was read and is indexable. |
| `empty` | The image was read and contained no text. |
| `failed` | The file was missing or OCR raised an error. |

The OCR metadata is internal for now: `EntryImageResource` exposes the image URL
and file metadata, not the extracted text or status.

### Image documents

`TextExtractionService` treats `jpg`, `jpeg`, `png`, `gif` and `webp` as OCR
inputs. It calls the same `OcrService` and records the result on the `documents`
row using the normal extraction statuses (`pending`, `ready`, `empty`,
`unsupported`, `failed`).

A failed OCR throws from `OcrService`, and `TextExtractionService` converts it
into an `ExtractionResult::failed()` with the error message. An image with no
text becomes `empty`, not `failed`.

### Calling the AI service

`App\Services\AI\OcrService` sends the image as multipart field `file`:

```php
$this->http->postMultipart('ocr', [
    [
        'name' => 'file',
        'contents' => $contents,
        'filename' => $filename,
        'headers' => ['Content-Type' => $mimeType ?: 'application/octet-stream'],
    ],
], timeout: (int) config('ai.ocr_timeout'), retries: 0);
```

Client retries are deliberately disabled. The calling job already has three
attempts with a 30/120/300-second backoff, so retrying here as well would make a
cold start pay the OCR timeout up to three times. The HTTP call is still a
single attempt per job attempt.

The AI service's base URL comes from `AI_SERVICE_URL`
(`http://ai-service:8002` in Docker).

---

## 7. From OCR text to retrieval

`ChunkingService` adds OCR text to an entry's chunks:

- `entry_images` rows with `extraction_status = ready` and non-null
  `extracted_text` become `source = "image:<id>"`.
- `documents` rows with `extraction_status = ready` and non-null
  `extracted_text` become `source = "document:<id>"`.
- Positions start at `0` per source, so two images cannot collide on the unique
  `(knowledge_entry_id, source, position)` index.
- Text goes through the same `flatten()` and `split()` as entry content: sentence
  boundaries, 1,800-character target, 300-character overlap.
- The per-entry `MAX_CHUNKS` cap (60) still applies.

The result is one passage per source, searchable by the hybrid keyword/vector
retrieval and available to the assistant as a retrieved passage. A fact that
appears only in a screenshot therefore becomes part of the entry's knowledge,
not just a decoration in the editor.

---

## 8. Configuration

| Variable | Default | Where | Purpose |
|---|---|---|---|
| `OCR_LANG` | `en` | AI service | PaddleOCR recognition language. Changing it downloads a different model. |
| `OCR_MAX_IMAGE_BYTES` | `20971520` (20 MB) | AI service | Direct-caller size ceiling for `/ocr`. |
| `PADDLE_PDX_CACHE_HOME` | `/srv/app/models/paddlex` in Compose | AI service | Where PaddleOCR stores downloaded models. |
| `AI_SERVICE_URL` | `http://ai-service:8002` | Backend | AI service base URL. |
| `AI_SERVICE_OCR_TIMEOUT` | `300` | Backend | Per-attempt OCR request timeout, in seconds. |
| `AI_SERVICE_RETRIES` | `2` | Backend | Retries for synchronous AI calls. Not applied to OCR: `OcrService` passes `retries: 0`. |
| `EXTRACTION_MAX_CHARACTERS` | `200000` | Backend | Cap on text kept per image or document. `0` disables it. |

Job budgets:

- `ExtractEntryImageText`: timeout 600 s, 3 attempts, backoff 30/120/300 s.
- `ExtractDocumentText`: timeout 600 s, 3 attempts, backoff 30/120/300 s.

Both are above the 300-second OCR request timeout, so one attempt can finish
before the job itself is killed.

---

## 9. Failure behaviour

| Situation | Result |
|---|---|
| Stored image missing | `failed`, with "The stored image is missing." |
| AI service unreachable | Guzzle exception; job retries, then `failed` with the transport error. |
| PaddleOCR cannot decode the image | `500` from `/ocr`; job records `failed` with the reason. |
| Image readable but has no text | `empty`; no chunks are created. |
| Text longer than the cap | Truncated to `EXTRACTION_MAX_CHARACTERS`; document truncation is logged. The image job also caps at the same value. |
| Job retries exhausted | The last attempt's status remains `failed`; Laravel marks the job failed. |

An image with no text is a normal image. It is still stored and rendered; it
simply contributes no searchable chunks.

---

## 10. Limits and known trade-offs

- **PDF pages are not OCR'd.** Only standalone raster images are. A scanned PDF
  with no text layer still extracts to nothing.
- **Language is fixed per process.** `OCR_LANG` selects one recognition model.
  A page mixing scripts may be read better or worse depending on that choice.
- **No layout reconstruction.** OCR returns detected lines, not columns, tables
  or reading order for complex layouts.
- **Rotated text.** Document and line-orientation classifiers are disabled for
  latency. EXIF orientation is applied, but text rotated inside an upright image
  may be missed.
- **One OCR at a time per process.** The engine lock serialises calls. Uploads
  queue behind each other rather than running concurrent inference on one
  predictor.
- **Cold start.** The first call downloads models and can take minutes. Later
  calls are much faster.
- **Memory.** A very large image is decoded by Pillow and re-encoded as PNG
  before OCR. The upload caps bound this, but a 20 MB photo can still expand to
  a much larger PNG in memory and on disk temporarily.
- **Handwriting and unusual fonts** are not guaranteed. PaddleOCR is strongest
  on printed text.
- **Not part of the public app API.** The browser never calls `/ocr`; images are
  uploaded through Laravel, which proxies the bytes to the AI service.

---

## 11. Tests

Run the OCR-specific suites:

```bash
cd ai-service
python -m pytest tests/test_ocr.py tests/test_ocr_extraction.py -q

cd ../backend
php artisan test --filter='EntryImageTest|DocumentTextExtractionTest|ChunkingServiceTest'
```

What each suite covers:

| Suite | Covers |
|---|---|
| `ai-service/tests/test_ocr.py` | The `/ocr` boundary: multipart bytes reach the extractor with the right suffix, non-images are refused before OCR, empty uploads are rejected. PaddleOCR is stubbed, so this does not download a model. |
| `ai-service/tests/test_ocr_extraction.py` | `extract_text()` with a fake engine: line joining, all accepted result shapes, WebP-to-PNG conversion, non-image pass-through, temp file cleanup. |
| `backend/tests/Feature/EntryImageTest.php` | Upload authorization, queued OCR, `ready`/`empty` statuses, re-indexing, image linking and deletion. |
| `backend/tests/Feature/DocumentTextExtractionTest.php` | Image documents route through `OcrService`, including `ready`, `empty` and `failed` outcomes. |
| `backend/tests/Feature/ChunkingServiceTest.php` | `image:<id>` chunks are built only for `ready` images and do not collide with document chunks. |

These tests stub PaddleOCR, so they are fast and do not require the model. To
check the real engine:

```bash
docker compose up -d ai-service
curl -F "file=@receipt.png;type=image/png" http://localhost:8002/ocr
curl -s http://localhost:8002/health
```

The first request downloads the models. `ocr_loaded` becomes `true` afterwards.

---

## 12. Troubleshooting

| Symptom | Likely cause | Check |
|---|---|---|
| First OCR call times out | Models are still downloading, or the host is slow. | `docker compose logs -f ai-service`, look for Paddle model download messages. Retry; later calls use the cache. |
| Every OCR call returns `500` | Paddle dependencies are missing, usually OpenCV's `libGL`. | The Dockerfile installs `libgl1`, `libglib2.0-0` and `libgomp1`; check the container logs and the image build. |
| `ocr_loaded` stays `false` | Nothing has called `/ocr` yet, or the first call failed before loading. | Call `/ocr` directly and read the response/logs. |
| Text is empty for an image that clearly has text | Wrong `OCR_LANG`, very small text, low contrast, or rotated lines. | Try a larger crop, the correct language, or run the image through the model directly. |
| `415` from `/ocr` | The filename has no recognized image suffix. | Send `.png`, `.jpg`, `.jpeg`, `.gif`, `.webp`, `.bmp`, `.tif` or `.tiff`. |
| `413` from `/ocr` | The upload exceeds `OCR_MAX_IMAGE_BYTES`. | Raise the limit or shrink the image. Backend uploads have their own lower limits. |
| Models re-download after `docker compose down` | The cache is not on a persistent path. | Confirm `PADDLE_PDX_CACHE_HOME=/srv/app/models/paddlex` and that `./ai-service` is bind-mounted. |
| An image never becomes searchable | Status is `empty` or `failed`, or the entry was not re-indexed. | Inspect `entry_images.extraction_status` / `documents.extraction_status` and the queued jobs. |

---

## 13. Where the code lives

| Path | Role |
|---|---|
| `ai-service/app/api/routes/ocr.py` | FastAPI `/ocr` boundary: suffix/size/empty checks, response model. |
| `ai-service/app/ocr.py` | Lazy PaddleOCR engine, locking, Pillow normalisation, temp file, result parsing. |
| `ai-service/app/main.py` | Mounts the OCR router. |
| `ai-service/app/api/routes/health.py` | Reports `ocr_loaded`. |
| `ai-service/tests/test_ocr.py` | Endpoint boundary tests. |
| `ai-service/tests/test_ocr_extraction.py` | Extractor unit tests with a fake engine. |
| `backend/app/Services/AI/OcrService.php` | Multipart client and response validation. |
| `backend/app/Jobs/ExtractEntryImageText.php` | Queued OCR for inline images, status persistence, re-indexing. |
| `backend/app/Services/Extraction/TextExtractionService.php` | Routes image documents to OCR, records the result. |
| `backend/app/Jobs/ExtractDocumentText.php` | Queued extraction for document attachments. |
| `backend/app/Http/Controllers/EntryImageController.php` | Inline image upload and serving. |
| `backend/app/Services/ChunkingService.php` | Turns OCR text into `image:<id>` / `document:<id>` chunks. |
| `backend/database/migrations/2026_10_21_000000_add_ocr_fields_to_entry_images_table.php` | OCR columns on `entry_images`. |
| `docker-compose.yml` | `PADDLE_PDX_CACHE_HOME`, `OCR_LANG`, `OCR_MAX_IMAGE_BYTES`, OCR timeout. |
| `ai-service/Dockerfile` | Paddle/OpenCV runtime libraries and dependencies. |
