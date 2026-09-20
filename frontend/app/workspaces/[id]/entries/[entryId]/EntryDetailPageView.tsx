"use client";

import axios from "axios";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import { EntryForm } from "@/app/features/knowledge/components/EntryForm";
import { deleteEntry, getEntry, KnowledgeEntry, updateEntry } from "@/lib/entries";
import { getWorkspace, Workspace } from "@/lib/workspaces";

type Status = "loading" | "ready" | "missing";

export function EntryDetailPageView({
  workspaceId,
  entryId,
}: {
  workspaceId: string;
  entryId: string;
}) {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();

  const [workspace, setWorkspace] = useState<Workspace | null>(null);
  const [entry, setEntry] = useState<KnowledgeEntry | null>(null);
  const [status, setStatus] = useState<Status>("loading");
  const [editing, setEditing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState(false);

  useEffect(() => {
    if (authLoading) {
      return;
    }

    if (!user) {
      router.replace("/auth/login");
      return;
    }

    let cancelled = false;

    Promise.all([getWorkspace(workspaceId), getEntry(workspaceId, entryId)])
      .then(([workspaceResult, entryResult]) => {
        if (cancelled) {
          return;
        }

        setWorkspace(workspaceResult);
        setEntry(entryResult);
        setStatus("ready");
      })
      .catch((requestError: unknown) => {
        if (cancelled) {
          return;
        }

        if (
          axios.isAxiosError(requestError) &&
          (requestError.response?.status === 403 ||
            requestError.response?.status === 404)
        ) {
          setStatus("missing");
          return;
        }

        setStatus("loading");
      });

    return () => {
      cancelled = true;
    };
  }, [workspaceId, entryId, user, authLoading, router]);

  async function handleSave(title: string, content: string) {
    const updated = await updateEntry(workspaceId, entryId, title, content);
    setEntry(updated);
    setEditing(false);
  }

  async function handleDelete() {
    if (!entry) {
      return;
    }

    const confirmed = window.confirm(
      `Delete "${entry.title}"? This cannot be undone.`,
    );

    if (!confirmed) {
      return;
    }

    setDeleting(true);
    setError(null);

    try {
      await deleteEntry(workspaceId, entryId);
      router.replace(`/workspaces/${workspaceId}/entries`);
      router.refresh();
    } catch {
      setDeleting(false);
      setError("Could not delete this entry. Please try again.");
    }
  }

  if (status === "loading" || !workspace || !entry) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#F7F6F2] font-sans">
        <div className="flex items-center gap-3 text-sm text-[#686873]">
          <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
          Loading entry…
        </div>
      </main>
    );
  }

  const updatedAt = new Date(entry.updated_at ?? entry.created_at);
  const author = entry.creator?.name ?? user?.name ?? "you";

  return (
    <div className="flex min-h-screen flex-col bg-[#F7F6F2] font-sans text-[#20202A]">
      <WorkspaceNav />

      <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-6 py-10 sm:px-10 sm:py-12">
        <Link
          href={`/workspaces/${workspace.id}/entries`}
          className="inline-flex items-center gap-1.5 text-sm font-medium text-[#686873] transition hover:text-[#20202A]"
        >
          <svg
            className="h-4 w-4"
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
          >
            <path
              d="M15 6l-6 6 6 6"
              stroke="currentColor"
              strokeWidth="1.7"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </svg>
          Entries
        </Link>

        {!editing && (
          <>
            <div className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
              <div className="min-w-0">
                <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
                  Entry
                </p>

                <h1 className="mt-1 text-3xl font-semibold tracking-[-0.03em]">
                  {entry.title}
                </h1>

                <p className="mt-2 text-sm leading-relaxed text-[#686873]">
                  Written by {author}
                  {entry.updated_at
                    ? ` · Updated ${updatedAt.toLocaleDateString("en-US", {
                        month: "short",
                        day: "numeric",
                        year: "numeric",
                      })}`
                    : ""}
                </p>
              </div>

              <div className="flex shrink-0 items-center gap-3">
                <button
                  onClick={() => {
                    setError(null);
                    setEditing(true);
                  }}
                  className="rounded-lg border border-[#DDDCE3] bg-white px-5 py-3 text-sm font-medium text-[#686873] transition hover:border-[#C9C7D0] hover:bg-[#F7F6F2] hover:text-[#20202A]"
                >
                  Edit
                </button>

                <button
                  onClick={handleDelete}
                  disabled={deleting}
                  className="rounded-lg border border-[#E85D75] px-5 py-3 text-sm font-medium text-[#B13D55] transition hover:bg-[#E85D75] hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {deleting ? "Deleting…" : "Delete"}
                </button>
              </div>
            </div>

            <section className="rounded-2xl border border-[#DDDCE3] bg-white shadow-sm">
              <div className="border-b border-[#DDDCE3] px-7 py-5 sm:px-8">
                <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
                  {workspace.name}
                </p>

                <h2 className="mt-1 text-xl font-semibold tracking-tight">
                  Content
                </h2>
              </div>

              {entry.content ? (
                <div
                  className="prose-content px-7 py-7 sm:px-8"
                  dangerouslySetInnerHTML={{ __html: entry.content }}
                />
              ) : (
                <p className="px-7 py-7 text-sm leading-relaxed text-[#9897A1] sm:px-8">
                  This entry has no content yet.
                </p>
              )}
            </section>
          </>
        )}

        {editing && (
          <EntryForm
            title="Title"
            heading="Edit entry"
            eyebrow={workspace.name}
            initialTitle={entry.title}
            initialContent={entry.content ?? ""}
            submitLabel="Save changes"
            submittingLabel="Saving…"
            error={error}
            onError={setError}
            onSubmit={handleSave}
            onCancel={() => setEditing(false)}
            cancelLabel="Cancel"
          />
        )}
      </main>
    </div>
  );
}