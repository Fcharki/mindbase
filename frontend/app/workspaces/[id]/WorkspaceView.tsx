"use client";

import axios from "axios";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import { RoleBadge } from "@/app/features/workspaces/RoleBadge";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import {
  deleteWorkspace,
  getWorkspace,
  updateWorkspace,
  Workspace,
} from "@/lib/workspaces";

type Status = "loading" | "ready" | "missing";

function WorkspaceMark() {
  return (
    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-[#EEEDFF]">
      <svg
        width="22"
        height="22"
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

export function WorkspaceView({ workspaceId }: { workspaceId: string }) {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();

  const [workspace, setWorkspace] = useState<Workspace | null>(null);
  const [status, setStatus] = useState<Status>("loading");
  const [name, setName] = useState("");
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [error, setError] = useState<string | null>(null);

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
        setName(result.name);
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

  async function handleRename(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!workspace) {
      return;
    }

    const trimmedName = name.trim();

    if (!trimmedName) {
      setError("Workspace name cannot be empty.");
      return;
    }

    setSaving(true);
    setError(null);

    try {
      const updated = await updateWorkspace(workspace.id, trimmedName);
      setWorkspace(updated);
      setName(updated.name);
    } catch {
      setError("Could not save the workspace name.");
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    if (!workspace) {
      return;
    }

    const confirmed = window.confirm(
      `Delete "${workspace.name}"? This cannot be undone.`,
    );

    if (!confirmed) {
      return;
    }

    setDeleting(true);
    setError(null);

    try {
      await deleteWorkspace(workspace.id);
      router.replace("/dashboard");
    } catch {
      setDeleting(false);
      setError("Could not delete the workspace.");
    }
  }

  const canRename = workspace?.role === "owner" || workspace?.role === "admin";
  const canDelete = workspace?.role === "owner";

  if (status === "loading") {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#F7F6F2] font-sans">
        <div className="flex items-center gap-3 text-sm text-[#686873]">
          <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
          Loading workspace…
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
                <circle
                  cx="12"
                  cy="16"
                  r="0.9"
                  fill="currentColor"
                />
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
        {/* Workspace header */}
        <section className="rounded-2xl border border-[#DDDCE3] bg-white p-7 shadow-sm sm:p-8">
          <div className="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
            <div className="flex min-w-0 items-start gap-4">
              <WorkspaceMark />

              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-3">
                  <h1 className="truncate text-3xl font-semibold tracking-[-0.03em]">
                    {workspace.name}
                  </h1>

                  {workspace.role && <RoleBadge role={workspace.role} />}
                </div>

                <p className="mt-2 text-sm leading-relaxed text-[#686873]">
                  Owned by {workspace.owner.name} · created{" "}
                  {new Date(workspace.created_at).toLocaleDateString()}
                </p>
              </div>
            </div>

            <span className="hidden text-sm text-[#9897A1] sm:block">
              Workspace
            </span>
          </div>
        </section>

        {/* Knowledge area */}
        <section className="rounded-2xl border border-[#DDDCE3] bg-white shadow-sm">
          <div className="border-b border-[#DDDCE3] px-7 py-5 sm:px-8">
            <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
              Knowledge
            </p>

            <h2 className="mt-1 text-xl font-semibold tracking-tight">
              Your workspace
            </h2>
          </div>

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
              Your knowledge lives here
            </h3>

            <p className="mt-2 max-w-md text-sm leading-relaxed text-[#686873]">
              Entries, tags, and notes in this workspace will appear here soon.
            </p>
          </div>
        </section>

        {/* Members */}
        <section className="rounded-2xl border border-[#DDDCE3] bg-white shadow-sm">
          <div className="border-b border-[#DDDCE3] px-7 py-5 sm:px-8">
            <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
              People
            </p>

            <h2 className="mt-1 text-xl font-semibold tracking-tight">
              Members
            </h2>
          </div>

          <ul className="divide-y divide-[#EEEDEF] px-7 sm:px-8">
            {(workspace.members ?? []).map((member) => (
              <li
                key={member.id}
                className="flex items-center justify-between gap-4 py-4"
              >
                <div className="flex min-w-0 items-center gap-3">
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#EEEDFF] text-sm font-semibold text-[#5146E5]">
                    {member.name.charAt(0).toUpperCase()}
                  </div>

                  <div className="flex min-w-0 flex-col">
                    <span className="truncate text-sm font-medium text-[#20202A]">
                      {member.name}
                    </span>

                    <span className="truncate text-sm text-[#9897A1]">
                      {member.email}
                    </span>
                  </div>
                </div>

                <RoleBadge role={member.pivot.role} />
              </li>
            ))}
          </ul>
        </section>

        {/* Settings */}
        {canRename && (
          <section className="rounded-2xl border border-[#DDDCE3] bg-white shadow-sm">
            <div className="border-b border-[#DDDCE3] px-7 py-5 sm:px-8">
              <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
                Workspace
              </p>

              <h2 className="mt-1 text-xl font-semibold tracking-tight">
                Settings
              </h2>
            </div>

            <div className="px-7 py-7 sm:px-8">
              <form
                onSubmit={handleRename}
                className="flex max-w-lg flex-col gap-5"
              >
                <div className="flex flex-col gap-2">
                  <label
                    htmlFor="workspace-name"
                    className="text-sm font-medium text-[#20202A]"
                  >
                    Workspace name
                  </label>

                  <input
                    id="workspace-name"
                    type="text"
                    required
                    maxLength={255}
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    className="w-full rounded-lg border border-[#DDDCE3] bg-white px-4 py-3 text-sm text-[#20202A] outline-none transition placeholder:text-[#A3A2AC] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF]"
                  />
                </div>

                {error && (
                  <p
                    role="alert"
                    className="rounded-lg border border-[#F2C9D1] bg-[#FCECEF] px-4 py-3 text-sm leading-relaxed text-[#B13D55]"
                  >
                    {error}
                  </p>
                )}

                <button
                  type="submit"
                  disabled={saving}
                  className="w-fit rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {saving ? "Saving…" : "Save changes"}
                </button>
              </form>
            </div>
          </section>
        )}

        {/* Danger zone */}
        {canDelete && (
          <section className="rounded-2xl border border-[#F2C9D1] bg-[#FFF8F9] shadow-sm">
            <div className="border-b border-[#F2C9D1] px-7 py-5 sm:px-8">
              <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#E85D75]">
                Advanced
              </p>

              <h2 className="mt-1 text-xl font-semibold tracking-tight text-[#B13D55]">
                Danger zone
              </h2>
            </div>

            <div className="flex flex-col gap-4 px-7 py-7 sm:px-8">
              <div>
                <p className="text-sm font-medium text-[#20202A]">
                  Delete this workspace
                </p>

                <p className="mt-1 max-w-2xl text-sm leading-relaxed text-[#686873]">
                  Deleting a workspace removes it and all of its content. This
                  action cannot be undone.
                </p>
              </div>

              {error && (
                <p
                  role="alert"
                  className="rounded-lg border border-[#F2C9D1] bg-white px-4 py-3 text-sm leading-relaxed text-[#B13D55]"
                >
                  {error}
                </p>
              )}

              <button
                onClick={handleDelete}
                disabled={deleting}
                className="w-fit rounded-lg border border-[#E85D75] px-5 py-3 text-sm font-medium text-[#B13D55] transition hover:bg-[#E85D75] hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
              >
                {deleting ? "Deleting…" : "Delete workspace"}
              </button>
            </div>
          </section>
        )}
      </main>
    </div>
  );
}
