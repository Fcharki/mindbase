"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import { listWorkspaces, Workspace } from "@/lib/workspaces";

function Logomark() {
  return (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M4 20V6.5C4 5.12 5.12 4 6.5 4c1.2 0 2.23.83 2.45 2M9 20V8c0-1.66 1.34-3 3-3s3 1.34 3 3M15 20v-9c0-1.38 1.12-2.5 2.5-2.5S20 9.62 20 11v9"
        stroke="#3B4A6B"
        strokeWidth="1.6"
        strokeLinecap="round"
      />
    </svg>
  );
}

export function WorkspaceNav() {
  const router = useRouter();
  const pathname = usePathname();
  const { user, logout } = useAuth();
  const [workspaces, setWorkspaces] = useState<Workspace[]>([]);

  const current =
    workspaces.find(
      (workspace) => pathname === `/workspaces/${workspace.id}`,
    ) ?? null;

  useEffect(() => {
    listWorkspaces()
      .then(setWorkspaces)
      .catch(() => setWorkspaces([]));
  }, []);

  async function handleLogout() {
    await logout();
    router.replace("/");
  }

  return (
    <header className="border-b border-[#D8D5C9] bg-[#F1F0EB] dark:border-[#2C2E23] dark:bg-[#15170F]">
      <div className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-6 py-4 sm:px-10">
        <div className="flex items-center gap-8">
          <Link href="/" className="flex items-center gap-2">
            <Logomark />
            <span className="font-serif text-lg tracking-tight text-[#1E211D] dark:text-[#E7E5DC]">
              Mindbase
            </span>
          </Link>

          <nav className="hidden items-center gap-6 text-sm sm:flex">
            <Link
              href="/dashboard"
              className="text-[#4A4D45] hover:text-[#1E211D] dark:text-[#9C9F94] dark:hover:text-[#E7E5DC]"
            >
              Workspaces
            </Link>
          </nav>
        </div>

        <div className="flex items-center gap-4">
          {workspaces.length > 0 && (
            <select
              aria-label="Switch workspace"
              value={current?.id ?? ""}
              onChange={(event) =>
                router.push(`/workspaces/${event.target.value}`)
              }
              className="rounded-full border border-[#C8C5B8] bg-white px-3 py-1.5 text-sm text-[#1E211D] focus:border-[#3B4A6B] focus:outline-none dark:border-[#3A3D30] dark:bg-[#1B1E24] dark:text-[#E7E5DC]"
            >
              {!current && <option value="">Switch workspace…</option>}
              {workspaces.map((workspace) => (
                <option key={workspace.id} value={workspace.id}>
                  {workspace.name}
                </option>
              ))}
            </select>
          )}

          <span className="hidden text-sm text-[#6B6F64] sm:block dark:text-[#9C9F94]">
            {user?.name}
          </span>

          <button
            onClick={handleLogout}
            className="rounded-full border border-[#C8C5B8] px-4 py-1.5 text-sm text-[#4A4D45] hover:bg-white dark:border-[#3A3D30] dark:text-[#9C9F94] dark:hover:bg-[#1B1E24]"
          >
            Log out
          </button>
        </div>
      </div>
    </header>
  );
}