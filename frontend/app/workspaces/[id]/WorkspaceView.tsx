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

    setSaving(true);
    setError(null);

    try {
      const updated = await updateWorkspace(workspace.id, name.trim());
      setWorkspace(updated);
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
      <main className="flex min-h-screen items-center justify-center bg-[#F1F0EB] dark:bg-[#15170F]">
        <p className="text-sm text-[#6B6F64] dark:text-[#9C9F94]">Loading…</p>
      </main>
    );
  }

  if (status === "missing" || !workspace) {
    return (
      <div className="flex min-h-screen flex-col bg-[#F1F0EB] dark:bg-[#15170F]">
        <WorkspaceNav />
        <main className="flex flex-1 flex-col items-center justify-center gap-4 px-6">
          <h1 className="font-serif text-2xl tracking-tight text-[#1E211D] dark:text-[#E7E5DC]">
            Workspace not available
          </h1>
          <p className="max-w-sm text-center text-sm text-[#6B6F64] dark:text-[#9C9F94]">
            This workspace may have been deleted, or you don&rsquo;t have access
            to it.
          </p>
          <Link
            href="/dashboard"
            className="rounded-full bg-[#3B4A6B] px-4 py-2 text-sm text-white hover:bg-[#2F3C57]"
          >
            Go to my workspaces
          </Link>
        </main>
      </div>
    );
  }

  return (
    <div className="flex min-h-screen flex-col bg-[#F1F0EB] font-sans text-[#1E211D] dark:bg-[#15170F] dark:text-[#E7E5DC]">
      <WorkspaceNav />

      <main className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-6 py-12 sm:px-10">
        {/* Header */}
        <section className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="font-serif text-3xl tracking-tight">
              {workspace.name}
            </h1>
            {workspace.role && <RoleBadge role={workspace.role} />}
          </div>
          <p className="text-sm text-[#6B6F64] dark:text-[#9C9F94]">
            Owned by {workspace.owner.name} · created{" "}
            {new Date(workspace.created_at).toLocaleDateString()}
          </p>
        </section>

        {/* Content placeholder */}
        <section className="rounded-2xl border border-dashed border-[#C8C5B8] p-10 text-center dark:border-[#3A3D30]">
          <h2 className="font-serif text-xl">Your knowledge lives here</h2>
          <p className="mx-auto mt-2 max-w-md text-sm text-[#6B6F64] dark:text-[#9C9F94]">
            Entries, tags and notes in this workspace will appear here soon.
          </p>
        </section>

        {/* Members */}
        <section className="flex flex-col gap-4 rounded-2xl border border-[#D8D5C9] bg-white p-8 dark:border-[#2C2E23] dark:bg-[#1B1E24]">
          <h2 className="font-serif text-xl">Members</h2>
          <ul className="flex flex-col divide-y divide-[#E2E0D4] dark:divide-[#2C2E23]">
            {(workspace.members ?? []).map((member) => (
              <li
                key={member.id}
                className="flex items-center justify-between gap-4 py-3"
              >
                <div className="flex min-w-0 flex-col">
                  <span className="truncate font-medium">{member.name}</span>
                  <span className="truncate text-sm text-[#8B8A7D] dark:text-[#71715F]">
                    {member.email}
                  </span>
                </div>
                <RoleBadge role={member.pivot.role} />
              </li>
            ))}
          </ul>
        </section>

        {/* Settings */}
        {canRename && (
          <section className="flex flex-col gap-4 rounded-2xl border border-[#D8D5C9] bg-white p-8 dark:border-[#2C2E23] dark:bg-[#1B1E24]">
            <h2 className="font-serif text-xl">Settings</h2>

            <form onSubmit={handleRename} className="flex max-w-md flex-col gap-4">
              <div className="flex flex-col gap-1.5">
                <label
                  htmlFor="workspace-name"
                  className="text-sm text-[#4A4D45] dark:text-[#9C9F94]"
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
                  className="w-full border-b border-[#C8C5B8] bg-transparent py-1.5 text-[#1E211D] focus:border-[#3B4A6B] focus:outline-none dark:border-[#3A3D30] dark:text-[#E7E5DC] dark:focus:border-[#8CA0D8]"
                />
              </div>

              {error && <p className="text-sm text-[#B3452C]">{error}</p>}

              <button
                type="submit"
                disabled={saving}
                className="w-fit rounded-full bg-[#3B4A6B] px-4 py-2 text-sm text-white hover:bg-[#2F3C57] disabled:opacity-50"
              >
                {saving ? "Saving…" : "Save changes"}
              </button>
            </form>
          </section>
        )}

        {/* Danger zone (owner only) */}
        {canDelete && (
          <section className="flex flex-col gap-3 rounded-2xl border border-[#E2B7A8] bg-[#FBF4F1] p-8 dark:border-[#5C3A30] dark:bg-[#231512]">
            <h2 className="font-serif text-xl text-[#B3452C]">Danger zone</h2>
            <p className="text-sm text-[#6B6F64] dark:text-[#9C9F94]">
              Deleting a workspace removes it and all of its content. This
              cannot be undone.
            </p>
            <button
              onClick={handleDelete}
              disabled={deleting}
              className="w-fit rounded-full border border-[#B3452C] px-4 py-2 text-sm text-[#B3452C] hover:bg-[#B3452C] hover:text-white disabled:opacity-50"
            >
              {deleting ? "Deleting…" : "Delete workspace"}
            </button>
          </section>
        )}
      </main>
    </div>
  );
}