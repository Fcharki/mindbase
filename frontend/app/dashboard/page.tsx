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
      <main className="flex min-h-screen items-center justify-center bg-[#F1F0EB] dark:bg-[#15170F]">
        <p className="text-sm text-[#6B6F64] dark:text-[#9C9F94]">Loading…</p>
      </main>
    );
  }

  return (
    <div className="flex min-h-screen flex-col bg-[#F1F0EB] font-sans text-[#1E211D] dark:bg-[#15170F] dark:text-[#E7E5DC]">
      <WorkspaceNav />

      <main className="flex flex-1 flex-col items-center justify-center gap-8 px-6 py-24">
        {!workspaces ? (
          <p className="text-sm text-[#6B6F64] dark:text-[#9C9F94]">
            Loading your workspaces…
          </p>
        ) : (
          <section className="w-full max-w-md text-center">
            <h1 className="font-serif text-3xl tracking-tight">
              Welcome to Mindbase
            </h1>
            <p className="mx-auto mt-3 max-w-sm text-[#4A4D45] dark:text-[#9C9F94]">
              Create a workspace to keep your knowledge organized. You&rsquo;ll
              be taken straight into it.
            </p>

            <div className="mt-10 flex justify-center">
              <CreateWorkspaceForm
                onCreated={(workspace) =>
                  router.push(`/workspaces/${workspace.id}`)
                }
              />
            </div>

            <p className="mt-8 text-sm text-[#8B8A7D]">
              <Link
                href="/"
                className="text-[#3B4A6B] hover:underline dark:text-[#8CA0D8]"
              >
                Back to home
              </Link>
            </p>
          </section>
        )}
      </main>
    </div>
  );
}