"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import { listWorkspaces, Workspace } from "@/lib/workspaces";

function Logomark() {
  return (
    <svg
      width="23"
      height="23"
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
  );
}

export function WorkspaceNav({
  workspaces: externalWorkspaces,
}: {
  workspaces?: Workspace[];
}) {
  const router = useRouter();
  const pathname = usePathname();
  const { user, logout } = useAuth();

  const [workspaces, setWorkspaces] = useState<Workspace[]>([]);

  const hasExternal = externalWorkspaces !== undefined;
  const effectiveWorkspaces = hasExternal ? externalWorkspaces : workspaces;

  const current =
    effectiveWorkspaces.find(
      (workspace) => pathname === `/workspaces/${workspace.id}`,
    ) ?? null;

  useEffect(() => {
    if (hasExternal) {
      return;
    }

    listWorkspaces()
      .then(setWorkspaces)
      .catch(() => setWorkspaces([]));
  }, [hasExternal]);

  async function handleLogout() {
    await logout();
    router.replace("/");
  }

  return (
    <header className="border-b border-[#DDDCE3] bg-white">
      <div className="mx-auto flex w-full max-w-6xl items-center justify-between gap-6 px-6 py-3.5 sm:px-10">
        {/* Brand + navigation */}
        <div className="flex min-w-0 items-center gap-8">
          <Link
            href="/"
            className="flex shrink-0 items-center gap-2 text-[#20202A]"
          >
            <Logomark />

            <span className="text-lg font-semibold tracking-tight">
              Mindbase
            </span>
          </Link>

          <nav className="hidden items-center gap-1 sm:flex">
            <Link
              href="/dashboard"
              className={`rounded-lg px-3 py-2 text-sm font-medium transition ${
                pathname === "/dashboard"
                  ? "bg-[#EEEDFF] text-[#5146E5]"
                  : "text-[#686873] hover:bg-[#F7F6F2] hover:text-[#20202A]"
              }`}
            >
              Workspaces
            </Link>
          </nav>
        </div>

        {/* Workspace + account */}
        <div className="flex min-w-0 items-center gap-3">
          {effectiveWorkspaces.length > 0 && (
            <div className="relative hidden sm:block">
              <select
                aria-label="Switch workspace"
                value={current?.id ?? ""}
                onChange={(event) => {
                  if (event.target.value) {
                    router.push(`/workspaces/${event.target.value}`);
                  }
                }}
                className="h-9 max-w-[220px] appearance-none rounded-lg border border-[#DDDCE3] bg-[#F7F6F2] py-0 pl-3 pr-9 text-sm font-medium text-[#20202A] outline-none transition hover:border-[#C9C7D0] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF]"
              >
                {!current && (
                  <option value="">Switch workspace…</option>
                )}

                {effectiveWorkspaces.map((workspace) => (
                  <option key={workspace.id} value={workspace.id}>
                    {workspace.name}
                  </option>
                ))}
              </select>

              <svg
                className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[#686873]"
                width="14"
                height="14"
                viewBox="0 0 24 24"
                fill="none"
                aria-hidden="true"
              >
                <path
                  d="M7 10l5 5 5-5"
                  stroke="currentColor"
                  strokeWidth="1.7"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </svg>
            </div>
          )}

          <div className="hidden h-6 w-px bg-[#DDDCE3] sm:block" />

          <div className="hidden max-w-[160px] truncate text-sm font-medium text-[#686873] sm:block">
            {user?.name}
          </div>

          <button
            onClick={handleLogout}
            className="rounded-lg border border-[#DDDCE3] bg-white px-3.5 py-2 text-sm font-medium text-[#686873] transition hover:border-[#C9C7D0] hover:bg-[#F7F6F2] hover:text-[#20202A] focus:outline-none focus:ring-2 focus:ring-[#EEEDFF]"
          >
            Log out
          </button>
        </div>
      </div>

      {/* Mobile workspace switcher */}
      {effectiveWorkspaces.length > 0 && (
        <div className="border-t border-[#EEEDEF] px-6 py-3 sm:hidden">
          <div className="relative">
            <select
              aria-label="Switch workspace"
              value={current?.id ?? ""}
              onChange={(event) => {
                if (event.target.value) {
                  router.push(`/workspaces/${event.target.value}`);
                }
              }}
              className="h-10 w-full appearance-none rounded-lg border border-[#DDDCE3] bg-[#F7F6F2] px-3 pr-9 text-sm font-medium text-[#20202A] outline-none focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF]"
            >
              {!current && (
                <option value="">Switch workspace…</option>
              )}

              {effectiveWorkspaces.map((workspace) => (
                <option key={workspace.id} value={workspace.id}>
                  {workspace.name}
                </option>
              ))}
            </select>

            <svg
              className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[#686873]"
              width="14"
              height="14"
              viewBox="0 0 24 24"
              fill="none"
              aria-hidden="true"
            >
              <path
                d="M7 10l5 5 5-5"
                stroke="currentColor"
                strokeWidth="1.7"
                strokeLinecap="round"
                strokeLinejoin="round"
              />
            </svg>
          </div>
        </div>
      )}
    </header>
  );
}
