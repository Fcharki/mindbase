"use client";

import axios from "axios";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useState, useSyncExternalStore } from "react";
import { toast } from "sonner";

import { useAuth } from "@/app/Providers/AuthProvider";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import { EntryList } from "@/app/features/knowledge/components/EntryList";
import { SearchPanel } from "@/app/features/search/SearchPanel";
import {
  bulkManageEntries,
  exportEntry,
  listEntries,
  pinEntry,
  type BulkAction,
  type KnowledgeEntry,
} from "@/lib/entries";
import { permissionsFor } from "@/lib/permissions";
import {
  clearRecentViewed,
  getRecentViewedServerSnapshot,
  getRecentViewedSnapshot,
  subscribeRecentViewed,
} from "@/lib/recent";
import { getWorkspace, Workspace } from "@/lib/workspaces";

type Status = "loading" | "ready" | "missing";

function EntryListSkeleton() {
  return (
    <ul className="divide-y divide-[#E7E6ED] dark:divide-[#2c2c33]" aria-hidden="true">
      {Array.from({ length: 4 }).map((_, index) => (
        <li key={index} className="flex items-start gap-4 px-7 py-4 sm:px-8">
          <div className="h-9 w-9 shrink-0 animate-pulse rounded-lg bg-[#E7E6ED] dark:bg-[#2c2c33]" />
          <div className="min-w-0 flex-1">
            <div className="h-4 w-1/3 animate-pulse rounded bg-[#E7E6ED] dark:bg-[#2c2c33]" />
            <div className="mt-2 h-3 w-2/3 animate-pulse rounded bg-[#F0EFF2] dark:bg-[#2a2a31]" />
          </div>
        </li>
      ))}
    </ul>
  );
}

export function EntriesPageView({ workspaceId }: { workspaceId: string }) {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();

  const [workspace, setWorkspace] = useState<Workspace | null>(null);
  const [entries, setEntries] = useState<KnowledgeEntry[]>([]);
  const [status, setStatus] = useState<Status>("loading");
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [bulkPending, setBulkPending] = useState(false);

  // Read through an external store rather than an effect, so the recency list
  // is a pure read during render and cannot cause a cascading update.
  const recentIds = useSyncExternalStore(
    subscribeRecentViewed,
    getRecentViewedSnapshot,
    getRecentViewedServerSnapshot,
  );

  useEffect(() => {
    if (authLoading) {
      return;
    }

    if (!user) {
      router.replace("/auth/login");
      return;
    }

    let cancelled = false;

    getWorkspace(workspaceId)
      .then((result) => {
        if (cancelled) {
          return;
        }

        setWorkspace(result);
        return listEntries(workspaceId);
      })
      .then((result) => {
        if (cancelled) {
          return;
        }

        setEntries(result ?? []);
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
  }, [workspaceId, user, authLoading, router]);

  const reloadEntries = useCallback(async () => {
    try {
      const result = await listEntries(workspaceId);
      setEntries(result ?? []);
    } catch {
      toast.error("Could not refresh the entry list.");
    }
  }, [workspaceId]);

  const recentEntries = useMemo(
    () =>
      recentIds
        .map((id) => entries.find((entry) => entry.id === id))
        .filter((entry): entry is KnowledgeEntry => Boolean(entry))
        .slice(0, 5),
    [entries, recentIds],
  );

  async function handleTogglePin(entry: KnowledgeEntry) {
    try {
      await pinEntry(workspaceId, entry.id, !entry.is_pinned);
      await reloadEntries();
      toast.success(entry.is_pinned ? "Entry unpinned." : "Entry pinned.");
    } catch {
      toast.error("Could not update the pin.");
    }
  }

  async function handleExport(entry: KnowledgeEntry) {
    try {
      await exportEntry(workspaceId, entry, "md");
      toast.success("Entry exported.");
    } catch {
      toast.error("Could not export this entry.");
    }
  }

  function toggleSelect(id: number) {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }

  async function handleBulk(action: BulkAction) {
    if (selected.size === 0 || bulkPending) {
      return;
    }

    let tags: string[] = [];

    if (action === "tag" || action === "untag") {
      const answer = window.prompt(
        action === "tag"
          ? "Tags to add, comma separated:"
          : "Tags to remove, comma separated:",
      );

      if (answer === null) {
        return;
      }

      tags = answer
        .split(",")
        .map((tag) => tag.trim())
        .filter((tag) => tag !== "");

      if (tags.length === 0) {
        return;
      }
    }

    if (action === "delete") {
      const confirmed = window.confirm(
        `Delete ${selected.size} ${selected.size === 1 ? "entry" : "entries"}? This cannot be undone.`,
      );

      if (!confirmed) {
        return;
      }
    }

    setBulkPending(true);

    try {
      const result = await bulkManageEntries(
        workspaceId,
        Array.from(selected),
        action,
        tags,
      );

      setSelected(new Set());
      await reloadEntries();

      const verb: Record<BulkAction, string> = {
        delete: "Deleted",
        tag: "Tagged",
        untag: "Untagged",
        pin: "Pinned",
        unpin: "Unpinned",
      };

      toast.success(
        `${verb[action]} ${result.affected} ${result.affected === 1 ? "entry" : "entries"}.`,
      );

      if (result.missing.length > 0) {
        toast.message(`${result.missing.length} entries were skipped.`);
      }
    } catch {
      toast.error("Could not apply that change.");
    } finally {
      setBulkPending(false);
    }
  }

  if (status === "loading") {
    return (
      <div className="flex min-h-screen flex-col bg-[#F2F0EA] font-sans text-[#20202A] dark:bg-[#121214] dark:text-[#ececf1]">
        <WorkspaceNav />

        <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-6 py-10 sm:px-10 sm:py-12">
          <div>
            <div className="h-4 w-24 animate-pulse rounded bg-[#E7E6ED] dark:bg-[#2c2c33]" />
            <div className="mt-3 h-8 w-40 animate-pulse rounded bg-[#E7E6ED] dark:bg-[#2c2c33]" />
          </div>

          <section className="overflow-hidden rounded-2xl border border-[#D7D5DE] bg-white shadow-sm dark:border-[#34343c] dark:bg-[#1b1b1f]">
            <EntryListSkeleton />
          </section>
        </main>
      </div>
    );
  }

  if (status === "missing" || !workspace) {
    return (
      <div className="flex min-h-screen flex-col bg-[#F2F0EA] font-sans text-[#20202A] dark:bg-[#121214] dark:text-[#ececf1]">
        <WorkspaceNav />

        <main className="flex flex-1 items-center justify-center px-6 py-20">
          <section className="w-full max-w-md rounded-2xl border border-[#D7D5DE] bg-white p-8 text-center shadow-sm sm:p-10 dark:border-[#34343c] dark:bg-[#1b1b1f]">
            <div className="mx-auto mb-6 flex h-12 w-12 items-center justify-center rounded-xl bg-[#FCECEF] dark:bg-[#43212b] text-[#E85D75] dark:text-[#f08098]">
              <svg
                width="22"
                height="22"
                viewBox="0 0 24 24"
                fill="none"
                aria-hidden="true"
              >
                <path
                  d="M12 8V12.5"
                  stroke="currentColor"
                  strokeWidth="1.8"
                  strokeLinecap="round"
                />
                <circle cx="12" cy="16" r="0.9" fill="currentColor" />
                <path
                  d="M10.3 4.8L3.8 16.2C3.05 17.52 4 19.15 5.52 19.15H18.48C20 19.15 20.95 17.52 20.2 16.2L13.7 4.8C12.95 3.48 11.05 3.48 10.3 4.8Z"
                  stroke="currentColor"
                  strokeWidth="1.5"
                  strokeLinejoin="round"
                />
              </svg>
            </div>

            <h1 className="text-2xl font-semibold tracking-[-0.025em]">
              Workspace not available
            </h1>

            <p className="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
              This workspace may have been deleted, or you don&rsquo;t have
              access to it.
            </p>

            <Link
              href="/dashboard"
              className="mt-7 inline-flex items-center justify-center rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA]"
            >
              Go to my workspaces
            </Link>
          </section>
        </main>
      </div>
    );
  }

  const canWrite = permissionsFor(workspace.role, workspace.is_member).canContribute;

  return (
    <div className="flex min-h-screen flex-col bg-[#F2F0EA] font-sans text-[#20202A] dark:bg-[#121214] dark:text-[#ececf1]">
      <WorkspaceNav />

      <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-6 py-10 sm:px-10 sm:py-12">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <Link
              href={`/workspaces/${workspace.id}`}
              className="inline-flex items-center gap-1.5 text-sm font-medium text-[#686873] transition hover:text-[#20202A] dark:text-[#b0b0b9] dark:hover:text-[#ececf1]"
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
              {workspace.name}
            </Link>

            <h1 className="mt-3 text-3xl font-semibold tracking-[-0.03em]">
              Entries
            </h1>

            <p className="mt-2 text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
              {entries.length === 0
                ? "Nothing here yet."
                : `${entries.length} ${entries.length === 1 ? "entry" : "entries"} in this workspace.`}
            </p>
          </div>

          {canWrite && (
            <Link
              href={`/workspaces/${workspace.id}/entries/new`}
              className="inline-flex w-fit items-center justify-center gap-2 rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA]"
            >
              New entry
            </Link>
          )}
        </div>

        <SearchPanel
          workspaceId={workspace.id}
          basePath={`/workspaces/${workspace.id}/entries`}
        />

        {recentEntries.length > 0 && (
          <section className="rounded-2xl border border-[#D7D5DE] bg-white px-7 py-5 shadow-sm sm:px-8 dark:border-[#34343c] dark:bg-[#1b1b1f]">
            <div className="flex items-center justify-between">
              <h2 className="text-xs font-semibold uppercase tracking-[0.1em] text-[#686873] dark:text-[#b0b0b9]">
                Recently viewed
              </h2>

              <button
                type="button"
                onClick={() => clearRecentViewed()}
                className="text-xs font-medium text-[#5146E5] transition hover:text-[#4338CA] dark:text-[#a5b4fc]"
              >
                Clear
              </button>
            </div>

            <div className="mt-3 flex flex-wrap gap-2">
              {recentEntries.map((entry) => (
                <Link
                  key={entry.id}
                  href={`/workspaces/${workspace.id}/entries/${entry.id}`}
                  className="max-w-[240px] truncate rounded-full border border-[#D7D5DE] px-3 py-1 text-xs font-medium text-[#20202A] transition hover:border-[#5146E5] hover:text-[#5146E5] dark:border-[#34343c] dark:text-[#ececf1]"
                >
                  {entry.title}
                </Link>
              ))}
            </div>
          </section>
        )}

        {canWrite && selected.size > 0 && (
          <div className="sticky top-4 z-10 flex flex-wrap items-center gap-2 rounded-xl border border-[#5146E5] bg-white px-4 py-3 shadow-md dark:bg-[#1b1b1f]">
            <span className="text-sm font-medium text-[#20202A] dark:text-[#ececf1]">
              {selected.size} selected
            </span>

            <div className="ml-auto flex flex-wrap items-center gap-2">
              <button
                type="button"
                onClick={() => handleBulk("pin")}
                disabled={bulkPending}
                className="rounded-lg border border-[#D7D5DE] px-3 py-1.5 text-sm font-medium text-[#686873] transition hover:bg-[#F2F0EA] disabled:opacity-50 dark:border-[#34343c] dark:text-[#b0b0b9] dark:hover:bg-[#24242a]"
              >
                Pin
              </button>

              <button
                type="button"
                onClick={() => handleBulk("unpin")}
                disabled={bulkPending}
                className="rounded-lg border border-[#D7D5DE] px-3 py-1.5 text-sm font-medium text-[#686873] transition hover:bg-[#F2F0EA] disabled:opacity-50 dark:border-[#34343c] dark:text-[#b0b0b9] dark:hover:bg-[#24242a]"
              >
                Unpin
              </button>

              <button
                type="button"
                onClick={() => handleBulk("tag")}
                disabled={bulkPending}
                className="rounded-lg border border-[#D7D5DE] px-3 py-1.5 text-sm font-medium text-[#686873] transition hover:bg-[#F2F0EA] disabled:opacity-50 dark:border-[#34343c] dark:text-[#b0b0b9] dark:hover:bg-[#24242a]"
              >
                Tag
              </button>

              <button
                type="button"
                onClick={() => handleBulk("delete")}
                disabled={bulkPending}
                className="rounded-lg border border-[#E85D75] dark:border-[#f08098] px-3 py-1.5 text-sm font-medium text-[#B13D55] transition hover:bg-[#E85D75] hover:text-white disabled:opacity-50 dark:text-[#f3a7b6]"
              >
                Delete
              </button>

              <button
                type="button"
                onClick={() => setSelected(new Set())}
                className="text-sm font-medium text-[#686873] transition hover:text-[#20202A] dark:text-[#b0b0b9]"
              >
                Clear
              </button>
            </div>
          </div>
        )}

        <section className="overflow-hidden rounded-2xl border border-[#D7D5DE] bg-white shadow-sm dark:border-[#34343c] dark:bg-[#1b1b1f]">
          {entries.length === 0 ? (
            <div className="flex min-h-[260px] flex-col items-center justify-center px-7 py-12 text-center sm:px-8">
              <div className="mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-[#EAF7F6] dark:bg-[#143036] text-[#159A9C] dark:text-[#3ac6c8]">
                <svg
                  width="23"
                  height="23"
                  viewBox="0 0 24 24"
                  fill="none"
                  aria-hidden="true"
                >
                  <path
                    d="M6 4.5h9.5L19 8v11.5H6V4.5Z"
                    stroke="currentColor"
                    strokeWidth="1.6"
                    strokeLinejoin="round"
                  />
                  <path
                    d="M15 4.5V8h4M9 12h7M9 15.5h5"
                    stroke="currentColor"
                    strokeWidth="1.6"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                </svg>
              </div>

              <h3 className="text-lg font-semibold tracking-tight">
                No entries yet
              </h3>

              <p className="mt-2 max-w-md text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
                {canWrite
                  ? "Capture your first thought, note, or idea in this workspace."
                  : "There are no knowledge entries to browse in this workspace yet."}
              </p>

              {canWrite && (
                <Link
                  href={`/workspaces/${workspace.id}/entries/new`}
                  className="mt-6 rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA]"
                >
                  Create your first entry
                </Link>
              )}
            </div>
          ) : (
            <EntryList
              entries={entries}
              basePath={`/workspaces/${workspace.id}/entries`}
              selectable={canWrite}
              selectedIds={selected}
              onToggleSelect={toggleSelect}
              onTogglePin={handleTogglePin}
              onExport={handleExport}
              canContribute={canWrite}
            />
          )}
        </section>
      </main>
    </div>
  );
}
