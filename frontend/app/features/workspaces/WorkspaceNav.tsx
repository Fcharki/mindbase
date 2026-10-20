"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { UserRound } from "lucide-react";

import { useAuth } from "@/app/Providers/AuthProvider";
import { NotificationBell } from "@/app/features/notifications/NotificationBell";
import { ThemeToggle } from "@/app/components/ThemeToggle";
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
      (workspace) =>
        pathname === `/workspaces/${workspace.id}` ||
        pathname.startsWith(`/workspaces/${workspace.id}/`),
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
    <header className="border-b border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f]">
      <div className="mx-auto flex w-full max-w-6xl items-center justify-between gap-6 px-6 py-3.5 sm:px-10">
        {/* Brand + navigation */}
        <div className="flex min-w-0 items-center gap-8">
          <Link
            href="/"
            className="flex shrink-0 items-center gap-2 text-[#20202A] dark:text-[#ececf1]"
          >
            <Logomark />

            <span className="text-lg font-semibold tracking-tight">
              Mindbase
            </span>
          </Link>

          <nav className="hidden items-center gap-1 sm:flex">
            <Link
              href="/workspaces"
              className={`rounded-lg px-3 py-2 text-sm font-medium transition ${
                pathname === "/workspaces"
                  ? "bg-[#EEEDFF] dark:bg-[#262449] text-[#5146E5]"
                  : "text-[#686873] dark:text-[#b0b0b9] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a] hover:text-[#20202A]"
              }`}
            >
              Workspaces
            </Link>
            <Link
              href="/dashboard"
              className={`rounded-lg px-3 py-2 text-sm font-medium transition ${
                pathname === "/dashboard"
                  ? "bg-[#EEEDFF] dark:bg-[#262449] text-[#5146E5]"
                  : "text-[#686873] dark:text-[#b0b0b9] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a] hover:text-[#20202A]"
              }`}
            >
              Directory
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
                className="h-9 max-w-[220px] appearance-none rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-[#F2F0EA] dark:bg-[#121214] py-0 pl-3 pr-9 text-sm font-medium text-[#20202A] dark:text-[#ececf1] outline-none transition hover:border-[#C9C7D0] dark:hover:border-[#474751] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
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
                className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[#686873] dark:text-[#b0b0b9]"
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

          <NotificationBell />

          <div className="hidden h-6 w-px bg-[#D7D5DE] dark:bg-[#333] sm:block" />

          <Link
            href="/profile"
            className="hidden max-w-[160px] truncate text-sm font-medium text-[#686873] transition hover:text-[#5146E5] dark:text-[#b0b0b9] dark:hover:text-[#a5b4fc] sm:block"
          >
            {user?.name}
          </Link>

          <div className="flex items-center gap-2">
            <Link
              href="/profile"
              aria-label="Your profile"
              title="Your profile"
              className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[#D7D5DE] text-[#5C5A66] transition hover:bg-[#F2F0EA] hover:text-[#1A1A1A] dark:border-[#333] dark:text-[#B8B6C2] dark:hover:bg-[#232329] dark:hover:text-white sm:hidden"
            >
              <UserRound className="h-4 w-4" aria-hidden="true" />
            </Link>

            <ThemeToggle />
            <button
              onClick={handleLogout}
              className="rounded-lg border border-[#D7D5DE] bg-white px-3.5 py-2 text-sm font-medium text-[#686873] transition hover:border-[#C9C7D0] dark:hover:border-[#474751] hover:bg-[#F2F0EA] hover:text-[#20202A] focus:outline-none focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a] dark:border-[#333] dark:bg-[#232329] dark:text-[#ececf1] dark:hover:bg-[#2c2c33]"
            >
              Log out
            </button>
          </div>
        </div>
      </div>

      {/* Mobile workspace switcher */}
      {effectiveWorkspaces.length > 0 && (
        <div className="flex items-center gap-2 border-t border-[#E7E6ED] dark:border-[#2a2a31] px-6 py-3 sm:hidden">
          <div className="relative min-w-0 flex-1">
            <select
              aria-label="Switch workspace"
              value={current?.id ?? ""}
              onChange={(event) => {
                if (event.target.value) {
                  router.push(`/workspaces/${event.target.value}`);
                }
              }}
              className="h-10 w-full appearance-none rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-[#F2F0EA] dark:bg-[#121214] px-3 pr-9 text-sm font-medium text-[#20202A] dark:text-[#ececf1] outline-none focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
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
              className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[#686873] dark:text-[#b0b0b9]"
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

          <Link
            href="/workspaces/new"
            aria-label="Create a new workspace"
            className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[#5146E5] text-white transition hover:bg-[#4338CA]"
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
          </Link>
        </div>
      )}
    </header>
  );
}
