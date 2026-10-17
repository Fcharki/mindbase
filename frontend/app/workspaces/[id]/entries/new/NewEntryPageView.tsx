"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import { EntryForm } from "@/app/features/knowledge/components/EntryForm";
import { createEntry } from "@/lib/entries";
import { getWorkspace, Workspace } from "@/lib/workspaces";

type Status = "loading" | "ready" | "missing";

export function NewEntryPageView({ workspaceId }: { workspaceId: string }) {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();

  const [workspace, setWorkspace] = useState<Workspace | null>(null);
  const [status, setStatus] = useState<Status>("loading");
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
  }, [workspaceId, user, authLoading, router]);

  async function handleSubmit(title: string, content: string) {
    const entry = await createEntry(workspaceId, title, content);
    router.push(`/workspaces/${workspaceId}/entries/${entry.id}`);
    router.refresh();
  }

  if (status === "loading" || !workspace) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#F7F6F2] dark:bg-[#0f0f0f] font-sans">
        <div className="flex items-center gap-3 text-sm text-[#686873] dark:text-[#a3a3a3]">
          <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
          Loading workspace…
        </div>
      </main>
    );
  }

  if (workspace.role === "viewer") {
    return (
      <div className="flex min-h-screen flex-col bg-[#F7F6F2] dark:bg-[#0f0f0f] font-sans text-[#20202A] dark:text-[#e5e5e5]">
        <WorkspaceNav />

        <main className="flex flex-1 items-center justify-center px-6 py-20 sm:px-10">
          <section className="w-full max-w-md rounded-2xl border border-[#DDDCE3] dark:border-[#2a2a2a] bg-white dark:bg-[#141414] p-8 text-center shadow-sm sm:p-10">
            <h1 className="text-2xl font-semibold tracking-[-0.025em]">
              Viewer access
            </h1>
            <p className="mt-3 text-sm leading-relaxed text-[#686873] dark:text-[#a3a3a3]">
              Join {workspace.name} from the workspace directory before creating
              entries.
            </p>
            <Link
              href="/dashboard"
              className="mt-7 inline-flex items-center justify-center rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA]"
            >
              Browse workspaces
            </Link>
          </section>
        </main>
      </div>
    );
  }

  return (
    <div className="flex min-h-screen flex-col bg-[#F7F6F2] dark:bg-[#0f0f0f] font-sans text-[#20202A] dark:text-[#e5e5e5]">
      <WorkspaceNav />

      <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-6 py-10 sm:px-10 sm:py-12">
        <Link
          href={`/workspaces/${workspace.id}/entries`}
          className="inline-flex items-center gap-1.5 text-sm font-medium text-[#686873] dark:text-[#a3a3a3] transition hover:text-[#20202A]"
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
          Entries
        </Link>

        <div>
          <h1 className="text-3xl font-semibold tracking-[-0.03em]">
            New entry
          </h1>

          <p className="mt-2 text-sm leading-relaxed text-[#686873] dark:text-[#a3a3a3]">
            Capture a thought, note, or idea in {workspace.name}.
          </p>
        </div>

        <EntryForm
          title="Title"
          heading="Write your entry"
          eyebrow={workspace.name}
          submitLabel="Create entry"
          submittingLabel="Creating…"
          error={error}
          onError={setError}
          onSubmit={handleSubmit}
        />
      </main>
    </div>
  );
}