"use client";

import axios from "axios";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import { CreateWorkspaceForm } from "@/app/features/workspaces/CreateWorkspaceForm";
import { UserSearch } from "@/app/features/users/UserSearch";
import { RoleBadge } from "@/app/features/workspaces/RoleBadge";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import {
  listWorkspaces,
  requestJoin,
  Workspace,
} from "@/lib/workspaces";

function WorkspaceMark() {
  return (
    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#EEEDFF] dark:bg-[#262449]">
      <svg
        width="20"
        height="20"
        viewBox="0 0 24 24"
        fill="none"
        aria-hidden="true"
      >
        <path
          d="M4 20V6.5C4 5.12 5.12 4 6.5 4c1.2 0 2.23.83 2.45 2M9 20V8c0-1.66 1.34-3 3-3s3 1.34 3 3M15 20v-9c0-1.38 1.12-2.5 2.5-2.5S20 9.62 20 11v9"
          stroke="#5146E5"
          strokeWidth="1.7"
          strokeLinecap="round"
        />
      </svg>
    </div>
  );
}

export default function DashboardPage() {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();
  const [workspaces, setWorkspaces] = useState<Workspace[] | null>(null);
  const [query, setQuery] = useState("");
  const [loadingError, setLoadingError] = useState<string | null>(null);
  const [requestingId, setRequestingId] = useState<number | null>(null);
  const [joinError, setJoinError] = useState<string | null>(null);

  useEffect(() => {
    if (authLoading) {
      return;
    }

    if (!user) {
      router.replace("/auth/login");
      return;
    }

    let cancelled = false;

    listWorkspaces()
      .then((result) => {
        if (!cancelled) {
          setWorkspaces(result);
          setLoadingError(null);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setWorkspaces([]);
          setLoadingError("Could not load workspaces. Please try again.");
        }
      });

    return () => {
      cancelled = true;
    };
  }, [user, authLoading, router]);

  const filteredWorkspaces = useMemo(() => {
    if (!workspaces) {
      return [];
    }

    const normalizedQuery = query.trim().toLowerCase().replace(/^@/, "");

    if (!normalizedQuery) {
      return workspaces;
    }

    return workspaces.filter((workspace) =>
      [workspace.name, workspace.owner.username].some((value) =>
        value.toLowerCase().includes(normalizedQuery),
      ),
    );
  }, [query, workspaces]);

  async function handleRequestJoin(workspaceId: number) {
    setRequestingId(workspaceId);
    setJoinError(null);

    try {
      await requestJoin(workspaceId, "member");

      markRequestPending(workspaceId);
    } catch (requestError: unknown) {
      // A duplicate request is not a failure from the caller's point of view:
      // the row is still pending, so show the same state as a fresh request.
      if (
        axios.isAxiosError(requestError) &&
        requestError.response?.status === 409
      ) {
        markRequestPending(workspaceId);
        return;
      }

      const message = axios.isAxiosError(requestError)
        ? (requestError.response?.data as { message?: string } | undefined)
            ?.message
        : undefined;

      setJoinError(message ?? "Could not send your join request.");
    } finally {
      setRequestingId(null);
    }
  }

  function markRequestPending(workspaceId: number) {
    setWorkspaces((current) => {
      if (!current) {
        return current;
      }

      return current.map((workspace) =>
        workspace.id === workspaceId
          ? { ...workspace, join_request_status: "pending" as const }
          : workspace,
      );
    });
  }

  if (authLoading || !user) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#F2F0EA] dark:bg-[#121214] font-sans">
        <div className="flex items-center gap-3 text-sm text-[#686873] dark:text-[#b0b0b9]">
          <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
          Loading workspaces…
        </div>
      </main>
    );
  }

  return (
    <div className="flex min-h-screen flex-col bg-[#F2F0EA] dark:bg-[#121214] font-sans text-[#20202A] dark:text-[#ececf1]">
      <WorkspaceNav workspaces={workspaces ?? []} />

      <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-10 sm:px-10 sm:py-12">
        <header className="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
              Workspace directory
            </p>
            <h1 className="mt-2 text-3xl font-semibold tracking-[-0.03em] sm:text-4xl">
              Explore shared knowledge
            </h1>
            <p className="mt-3 max-w-2xl text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9] sm:text-base">
              Browse every workspace as a viewer, read its knowledge entries,
              and request to join the ones you want to help build.
            </p>
          </div>

          <Link
            href="/workspaces/new"
            className="inline-flex w-fit items-center justify-center gap-2 rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA]"
          >
            <svg
              width="16"
              height="16"
              viewBox="0 0 24 24"
              fill="none"
              aria-hidden="true"
            >
              <path
                d="M12 5v14M5 12h14"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
              />
            </svg>
            New workspace
          </Link>
        </header>

        {loadingError && (
          <p
            role="alert"
            className="mt-6 rounded-xl border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-4 py-3 text-sm text-[#B13D55] dark:text-[#f0a0b0]"
          >
            {loadingError}
          </p>
        )}

        <div className="mt-9 grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
          <section className="min-w-0">
            <div className="relative">
              <svg
                className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-[#9897A1] dark:text-[#8b8b94]"
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                aria-hidden="true"
              >
                <circle
                  cx="11"
                  cy="11"
                  r="6.5"
                  stroke="currentColor"
                  strokeWidth="1.7"
                />
                <path
                  d="m16 16 4 4"
                  stroke="currentColor"
                  strokeWidth="1.7"
                  strokeLinecap="round"
                />
              </svg>
              <input
                type="search"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Search workspaces or owner usernames"
                aria-label="Search workspaces or owner usernames"
                className="h-12 w-full rounded-xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] pl-11 pr-4 text-sm text-[#20202A] dark:text-[#ececf1] outline-none transition placeholder:text-[#A3A2AC] dark:placeholder:text-[#7e7e88] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
              />
            </div>

            <div className="mt-4 flex items-center justify-between text-sm text-[#686873] dark:text-[#b0b0b9]">
              <span>
                {workspaces
                  ? `${filteredWorkspaces.length} ${filteredWorkspaces.length === 1 ? "workspace" : "workspaces"}`
                  : "Loading workspaces…"}
              </span>
              <span>Owners approve every join request</span>
            </div>

            {joinError && (
              <p
                role="alert"
                className="mt-4 rounded-xl border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-4 py-3 text-sm text-[#B13D55] dark:text-[#f0a0b0]"
              >
                {joinError}
              </p>
            )}

            {!workspaces ? (
              <div className="mt-5 flex min-h-64 items-center justify-center rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] text-sm text-[#686873] dark:text-[#b0b0b9] shadow-sm">
                Loading workspaces…
              </div>
            ) : filteredWorkspaces.length === 0 ? (
              <div className="mt-5 flex min-h-64 flex-col items-center justify-center rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-7 py-12 text-center shadow-sm">
                <WorkspaceMark />
                <h2 className="mt-5 text-lg font-semibold tracking-tight">
                  {query ? "No matching workspaces" : "No workspaces yet"}
                </h2>
                <p className="mt-2 max-w-sm text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
                  {query
                    ? "Try a different workspace name or owner username."
                    : "Create the first workspace to start sharing knowledge."}
                </p>
              </div>
            ) : (
              <div className="mt-5 grid gap-4 sm:grid-cols-2">
                {filteredWorkspaces.map((workspace) => (
                  <article
                    key={workspace.id}
                    className="flex min-h-64 flex-col rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] p-6 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                  >
                    <div className="flex items-start justify-between gap-4">
                      <WorkspaceMark />
                      <RoleBadge role={workspace.role} />
                    </div>

                    <Link
                      href={`/workspaces/${workspace.id}`}
                      className="mt-5 text-xl font-semibold tracking-tight text-[#20202A] dark:text-[#ececf1] transition hover:text-[#5146E5]"
                    >
                      {workspace.name}
                    </Link>
                    <p className="mt-1 text-sm text-[#686873] dark:text-[#b0b0b9]">
                      Owned by @{workspace.owner.username}
                    </p>
                    <p className="mt-3 text-sm text-[#9897A1] dark:text-[#8b8b94]">
                      {workspace.entries_count ?? 0}{" "}
                      {workspace.entries_count === 1 ? "entry" : "entries"}
                    </p>

                    <div className="mt-auto flex flex-wrap items-center gap-2 pt-6">
                      <Link
                        href={`/workspaces/${workspace.id}/entries`}
                        className="rounded-lg bg-[#5146E5] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[#4338CA]"
                      >
                        View entries
                      </Link>
                      {workspace.role === "viewer" &&
                        !workspace.is_member &&
                        (workspace.join_request_status === "pending" ? (
                          <span className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-[#F2F0EA] dark:bg-[#24242a] px-4 py-2.5 text-sm font-medium text-[#686873] dark:text-[#b0b0b9]">
                            Request pending
                          </span>
                        ) : (
                          <button
                            type="button"
                            onClick={() => handleRequestJoin(workspace.id)}
                            disabled={requestingId === workspace.id}
                            className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-2.5 text-sm font-medium text-[#20202A] dark:text-[#ececf1] transition hover:border-[#C9C7D0] dark:hover:border-[#474751] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a] disabled:cursor-not-allowed disabled:opacity-50"
                          >
                            {requestingId === workspace.id
                              ? "Sending…"
                              : "Request to join"}
                          </button>
                        ))}
                    </div>
                  </article>
                ))}
              </div>
            )}
          </section>

          <aside className="space-y-6 lg:sticky lg:top-6">
            <UserSearch />

            <section className="rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] p-6 shadow-sm">
              <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
                Start something new
              </p>
              <h2 className="mt-2 text-xl font-semibold tracking-tight">
                Create a workspace
              </h2>
              <p className="mt-2 text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
                You&rsquo;ll become its owner and can add entries and members.
              </p>
              <div className="mt-6">
                <CreateWorkspaceForm
                  onCreated={(workspace) =>
                    router.push(`/workspaces/${workspace.id}`)
                  }
                />
              </div>
            </section>
          </aside>
        </div>
      </main>
    </div>
  );
}
