"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

import { useAuth } from "@/app/Providers/AuthProvider";

export default function GoogleCallbackPage() {
  const router = useRouter();
  const { loading } = useAuth();

  useEffect(() => {
    if (loading) {
      return;
    }

    router.replace("/dashboard");
  }, [loading, router]);

  return (
    <main className="flex min-h-screen items-center justify-center bg-[#F7F6F2] font-sans">
      <div className="flex flex-col items-center gap-4">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-[#EEEDFF]">
          <span className="h-2.5 w-2.5 rounded-full bg-[#5146E5]" />
        </div>

        <div className="text-center">
          <p className="text-sm font-medium text-[#20202A]">
            {loading ? "Signing you in…" : "Redirecting…"}
          </p>

          <p className="mt-1 text-sm text-[#9897A1]">
            Taking you to your workspace
          </p>
        </div>
      </div>
    </main>
  );
}
