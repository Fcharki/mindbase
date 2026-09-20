"use client";

import axios from "axios";
import Link from "next/link";
import { useEffect, useState, type FormEvent } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import {
  getWorkspaceByInviteCode,
  JoinableWorkspace,
  requestJoin,
  WorkspaceRole,
} from "@/lib/workspaces";

type Status = "loading" | "ready" | "missing";

export function JoinView({ inviteCode }: { inviteCode: string }) {
  const { user, loading: authLoading } = useAuth();

  const [workspace, setWorkspace] = useState<JoinableWorkspace | null>(null);
  const [status, setStatus] = useState<Status>("loading");
  const [role, setRole] = useState<WorkspaceRole>("member");
  const [message, setMessage] = useState<string | null>(null);
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

  async function handleRequest(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!workspace) {
      return;
    }

    setSubmitting(true);
    setError(null);
    setMessage(null);

    try {
      await requestJoin(workspace.id, role);
      setMessage(
        "Your request has been sent. The workspace owner will approve it shortly.",
      );
    } catch (requestError: unknown) {
      const responseMessage = axios.isAxiosError(requestError)
        ? (requestError.response?.data as { message?: string } | undefined)
            ?.message
        : undefined;

      setError(responseMessage ?? "Could not send your request.");
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

        <h1 className="text-2xl font-semibold tracking-[-0.025em]">
          {workspace.name}
        </h1>

        <p className="mt-3 text-sm leading-relaxed text-[#686873]">
          Owned by {workspace.owner.name}. Request access to start capturing
          knowledge together.
        </p>

        {message && (
          <p
            role="status"
            className="mt-5 rounded-lg border border-[#C8ECD9] bg-[#E9F9F0] px-4 py-3 text-sm leading-relaxed text-[#1F7A44]"
          >
            {message}
          </p>
        )}

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
          <form onSubmit={handleRequest} className="mt-7 space-y-4">
            <div className="flex items-center justify-center gap-2">
              <span className="text-sm text-[#686873]">Request role:</span>

              <select
                value={role}
                onChange={(event) =>
                  setRole(event.target.value as WorkspaceRole)
                }
                className="rounded-lg border border-[#DDDCE3] bg-white px-3 py-2 text-sm text-[#20202A] outline-none focus:border-[#5146E5]"
              >
                <option value="member">member</option>
                <option value="viewer">viewer</option>
              </select>
            </div>

            <button
              type="submit"
              disabled={submitting}
              className="inline-flex w-full items-center justify-center rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {submitting ? "Sending request…" : "Request to join"}
            </button>
          </form>
        ) : (
          <>
            <Link
              href={`/auth/login?next=${encodeURIComponent(`/join/${inviteCode}`)}`}
              className="mt-7 inline-flex w-full items-center justify-center rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA]"
            >
              Log in to request access
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