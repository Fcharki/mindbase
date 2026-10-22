"use client";

import axios from "axios";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import { RoleBadge } from "@/app/features/workspaces/RoleBadge";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import {
  OwnerAvatar,
  OwnerProfileDialog,
} from "@/app/features/workspaces/OwnerProfileDialog";
import { EntryList } from "@/app/features/knowledge/components/EntryList";
import { SearchPanel } from "@/app/features/search/SearchPanel";
import { AssistantPanel } from "@/app/features/assistant/AssistantPanel";
import { listEntries, KnowledgeEntry } from "@/lib/entries";
import { permissionsFor } from "@/lib/permissions";
import {
  addWorkspaceMember,
  approveJoinRequest,
  deleteWorkspace,
  getWorkspace,
  listJoinRequests,
  MembershipRequest,
  rejectJoinRequest,
  removeWorkspaceMember,
  updateWorkspace,
  updateWorkspaceMemberRole,
  Workspace,
  WorkspaceRole,
} from "@/lib/workspaces";

type Status = "loading" | "ready" | "missing";

function WorkspaceMark() {
  return (
    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-[#EEEDFF] dark:bg-[#262449]">
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
  const [entries, setEntries] = useState<KnowledgeEntry[]>([]);
  const [status, setStatus] = useState<Status>("loading");
  const [name, setName] = useState("");
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [joinRequests, setJoinRequests] = useState<MembershipRequest[]>([]);
  const [memberEmail, setMemberEmail] = useState("");
  const [memberRole, setMemberRole] = useState<WorkspaceRole>("member");
  const [memberError, setMemberError] = useState<string | null>(null);
  const [addingMember, setAddingMember] = useState(false);
  const [requestsError, setRequestsError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [ownerProfileOpen, setOwnerProfileOpen] = useState(false);

  const permissions = permissionsFor(workspace?.role, workspace?.is_member);
  const isOwner = permissions.isOwner;
  const canWrite = permissions.canContribute;

  useEffect(() => {
    if (authLoading) {
      return;
    }

    if (!user) {
      router.replace("/auth/login");
      return;
    }

    let cancelled = false;

    Promise.all([getWorkspace(workspaceId), listEntries(workspaceId)])
      .then(async ([workspaceResult, entryResult]) => {
        if (cancelled) {
          return;
        }

        setWorkspace(workspaceResult);
        setName(workspaceResult.name);
        setEntries(entryResult);
        setStatus("ready");

        if (workspaceResult.role === "owner") {
          try {
            const requests = await listJoinRequests(workspaceId);

            if (!cancelled) {
              setJoinRequests(requests);
            }
          } catch {
            if (!cancelled) {
              setRequestsError("Could not load join requests.");
            }
          }
        }
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
      router.replace("/workspaces");
    } catch {
      setDeleting(false);
      setError("Could not delete the workspace.");
    }
  }

  async function handleAddMember(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!workspace) {
      return;
    }

    const email = memberEmail.trim();

    if (!email) {
      setMemberError("Please enter an email address.");
      return;
    }

    setAddingMember(true);
    setMemberError(null);

    try {
      await addWorkspaceMember(workspace.id, email, memberRole);
      const [updatedWorkspace, requests] = await Promise.all([
        getWorkspace(workspace.id),
        listJoinRequests(workspace.id),
      ]);

      setWorkspace(updatedWorkspace);
      setJoinRequests(requests);
      setMemberEmail("");
    } catch (requestError: unknown) {
      const message = axios.isAxiosError(requestError)
        ? (requestError.response?.data as { message?: string } | undefined)
            ?.message
        : undefined;

      setMemberError(message ?? "Could not add that member.");
    } finally {
      setAddingMember(false);
    }
  }

  async function handleRoleChange(memberId: number, role: WorkspaceRole) {
    if (!workspace) {
      return;
    }

    setMemberError(null);

    try {
      const updated = await updateWorkspaceMemberRole(
        workspace.id,
        memberId,
        role,
      );

      setWorkspace({
        ...workspace,
        members: (workspace.members ?? []).map((member) =>
          member.id === memberId
            ? { ...member, pivot: { role: updated.pivot.role } }
            : member,
        ),
      });
    } catch {
      setMemberError("Could not change that member\u2019s role.");
    }
  }

  async function handleRemoveMember(memberId: number) {
    if (!workspace) {
      return;
    }

    if (!window.confirm("Remove this member from the workspace?")) {
      return;
    }

    setMemberError(null);

    try {
      await removeWorkspaceMember(workspace.id, memberId);
      setWorkspace({
        ...workspace,
        members: (workspace.members ?? []).filter(
          (member) => member.id !== memberId,
        ),
      });
    } catch {
      setMemberError("Could not remove that member.");
    }
  }

  async function handleApproveRequest(
    requestId: number,
    role: WorkspaceRole,
  ) {
    if (!workspace) {
      return;
    }

    setRequestsError(null);

    try {
      await approveJoinRequest(workspace.id, requestId, role);
      const [updatedWorkspace, requests] = await Promise.all([
        getWorkspace(workspace.id),
        listJoinRequests(workspace.id),
      ]);

      setWorkspace(updatedWorkspace);
      setJoinRequests(requests);
    } catch {
      setRequestsError("Could not approve that request.");
    }
  }

  async function handleRejectRequest(requestId: number) {
    if (!workspace) {
      return;
    }

    setRequestsError(null);

    try {
      await rejectJoinRequest(workspace.id, requestId);
      setJoinRequests((current) =>
        current.filter((request) => request.id !== requestId),
      );
    } catch {
      setRequestsError("Could not reject that request.");
    }
  }

  async function handleCopyInviteLink() {
    if (!workspace?.invite_code) {
      return;
    }

    const link = `${window.location.origin}/join/${workspace.invite_code}`;

    try {
      await navigator.clipboard.writeText(link);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      setRequestsError("Could not copy the invite link.");
    }
  }

  const canRename = permissions.isOwner;
  const canDelete = permissions.isOwner;

  if (status === "loading") {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#F2F0EA] dark:bg-[#121214] font-sans">
        <div className="flex items-center gap-3 text-sm text-[#686873] dark:text-[#b0b0b9]">
          <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
          Loading workspace…
        </div>
      </main>
    );
  }

  if (status === "missing" || !workspace) {
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

            <p className="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
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
    <div className="flex min-h-screen flex-col bg-[#F2F0EA] dark:bg-[#121214] font-sans text-[#20202A] dark:text-[#ececf1]">
      <WorkspaceNav />

      <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-6 py-10 sm:px-10 sm:py-12">
        {/* Workspace header */}
        <section className="rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] p-7 shadow-sm sm:p-8">
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

                <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
                  <button
                    type="button"
                    onClick={() => setOwnerProfileOpen(true)}
                    aria-label={`View ${workspace.owner.username}'s profile`}
                    className="group flex min-w-0 items-center gap-2 rounded-full border border-transparent py-1 pl-1 pr-3 transition hover:border-[#D7D5DE] hover:bg-[#F2F0EA] dark:hover:border-[#34343c] dark:hover:bg-[#24242a]"
                  >
                    <OwnerAvatar
                      owner={workspace.owner}
                      className="h-7 w-7 text-xs"
                    />
                    <span className="truncate">
                      Owned by{" "}
                      <span className="font-medium text-[#5146E5] group-hover:underline">
                        @{workspace.owner.username}
                      </span>
                    </span>
                  </button>

                  <span aria-hidden="true">·</span>
                  <span>
                    created{" "}
                    {new Date(workspace.created_at).toLocaleDateString()}
                  </span>
                </div>
              </div>
            </div>

            <Link
              href="/workspaces/new"
              className="shrink-0 rounded-lg bg-[#5146E5] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[#4338CA]"
            >
              New workspace
            </Link>
          </div>
        </section>

        {/* Knowledge area */}
        <SearchPanel
          workspaceId={workspace.id}
          basePath={`/workspaces/${workspace.id}/entries`}
        />

        <AssistantPanel workspaceId={workspace.id} />

        <section className="rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] shadow-sm">
          <div className="flex items-center justify-between border-b border-[#D7D5DE] dark:border-[#34343c] px-7 py-5 sm:px-8">
            <div>
              <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
                Knowledge
              </p>

              <h2 className="mt-1 text-xl font-semibold tracking-tight">
                Entries
              </h2>
            </div>

            {canWrite && (
              <Link
                href={`/workspaces/${workspace.id}/entries/new`}
                className="rounded-lg bg-[#5146E5] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[#4338CA]"
              >
                New entry
              </Link>
            )}
          </div>

          {entries.length === 0 ? (
            <div className="flex min-h-[260px] flex-col items-center justify-center px-7 py-12 text-center sm:px-8">
              <div className="mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-[#EAF7F6] dark:bg-[#143036] text-[#159A9C] dark:text-[#3ac6c8]">
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
                {workspace.role === "viewer"
                  ? "No entries yet"
                  : "Your knowledge lives here"}
              </h3>

              <p className="mt-2 max-w-md text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
                {workspace.role === "viewer"
                  ? "There are no knowledge entries to browse in this workspace yet."
                  : "Capture your first thought, note, or idea — it’ll show up here alongside all of your entries."}
              </p>

              {canWrite && (
                <Link
                  href={`/workspaces/${workspace.id}/entries/new`}
                  className="mt-6 rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA]"
                >
                  Create your first entry
                </Link>
              )}
            </div>
          ) : (
            <>
              <EntryList
                entries={entries.slice(0, 5)}
                basePath={`/workspaces/${workspace.id}/entries`}
              />

              {entries.length > 5 && (
                <div className="border-t border-[#E7E6ED] dark:border-[#2a2a31] px-7 py-4 sm:px-8">
                  <Link
                    href={`/workspaces/${workspace.id}/entries`}
                    className="text-sm font-medium text-[#5146E5] transition hover:text-[#4338CA]"
                  >
                    View all {entries.length} entries →
                  </Link>
                </div>
              )}
            </>
          )}
        </section>

        {/* Members */}
        <section className="rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] shadow-sm">
          <div className="border-b border-[#D7D5DE] dark:border-[#34343c] px-7 py-5 sm:px-8">
            <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
              People
            </p>

            <h2 className="mt-1 text-xl font-semibold tracking-tight">
              Members
            </h2>
          </div>

          {isOwner && (
            <>
              {joinRequests.length > 0 && (
                <div className="border-b border-[#E7E6ED] dark:border-[#2a2a31] px-7 py-6 sm:px-8">
                  <h3 className="text-sm font-semibold tracking-tight">
                    Pending requests{" "}
                    <span className="text-[#9897A1] dark:text-[#8b8b94]">
                      ({joinRequests.length})
                    </span>
                  </h3>

                  {requestsError && (
                    <p
                      role="alert"
                      className="mt-3 rounded-lg border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-4 py-3 text-sm leading-relaxed text-[#B13D55] dark:text-[#f0a0b0]"
                    >
                      {requestsError}
                    </p>
                  )}

                  <ul className="mt-3 divide-y divide-[#E7E6ED] dark:divide-[#2a2a31]">
                    {joinRequests.map((request) => (
                      <li
                        key={request.id}
                        className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between"
                      >
                        <div className="flex min-w-0 items-center gap-3">
                          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#EEEDFF] dark:bg-[#262449] text-sm font-semibold text-[#5146E5]">
                            {request.user?.username.charAt(0).toUpperCase()}
                          </div>

                          <div className="flex min-w-0 flex-col">
                            <span className="truncate text-sm font-medium text-[#20202A] dark:text-[#ececf1]">
                              @{request.user?.username}
                            </span>

                            <span className="truncate text-sm text-[#9897A1] dark:text-[#8b8b94]">
                              {request.user?.email} · requested{" "}
                              {request.requested_role}
                            </span>
                          </div>
                        </div>

                        <div className="flex shrink-0 items-center gap-2">
                          <select
                            defaultValue={request.requested_role}
                            onChange={(event) =>
                              handleApproveRequest(
                                request.id,
                                event.target.value as WorkspaceRole,
                              )
                            }
                            className="w-fit rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-3 py-2 text-sm text-[#20202A] dark:text-[#ececf1] outline-none focus:border-[#5146E5]"
                          >
                            <option value="member">member</option>
                            <option value="viewer">viewer</option>
                          </select>

                          <button
                            onClick={() => handleRejectRequest(request.id)}
                            className="rounded-lg border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-3 py-2 text-sm font-medium text-[#B13D55] dark:text-[#f0a0b0] transition hover:bg-[#F9DEE3] dark:hover:bg-[#4a2630]"
                          >
                            Reject
                          </button>
                        </div>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              <div className="border-b border-[#E7E6ED] dark:border-[#2a2a31] px-7 py-6 sm:px-8">
                <h3 className="text-sm font-semibold tracking-tight">
                  Invite by email
                </h3>

                {memberError && (
                  <p
                    role="alert"
                    className="mt-3 rounded-lg border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-4 py-3 text-sm leading-relaxed text-[#B13D55] dark:text-[#f0a0b0]"
                  >
                    {memberError}
                  </p>
                )}

                <form
                  onSubmit={handleAddMember}
                  className="mt-3 flex flex-col gap-3 sm:flex-row"
                >
                  <input
                    type="email"
                    required
                    placeholder="colleague@example.com"
                    value={memberEmail}
                    onChange={(event) => setMemberEmail(event.target.value)}
                    className="w-full rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-2.5 text-sm text-[#20202A] dark:text-[#ececf1] outline-none transition placeholder:text-[#A3A2AC] dark:placeholder:text-[#7e7e88] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
                  />

                  <select
                    value={memberRole}
                    onChange={(event) =>
                      setMemberRole(event.target.value as WorkspaceRole)
                    }
                    className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-3 py-2.5 text-sm text-[#20202A] dark:text-[#ececf1] outline-none focus:border-[#5146E5]"
                  >
                    <option value="member">member</option>
                    <option value="viewer">viewer</option>
                  </select>

                  <button
                    type="submit"
                    disabled={addingMember}
                    className="rounded-lg bg-[#5146E5] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    {addingMember ? "Adding…" : "Add member"}
                  </button>
                </form>

                <div className="mt-5 flex flex-col gap-2">
                  <span className="text-sm font-medium text-[#20202A] dark:text-[#ececf1]">
                    Share link
                  </span>

                  <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <code className="truncate rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-[#F2F0EA] dark:bg-[#121214] px-3 py-2.5 text-sm text-[#686873] dark:text-[#b0b0b9]">
                      /join/{workspace?.invite_code ?? "…"}
                    </code>

                    <button
                      type="button"
                      onClick={handleCopyInviteLink}
                      className="w-fit rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-2.5 text-sm font-medium text-[#20202A] dark:text-[#ececf1] transition hover:border-[#5146E5] hover:text-[#5146E5]"
                    >
                      {copied ? "Copied" : "Copy"}
                    </button>
                  </div>
                </div>
              </div>
            </>
          )}

          <ul className="divide-y divide-[#E7E6ED] dark:divide-[#2a2a31] px-7 sm:px-8">
            {(workspace.members ?? []).map((member) => {
              const isSelf = workspace?.owner_id === member.id;

              return (
                <li
                  key={member.id}
                  className="flex items-center justify-between gap-4 py-4"
                >
                  <div className="flex min-w-0 items-center gap-3">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#EEEDFF] dark:bg-[#262449] text-sm font-semibold text-[#5146E5]">
                      {member.username.charAt(0).toUpperCase()}
                    </div>

                    <div className="flex min-w-0 flex-col">
                      <span className="truncate text-sm font-medium text-[#20202A] dark:text-[#ececf1]">
                        @{member.username}
                      </span>

                      <span className="truncate text-sm text-[#9897A1] dark:text-[#8b8b94]">
                        {member.email}
                      </span>
                    </div>
                  </div>

                  <div className="flex shrink-0 items-center gap-2">
                    <RoleBadge role={member.pivot.role} />

                    {isOwner && !isSelf && (
                      <>
                        <select
                          className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-2 py-1.5 text-sm text-[#20202A] dark:text-[#ececf1] outline-none focus:border-[#5146E5]"
                          value={member.pivot.role}
                          onChange={(event) =>
                            handleRoleChange(
                              member.id,
                              event.target.value as WorkspaceRole,
                            )
                          }
                        >
                          <option value="member">member</option>
                          <option value="viewer">viewer</option>
                        </select>

                        <button
                          onClick={() => handleRemoveMember(member.id)}
                          className="rounded-lg border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-3 py-1.5 text-sm font-medium text-[#B13D55] dark:text-[#f0a0b0] transition hover:bg-[#F9DEE3] dark:hover:bg-[#4a2630]"
                        >
                          Remove
                        </button>
                      </>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        </section>

        {/* Settings */}
        {canRename && (
          <section className="rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[#D7D5DE] dark:border-[#34343c] px-7 py-5 sm:px-8">
              <div>
                <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
                  Workspace
                </p>

                <h2 className="mt-1 text-xl font-semibold tracking-tight">
                  Settings
                </h2>
              </div>

              <div className="flex flex-wrap items-center gap-2">
                <Link
                  href={`/workspaces/${workspace.id}/tags`}
                  className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-2.5 text-sm font-medium text-[#20202A] dark:text-[#ececf1] transition hover:border-[#C9C7D0] dark:hover:border-[#474751] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a]"
                >
                  Manage tags
                </Link>

                <Link
                  href={`/workspaces/${workspace.id}/logs`}
                  className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-2.5 text-sm font-medium text-[#20202A] dark:text-[#ececf1] transition hover:border-[#C9C7D0] dark:hover:border-[#474751] hover:bg-[#F2F0EA] dark:hover:bg-[#24242a]"
                >
                  View activity logs
                </Link>
              </div>
            </div>

            <div className="px-7 py-7 sm:px-8">
              <form
                onSubmit={handleRename}
                className="flex max-w-lg flex-col gap-5"
              >
                <div className="flex flex-col gap-2">
                  <label
                    htmlFor="workspace-name"
                    className="text-sm font-medium text-[#20202A] dark:text-[#ececf1]"
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
                    className="w-full rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-3 text-sm text-[#20202A] dark:text-[#ececf1] outline-none transition placeholder:text-[#A3A2AC] dark:placeholder:text-[#7e7e88] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
                  />
                </div>

                {error && (
                  <p
                    role="alert"
                    className="rounded-lg border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-4 py-3 text-sm leading-relaxed text-[#B13D55] dark:text-[#f0a0b0]"
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
          <section className="rounded-2xl border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FFF8F9] dark:bg-[#1a1416] shadow-sm">
            <div className="border-b border-[#F2C9D1] dark:border-[#4a2630] px-7 py-5 sm:px-8">
              <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#E85D75] dark:text-[#f08098]">
                Advanced
              </p>

              <h2 className="mt-1 text-xl font-semibold tracking-tight text-[#B13D55] dark:text-[#f0a0b0]">
                Danger zone
              </h2>
            </div>

            <div className="flex flex-col gap-4 px-7 py-7 sm:px-8">
              <div>
                <p className="text-sm font-medium text-[#20202A] dark:text-[#ececf1]">
                  Delete this workspace
                </p>

                <p className="mt-1 max-w-2xl text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
                  Deleting a workspace removes it and all of its content. This
                  action cannot be undone.
                </p>
              </div>

              {error && (
                <p
                  role="alert"
                  className="rounded-lg border border-[#F2C9D1] dark:border-[#4a2630] bg-white dark:bg-[#1b1b1f] px-4 py-3 text-sm leading-relaxed text-[#B13D55] dark:text-[#f0a0b0]"
                >
                  {error}
                </p>
              )}

              <button
                onClick={handleDelete}
                disabled={deleting}
                className="w-fit rounded-lg border border-[#E85D75] dark:border-[#f08098] px-5 py-3 text-sm font-medium text-[#B13D55] dark:text-[#f0a0b0] transition hover:bg-[#E85D75] hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
              >
                {deleting ? "Deleting…" : "Delete workspace"}
              </button>
            </div>
          </section>
        )}
      </main>

      {ownerProfileOpen && workspace && (
        <OwnerProfileDialog
          owner={workspace.owner}
          onClose={() => setOwnerProfileOpen(false)}
        />
      )}
    </div>
  );
}
