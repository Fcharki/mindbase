"use client";

import axios from "axios";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import { RoleBadge } from "@/app/features/workspaces/RoleBadge";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import { getPublicUser, PublicUser } from "@/lib/users";
import { requestJoin } from "@/lib/workspaces";

type Status = "loading" | "ready" | "missing";

function messageFrom(error: unknown, fallback: string) {
  const message = axios.isAxiosError(error)
    ? (error.response?.data as { message?: string } | undefined)?.message
    : undefined;

  return message ?? fallback;
}

export function PublicProfileView({ username }: { username: string }) {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();
  const [person, setPerson] = useState<PublicUser | null>(null);
  const [status, setStatus] = useState<Status>("loading");
  const [requestingId, setRequestingId] = useState<number | null>(null);
  const [joinError, setJoinError] = useState<string | null>(null);

  useEffect(() => {
    if (authLoading) {
      return;
    }

    if (!user) {
      router.replace(
        `/auth/login?next=${encodeURIComponent(`/users/${username}`)}`,
      );
      return;
    }

    let cancelled = false;

    getPublicUser(username)
      .then((result) => {
        if (!cancelled) {
          setPerson(result);
          setStatus("ready");
        }
      })
      .catch(() => {
        if (!cancelled) {
          setStatus("missing");
        }
      });

    return () => {
      cancelled = true;
    };
  }, [authLoading, router, user, username]);

  const totalEntries = useMemo(
    () =>
      person?.owned_workspaces.reduce(
        (total, workspace) => total + workspace.entries_count,
        0,
      ) ?? 0,
    [person],
  );

  function markRequestPending(workspaceId: number) {
    setPerson((current) => {
      if (!current) {
        return current;
      }

      return {
        ...current,
        owned_workspaces: current.owned_workspaces.map((workspace) =>
          workspace.id === workspaceId
            ? { ...workspace, join_request_status: "pending" as const }
            : workspace,
        ),
      };
    });
  }

  async function handleRequestJoin(workspaceId: number) {
    setRequestingId(workspaceId);
    setJoinError(null);

    try {
      await requestJoin(workspaceId, "member");
      markRequestPending(workspaceId);
    } catch (error: unknown) {
      if (axios.isAxiosError(error) && error.response?.status === 409) {
        markRequestPending(workspaceId);
        return;
      }

      setJoinError(messageFrom(error, "Could not send your join request."));
    } finally {
      setRequestingId(null);
    }
  }

  if (authLoading || status === "loading") {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#F2F0EA] dark:bg-[#121214] font-sans">
        <div className="flex items-center gap-3 text-sm text-[#686873] dark:text-[#b0b0b9]">
          <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
          Loading profile…
        </div>
      </main>
    );
  }

  if (!user) {
    return null;
  }

  if (status === "missing" || !person) {
    return (
      <div className="flex min-h-screen flex-col bg-[#F2F0EA] dark:bg-[#121214] font-sans text-[#20202A] dark:text-[#ececf1]">
        <WorkspaceNav />
        <main className="mx-auto flex w-full max-w-3xl flex-1 items-center justify-center px-6 py-12">
          <div className="w-full rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] p-8 text-center shadow-sm sm:p-10">
            <h1 className="text-2xl font-semibold tracking-[-0.025em]">
              Profile not found
            </h1>
            <p className="mt-3 text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
              This username does not belong to anyone here.
            </p>
            <Link
              href="/dashboard"
              className="mt-7 inline-flex items-center justify-center rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA]"
            >
              Back to the directory
            </Link>
          </div>
        </main>
      </div>
    );
  }

  const memberSince = new Date(person.created_at).toLocaleDateString(
    undefined,
    { month: "long", year: "numeric" },
  );

  return (
    <div className="flex min-h-screen flex-col bg-[#F2F0EA] dark:bg-[#121214] font-sans text-[#20202A] dark:text-[#ececf1]">
      <WorkspaceNav />

      <main className="mx-auto w-full max-w-4xl flex-1 px-6 py-10 sm:px-10 sm:py-12">
        <Link
          href="/dashboard"
          className="inline-flex items-center gap-2 text-sm font-medium text-[#686873] transition hover:text-[#5146E5] dark:text-[#b0b0b9] dark:hover:text-[#a5b4fc]"
        >
          <svg
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
          >
            <path
              d="M19 12H5m6-6-6 6 6 6"
              stroke="currentColor"
              strokeWidth="1.7"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </svg>
          Directory
        </Link>

        <section className="mt-6 overflow-hidden rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] shadow-sm">
          <div className="h-28 bg-gradient-to-r from-[#EEEDFF] via-[#E7F1FF] to-[#EAF7F6] dark:from-[#262449] dark:via-[#1c2b3a] dark:to-[#143036]" />

          <div className="px-6 pb-7 sm:px-8">
            <div className="-mt-10 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
              <div className="flex items-end gap-4">
                <span className="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-full border-4 border-white bg-[#EEEDFF] text-3xl font-semibold text-[#5146E5] dark:border-[#1b1b1f] dark:bg-[#262449]">
                  {person.photo_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                      src={person.photo_url}
                      alt={`${person.username}'s profile photo`}
                      className="h-full w-full object-cover"
                    />
                  ) : (
                    person.username.charAt(0).toUpperCase()
                  )}
                </span>

                <div className="pb-1">
                  <h1 className="text-2xl font-semibold tracking-[-0.03em] sm:text-3xl">
                    @{person.username}
                  </h1>
                  {person.location && (
                    <p className="mt-1 text-sm text-[#686873] dark:text-[#b0b0b9]">
                      {person.location}
                    </p>
                  )}
                </div>
              </div>

              <div className="flex gap-5 pb-1 text-sm">
                <div>
                  <p className="text-lg font-semibold">
                    {person.owned_workspaces.length}
                  </p>
                  <p className="text-[#686873] dark:text-[#b0b0b9]">
                    {person.owned_workspaces.length === 1
                      ? "workspace"
                      : "workspaces"}
                  </p>
                </div>
                <div>
                  <p className="text-lg font-semibold">{totalEntries}</p>
                  <p className="text-[#686873] dark:text-[#b0b0b9]">
                    {totalEntries === 1 ? "entry" : "entries"}
                  </p>
                </div>
              </div>
            </div>

            <p className="mt-5 max-w-2xl text-sm leading-relaxed text-[#4A4D45] dark:text-[#c4c3cc]">
              {person.bio?.trim() || "This person has not added a bio yet."}
            </p>

            <p className="mt-3 text-xs text-[#9897A1] dark:text-[#8b8b94]">
              Member since {memberSince}
            </p>
          </div>
        </section>

        <section className="mt-8">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <div>
              <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
                Owned workspaces
              </p>
              <h2 className="mt-2 text-2xl font-semibold tracking-[-0.025em]">
                Knowledge shared by @{person.username}
              </h2>
            </div>
            <p className="text-sm text-[#686873] dark:text-[#b0b0b9]">
              Owners approve every join request
            </p>
          </div>

          {joinError && (
            <p
              role="alert"
              className="mt-5 rounded-xl border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-4 py-3 text-sm text-[#B13D55] dark:text-[#f0a0b0]"
            >
              {joinError}
            </p>
          )}

          {person.owned_workspaces.length === 0 ? (
            <div className="mt-5 rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-7 py-12 text-center shadow-sm">
              <h3 className="text-lg font-semibold tracking-tight">
                No owned workspaces yet
              </h3>
              <p className="mt-2 text-sm text-[#686873] dark:text-[#b0b0b9]">
                @{person.username} has not created a workspace.
              </p>
            </div>
          ) : (
            <div className="mt-5 grid gap-4 sm:grid-cols-2">
              {person.owned_workspaces.map((workspace) => (
                <article
                  key={workspace.id}
                  className="flex flex-col rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] p-6 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                >
                  <div className="flex items-start justify-between gap-4">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#EEEDFF] dark:bg-[#262449]">
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
                    </span>
                    <RoleBadge role={workspace.role} />
                  </div>

                  <Link
                    href={`/workspaces/${workspace.id}`}
                    className="mt-5 text-xl font-semibold tracking-tight text-[#20202A] transition hover:text-[#5146E5] dark:text-[#ececf1]"
                  >
                    {workspace.name}
                  </Link>

                  <p className="mt-3 text-sm text-[#9897A1] dark:text-[#8b8b94]">
                    {workspace.entries_count}{" "}
                    {workspace.entries_count === 1 ? "entry" : "entries"}
                  </p>

                  <div className="mt-auto flex flex-wrap items-center gap-2 pt-6">
                    {workspace.is_member ? (
                      <Link
                        href={`/workspaces/${workspace.id}`}
                        className="rounded-lg bg-[#5146E5] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[#4338CA]"
                      >
                        Open workspace
                      </Link>
                    ) : workspace.join_request_status === "pending" ? (
                      <span className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-[#F2F0EA] dark:bg-[#24242a] px-4 py-2.5 text-sm font-medium text-[#686873] dark:text-[#b0b0b9]">
                        Request pending
                      </span>
                    ) : (
                      <button
                        type="button"
                        onClick={() => handleRequestJoin(workspace.id)}
                        disabled={requestingId === workspace.id}
                        className="rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-2.5 text-sm font-medium text-[#20202A] transition hover:bg-[#F2F0EA] disabled:cursor-not-allowed disabled:opacity-50 dark:text-[#ececf1] dark:hover:bg-[#24242a]"
                      >
                        {requestingId === workspace.id
                          ? "Sending…"
                          : "Request to join"}
                      </button>
                    )}
                  </div>
                </article>
              ))}
            </div>
          )}
        </section>
      </main>
    </div>
  );
}
