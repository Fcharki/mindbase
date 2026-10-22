"use client";

import axios from "axios";
import Link from "next/link";
import { useState, type FormEvent } from "react";

import { RoleBadge } from "@/app/features/workspaces/RoleBadge";
import {
  deleteWorkspace,
  updateWorkspace,
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

/**
 * One workspace in the list, with management controls when the signed-in user
 * owns it.
 *
 * Ownership is decided by the caller from owner_id rather than from the role
 * the API reports, because the two can disagree: workspace_members carries its
 * own 'owner' role, but workspaces.owner_id is the column WorkspacePolicy
 * actually tests, so it is the one that decides who may rename or delete.
 */
export function WorkspaceCard({
  workspace,
  isOwner,
  onRenamed,
  onDeleted,
}: {
  workspace: Workspace;
  isOwner: boolean;
  onRenamed: (workspace: Workspace) => void;
  onDeleted: (workspaceId: number) => void;
}) {
  const [renaming, setRenaming] = useState(false);
  const [name, setName] = useState(workspace.name);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleRename(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const trimmedName = name.trim();

    if (!trimmedName) {
      setError("Workspace name cannot be empty.");
      return;
    }

    setSaving(true);
    setError(null);

    try {
      onRenamed(await updateWorkspace(workspace.id, trimmedName));
      setRenaming(false);
    } catch {
      setError("Could not rename the workspace.");
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    // Names the workspace and states what goes with it. Deleting cascades to
    // every entry, summary, embedded chunk and uploaded file, and there is no
    // soft delete to fall back on, so the cost of a stray click is total.
    const confirmed = window.confirm(
      `Delete "${workspace.name}"?\n\n` +
        `This permanently removes ${workspace.entries_count ?? 0} ` +
        `${workspace.entries_count === 1 ? "entry" : "entries"}, their ` +
        `summaries and attachments, and every question asked in it.\n\n` +
        `This cannot be undone.`,
    );

    if (!confirmed) {
      return;
    }

    setDeleting(true);
    setError(null);

    try {
      await deleteWorkspace(workspace.id);
      onDeleted(workspace.id);
    } catch (requestError: unknown) {
      const message = axios.isAxiosError(requestError)
        ? (requestError.response?.data as { message?: string } | undefined)
            ?.message
        : undefined;

      setError(message ?? "Could not delete the workspace.");
      setDeleting(false);
    }
  }

  async function handleCopyInviteLink() {
    if (!workspace.invite_code) {
      return;
    }

    const link = `${window.location.origin}/join/${workspace.invite_code}`;

    try {
      await navigator.clipboard.writeText(link);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      setError("Could not copy the invite link.");
    }
  }

  return (
    <article className="flex flex-col rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] p-6 shadow-sm transition hover:shadow-md">
      <div className="flex items-start justify-between gap-4">
        <WorkspaceMark />
        <RoleBadge role={workspace.role} />
      </div>

      {renaming ? (
        <form onSubmit={handleRename} className="mt-5">
          <label
            htmlFor={`rename-${workspace.id}`}
            className="sr-only"
          >
            Workspace name
          </label>
          <input
            id={`rename-${workspace.id}`}
            type="text"
            required
            maxLength={255}
            autoComplete="off"
            autoFocus
            value={name}
            onChange={(event) => setName(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === "Escape") {
                setName(workspace.name);
                setRenaming(false);
              }
            }}
            className="w-full rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-3 py-2 text-lg font-semibold tracking-tight text-[#20202A] dark:text-[#ececf1] outline-none transition focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
          />
          <div className="mt-3 flex items-center gap-2">
            <button
              type="submit"
              disabled={saving}
              className="rounded-lg bg-[#5146E5] px-3.5 py-2 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {saving ? "Saving…" : "Save"}
            </button>
            <button
              type="button"
              onClick={() => {
                setName(workspace.name);
                setRenaming(false);
                setError(null);
              }}
              disabled={saving}
              className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-3.5 py-2 text-sm font-medium text-[#20202A] dark:text-[#ececf1] transition hover:bg-[#F2F0EA] dark:hover:bg-[#24242a] disabled:cursor-not-allowed disabled:opacity-50"
            >
              Cancel
            </button>
          </div>
        </form>
      ) : (
        <Link
          href={`/workspaces/${workspace.id}`}
          className="mt-5 text-xl font-semibold tracking-tight text-[#20202A] dark:text-[#ececf1] transition hover:text-[#5146E5]"
        >
          {workspace.name}
        </Link>
      )}

      <p className="mt-1 text-sm text-[#686873] dark:text-[#b0b0b9]">
        {isOwner ? "You own this workspace" : `Owned by @${workspace.owner.username}`}
      </p>

      <p className="mt-3 text-sm text-[#9897A1] dark:text-[#8b8b94]">
        {[
          `${workspace.entries_count ?? 0} ${
            workspace.entries_count === 1 ? "entry" : "entries"
          }`,
          workspace.members
            ? `${workspace.members.length} ${
                workspace.members.length === 1 ? "member" : "members"
              }`
            : null,
        ]
          .filter(Boolean)
          .join(" · ")}
      </p>

      {error && (
        <p
          role="alert"
          className="mt-4 rounded-xl border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-3.5 py-2.5 text-sm text-[#B13D55] dark:text-[#f0a0b0]"
        >
          {error}
        </p>
      )}

      <div className="mt-auto flex flex-wrap items-center gap-2 pt-6">
        <Link
          href={`/workspaces/${workspace.id}/entries`}
          className="rounded-lg bg-[#5146E5] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[#4338CA]"
        >
          Open entries
        </Link>

        {isOwner && (
          <>
            <Link
              href={`/workspaces/${workspace.id}`}
              className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-2.5 text-sm font-medium text-[#20202A] dark:text-[#ececf1] transition hover:border-[#C9C7D0] dark:hover:border-[#474751] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a]"
            >
              Manage
            </Link>

            <Link
              href={`/workspaces/${workspace.id}/logs`}
              className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-2.5 text-sm font-medium text-[#20202A] dark:text-[#ececf1] transition hover:border-[#C9C7D0] dark:hover:border-[#474751] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a]"
            >
              Logs
            </Link>

            <button
              type="button"
              onClick={() => {
                setRenaming(true);
                setError(null);
              }}
              disabled={renaming || deleting}
              className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-2.5 text-sm font-medium text-[#20202A] dark:text-[#ececf1] transition hover:border-[#C9C7D0] dark:hover:border-[#474751] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a] disabled:cursor-not-allowed disabled:opacity-50"
            >
              Rename
            </button>

            {workspace.invite_code && (
              <button
                type="button"
                onClick={handleCopyInviteLink}
                className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-2.5 text-sm font-medium text-[#20202A] dark:text-[#ececf1] transition hover:border-[#C9C7D0] dark:hover:border-[#474751] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a]"
              >
                {copied ? "Link copied" : "Copy invite link"}
              </button>
            )}

            <button
              type="button"
              onClick={handleDelete}
              disabled={deleting}
              className="rounded-lg border border-[#F2C9D1] dark:border-[#4a2630] bg-white dark:bg-[#1b1b1f] px-4 py-2.5 text-sm font-medium text-[#B13D55] dark:text-[#f0a0b0] transition hover:bg-[#FCECEF] dark:hover:bg-[#4a2630] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {deleting ? "Deleting…" : "Delete"}
            </button>
          </>
        )}
      </div>
    </article>
  );
}
