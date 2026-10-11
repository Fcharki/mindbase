# Attachment Text Extraction — Implementation Notes

Entries used to be the only searchable thing in a workspace. A PDF attached to
an entry was stored and downloadable, but its contents were invisible to search
and to the assistant. This adds a step that reads the text out of an attachment,
stores it, and feeds it into the same chunk-and-embed pipeline the entry body
already uses.

The point is that an answer can now be grounded in a document's text, not just
in the entry someone typed around it.

---

## 1. What it reads

| Format | Extractor | Notes |
|---|---|---|
| `.pdf` | `PdfTextExtractor` (`smalot/pdfparser`) | Text layer only. No OCR. |
| `.docx` | `DocxReader` (`ZipArchive` + DOM) | Body, headers, footers, footnotes; headings, lists, tables |
| `.xlsx` | `XlsxReader` | Worksheet names, cell coordinates, shared/inline strings, booleans, formulas, dates |
| `.pptx` | `PptxReader` | Slides in presentation order, titles, tables, charts |
| `.txt` `.md` `.csv` | `TextExtractionService` | Read straight from disk |
| `.doc` `.xls` `.ppt` | — | Legacy binary container, recorded `unsupported` |

A **scanned PDF with no text layer** extracts to an empty string and is recorded
as `empty`, not as a failure. There is no OCR in this pipeline; adding it would
be a separate concern with its own model and timeout budget.

## 2. Where it runs

```
upload ──► DocumentController@store ──► ExtractDocumentText (queued)
                                             │
                                             ▼
                                    TextExtractionService
                                             │
                    ┌────────────────────────┼───────────────────────┐
                    ▼                        ▼                       ▼
              PdfTextExtractor      OpenXmlTextExtractor      plain read
                                             │
                                             ▼
                     documents.extracted_text + extraction_status
                                             │
                                             ▼
                              IndexEntryChunks (re-queued)
                                             │
                                             ▼
                     ChunkingService adds source "document:<id>"
                                             │
                                             ▼
                        entry_chunks → hybrid search / RAG passages
```

Extraction is **queued**, not run inline. Parsing a PDF is not instant and an
upload should not hold a request open while it happens. The API returns `201`
with the document metadata immediately; the text appears on the row once the job
has run.

When extraction finishes, the job re-dispatches `IndexEntryChunks` for the entry
so the new text becomes part of retrieval. When a document is **deleted**,
`DocumentController@destroy` re-dispatches `IndexEntryChunks` as well, which
replaces the entry's chunks wholesale and so drops the removed document's text.

## 3. Storage

Text is stored on the document row rather than re-parsed on every reindex:

| Column | Purpose |
|---|---|
| `extracted_text` | The plain text, nullable |
| `extraction_status` | One of the statuses below |
| `extraction_error` | Why it failed or was refused, nullable |
| `extracted_at` | When the job last ran |

Re-parsing on every reindex would be wasteful: an entry is reindexed on each
edit, and `IndexEntryChunks` can run more than once as content and attachments
change. Reading a column is free, so parsing happens once, at upload.

### Statuses

| Status | Meaning |
|---|---|
| `pending` | Default. The job has not run yet. |
| `ready` | Text was read and is indexable. |
| `empty` | The file was read and had no text (blank file, scan, spare sheet). |
| `unsupported` | No extractor for the format (e.g. `.doc`). |
| `failed` | The file could not be read (missing on disk, corrupt archive). |

The difference between `empty` and `failed` is deliberate. A blank file and an
unreadable file are not the same problem, and the job records which happened so
the status is worth showing and logging.

## 4. Chunking

`ChunkingService::chunksFor()` now returns a third source alongside `summary`
and `content`:

- Every document with status `ready` and non-empty text contributes chunks.
- Their `source` is `document:<id>`, namespaced by document id so two documents
  on the same entry cannot collide on the unique `(knowledge_entry_id, source,
  position)` index.
- Positions start at `0` **per document**, the same rule each source already
  follows.
- Documents are processed in id order, so the chunk list is stable.

The entry-wide `MAX_CHUNKS` cap still applies. A pathological attachment cannot
balloon the index; the tail is dropped and the cap is logged, exactly as it
already was for a huge entry body.

Extracted text is run through the same `flatten()` and `split()` used for entry
content, so document chunks are cut on sentence boundaries and carry the same
300-character overlap as everything else.

## 5. Reading the office formats

`.docx`, `.xlsx` and `.pptx` are zip archives of XML, so they are walked
directly with `ZipArchive` rather than pulling in a word-processor or
spreadsheet library. `OpenXmlTextExtractor` is only a coordinator: it validates
the extension, opens the archive once under the limits below, and hands it to a
reader for the format (`DocxReader`, `XlsxReader`, `PptxReader`).

Each reader parses with the DOM and queries with XPath rather than replacing
tags in the raw string. That is what preserves structure:

- **docx** — the body, `headerN.xml`, `footerN.xml`, `footnotes.xml` and
  `endnotes.xml`. Paragraph and table children are walked in document order, so
  a table's paragraphs are not also emitted as body text. `HeadingN`/`Title`
  styles become markdown headings, `w:numPr` becomes a list item, `w:br`
  becomes a newline and `w:tab` a tab. Tables render as pipe-separated rows.
- **xlsx** — worksheet names come from `workbook.xml` and its `.rels`. Every
  cell is resolved by its real reference (`B3`), so a row keeps its column
  alignment even where cells in between are empty. Shared strings, inline
  strings, booleans, error literals and formulas (using the cached result) are
  all handled; a number whose style is a date is rendered as a date rather than
  a raw serial. Each worksheet is prefixed with its name and range.
- **pptx** — slide order comes from the relationships referenced by
  `presentation.xml`, not from sorting filenames. A slide's title (the shape
  with a title placeholder) is extracted on its own line, followed by body
  text, tables and any linked charts.

### Structured output

Readers return an `ExtractedDocument`: the format, the source filename, a list
of `ExtractedSection`s (each with a kind, its text and a location such as the
slide number, worksheet name or paragraph index) and a list of warnings.
`toText()` projects that down to the flat text the chunker consumes, keeping
section boundaries as blank lines. The sections and their locations are what
let a future change trace a chunk back to where in the file it came from.

Some of that location is also rendered into the text where it aids retrieval —
`Slide 3:`, `Worksheet: Data (A1:C3)`, `Header:` — because `entry_chunks` has no
per-location columns yet. That is the trade-off: the information survives into
retrieval today, at the cost of a small amount of prose in the indexed text.

### Hardening

- **Extensions are validated** before the archive is opened; anything outside
  `.docx`/`.xlsx`/`.pptx` raises.
- **Archive limits** (below) are checked against the archive index before any
  entry is inflated, so a zip bomb is rejected while still cheap to abandon.
- **Processing is budgeted.** docx paragraphs/tables, pptx slides and xlsx
  rows/cells are counted as they are read. Past the configured limit the reader
  stops and returns what it has, with a warning, instead of parsing the whole
  file only to be capped afterwards.
- **XML parsing is locked down**: `LIBXML_NONET`, no entity substitution
  (`LIBXML_NOENT` is never set), and an external entity loader that refuses
  everything. Office XML needs none of it.
- A part that cannot be read raises an `ExtractionException` with a readable
  message; `TextExtractionService` turns that into a `failed` result rather than
  letting the job die. Non-fatal gaps (a missing slide, a formula with no cached
  result) become warnings instead, so partial content is never silently returned
  as if it were whole.

## 6. Configuration

| Env var | Default | Purpose |
|---|---|---|
| `EXTRACTION_MAX_CHARACTERS` | `200000` | Cap on text kept per document. `0` disables it. |
| `EXTRACTION_MAX_FILE_BYTES` | `52428800` | Archive size on disk. `0` disables it. |
| `EXTRACTION_MAX_ENTRIES` | `5000` | Parts an archive may contain. `0` disables it. |
| `EXTRACTION_MAX_UNCOMPRESSED_BYTES` | `209715200` | Total uncompressed size of all parts. `0` disables it. |
| `EXTRACTION_MAX_PART_BYTES` | `52428800` | Uncompressed size of any single part. `0` disables it. |
| `EXTRACTION_MAX_PARAGRAPHS` | `200000` | docx paragraphs and tables read before truncating. `0` disables it. |
| `EXTRACTION_MAX_SLIDES` | `5000` | pptx slides read before truncating. `0` disables it. |
| `EXTRACTION_MAX_ROWS` | `200000` | xlsx rows read across all worksheets. `0` disables it. |
| `EXTRACTION_MAX_CELLS` | `2000000` | xlsx cells read across all worksheets. `0` disables it. |

`config/extraction.php` holds the character cap and a `limits` block. The
character cap exists because retrieval is per workspace: without it a long PDF
would be embedded in full and could dominate every search in that workspace. The
archive limits exist because an office file is a zip archive and a hostile one
can be a zip bomb. The processing limits exist because the archive limits bound
a file's size but not its contents: a legitimately-sized spreadsheet can still
hold a million rows, so the readers spend a budget as they walk and stop, with a
warning, rather than parsing everything only to be capped afterwards.

## 7. Requirements

- **`smalot/pdfparser`** (`composer.json`) — pure PHP, no system dependency.
- **ext-zip** — added to `backend/Dockerfile` (`libzip-dev` +
  `docker-php-ext-install zip`). Needed for the office formats; PDF and text
  extraction work without it. `OpenXmlTextExtractor` fails cleanly with a
  clear message if the extension is missing.

Because the backend bind-mounts `./backend`, the container uses the host's
`vendor/`; installing the parser on the host (`composer require`) is enough for
the running container. The extension is compiled into the image, so a Dockerfile
change needs an image rebuild:

```bash
docker compose build backend && docker compose up -d backend
```

## 8. API surface

`DocumentResource` now includes `extraction_status`, so the attachments panel can
show whether a document's text is searchable:

| Value | UI label |
|---|---|
| `pending` | Extracting text… |
| `ready` | Text searchable |
| `empty` | No text found |
| `unsupported` | Format not searchable |
| `failed` | Text could not be read |

## 9. Limits

- **No OCR.** Image-only PDFs and scanned documents extract to nothing. They are
  stored and downloadable, but not searchable.
- **No legacy office formats.** `.doc`, `.xls` and `.ppt` are recorded
  `unsupported`.
- **Structure, not layout.** Headings, lists, tables and spreadsheet rows keep
  their structure: table cells stay in their row, and a spreadsheet row keeps
  its column alignment, so rows stay under their headers. What is not kept is
  visual layout (fonts, colours, merged-cell spans) or PDF page numbers, so a
  citation points at the entry and its passages rather than at a page.
- **Text is capped** at `EXTRACTION_MAX_CHARACTERS`; archives are bounded by the
  archive limits and readers by the processing limits, so a very large file is
  truncated with a warning rather than parsed in full.

## 10. Tests

```bash
cd backend && php artisan test --filter="Extraction|Chunking"
```

- `DocumentTextExtractionTest` — queues on upload; extracts plain text and a real
  PDF fixture; records `unsupported`, `empty` and `failed`; reindexes the entry.
- `OpenXmlTextExtractorTest` — docx structure (headings, lists, tables, headers,
  footnotes), pptx presentation order and separate titles, xlsx shared strings
  with alignment, date serials and a formula-without-cached-result warning; plus
  a malformed document, an empty document, a non-archive, an archive that
  breaches the expansion limit, and each reader truncating (with a warning) when
  a processing limit is exceeded. Archives are built as real zips in the test.
- `ChunkingServiceTest` — attachment text is indexed under its own source,
  positions restart per document, and a document without text contributes
  nothing.

The PDF fixture lives at `tests/Fixtures/documents/sample.pdf` and is a minimal
single-page PDF with a text layer.

---

By the end of this step, an attachment's text is as searchable as the entry it
is attached to: it is read once, stored, chunked like everything else, and shows
up in both keyword/vector retrieval and the passages the assistant answers from.
