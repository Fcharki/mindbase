"use client";

import axios from "axios";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";

import { useAuth } from "@/app/Providers/AuthProvider";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import { listTags, updateTag, type WorkspaceTag } from "@/lib/tags";
import { getWorkspace, Workspace } from "@/lib/workspaces";
import { permissionsFor } from "@/lib/permissions";

type Status = "loading" | "ready" | "missing";

interface TreeNode {
  tag: WorkspaceTag;
  depth: number;
}

/**
 * Flatten the tag forest into display order, depth-first so a child always
 * follows its parent. Tags whose parent is missing (deleted out from under
 * them) are treated as roots rather than dropped.
 */
function flatten(tags: WorkspaceTag[]): TreeNode[] {
  const byParent = new Map<number | null, WorkspaceTag[]>();
  const ids = new Set(tags.map((tag) => tag.id));

  for (const tag of tags) {
    const parent =
      tag.parent_id !== null && ids.has(tag.parent_id) ? tag.parent_id : null;

    const bucket = byParent.get(parent) ?? [];
    bucket.push(tag);
    byParent.set(parent, bucket);
  }

  const rows: TreeNode[] = [];

  const walk = (parentId: number | null, depth: number) => {
    for (const tag of byParent.get(parentId) ?? []) {
      rows.push({ tag, depth });
      walk(tag.id, depth + 1);
    }
  };

  walk(null, 0);

  return rows;
}

function descendantIds(tags: WorkspaceTag[], rootId: number): Set<number> {
  const byParent = new Map<number, number[]>();

  for (const tag of tags) {
    if (tag.parent_id === null) {
      continue;
    }

    const bucket = byParent.get(tag.parent_id) ?? [];
    bucket.push(tag.id);
    byParent.set(tag.parent_id, bucket);
  }

  const found = new Set<number>();
  const stack = [...(byParent.get(rootId) ?? [])];

  while (stack.length > 0) {
    const id = stack.pop() as number;

    if (found.has(id)) {
      continue;
    }

    found.add(id);
    stack.push(...(byParent.get(id) ?? []));
  }

  return found;
}

export function TagsPageView({ workspaceId }: { workspaceId: string }) {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();

  const [workspace, setWorkspace] = useState<Workspace | null>(null);
  const [tags, setTags] = useState<WorkspaceTag[]>([]);
  const [status, setStatus] = useState<Status>("loading");

  const [editingId, setEditingId] = useState<number | null>(null);
  const [draftName, setDraftName] = useState("");
  const [draftParent, setDraftParent] = useState<string>("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (authLoading) {
      return;
    }

    if (!user) {
      router.replace("/auth/login");
      return;
    }

    let cancelled = false;

    Promise.all([getWorkspace(workspaceId), listTags(workspaceId)])
      .then(([workspaceResult, tagResult]) => {
        if (cancelled) {
          return;
        }

        setWorkspace(workspaceResult);
        setTags(tagResult);
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

  const permissions = permissionsFor(workspace?.role, workspace?.is_member);
  const rows = useMemo(() => flatten(tags), [tags]);

  function beginEdit(tag: WorkspaceTag) {
    setEditingId(tag.id);
    setDraftName(tag.name);
    setDraftParent(tag.parent_id === null ? "" : String(tag.parent_id));
  }

  function cancelEdit() {
    setEditingId(null);
    setDraftName("");
    setDraftParent("");
  }

  async function handleSave(tag: WorkspaceTag) {
    const name = draftName.trim().toLowerCase();
    const parentId = draftParent === "" ? null : Number(draftParent);

    if (!name) {
      toast.error("A tag needs a name.");
      return;
    }

    const changes: { name?: string; parent_id?: number | null } = {};

    if (name !== tag.name) {
      changes.name = name;
    }

    if (parentId !== tag.parent_id) {
      changes.parent_id = parentId;
    }

    if (Object.keys(changes).length === 0) {
      cancelEdit();
      return;
    }

    setSaving(true);

    try {
      const saved = await updateTag(workspaceId, tag.id, changes);
      setTags((current) =>
        current.map((item) => (item.id === saved.id ? saved : item)),
      );
      cancelEdit();
      toast.success("Tag updated.");
    } catch (requestError: unknown) {
      const message = axios.isAxiosError(requestError)
        ? (requestError.response?.data as { message?: string } | undefined)
            ?.message
        : undefined;

      toast.error(message ?? "Could not update that tag.");
    } finally {
      setSaving(false);
    }
  }

  if (status === "missing") {
    return (
      <div className="flex min-h-screen flex-col bg-[#F7F6F2] font-sans text-[#20202A] dark:bg-[#0f0f0f] dark:text-[#e5e5e5]">
        <WorkspaceNav />

        <main className="flex flex-1 items-center justify-center px-6 py-20">
          <section className="w-full max-w-md rounded-2xl border border-[#DDDCE3] bg-white p-8 text-center shadow-sm dark:border-[#2a2a2a] dark:bg-[#141414] sm:p-10">
            <h1 className="text-2xl font-semibold tracking-[-0.025em]">
              Workspace not available
            </h1>

            <p className="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-[#686873] dark:text-[#a3a3a3]">
              This workspace may have been deleted, or you don&rsquo;t have
              access to it.
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

  if (status === "loading" || !workspace) {
    return (
      <div className="flex min-h-screen flex-col bg-[#F7F6F2] font-sans dark:bg-[#0f0f0f]">
        <WorkspaceNav />

        <main className="mx-auto flex w-full max-w-3xl flex-col gap-4 px-6 py-10 sm:px-10 sm:py-12">
          <div className="h-28 animate-pulse rounded-2xl border border-[#DDDCE3] bg-white dark:border-[#2a2a2a] dark:bg-[#141414]" />
          <div className="h-64 animate-pulse rounded-2xl border border-[#DDDCE3] bg-white dark:border-[#2a2a2a] dark:bg-[#141414]" />
        </main>
      </div>
    );
  }

  return (
    <div className="flex min-h-screen flex-col bg-[#F7F6F2] font-sans text-[#20202A] dark:bg-[#0f0f0f] dark:text-[#e5e5e5]">
      <WorkspaceNav />

      <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-10 sm:px-10 sm:py-12">
        <Link
          href={`/workspaces/${workspace.id}`}
          className="inline-flex items-center gap-1.5 text-sm font-medium text-[#686873] transition hover:text-[#20202A] dark:text-[#a3a3a3] dark:hover:text-[#e5e5e5]"
        >
          <svg
            className="h-4 w-4"
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
          >
            <path
              d="M15 6l-6 6 6 6"
              stroke="currentColor"
              strokeWidth="1.7"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </svg>
          {workspace.name}
        </Link>

        <section className="rounded-2xl border border-[#DDDCE3] bg-white p-7 shadow-sm dark:border-[#2a2a2a] dark:bg-[#141414] sm:p-8">
          <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5] dark:text-[#a5b4fc]">
            Organize
          </p>

          <h1 className="mt-1 text-2xl font-semibold tracking-[-0.025em]">
            Tags
          </h1>

          <p className="mt-2 max-w-prose text-sm leading-relaxed text-[#686873] dark:text-[#a3a3a3]">
            Nest related tags to keep this workspace tidy. Tags appear as soon
            as you add them to an entry; here you can rename them or choose
            which tag they sit under.
          </p>
        </section>

        <section className="rounded-2xl border border-[#DDDCE3] bg-white shadow-sm dark:border-[#2a2a2a] dark:bg-[#141414]">
          {tags.length === 0 ? (
            <div className="flex min-h-[200px] flex-col items-center justify-center px-7 py-12 text-center sm:px-8">
              <h2 className="text-lg font-semibold tracking-tight">
                No tags yet
              </h2>

              <p className="mt-2 max-w-sm text-sm leading-relaxed text-[#686873] dark:text-[#a3a3a3]">
                Add tags to an entry and they will show up here, ready to be
                organized.
              </p>
            </div>
          ) : (
            <ul className="divide-y divide-[#EEEDEF] dark:divide-[#1f1f1f]">
              {rows.map(({ tag, depth }) => {
                const editing = editingId === tag.id;
                const blocked = descendantIds(tags, tag.id);

                return (
                  <li
                    key={tag.id}
                    className="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-7"
                  >
                    <div
                      className="flex min-w-0 items-center gap-2"
                      style={{ paddingLeft: `${depth * 1.5}rem` }}
                    >
                      {depth > 0 && (
                        <span
                          className="text-[#C9C7D0] dark:text-[#3a3a3a]"
                          aria-hidden="true"
                        >
                          ↳
                        </span>
                      )}

                      {editing ? (
                        <input
                          type="text"
                          value={draftName}
                          maxLength={50}
                          autoFocus
                          onChange={(event) => setDraftName(event.target.value)}
                          className="w-40 rounded-lg border border-[#DDDCE3] bg-white px-2.5 py-1.5 text-sm text-[#20202A] outline-none transition focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:border-[#2a2a2a] dark:bg-[#0f0f0f] dark:text-[#e5e5e5] dark:focus:ring-[#2a2a3a]"
                        />
                      ) : (
                        <span className="truncate text-sm font-medium text-[#20202A] dark:text-[#e5e5e5]">
                          {tag.name}
                        </span>
                      )}

                      {tag.entries_count !== undefined && !editing && (
                        <span className="shrink-0 rounded-full bg-[#F2F1F7] px-2 py-0.5 text-xs font-medium text-[#686873] dark:bg-[#1c1c1c] dark:text-[#a3a3a3]">
                          {tag.entries_count}
                        </span>
                      )}
                    </div>

                    {permissions.canContribute && (
                      <div className="flex shrink-0 items-center gap-2 self-start sm:self-auto">
                        {editing ? (
                          <>
                            <label className="sr-only" htmlFor={`parent-${tag.id}`}>
                              Parent tag
                            </label>

                            <select
                              id={`parent-${tag.id}`}
                              value={draftParent}
                              onChange={(event) =>
                                setDraftParent(event.target.value)
                              }
                              className="max-w-[160px] rounded-lg border border-[#DDDCE3] bg-white px-2 py-1.5 text-sm text-[#20202A] outline-none focus:border-[#5146E5] dark:border-[#2a2a2a] dark:bg-[#0f0f0f] dark:text-[#e5e5e5]"
                            >
                              <option value="">No parent</option>

                              {tags
                                .filter(
                                  (option) =>
                                    option.id !== tag.id &&
                                    !blocked.has(option.id),
                                )
                                .map((option) => (
                                  <option key={option.id} value={option.id}>
                                    {option.name}
                                  </option>
                                ))}
                            </select>

                            <button
                              type="button"
                              onClick={() => handleSave(tag)}
                              disabled={saving}
                              className="rounded-lg bg-[#5146E5] px-3 py-1.5 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:opacity-50"
                            >
                              Save
                            </button>

                            <button
                              type="button"
                              onClick={cancelEdit}
                              disabled={saving}
                              className="rounded-lg px-2 py-1.5 text-sm font-medium text-[#686873] transition hover:text-[#20202A] disabled:opacity-50 dark:text-[#a3a3a3] dark:hover:text-[#e5e5e5]"
                            >
                              Cancel
                            </button>
                          </>
                        ) : (
                          <button
                            type="button"
                            onClick={() => beginEdit(tag)}
                            className="rounded-lg border border-[#DDDCE3] bg-white px-3 py-1.5 text-sm font-medium text-[#686873] transition hover:border-[#C9C7D0] hover:bg-[#F7F6F2] hover:text-[#20202A] dark:border-[#2a2a2a] dark:bg-[#141414] dark:text-[#a3a3a3] dark:hover:bg-[#1c1c1c] dark:hover:text-[#e5e5e5]"
                          >
                            Edit
                          </button>
                        )}
                      </div>
                    )}
                  </li>
                );
              })}
            </ul>
          )}
        </section>
      </main>
    </div>
  );
}
