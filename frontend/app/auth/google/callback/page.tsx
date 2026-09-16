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
    <main className="flex min-h-screen items-center justify-center bg-[#F1F0EB] dark:bg-[#15170F]">
      <p className="text-sm text-[#6B6F64] dark:text-[#9C9F94]">
        {loading ? "Signing you in…" : "Redirecting…"}
      </p>
    </main>
  );
}