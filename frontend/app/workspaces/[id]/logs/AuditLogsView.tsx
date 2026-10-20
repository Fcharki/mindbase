"use client";

import axios from "axios";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import {
  type AuditLog,
  type AuditLogFilterOptions,
  type AuditLogQuery,
  listAuditLogs,
  type PaginatedAuditLogs,
} from "@/lib/auditLogs";
import { getWorkspace, type Workspace } from "@/lib/workspaces";

type Status = "loading" | "ready" | "forbidden" | "missing" | "error";

const PER_PAGE_OPTIONS = [10, 15, 25, 50];

const EMPTY_FILTERS: Filters = {
  action: "",
  entity: "",
  userId: "",
  from: "",
  to: "",
};

interface Filters {
  action: string;
  entity: string;
  userId: string;
  from: string;
  to: string;
}

const ENTITY_LABELS: Record<string, string> = {
  workspace: "Workspace",
  knowledge_entry: "Entry",
  member: "Member",
  join_request: "Join request",
};

const ACTION_LABELS: Record<string, string> = {
  created: "Created",
  updated: "Updated",
  deleted: "Deleted",
  added: "Added",
  removed: "Removed",
  role_changed: "Role changed",
  requested: "Requested",
  approved: "Approved",
  rejected: "Rejected",
};

const ACTION_STYLES: Record<string, string> = {
  created: "bg-[#EAF7F6] dark:bg-[#143036] text-[#0F7B7D] dark:text-[#5ad0d2]",
  added: "bg-[#EAF7F6] dark:bg-[#143036] text-[#0F7B7D] dark:text-[#5ad0d2]",
  approved: "bg-[#EAF7F6] dark:bg-[#143036] text-[#0F7B7D] dark:text-[#5ad0d2]",
  requested: "bg-[#EEEDFF] dark:bg-[#262449] text-[#5146E5]",
  updated: "bg-[#FDF3E7] dark:bg-[#2a2114] text-[#9A6212] dark:text-[#e0b060]",
  role_changed: "bg-[#FDF3E7] dark:bg-[#2a2114] text-[#9A6212] dark:text-[#e0b060]",
  deleted: "bg-[#FCECEF] dark:bg-[#43212b] text-[#B13D55] dark:text-[#f0a0b0]",
  removed: "bg-[#FCECEF] dark:bg-[#43212b] text-[#B13D55] dark:text-[#f0a0b0]",
  rejected: "bg-[#FCECEF] dark:bg-[#43212b] text-[#B13D55] dark:text-[#f0a0b0]",
};

function entityLabel(type: string): string {
  return ENTITY_LABELS[type] ?? type.replace(/_/g, " ");
}

function actionLabel(action: string): string {
  return ACTION_LABELS[action] ?? action.replace(/_/g, " ");
}

/**
 * A one-line, human summary of what an audit entry is about.
 *
 * The shape of `metadata` depends on the entity, so this reads the few keys
 * that carry meaning across them and falls back to nothing rather than
 * dumping raw JSON into the table.
 */
function describe(log: AuditLog): string | null {
  const metadata = log.metadata ?? {};
  const name = typeof metadata.name === "string" ? metadata.name : null;
  const email = typeof metadata.email === "string" ? metadata.email : null;

  if (name || email) {
    return [name, email].filter(Boolean).join(" · ");
  }

  if (typeof metadata.title === "string") {
    return metadata.title;
  }

  if (typeof metadata.workspace_name === "string") {
    return metadata.workspace_name;
  }

  return null;
}

function formatTimestamp(value: string): { date: string; time: string } {
  const parsed = new Date(value);

  if (Number.isNaN(parsed.getTime())) {
    return { date: value, time: "" };
  }

  return {
    date: parsed.toLocaleDateString(undefined, {
      year: "numeric",
      month: "short",
      day: "numeric",
    }),
    time: parsed.toLocaleTimeString(undefined, {
      hour: "2-digit",
      minute: "2-digit",
    }),
  };
}

export function AuditLogsView({ workspaceId }: { workspaceId: string }) {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();

  const [workspace, setWorkspace] = useState<Workspace | null>(null);
  const [result, setResult] = useState<PaginatedAuditLogs | null>(null);
  const [status, setStatus] = useState<Status>("loading");
  const [retry, setRetry] = useState(0);

  const [filters, setFilters] = useState<Filters>(EMPTY_FILTERS);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);

  useEffect(() => {
    if (authLoading || !user) {
      return;
    }

    let cancelled = false;

    getWorkspace(workspaceId)
      .then((loaded) => {
        if (!cancelled) {
          setWorkspace(loaded);
        }
      })
      .catch(() => {
        // The header degrades to the workspace id; the log request below
        // already owns the error states for this page.
      });

    return () => {
      cancelled = true;
    };
  }, [workspaceId, user, authLoading]);

  const query = useMemo<AuditLogQuery>(() => {
    const next: AuditLogQuery = { page, per_page: perPage };

    if (filters.action) {
      next.action = filters.action;
    }

    if (filters.entity) {
      next.entity = filters.entity;
    }

    if (filters.userId) {
      next.user_id = Number(filters.userId);
    }

    if (filters.from) {
      next.from = filters.from;
    }

    if (filters.to) {
      next.to = filters.to;
    }

    return next;
  }, [page, perPage, filters]);

  useEffect(() => {
    if (authLoading) {
      return;
    }

    if (!user) {
      router.replace("/auth/login");
      return;
    }

    let cancelled = false;

    listAuditLogs(workspaceId, query)
      .then((loaded) => {
        if (cancelled) {
          return;
        }

        setResult(loaded);
        setStatus("ready");
      })
      .catch((requestError: unknown) => {
        if (cancelled) {
          return;
        }

        if (axios.isAxiosError(requestError)) {
          const code = requestError.response?.status;

          if (code === 403) {
            setStatus("forbidden");
            return;
          }

          if (code === 404) {
            setStatus("missing");
            return;
          }
        }

        setStatus("error");
      });

    return () => {
      cancelled = true;
    };
  }, [workspaceId, user, authLoading, router, query, retry]);

  function updateFilter(key: keyof Filters, value: string) {
    setFilters((current) => ({ ...current, [key]: value }));
    setPage(1);
  }

  function clearFilters() {
    setFilters(EMPTY_FILTERS);
    setPage(1);
  }

  const hasActiveFilters = Object.values(filters).some(Boolean);
  const filterOptions: AuditLogFilterOptions = result?.filters ?? {
    actions: [],
    entities: [],
    users: [],
  };

  if (authLoading || !user || (status === "loading" && !result)) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#F2F0EA] dark:bg-[#121214] font-sans">
        <div className="flex items-center gap-3 text-sm text-[#686873] dark:text-[#b0b0b9]">
          <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
          Loading audit logs…
        </div>
      </main>
    );
  }

  if (status === "forbidden" || status === "missing") {
    return (
      <div className="flex min-h-screen flex-col bg-[#F2F0EA] dark:bg-[#121214] font-sans text-[#20202A] dark:text-[#ececf1]">
        <WorkspaceNav />

        <main className="flex flex-1 items-center justify-center px-6 py-20">
          <section className="w-full max-w-md rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] p-8 text-center shadow-sm sm:p-10">
            <div className="mx-auto mb-6 flex h-12 w-12 items-center justify-center rounded-xl bg-[#FCECEF] dark:bg-[#43212b] text-[#E85D75] dark:text-[#f08098]">
              <svg
                width="22"
                height="22"
                viewBox="0 0 24 24"
                fill="none"
                aria-hidden="true"
              >
                <rect
                  x="5"
                  y="10.5"
                  width="14"
                  height="9"
                  rx="2"
                  stroke="currentColor"
                  strokeWidth="1.6"
                />
                <path
                  d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"
                  stroke="currentColor"
                  strokeWidth="1.6"
                  strokeLinecap="round"
                />
              </svg>
            </div>

            <h1 className="text-2xl font-semibold tracking-[-0.025em]">
              {status === "forbidden"
                ? "Audit logs are owner-only"
                : "Workspace not available"}
            </h1>

            <p className="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
              {status === "forbidden"
                ? "Only the workspace owner can review its activity history."
                : "This workspace may have been deleted, or you don’t have access to it."}
            </p>

            <Link
              href="/workspaces"
              className="mt-7 inline-flex items-center justify-center rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA]"
            >
              Go to my workspaces
            </Link>
          </section>
        </main>
      </div>
    );
  }

  const logs = result?.data ?? [];
  const meta = result?.meta;

  return (
    <div className="flex min-h-screen flex-col bg-[#F2F0EA] dark:bg-[#121214] font-sans text-[#20202A] dark:text-[#ececf1]">
      <WorkspaceNav />

      <main className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-6 py-10 sm:px-10 sm:py-12">
        <header className="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
              Audit logs
            </p>

            <h1 className="mt-2 text-3xl font-semibold tracking-[-0.03em] sm:text-4xl">
              {workspace?.name ?? "Workspace activity"}
            </h1>

            <p className="mt-3 max-w-2xl text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9] sm:text-base">
              Every change made in this workspace, newest first. Filter by
              action, entity, person or date to narrow the timeline.
            </p>
          </div>

          <Link
            href={`/workspaces/${workspaceId}`}
            className="inline-flex w-fit items-center justify-center gap-2 rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-5 py-3 text-sm font-medium text-[#20202A] dark:text-[#ececf1] transition hover:border-[#C9C7D0] dark:hover:border-[#474751]"
          >
            Back to workspace
          </Link>
        </header>

        {/* Filters */}
        <section className="rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] p-5 shadow-sm sm:p-6">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <label className="flex flex-col gap-1.5">
              <span className="text-xs font-semibold uppercase tracking-[0.08em] text-[#9897A1] dark:text-[#8b8b94]">
                Action
              </span>
              <select
                value={filters.action}
                onChange={(event) =>
                  updateFilter("action", event.target.value)
                }
                className="h-10 rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-3 text-sm text-[#20202A] dark:text-[#ececf1] outline-none focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
              >
                <option value="">All actions</option>
                {filterOptions.actions.map((action) => (
                  <option key={action} value={action}>
                    {actionLabel(action)}
                  </option>
                ))}
              </select>
            </label>

            <label className="flex flex-col gap-1.5">
              <span className="text-xs font-semibold uppercase tracking-[0.08em] text-[#9897A1] dark:text-[#8b8b94]">
                Entity
              </span>
              <select
                value={filters.entity}
                onChange={(event) =>
                  updateFilter("entity", event.target.value)
                }
                className="h-10 rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-3 text-sm text-[#20202A] dark:text-[#ececf1] outline-none focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
              >
                <option value="">All entities</option>
                {filterOptions.entities.map((entity) => (
                  <option key={entity} value={entity}>
                    {entityLabel(entity)}
                  </option>
                ))}
              </select>
            </label>

            <label className="flex flex-col gap-1.5">
              <span className="text-xs font-semibold uppercase tracking-[0.08em] text-[#9897A1] dark:text-[#8b8b94]">
                Person
              </span>
              <select
                value={filters.userId}
                onChange={(event) =>
                  updateFilter("userId", event.target.value)
                }
                className="h-10 rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-3 text-sm text-[#20202A] dark:text-[#ececf1] outline-none focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
              >
                <option value="">Anyone</option>
                {filterOptions.users.map((person) => (
                  <option key={person.id} value={person.id}>
                    {person.name}
                  </option>
                ))}
              </select>
            </label>

            <label className="flex flex-col gap-1.5">
              <span className="text-xs font-semibold uppercase tracking-[0.08em] text-[#9897A1] dark:text-[#8b8b94]">
                From
              </span>
              <input
                type="date"
                value={filters.from}
                max={filters.to || undefined}
                onChange={(event) => updateFilter("from", event.target.value)}
                className="h-10 rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-3 text-sm text-[#20202A] dark:text-[#ececf1] outline-none focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
              />
            </label>

            <label className="flex flex-col gap-1.5">
              <span className="text-xs font-semibold uppercase tracking-[0.08em] text-[#9897A1] dark:text-[#8b8b94]">
                To
              </span>
              <input
                type="date"
                value={filters.to}
                min={filters.from || undefined}
                onChange={(event) => updateFilter("to", event.target.value)}
                className="h-10 rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-3 text-sm text-[#20202A] dark:text-[#ececf1] outline-none focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
              />
            </label>
          </div>

          {hasActiveFilters && (
            <button
              type="button"
              onClick={clearFilters}
              className="mt-4 text-sm font-medium text-[#5146E5] transition hover:text-[#4338CA]"
            >
              Clear filters
            </button>
          )}
        </section>

        {/* Results */}
        <section className="overflow-hidden rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] shadow-sm">
          {status === "error" ? (
            <div className="flex min-h-64 flex-col items-center justify-center gap-4 px-6 py-16 text-center">
              <p className="text-sm text-[#686873] dark:text-[#b0b0b9]">
                Could not load the audit logs. Please try again.
              </p>
              <button
                type="button"
                onClick={() => setRetry((value) => value + 1)}
                className="rounded-lg bg-[#5146E5] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[#4338CA]"
              >
                Retry
              </button>
            </div>
          ) : logs.length === 0 ? (
            <div className="flex min-h-64 flex-col items-center justify-center px-6 py-16 text-center">
              <div className="mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-[#EEEDFF] dark:bg-[#262449] text-[#5146E5]">
                <svg
                  width="22"
                  height="22"
                  viewBox="0 0 24 24"
                  fill="none"
                  aria-hidden="true"
                >
                  <path
                    d="M12 7v5l3.5 2"
                    stroke="currentColor"
                    strokeWidth="1.7"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                  <circle
                    cx="12"
                    cy="12"
                    r="8.5"
                    stroke="currentColor"
                    strokeWidth="1.7"
                  />
                </svg>
              </div>

              <h2 className="text-lg font-semibold tracking-tight">
                {hasActiveFilters
                  ? "No logs match these filters"
                  : "No logs yet"}
              </h2>

              <p className="mt-2 max-w-sm text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
                {hasActiveFilters
                  ? "Try widening the date range or clearing a filter."
                  : "Nothing has been recorded in this workspace yet. As soon as someone makes a change, it will show up here."}
              </p>

              {hasActiveFilters && (
                <button
                  type="button"
                  onClick={clearFilters}
                  className="mt-5 rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-2.5 text-sm font-medium text-[#20202A] dark:text-[#ececf1] transition hover:border-[#C9C7D0] dark:hover:border-[#474751] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a]"
                >
                  Clear filters
                </button>
              )}
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] border-collapse text-left text-sm">
                <thead>
                  <tr className="border-b border-[#D7D5DE] dark:border-[#34343c] bg-[#F2F0EA] dark:bg-[#121214] text-xs font-semibold uppercase tracking-[0.06em] text-[#9897A1] dark:text-[#8b8b94]">
                    <th className="px-6 py-3 font-semibold">When</th>
                    <th className="px-6 py-3 font-semibold">Who</th>
                    <th className="px-6 py-3 font-semibold">Action</th>
                    <th className="px-6 py-3 font-semibold">Entity</th>
                    <th className="px-6 py-3 font-semibold">Details</th>
                  </tr>
                </thead>

                <tbody className="divide-y divide-[#E7E6ED] dark:divide-[#2a2a31]">
                  {logs.map((log) => {
                    const { date, time } = formatTimestamp(log.occurred_at);
                    const details = describe(log);

                    return (
                      <tr key={log.id} className="align-top">
                        <td className="whitespace-nowrap px-6 py-4 text-[#20202A] dark:text-[#ececf1]">
                          <span className="font-medium">{date}</span>
                          {time && (
                            <span className="block text-xs text-[#9897A1] dark:text-[#8b8b94]">
                              {time}
                            </span>
                          )}
                        </td>

                        <td className="px-6 py-4">
                          {log.user ? (
                            <>
                              <span className="font-medium text-[#20202A] dark:text-[#ececf1]">
                                {log.user.name}
                              </span>
                              <span className="block text-xs text-[#9897A1] dark:text-[#8b8b94]">
                                {log.user.email}
                              </span>
                            </>
                          ) : (
                            <span className="text-[#9897A1] dark:text-[#8b8b94]">System</span>
                          )}
                        </td>

                        <td className="px-6 py-4">
                          <span
                            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                              ACTION_STYLES[log.action] ??
                              "bg-[#F0EFF4] dark:bg-[#24242a] text-[#686873] dark:text-[#b0b0b9]"
                            }`}
                          >
                            {actionLabel(log.action)}
                          </span>
                        </td>

                        <td className="px-6 py-4 text-[#20202A] dark:text-[#ececf1]">
                          {entityLabel(log.entity_type)}
                          {log.entity_id != null && (
                            <span className="block text-xs text-[#9897A1] dark:text-[#8b8b94]">
                              #{log.entity_id}
                            </span>
                          )}
                        </td>

                        <td className="px-6 py-4 text-[#686873] dark:text-[#b0b0b9]">
                          {details ?? "—"}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          {/* Pagination */}
          {meta && meta.total > 0 && (
            <div className="flex flex-col gap-4 border-t border-[#D7D5DE] dark:border-[#34343c] px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
              <p className="text-sm text-[#686873] dark:text-[#b0b0b9]">
                Showing <span className="font-medium">{meta.from ?? 0}</span>–
                <span className="font-medium">{meta.to ?? 0}</span> of{" "}
                <span className="font-medium">{meta.total}</span>
              </p>

              <div className="flex items-center gap-3">
                <label className="flex items-center gap-2 text-sm text-[#686873] dark:text-[#b0b0b9]">
                  <span className="hidden sm:inline">Per page</span>
                  <select
                    value={perPage}
                    onChange={(event) => {
                      setPerPage(Number(event.target.value));
                      setPage(1);
                    }}
                    className="h-9 rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-2 text-sm text-[#20202A] dark:text-[#ececf1] outline-none focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
                  >
                    {PER_PAGE_OPTIONS.map((option) => (
                      <option key={option} value={option}>
                        {option}
                      </option>
                    ))}
                  </select>
                </label>

                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => setPage((value) => Math.max(1, value - 1))}
                    disabled={meta.current_page <= 1}
                    className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-3.5 py-2 text-sm font-medium text-[#20202A] dark:text-[#ececf1] transition hover:border-[#C9C7D0] dark:hover:border-[#474751] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a] disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    Previous
                  </button>

                  <span className="text-sm text-[#686873] dark:text-[#b0b0b9]">
                    Page{" "}
                    <span className="font-medium">{meta.current_page}</span> of{" "}
                    <span className="font-medium">{meta.last_page}</span>
                  </span>

                  <button
                    type="button"
                    onClick={() => setPage((value) => value + 1)}
                    disabled={meta.current_page >= meta.last_page}
                    className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-3.5 py-2 text-sm font-medium text-[#20202A] dark:text-[#ececf1] transition hover:border-[#C9C7D0] dark:hover:border-[#474751] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a] disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    Next
                  </button>
                </div>
              </div>
            </div>
          )}
        </section>
      </main>
    </div>
  );
}
