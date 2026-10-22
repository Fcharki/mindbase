"use client";

import axios from "axios";
import Link from "next/link";
import { notFound, useRouter } from "next/navigation";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";

import { useAuth } from "@/app/Providers/AuthProvider";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import { CommentsPanel } from "@/app/features/knowledge/components/CommentsPanel";
import { DocumentPanel } from "@/app/features/knowledge/components/DocumentPanel";
import { EntryForm } from "@/app/features/knowledge/components/EntryForm";
import {
  deleteEntry,
  exportEntry,
  ExportFormat,
  getEntry,
  KnowledgeEntry,
  pinEntry,
  suggestEntryTags,
  summarizeEntry,
  tagEntry,
  updateEntry,
} from "@/lib/entries";
import { addRecentViewed } from "@/lib/recent";
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
  const [pinSaving, setPinSaving] = useState(false);
  const [suggestions, setSuggestions] = useState<string[]>([]);
  const [suggesting, setSuggesting] = useState(false);
  const [exportMenuOpen, setExportMenuOpen] = useState(false);
  const exportMenuRef = useRef<HTMLDivElement>(null);

  // Close the export menu on an outside click or Escape, the same way the
  // notification panel does. Without this it stayed open until Export was
  // pressed again, so the next stray click landed on a format item.
  useEffect(() => {
    if (!exportMenuOpen) {
      return;
    }

    function handlePointerDown(event: MouseEvent) {
      if (!exportMenuRef.current?.contains(event.target as Node)) {
        setExportMenuOpen(false);
      }
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") {
        setExportMenuOpen(false);
      }
    }

    document.addEventListener("mousedown", handlePointerDown);
    document.addEventListener("keydown", handleKeyDown);

    return () => {
      document.removeEventListener("mousedown", handlePointerDown);
      document.removeEventListener("keydown", handleKeyDown);
    };
  }, [exportMenuOpen]);

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

  // Recorded once the entry is known to be readable, so a 404 does not leave a
  // dead id in the recent list.
  useEffect(() => {
    if (entry) {
      addRecentViewed(entry.id);
    }
  }, [entry]);

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

  async function handleTogglePin() {
    if (!entry || pinSaving) {
      return;
    }

    setPinSaving(true);

    try {
      const updated = await pinEntry(workspaceId, entry.id, !entry.is_pinned);
      setEntry(updated);
      toast.success(updated.is_pinned ? "Entry pinned." : "Entry unpinned.");
    } catch {
      toast.error("Could not update the pin.");
    } finally {
      setPinSaving(false);
    }
  }

  async function handleExport(format: ExportFormat = "md") {
    if (!entry) {
      return;
    }

    // Choosing a format consumes the menu, so a later click cannot re-trigger
    // an export from a menu that is no longer meant to be open.
    setExportMenuOpen(false);

    try {
      await exportEntry(workspaceId, entry, format);
      toast.success("Entry exported.");
    } catch {
      toast.error("Could not export this entry.");
    }
  }

  async function handleSuggestTags() {
    if (!entry || suggesting) {
      return;
    }

    setSuggesting(true);

    try {
      const names = await suggestEntryTags(workspaceId, entry.id);
      const current = new Set((entry.tags ?? []).map((tag) => tag.name));

      const fresh = names.filter((name) => !current.has(name));

      setSuggestions(fresh);

      if (fresh.length === 0) {
        toast.message("No new tag suggestions for this entry.");
      }
    } catch {
      toast.error("Could not fetch tag suggestions.");
    } finally {
      setSuggesting(false);
    }
  }

  async function handleAcceptSuggestions() {
    if (suggestions.length === 0) {
      return;
    }

    const currentTags = (entry?.tags ?? []).map((tag) => tag.name);

    await syncTags([...currentTags, ...suggestions]);
    setSuggestions([]);
    toast.success("Tags added.");
  }

  if (status === "missing") {
    notFound();
  }

  if (status === "loading" || !workspace || !entry) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#F2F0EA] dark:bg-[#121214] font-sans">
        <div className="flex items-center gap-3 text-sm text-[#686873] dark:text-[#b0b0b9]">
          <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
          Loading entry…
        </div>
      </main>
    );
  }

  const updatedAt = new Date(entry.updated_at ?? entry.created_at);
  const author = entry.creator?.username ?? user?.username ?? "you";
  const permissions = permissionsFor(workspace.role, workspace.is_member);

  return (
    <div className="flex min-h-screen flex-col bg-[#F2F0EA] dark:bg-[#121214] font-sans text-[#20202A] dark:text-[#ececf1]">
      <WorkspaceNav />

      <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-6 py-10 sm:px-10 sm:py-12">
        <Link
          href={`/workspaces/${workspace.id}/entries`}
          className="inline-flex items-center gap-1.5 text-sm font-medium text-[#686873] dark:text-[#b0b0b9] transition hover:text-[#20202A]"
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

                <p className="mt-2 text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
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
                    onClick={handleTogglePin}
                    disabled={pinSaving}
                    className={`rounded-lg border px-5 py-3 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50 ${
                      entry.is_pinned
                        ? "border-[#5146E5] bg-[#EEEDFF] text-[#4338CA] dark:bg-[#2b2b5e] dark:text-[#a5b4fc]"
                        : "border-[#D7D5DE] bg-white text-[#686873] hover:border-[#C9C7D0] dark:hover:border-[#474751] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a] hover:text-[#20202A] dark:border-[#34343c] dark:bg-[#1b1b1f] dark:text-[#b0b0b9]"
                    }`}
                  >
                    {entry.is_pinned ? "Pinned" : "Pin"}
                  </button>

                  {permissions.isMember && (
                    <div
                      className="relative"
                      ref={exportMenuRef}
                      onClick={(event) => event.stopPropagation()}
                    >
                      <button
                        onClick={() => setExportMenuOpen((open) => !open)}
                        aria-haspopup="menu"
                        aria-expanded={exportMenuOpen}
                        className="rounded-lg border border-[#D7D5DE] bg-white px-5 py-3 text-sm font-medium text-[#686873] transition hover:border-[#C9C7D0] dark:hover:border-[#474751] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a] hover:text-[#20202A] dark:border-[#34343c] dark:bg-[#1b1b1f] dark:text-[#b0b0b9]"
                      >
                        Export
                      </button>
                      {exportMenuOpen && (
                        <div
                          role="menu"
                          className="absolute right-0 z-20 mt-2 w-36 rounded-lg border border-[#D7D5DE] bg-white shadow-lg dark:border-[#34343c] dark:bg-[#1b1b1f]"
                        >
                          <button
                            type="button"
                            role="menuitem"
                            onClick={() => handleExport("md")}
                            className="flex w-full items-center justify-start px-4 py-2 text-sm text-[#686873] transition hover:bg-[#F2F0EA] dark:text-[#b0b0b9] dark:hover:bg-[#24242a]"
                          >
                            Markdown
                          </button>
                          <button
                            type="button"
                            role="menuitem"
                            onClick={() => handleExport("html")}
                            className="flex w-full items-center justify-start px-4 py-2 text-sm text-[#686873] transition hover:bg-[#F2F0EA] dark:text-[#b0b0b9] dark:hover:bg-[#24242a]"
                          >
                            HTML
                          </button>
                          <button
                            type="button"
                            role="menuitem"
                            onClick={() => handleExport("json")}
                            className="flex w-full items-center justify-start px-4 py-2 text-sm text-[#686873] transition hover:bg-[#F2F0EA] dark:text-[#b0b0b9] dark:hover:bg-[#24242a]"
                          >
                            JSON
                          </button>
                          <button
                            type="button"
                            role="menuitem"
                            onClick={() => handleExport("pdf")}
                            className="flex w-full items-center justify-start px-4 py-2 text-sm text-[#686873] transition hover:bg-[#F2F0EA] dark:text-[#b0b0b9] dark:hover:bg-[#24242a]"
                          >
                            PDF
                          </button>
                        </div>
                      )}
                    </div>
                  )}

                  <button
                    onClick={() => {
                      setError(null);
                      setEditing(true);
                    }}
                    className="rounded-lg border border-[#D7D5DE] bg-white px-5 py-3 text-sm font-medium text-[#686873] transition hover:border-[#C9C7D0] dark:hover:border-[#474751] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a] hover:text-[#20202A] dark:border-[#34343c] dark:bg-[#1b1b1f] dark:text-[#b0b0b9]"
                  >
                    Edit
                  </button>

                  <button
                    onClick={handleDelete}
                    disabled={deleting}
                    className="rounded-lg border border-[#E85D75] dark:border-[#f08098] px-5 py-3 text-sm font-medium text-[#B13D55] dark:text-[#f0a0b0] transition hover:bg-[#E85D75] hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    {deleting ? "Deleting…" : "Delete"}
                  </button>
                </div>
              )}
            </div>

            <section className="rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] shadow-sm">
              <div className="flex items-start justify-between gap-4 border-b border-[#D7D5DE] dark:border-[#34343c] px-7 py-5 sm:px-8">
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
                  className="shrink-0 rounded-lg border border-[#5146E5] px-4 py-2 text-sm font-medium text-[#5146E5] transition hover:bg-[#EEEDFF] dark:hover:bg-[#2a2748] disabled:cursor-not-allowed disabled:opacity-50"
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
                <p className="px-7 py-7 text-sm leading-relaxed text-[#9897A1] dark:text-[#8b8b94] sm:px-8">
                  This entry has no content yet.
                </p>
              )}

              {summary && (
                <div className="border-t border-[#D7D5DE] dark:border-[#34343c] px-7 py-6 sm:px-8">
                  <p className="text-xs font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
                    AI Summary
                  </p>

                  <p className="mt-2 text-sm leading-relaxed text-[#20202A] dark:text-[#ececf1]">
                    {summary}
                  </p>
                </div>
              )}

              {summaryError && (
                <p
                  role="alert"
                  className="mx-7 mb-6 rounded-lg border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-4 py-3 text-sm leading-relaxed text-[#B13D55] dark:text-[#f0a0b0] sm:mx-8"
                >
                  {summaryError}
                </p>
              )}
            </section>

            <section className="rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] shadow-sm">
              <div className="border-b border-[#D7D5DE] dark:border-[#34343c] px-7 py-5 sm:px-8">
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
                      className="inline-flex items-center gap-1.5 rounded-full bg-[#EEEDFF] dark:bg-[#262449] px-3 py-1 text-sm font-medium text-[#4338CA]"
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
                    <span className="text-sm text-[#9897A1] dark:text-[#8b8b94]">
                      No tags yet.
                    </span>
                  )}
                </div>

                {tagError && (
                  <p
                    role="alert"
                    className="mt-4 rounded-lg border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-4 py-3 text-sm leading-relaxed text-[#B13D55] dark:text-[#f0a0b0]"
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
                      className="w-full rounded-lg border border-[#D7D5DE] bg-white px-4 py-2.5 text-sm text-[#20202A] outline-none transition placeholder:text-[#A3A2AC] dark:placeholder:text-[#7e7e88] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a] dark:border-[#34343c] dark:bg-[#1b1b1f] dark:text-[#ececf1]"
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

                {permissions.canContribute && (
                  <div className="mt-4">
                    <button
                      type="button"
                      onClick={handleSuggestTags}
                      disabled={suggesting}
                      className="text-sm font-medium text-[#5146E5] transition hover:text-[#4338CA] disabled:opacity-50 dark:text-[#a5b4fc]"
                    >
                      {suggesting ? "Thinking…" : "Suggest tags"}
                    </button>

                    {suggestions.length > 0 && (
                      <div className="mt-3 flex flex-wrap items-center gap-2 rounded-lg border border-[#E7E6ED] bg-[#F2F0EA] px-3 py-2 dark:border-[#34343c] dark:bg-[#24242a]">
                        <span className="text-xs font-medium text-[#686873] dark:text-[#b0b0b9]">
                          Suggested:
                        </span>

                        {suggestions.map((name) => (
                          <span
                            key={name}
                            className="rounded-full bg-[#EEEDFF] px-2 py-0.5 text-xs font-medium text-[#4338CA] dark:bg-[#2b2b5e] dark:text-[#a5b4fc]"
                          >
                            {name}
                          </span>
                        ))}

                        <button
                          type="button"
                          onClick={handleAcceptSuggestions}
                          disabled={tagSaving}
                          className="ml-auto rounded-md bg-[#5146E5] px-3 py-1 text-xs font-medium text-white transition hover:bg-[#4338CA] disabled:opacity-50"
                        >
                          Add all
                        </button>

                        <button
                          type="button"
                          onClick={() => setSuggestions([])}
                          className="rounded-md px-2 py-1 text-xs font-medium text-[#686873] transition hover:text-[#20202A] dark:text-[#b0b0b9]"
                        >
                          Dismiss
                        </button>
                      </div>
                    )}
                  </div>
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
              <section className="rounded-2xl border border-[#E8E7EC] bg-white dark:bg-[#1b1b1f] px-6 py-8">
                <h2 className="text-lg font-semibold tracking-tight text-[#20202A] dark:text-[#ececf1]">
                  Documents and discussion are private
                </h2>
                <p className="mt-2 max-w-prose text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
                  You are not a member of this workspace, so its documents and
                  discussions are closed to you. You can still read this entry.
                </p>
                <p className="mt-2 max-w-prose text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
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
            workspaceId={workspaceId}
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