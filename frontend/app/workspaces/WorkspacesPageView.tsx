"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import { WorkspaceCard } from "@/app/features/workspaces/WorkspaceCard";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import { listWorkspaces, Workspace } from "@/lib/workspaces";

type Filter = "all" | "owned" | "shared";

const filters: { value: Filter; label: string }[] = [
  { value: "all", label: "All" },
  { value: "owned", label: "Owned by me" },
  { value: "shared", label: "Shared with me" },
];

function EmptyState({
  filter,
  hasAny,
}: {
  filter: Filter;
  hasAny: boolean;
}) {
  return (
    <div className="flex min-h-64 flex-col items-center justify-center rounded-2xl border border-[#DDDCE3] dark:border-[#2a2a2a] bg-white dark:bg-[#141414] px-7 py-12 text-center shadow-sm">
      <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#EEEDFF] dark:bg-[#211f3d]">
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

      <h2 className="mt-5 text-lg font-semibold tracking-tight">
        {filter === "owned"
          ? "You do not own any workspaces yet"
          : filter === "shared"
            ? "Nothing has been shared with you"
            : "No workspaces yet"}
      </h2>

      <p className="mt-2 max-w-sm text-sm leading-relaxed text-[#686873] dark:text-[#a3a3a3]">
        {hasAny
          ? "Try a different filter to see the rest of your workspaces."
          : "Create a workspace to start organizing your knowledge."}
      </p>
    </div>
  );
}

/**
 * The signed-in user's own workspace list.
 *
 * Every workspace they can reach is shown, but the management controls only
 * appear on the ones they own. The dashboard covers the other half of the job,
 * browsing and joining workspaces that belong to other people.
 */
export function WorkspacesPageView() {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();

  const [workspaces, setWorkspaces] = useState<Workspace[] | null>(null);
  const [filter, setFilter] = useState<Filter>("all");
  const [loadingError, setLoadingError] = useState<string | null>(null);

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

  const ownedIds = useMemo(() => {
    if (!workspaces || !user) {
      return new Set<number>();
    }

    return new Set(
      workspaces
        .filter((workspace) => workspace.owner_id === user.id)
        .map((workspace) => workspace.id),
    );
  }, [workspaces, user]);

  const visibleWorkspaces = useMemo(() => {
    if (!workspaces) {
      return [];
    }

    if (filter === "owned") {
      return workspaces.filter((workspace) => ownedIds.has(workspace.id));
    }

    if (filter === "shared") {
      return workspaces.filter((workspace) => !ownedIds.has(workspace.id));
    }

    return workspaces;
  }, [filter, ownedIds, workspaces]);

  const ownedCount = ownedIds.size;

  function handleRenamed(updated: Workspace) {
    setWorkspaces((current) =>
      current
        ? current.map((workspace) =>
            workspace.id === updated.id ? { ...updated, role: workspace.role } : workspace,
          )
        : current,
    );
  }

  function handleDeleted(workspaceId: number) {
    setWorkspaces((current) =>
      current
        ? current.filter((workspace) => workspace.id !== workspaceId)
        : current,
    );
  }

  if (authLoading || !user) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#F7F6F2] dark:bg-[#0f0f0f] font-sans">
        <div className="flex items-center gap-3 text-sm text-[#686873] dark:text-[#a3a3a3]">
          <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
          Loading your workspaces…
        </div>
      </main>
    );
  }

  return (
    <div className="flex min-h-screen flex-col bg-[#F7F6F2] dark:bg-[#0f0f0f] font-sans text-[#20202A] dark:text-[#e5e5e5]">
      <WorkspaceNav workspaces={workspaces ?? []} />

      <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-10 sm:px-10 sm:py-12">
        <header className="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
              Your workspaces
            </p>
            <h1 className="mt-2 text-3xl font-semibold tracking-[-0.03em] sm:text-4xl">
              Everything you can work in
            </h1>
            <p className="mt-3 max-w-2xl text-sm leading-relaxed text-[#686873] dark:text-[#a3a3a3] sm:text-base">
              Workspaces you own can be renamed, shared and deleted here.
              Workspaces shared with you are read and contribute until you ask
              for a member role.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <Link
              href="/dashboard"
              className="inline-flex w-fit items-center justify-center gap-2 rounded-lg border border-[#DDDCE3] dark:border-[#2a2a2a] bg-white dark:bg-[#141414] px-5 py-3 text-sm font-medium text-[#20202A] dark:text-[#e5e5e5] transition hover:border-[#C9C7D0] dark:hover:border-[#3a3a3a] hover:bg-white"
            >
              Browse directory
            </Link>
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
          </div>
        </header>

        {loadingError && (
          <p
            role="alert"
            className="mt-6 rounded-xl border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#3a1c24] px-4 py-3 text-sm text-[#B13D55] dark:text-[#f0a0b0]"
          >
            {loadingError}
          </p>
        )}

        {workspaces && workspaces.length > 0 && (
          <div className="mt-8 flex flex-wrap items-center gap-2">
            {filters.map((option) => {
              const count =
                option.value === "all"
                  ? workspaces.length
                  : option.value === "owned"
                    ? ownedCount
                    : workspaces.length - ownedCount;

              const active = filter === option.value;

              return (
                <button
                  key={option.value}
                  type="button"
                  onClick={() => setFilter(option.value)}
                  aria-pressed={active}
                  className={`rounded-lg px-3.5 py-2 text-sm font-medium transition ${
                    active
                      ? "bg-[#5146E5] text-white"
                      : "border border-[#DDDCE3] dark:border-[#2a2a2a] bg-white dark:bg-[#141414] text-[#686873] dark:text-[#a3a3a3] hover:border-[#C9C7D0] dark:hover:border-[#3a3a3a] hover:text-[#20202A]"
                  }`}
                >
                  {option.label}
                  <span className={active ? "ml-1.5 opacity-70" : "ml-1.5"}>
                    {count}
                  </span>
                </button>
              );
            })}
          </div>
        )}

        {!workspaces ? (
          <div className="mt-5 flex min-h-64 items-center justify-center rounded-2xl border border-[#DDDCE3] dark:border-[#2a2a2a] bg-white dark:bg-[#141414] text-sm text-[#686873] dark:text-[#a3a3a3] shadow-sm">
            Loading workspaces…
          </div>
        ) : visibleWorkspaces.length === 0 ? (
          <div className="mt-5">
            <EmptyState filter={filter} hasAny={workspaces.length > 0} />
          </div>
        ) : (
          <div className="mt-5 grid gap-4 sm:grid-cols-2">
            {visibleWorkspaces.map((workspace) => (
              <WorkspaceCard
                key={workspace.id}
                workspace={workspace}
                isOwner={ownedIds.has(workspace.id)}
                onRenamed={handleRenamed}
                onDeleted={handleDeleted}
              />
            ))}
          </div>
        )}
      </main>
    </div>
  );
}