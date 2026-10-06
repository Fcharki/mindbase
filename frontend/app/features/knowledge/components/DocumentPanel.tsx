"use client";

import { useEffect, useRef, useState, type ChangeEvent } from "react";

import {
  deleteDocument,
  downloadDocument,
  KnowledgeDocument,
  listDocuments,
  uploadDocument,
} from "@/lib/documents";

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

  return (
    <section className="rounded-2xl border border-[#DDDCE3] bg-white shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-4 border-b border-[#DDDCE3] px-7 py-5 sm:px-8">
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
              className="shrink-0 rounded-lg border border-[#5146E5] px-4 py-2 text-sm font-medium text-[#5146E5] transition hover:bg-[#EEEDFF] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {uploading ? `Uploading ${progress}%` : "Upload file"}
            </button>
          </>
        )}
      </div>

      <div className="px-7 py-7 sm:px-8">
        {documents === null && (
          <span className="text-sm text-[#9897A1]">Loading documents…</span>
        )}

        {documents !== null && documents.length === 0 && (
          <span className="text-sm text-[#9897A1]">
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
                    <p className="truncate text-sm font-medium text-[#20202A]">
                      {document.original_name}
                    </p>

                    <p className="mt-0.5 text-xs text-[#9897A1]">
                      {document.human_size}
                      {document.uploader
                        ? ` · ${document.uploader.name}`
                        : ""}
                    </p>
                  </div>

                  <div className="flex shrink-0 items-center gap-2">
                    {canDownload ? (
                      <button
                        type="button"
                        onClick={() => handleDownload(document)}
                        disabled={busy}
                        className="rounded-lg border border-[#DDDCE3] px-3 py-1.5 text-sm font-medium text-[#686873] transition hover:bg-[#F7F6F2] hover:text-[#20202A] disabled:cursor-not-allowed disabled:opacity-50"
                      >
                        {busy ? "Working…" : "Download"}
                      </button>
                    ) : (
                      <span className="text-xs text-[#9897A1]">
                        Download not available
                      </span>
                    )}

                    {canUpload && (
                      <button
                        type="button"
                        onClick={() => handleDelete(document)}
                        disabled={busy}
                        className="rounded-lg border border-[#E85D75] px-3 py-1.5 text-sm font-medium text-[#B13D55] transition hover:bg-[#E85D75] hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
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
            className="mt-4 rounded-lg border border-[#F2C9D1] bg-[#FCECEF] px-4 py-3 text-sm leading-relaxed text-[#B13D55]"
          >
            {error}
          </p>
        )}
      </div>
    </section>
  );
}
