"use client";

import axios from "axios";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import { EntryList } from "@/app/features/knowledge/components/EntryList";
import { SearchPanel } from "@/app/features/search/SearchPanel";
import { listEntries, KnowledgeEntry } from "@/lib/entries";
import { getWorkspace, Workspace } from "@/lib/workspaces";

type Status = "loading" | "ready" | "missing";

export function EntriesPageView({ workspaceId }: { workspaceId: string }) {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();

  const [workspace, setWorkspace] = useState<Workspace | null>(null);
  const [entries, setEntries] = useState<KnowledgeEntry[]>([]);
  const [status, setStatus] = useState<Status>("loading");

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

  if (status === "loading") {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#F7F6F2] font-sans">
        <div className="flex items-center gap-3 text-sm text-[#686873]">
          <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
          Loading entries…
        </div>
      </main>
    );
  }

  if (status === "missing" || !workspace) {
    return (
      <div className="flex min-h-screen flex-col bg-[#F7F6F2] font-sans text-[#20202A]">
        <WorkspaceNav />

        <main className="flex flex-1 items-center justify-center px-6 py-20">
          <section className="w-full max-w-md rounded-2xl border border-[#DDDCE3] bg-white p-8 text-center shadow-sm sm:p-10">
            <div className="mx-auto mb-6 flex h-12 w-12 items-center justify-center rounded-xl bg-[#FCECEF] text-[#E85D75]">
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

            <p className="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-[#686873]">
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

  return (
    <div className="flex min-h-screen flex-col bg-[#F7F6F2] font-sans text-[#20202A]">
      <WorkspaceNav />

      <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-6 py-10 sm:px-10 sm:py-12">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <Link
              href={`/workspaces/${workspace.id}`}
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
              {workspace.name}
            </Link>

            <h1 className="mt-3 text-3xl font-semibold tracking-[-0.03em]">
              Entries
            </h1>

            <p className="mt-2 text-sm leading-relaxed text-[#686873]">
              {entries.length === 0
                ? "Nothing here yet."
                : `${entries.length} ${entries.length === 1 ? "entry" : "entries"} in this workspace.`}
            </p>
          </div>

          <Link
            href={`/workspaces/${workspace.id}/entries/new`}
            className="inline-flex w-fit items-center justify-center gap-2 rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA]"
          >
            New entry
          </Link>
        </div>

        <SearchPanel
          workspaceId={workspace.id}
          basePath={`/workspaces/${workspace.id}/entries`}
        />

        <section className="overflow-hidden rounded-2xl border border-[#DDDCE3] bg-white shadow-sm">
          {entries.length === 0 ? (
            <div className="flex min-h-[260px] flex-col items-center justify-center px-7 py-12 text-center sm:px-8">
              <div className="mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-[#EAF7F6] text-[#159A9C]">
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

              <p className="mt-2 max-w-md text-sm leading-relaxed text-[#686873]">
                Capture your first thought, note, or idea in this workspace.
              </p>

              <Link
                href={`/workspaces/${workspace.id}/entries/new`}
                className="mt-6 rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA]"
              >
                Create your first entry
              </Link>
            </div>
          ) : (
            <EntryList
              entries={entries}
              basePath={`/workspaces/${workspace.id}/entries`}
            />
          )}
        </section>
      </main>
    </div>
  );
}