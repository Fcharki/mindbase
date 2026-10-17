"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";

import { CreateWorkspaceForm } from "@/app/features/workspaces/CreateWorkspaceForm";
import { WorkspaceNav } from "@/app/features/workspaces/WorkspaceNav";

export function NewWorkspaceView() {
  const router = useRouter();

  return (
    <div className="flex min-h-screen flex-col bg-[#F7F6F2] dark:bg-[#0f0f0f] font-sans text-[#20202A] dark:text-[#e5e5e5]">
      <WorkspaceNav />

      <main className="flex flex-1 items-center justify-center px-6 py-20 sm:px-10">
        <section className="w-full max-w-lg">
          <div className="rounded-2xl border border-[#DDDCE3] dark:border-[#2a2a2a] bg-white dark:bg-[#141414] p-8 shadow-sm sm:p-10">
            <div className="mb-7 flex h-11 w-11 items-center justify-center rounded-xl bg-[#EEEDFF] dark:bg-[#211f3d]">
              <svg
                width="22"
                height="22"
                viewBox="0 0 24 24"
                fill="none"
                aria-hidden="true"
              >
                <path
                  d="M12 5v14M5 12h14"
                  stroke="#5146E5"
                  strokeWidth="1.8"
                  strokeLinecap="round"
                />
              </svg>
            </div>

            <p className="mb-2 text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
              New workspace
            </p>

            <h1 className="text-3xl font-semibold tracking-[-0.025em]">
              Create a workspace
            </h1>

            <p className="mt-3 max-w-md leading-relaxed text-[#686873] dark:text-[#a3a3a3]">
              Give your new workspace a name, then you&rsquo;ll go straight into
              it to start organizing your knowledge.
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
            <Link
              href="/workspaces"
              className="text-sm font-medium text-[#5146E5] transition hover:text-[#4338CA]"
            >
              Back to workspaces
            </Link>
          </div>
        </section>
      </main>
    </div>
  );
}