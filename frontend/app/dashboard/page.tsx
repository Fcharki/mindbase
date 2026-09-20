"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import { CreateWorkspaceForm } from "@/app/features/workspaces/CreateWorkspaceForm";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";
import { listWorkspaces, Workspace } from "@/lib/workspaces";

export default function DashboardPage() {
  const router = useRouter();
  const { user, loading } = useAuth();
  const [workspaces, setWorkspaces] = useState<Workspace[] | null>(null);

  useEffect(() => {
    if (loading) {
      return;
    }

    if (!user) {
      router.replace("/auth/login");
      return;
    }

    let cancelled = false;

    listWorkspaces()
      .then((result) => {
        if (!cancelled) {
          setWorkspaces(result);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setWorkspaces([]);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [user, loading, router]);

  useEffect(() => {
    if (!workspaces || workspaces.length === 0) {
      return;
    }

    const primary =
      workspaces.find((workspace) => workspace.role === "owner") ??
      workspaces[0];

    router.replace(`/workspaces/${primary.id}`);
  }, [workspaces, router]);

  if (loading) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#F7F6F2]">
        <div className="flex items-center gap-3 text-sm text-[#686873]">
          <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
          Loading…
        </div>
      </main>
    );
  }

  return (
    <div className="flex min-h-screen flex-col bg-[#F7F6F2] font-sans text-[#20202A]">
      <WorkspaceNav workspaces={workspaces ?? []} />

      <main className="flex flex-1 items-center justify-center px-6 py-20 sm:px-10">
        {!workspaces ? (
          <div className="flex items-center gap-3 text-sm text-[#686873]">
            <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
            Loading your workspaces…
          </div>
        ) : (
          <section className="w-full max-w-lg">
            {/* Intro card */}
            <div className="rounded-2xl border border-[#DDDCE3] bg-white p-8 shadow-sm sm:p-10">
              <div className="mb-7 flex h-11 w-11 items-center justify-center rounded-xl bg-[#EEEDFF]">
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

              <p className="mb-2 text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
                Your knowledge space
              </p>

              <h1 className="text-3xl font-semibold tracking-[-0.025em]">
                Welcome to Mindbase
              </h1>

              <p className="mt-3 max-w-md leading-relaxed text-[#686873]">
                Create a workspace to keep your knowledge organized. Once
                created, you&rsquo;ll go straight into it.
              </p>

              <div className="mt-8">
                <CreateWorkspaceForm
                  onCreated={(workspace) =>
                    router.push(`/workspaces/${workspace.id}`)
                  }
                />
              </div>
            </div>

            <div className="mt-6 flex items-center justify-between px-1">
              <span className="text-sm text-[#9897A1]">
                You can create more workspaces later.
              </span>

              <Link
                href="/"
                className="text-sm font-medium text-[#5146E5] transition hover:text-[#4338CA]"
              >
                Back to home
              </Link>
            </div>
          </section>
        )}
      </main>
    </div>
  );
}
