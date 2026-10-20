"use client";

import { useEffect, useRef, useState, type ChangeEvent } from "react";
import { toast } from "sonner";

import {
  deleteDocument,
  downloadDocument,
  fetchDocumentPreviewUrl,
  KnowledgeDocument,
  listDocuments,
  uploadDocument,
} from "@/lib/documents";

const EXTRACTION_LABELS: Record<
  KnowledgeDocument["extraction_status"],
  string
> = {
  pending: "Extracting text…",
  ready: "Text searchable",
  empty: "No text found",
  unsupported: "Format not searchable",
  failed: "Text could not be read",
};

// Only formats a browser can render inline are offered a preview button. The
// backend serves any stored file, but an <iframe> pointed at a .docx shows a
// download prompt, which is what the Download button already does.
function previewKind(
  mimeType: string | null,
): "image" | "pdf" | "text" | null {
  if (!mimeType) {
    return null;
  }

  if (mimeType.startsWith("image/")) {
    return "image";
  }

  if (mimeType === "application/pdf") {
    return "pdf";
  }

  if (mimeType.startsWith("text/")) {
    return "text";
  }

  return null;
}

interface DocumentPanelProps {
  workspaceId: string;
  entryId: string;
  canUpload: boolean;
  // Uploading and downloading are separate capabilities: a viewer sees which
  // documents exist but cannot take the bytes behind them.
  canDownload: boolean;
}

export function DocumentPanel({
  workspaceId,
  entryId,
  canUpload,
  canDownload,
}: DocumentPanelProps) {
  const inputRef = useRef<HTMLInputElement>(null);

  const [documents, setDocuments] = useState<KnowledgeDocument[] | null>(
    null,
  );
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [preview, setPreview] = useState<{
    document: KnowledgeDocument;
    url: string;
    kind: "image" | "pdf" | "text";
  } | null>(null);

  // Mirrors preview.url for the unmount cleanup, which must not read state.
  const previewUrlRef = useRef<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    listDocuments(workspaceId, entryId)
      .then((result) => {
        if (!cancelled) {
          setDocuments(result);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setError("Could not load documents.");
        }
      });

    return () => {
      cancelled = true;
    };
  }, [workspaceId, entryId]);

  async function handleUpload(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];

    // Let the same file be re-selected after a failed attempt.
    event.target.value = "";

    if (!file) {
      return;
    }

    setUploading(true);
    setError(null);
    setProgress(0);

    try {
      const created = await uploadDocument(
        workspaceId,
        entryId,
        file,
        setProgress,
      );

      setDocuments((current) => [created, ...(current ?? [])]);
    } catch {
      setError("Upload failed. Check the file type and size, then retry.");
    } finally {
      setUploading(false);
    }
  }

  async function handleDownload(document: KnowledgeDocument) {
    setBusyId(document.id);
    setError(null);

    try {
      await downloadDocument(workspaceId, entryId, document);
    } catch {
      setError(`Could not download "${document.original_name}".`);
    } finally {
      setBusyId(null);
    }
  }

  async function handleDelete(document: KnowledgeDocument) {
    const confirmed = window.confirm(
      `Delete "${document.original_name}"? This cannot be undone.`,
    );

    if (!confirmed) {
      return;
    }

    setBusyId(document.id);
    setError(null);

    try {
      await deleteDocument(workspaceId, entryId, document.id);
      setDocuments((current) =>
        (current ?? []).filter((item) => item.id !== document.id),
      );
    } catch {
      setError(`Could not delete "${document.original_name}".`);
    } finally {
      setBusyId(null);
    }
  }

  async function handlePreview(document: KnowledgeDocument) {
    const kind = previewKind(document.mime_type);

    if (!kind) {
      return;
    }

    setBusyId(document.id);
    setError(null);

    try {
      const url = await fetchDocumentPreviewUrl(workspaceId, entryId, document);
      previewUrlRef.current = url;
      setPreview({ document, url, kind });
    } catch {
      toast.error(`Could not preview "${document.original_name}".`);
    } finally {
      setBusyId(null);
    }
  }

  function closePreview() {
    if (previewUrlRef.current) {
      // The object URL owns the fetched bytes; without this the blob stays
      // alive for the life of the document.
      URL.revokeObjectURL(previewUrlRef.current);
      previewUrlRef.current = null;
    }

    setPreview(null);
  }

  // A preview left open when the panel unmounts would leak its object URL.
  useEffect(() => {
    return () => {
      if (previewUrlRef.current) {
        URL.revokeObjectURL(previewUrlRef.current);
      }
    };
  }, []);

  return (
    <section className="rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-4 border-b border-[#D7D5DE] dark:border-[#34343c] px-7 py-5 sm:px-8">
        <div>
          <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
            Attachments
          </p>

          <h2 className="mt-1 text-xl font-semibold tracking-tight">
            Documents
          </h2>
        </div>

        {canUpload && (
          <>
            <input
              ref={inputRef}
              type="file"
              onChange={handleUpload}
              disabled={uploading}
              className="hidden"
              accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.md,.csv"
            />

            <button
              type="button"
              onClick={() => inputRef.current?.click()}
              disabled={uploading}
              className="shrink-0 rounded-lg border border-[#5146E5] px-4 py-2 text-sm font-medium text-[#5146E5] transition hover:bg-[#EEEDFF] dark:hover:bg-[#2a2748] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {uploading ? `Uploading ${progress}%` : "Upload file"}
            </button>
          </>
        )}
      </div>

      <div className="px-7 py-7 sm:px-8">
        {documents === null && (
          <span className="text-sm text-[#9897A1] dark:text-[#8b8b94]">Loading documents…</span>
        )}

        {documents !== null && documents.length === 0 && (
          <span className="text-sm text-[#9897A1] dark:text-[#8b8b94]">
            No documents attached yet.
          </span>
        )}

        {documents !== null && documents.length > 0 && (
          <ul className="flex flex-col gap-2">
            {documents.map((document) => {
              const busy = busyId === document.id;

              return (
                <li
                  key={document.id}
                  className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[#E8E7EC] px-4 py-3"
                >
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-[#20202A] dark:text-[#ececf1]">
                      {document.original_name}
                    </p>

                    <p className="mt-0.5 text-xs text-[#9897A1] dark:text-[#8b8b94]">
                      {document.human_size}
                      {document.uploader
                        ? ` · ${document.uploader.name}`
                        : ""}
                    </p>

                    {document.extraction_status && (
                      <p
                        className={`mt-0.5 text-xs ${
                          document.extraction_status === "ready"
                            ? "text-[#3B9C6B]"
                            : "text-[#9897A1] dark:text-[#8b8b94]"
                        }`}
                      >
                        {EXTRACTION_LABELS[document.extraction_status]}
                      </p>
                    )}
                  </div>

                  <div className="flex shrink-0 items-center gap-2">
                    {canDownload && previewKind(document.mime_type) && (
                      <button
                        type="button"
                        onClick={() => handlePreview(document)}
                        disabled={busy}
                        className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] px-3 py-1.5 text-sm font-medium text-[#686873] dark:text-[#b0b0b9] transition hover:bg-[#F2F0EA] dark:hover:bg-[#24242a] hover:text-[#20202A] disabled:cursor-not-allowed disabled:opacity-50"
                      >
                        Preview
                      </button>
                    )}

                    {canDownload ? (
                      <button
                        type="button"
                        onClick={() => handleDownload(document)}
                        disabled={busy}
                        className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] px-3 py-1.5 text-sm font-medium text-[#686873] dark:text-[#b0b0b9] transition hover:bg-[#F2F0EA] dark:hover:bg-[#24242a] hover:text-[#20202A] disabled:cursor-not-allowed disabled:opacity-50"
                      >
                        {busy ? "Working…" : "Download"}
                      </button>
                    ) : (
                      <span className="text-xs text-[#9897A1] dark:text-[#8b8b94]">
                        Download not available
                      </span>
                    )}

                    {canUpload && (
                      <button
                        type="button"
                        onClick={() => handleDelete(document)}
                        disabled={busy}
                        className="rounded-lg border border-[#E85D75] dark:border-[#f08098] px-3 py-1.5 text-sm font-medium text-[#B13D55] dark:text-[#f0a0b0] transition hover:bg-[#E85D75] hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                      >
                        Delete
                      </button>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        )}

        {error && (
          <p
            role="alert"
            className="mt-4 rounded-lg border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-4 py-3 text-sm leading-relaxed text-[#B13D55] dark:text-[#f0a0b0]"
          >
            {error}
          </p>
        )}
      </div>

      {preview && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label={`Preview of ${preview.document.original_name}`}
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
          onClick={closePreview}
        >
          <div
            className="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl dark:bg-[#1b1b1f]"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="flex items-center justify-between gap-4 border-b border-[#D7D5DE] px-5 py-3 dark:border-[#34343c]">
              <p className="truncate text-sm font-medium text-[#20202A] dark:text-[#ececf1]">
                {preview.document.original_name}
              </p>

              <button
                type="button"
                onClick={closePreview}
                className="rounded-lg border border-[#D7D5DE] px-3 py-1.5 text-sm font-medium text-[#686873] transition hover:bg-[#F2F0EA] hover:text-[#20202A] dark:border-[#34343c] dark:text-[#b0b0b9] dark:hover:bg-[#24242a]"
              >
                Close
              </button>
            </div>

            <div className="min-h-0 flex-1 overflow-auto bg-[#F2F0EA] dark:bg-[#121214]">
              {preview.kind === "image" ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={preview.url}
                  alt={preview.document.original_name}
                  className="mx-auto max-h-[80vh] object-contain"
                />
              ) : (
                <iframe
                  src={preview.url}
                  title={preview.document.original_name}
                  className="h-[80vh] w-full"
                />
              )}
            </div>
          </div>
        </div>
      )}
    </section>
  );
}
