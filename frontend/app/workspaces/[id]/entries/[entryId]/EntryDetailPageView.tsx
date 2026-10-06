"use client";

import axios from "axios";
import Link from "next/link";
import { notFound, useRouter } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import { CommentsPanel } from "@/app/features/knowledge/components/CommentsPanel";
import { DocumentPanel } from "@/app/features/knowledge/components/DocumentPanel";
import { EntryForm } from "@/app/features/knowledge/components/EntryForm";
import {
  deleteEntry,
  getEntry,
  KnowledgeEntry,
  summarizeEntry,
  tagEntry,
  updateEntry,
} from "@/lib/entries";
import { getWorkspace, Workspace } from "@/lib/workspaces";
import { permissionsFor } from "@/lib/permissions";

type Status = "loading" | "ready" | "missing";

export function EntryDetailPageView({
  workspaceId,
  entryId,
  highlightCommentId,
  highlightReplyId,
}: {
  workspaceId: string;
  entryId: string;
  // Set when the page was opened from a notification, naming the comment or
  // reply the discussion panel should scroll to.
  highlightCommentId?: string;
  highlightReplyId?: string;
}) {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();

  const [workspace, setWorkspace] = useState<Workspace | null>(null);
  const [entry, setEntry] = useState<KnowledgeEntry | null>(null);
  const [status, setStatus] = useState<Status>("loading");
  const [editing, setEditing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [tagInput, setTagInput] = useState("");
  const [tagError, setTagError] = useState<string | null>(null);
  const [tagSaving, setTagSaving] = useState(false);
  const [summary, setSummary] = useState<string | null>(null);
  const [summarizing, setSummarizing] = useState(false);
  const [summaryError, setSummaryError] = useState<string | null>(null);

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

  async function syncTags(tags: string[]) {
    setTagSaving(true);
    setTagError(null);

    try {
      const saved = await tagEntry(workspaceId, entryId, tags);
      setEntry((current) =>
        current ? { ...current, tags: saved, tags_count: saved.length } : current,
      );
    } catch {
      setTagError("Could not save tags.");
    } finally {
      setTagSaving(false);
    }
  }

  async function handleAddTag(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const currentTags = (entry?.tags ?? []).map((tag) => tag.name);
    const additions = tagInput
      .split(",")
      .map((tag) => tag.trim().toLowerCase())
      .filter((tag) => tag !== "" && !currentTags.includes(tag));

    if (additions.length === 0) {
      setTagInput("");
      return;
    }

    await syncTags([...currentTags, ...additions]);
    setTagInput("");
  }

  async function handleRemoveTag(tagName: string) {
    const next = (entry?.tags ?? [])
      .map((tag) => tag.name)
      .filter((name) => name !== tagName);

    await syncTags(next);
  }

  async function handleSummarize() {
    setSummarizing(true);
    setSummaryError(null);

    try {
      const result = await summarizeEntry(workspaceId, entryId);
      setSummary(result);
    } catch {
      setSummaryError(
        "Could not summarize this entry. The AI service may be starting up – please try again in a moment.",
      );
    } finally {
      setSummarizing(false);
    }
  }

  if (status === "missing") {
    notFound();
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
  const permissions = permissionsFor(workspace.role, workspace.is_member);

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

              {permissions.canContribute && (
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
              )}
            </div>

            <section className="rounded-2xl border border-[#DDDCE3] bg-white shadow-sm">
              <div className="flex items-start justify-between gap-4 border-b border-[#DDDCE3] px-7 py-5 sm:px-8">
                <div>
                  <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
                    {workspace.name}
                  </p>

                  <h2 className="mt-1 text-xl font-semibold tracking-tight">
                    Content
                  </h2>
                </div>

                <button
                  onClick={handleSummarize}
                  disabled={summarizing || !entry.content}
                  className="shrink-0 rounded-lg border border-[#5146E5] px-4 py-2 text-sm font-medium text-[#5146E5] transition hover:bg-[#EEEDFF] disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {summarizing ? "Summarizing…" : "Summarize"}
                </button>
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

              {summary && (
                <div className="border-t border-[#DDDCE3] px-7 py-6 sm:px-8">
                  <p className="text-xs font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
                    AI Summary
                  </p>

                  <p className="mt-2 text-sm leading-relaxed text-[#20202A]">
                    {summary}
                  </p>
                </div>
              )}

              {summaryError && (
                <p
                  role="alert"
                  className="mx-7 mb-6 rounded-lg border border-[#F2C9D1] bg-[#FCECEF] px-4 py-3 text-sm leading-relaxed text-[#B13D55] sm:mx-8"
                >
                  {summaryError}
                </p>
              )}
            </section>

            <section className="rounded-2xl border border-[#DDDCE3] bg-white shadow-sm">
              <div className="border-b border-[#DDDCE3] px-7 py-5 sm:px-8">
                <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
                  Organize
                </p>

                <h2 className="mt-1 text-xl font-semibold tracking-tight">
                  Tags
                </h2>
              </div>

              <div className="px-7 py-7 sm:px-8">
                <div className="flex flex-wrap gap-2">
                  {(entry.tags ?? []).map((tag) => (
                    <span
                      key={tag.id}
                      className="inline-flex items-center gap-1.5 rounded-full bg-[#EEEDFF] px-3 py-1 text-sm font-medium text-[#4338CA]"
                    >
                      {tag.name}

                      {permissions.canContribute && (
                        <button
                          type="button"
                          onClick={() => handleRemoveTag(tag.name)}
                          disabled={tagSaving}
                          aria-label={`Remove tag ${tag.name}`}
                          className="text-[#4338CA] opacity-60 transition hover:opacity-100 disabled:opacity-40"
                        >
                          ×
                        </button>
                      )}
                    </span>
                  ))}

                  {(entry.tags ?? []).length === 0 && (
                    <span className="text-sm text-[#9897A1]">
                      No tags yet.
                    </span>
                  )}
                </div>

                {tagError && (
                  <p
                    role="alert"
                    className="mt-4 rounded-lg border border-[#F2C9D1] bg-[#FCECEF] px-4 py-3 text-sm leading-relaxed text-[#B13D55]"
                  >
                    {tagError}
                  </p>
                )}

                {permissions.canContribute && (
                  <form
                    onSubmit={handleAddTag}
                    className="mt-5 flex max-w-md flex-col gap-2 sm:flex-row"
                  >
                    <input
                      type="text"
                      placeholder="Add a tag, e.g. research, ideas"
                      value={tagInput}
                      onChange={(event) => setTagInput(event.target.value)}
                      disabled={tagSaving}
                      className="w-full rounded-lg border border-[#DDDCE3] bg-white px-4 py-2.5 text-sm text-[#20202A] outline-none transition placeholder:text-[#A3A2AC] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF]"
                    />

                    <button
                      type="submit"
                      disabled={tagSaving}
                      className="rounded-lg bg-[#5146E5] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      {tagSaving ? "Saving…" : "Add"}
                    </button>
                  </form>
                )}
              </div>
            </section>

            {/* Entry text is readable by any signed-in user, so a non-member can
                land here. Say why the panels are closed instead of letting both
                requests fail with a bare 403. */}
            {permissions.isMember ? (
              <>
                <DocumentPanel
                  workspaceId={workspaceId}
                  entryId={entryId}
                  canUpload={permissions.canContribute}
                  canDownload={permissions.canDownload}
                />

                <CommentsPanel
                  workspaceId={workspaceId}
                  entryId={entryId}
                  canPost={permissions.canDiscuss}
                  isOwner={permissions.isOwner}
                  highlightCommentId={highlightCommentId}
                  highlightReplyId={highlightReplyId}
                />
              </>
            ) : (
              <section className="rounded-2xl border border-[#E8E7EC] bg-white px-6 py-8">
                <h2 className="text-lg font-semibold tracking-tight text-[#20202A]">
                  Documents and discussion are private
                </h2>
                <p className="mt-2 max-w-prose text-sm leading-relaxed text-[#686873]">
                  You are not a member of this workspace, so its documents and
                  discussions are closed to you. You can still read this entry.
                </p>
                <p className="mt-2 max-w-prose text-sm leading-relaxed text-[#686873]">
                  Ask a member or the owner to add you if you need access.
                </p>
              </section>
            )}
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