"use client";

import axios from "axios";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import {
  getWorkspaceByInviteCode,
  joinWorkspace,
  JoinableWorkspace,
} from "@/lib/workspaces";

type Status = "loading" | "ready" | "missing";

export function JoinView({ inviteCode }: { inviteCode: string }) {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();

  const [workspace, setWorkspace] = useState<JoinableWorkspace | null>(null);
  const [status, setStatus] = useState<Status>("loading");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    let cancelled = false;

    getWorkspaceByInviteCode(inviteCode)
      .then((result) => {
        if (!cancelled) {
          setWorkspace(result);
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
  }, [inviteCode]);

  async function handleJoin() {
    if (!workspace) {
      return;
    }

    setSubmitting(true);
    setError(null);

    try {
      await joinWorkspace(workspace.id);
      router.push(`/workspaces/${workspace.id}/entries`);
      router.refresh();
    } catch (requestError: unknown) {
      const responseMessage = axios.isAxiosError(requestError)
        ? (requestError.response?.data as { message?: string } | undefined)
            ?.message
        : undefined;

      setError(responseMessage ?? "Could not join this workspace.");
    } finally {
      setSubmitting(false);
    }
  }

  if (status === "loading") {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#F7F6F2] font-sans">
        <div className="flex items-center gap-3 text-sm text-[#686873]">
          <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
          Loading…
        </div>
      </main>
    );
  }

  if (status === "missing" || !workspace) {
    return (
      <main className="flex min-h-screen flex-col items-center justify-center bg-[#F7F6F2] px-6 font-sans">
        <div className="w-full max-w-md rounded-2xl border border-[#DDDCE3] bg-white p-8 text-center shadow-sm sm:p-10">
          <h1 className="text-2xl font-semibold tracking-[-0.025em]">
            Link not found
          </h1>

          <p className="mt-3 text-sm leading-relaxed text-[#686873]">
            This invite link doesn&rsquo;t point to a workspace. Double-check
            the link your owner shared.
          </p>

          <Link
            href="/dashboard"
            className="mt-7 inline-flex items-center justify-center rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA]"
          >
            Go to my workspaces
          </Link>
        </div>
      </main>
    );
  }

  return (
    <main className="flex min-h-screen flex-col items-center justify-center bg-[#F7F6F2] px-6 py-12 font-sans">
      <div className="w-full max-w-md rounded-2xl border border-[#DDDCE3] bg-white p-8 text-center shadow-sm sm:p-10">
        <div className="mx-auto mb-6 flex h-12 w-12 items-center justify-center rounded-xl bg-[#EEEDFF] text-[#5146E5]">
          <svg
            width="23"
            height="23"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
          >
            <path
              d="M4 20V6.5C4 5.12 5.12 4 6.5 4c1.2 0 2.23.83 2.45 2M9 20V8c0-1.66 1.34-3 3-3s3 1.34 3 3M15 20v-9c0-1.38 1.12-2.5 2.5-2.5S20 9.62 20 11v9"
              stroke="currentColor"
              strokeWidth="1.7"
              strokeLinecap="round"
            />
          </svg>
        </div>

        <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
          You have viewer access
        </p>

        <h1 className="mt-2 text-2xl font-semibold tracking-[-0.025em]">
          {workspace.name}
        </h1>

        <p className="mt-3 text-sm leading-relaxed text-[#686873]">
          Owned by {workspace.owner.name}. Join as a member to add and manage
          knowledge entries.
        </p>

        {error && (
          <p
            role="alert"
            className="mt-5 rounded-lg border border-[#F2C9D1] bg-[#FCECEF] px-4 py-3 text-sm leading-relaxed text-[#B13D55]"
          >
            {error}
          </p>
        )}

        {authLoading ? (
          <p className="mt-7 text-sm text-[#686873]">Loading…</p>
        ) : user ? (
          <button
            type="button"
            onClick={handleJoin}
            disabled={submitting}
            className="mt-7 inline-flex w-full items-center justify-center rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-50"
          >
            {submitting ? "Joining…" : "Join as member"}
          </button>
        ) : (
          <>
            <Link
              href={`/auth/login?next=${encodeURIComponent(`/join/${inviteCode}`)}`}
              className="mt-7 inline-flex w-full items-center justify-center rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA]"
            >
              Log in to join
            </Link>

            <p className="mt-4 text-sm text-[#686873]">
              Don&rsquo;t have an account?{" "}
              <Link
                href="/auth/register"
                className="font-medium text-[#5146E5] transition hover:text-[#4338CA]"
              >
                Create one
              </Link>
            </p>
          </>
        )}
      </div>
    </main>
  );
}
