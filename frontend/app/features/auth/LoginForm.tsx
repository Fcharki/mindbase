"use client";

import { useState, type FormEvent } from "react";
import { useRouter, useSearchParams } from "next/navigation";

import { useAuth } from "@/app/Providers/AuthProvider";

function GoogleIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"
        fill="#4285F4"
      />
      <path
        d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.99.66-2.26 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.85A11 11 0 0 0 12 23z"
        fill="#34A853"
      />
      <path
        d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.05H2.18a11 11 0 0 0 0 9.9l3.66-2.85z"
        fill="#FBBC05"
      />
      <path
        d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1a11 11 0 0 0-9.82 6.05l3.66 2.85C6.71 7.3 9.14 5.38 12 5.38z"
        fill="#EA4335"
      />
    </svg>
  );
}

export function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { login, loginWithGoogle } = useAuth();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      await login(email, password);
      const next = searchParams.get("next") ?? "/dashboard";
      router.push(next);
      router.refresh();
    } catch {
      setError("Invalid credentials. Please try again.");
      setSubmitting(false);
    }
  }

  async function handleGoogleLogin() {
    setSubmitting(true);
    setError(null);

    try {
      await loginWithGoogle();
    } catch {
      setError("Unable to sign in with Google. Please try again.");
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="w-full max-w-sm space-y-6">
      <button
        type="button"
        onClick={handleGoogleLogin}
        disabled={submitting}
        className="flex w-full items-center justify-center gap-2 rounded-full border border-[#D8D5C9] bg-white px-4 py-2.5 text-sm font-medium text-[#1E211D] hover:bg-[#F7F6F2] disabled:opacity-50 dark:border-[#2C2E23] dark:bg-transparent dark:text-[#E7E5DC] dark:hover:bg-[#22261B]"
      >
        <GoogleIcon />
        Continue with Google
      </button>

      <div className="relative flex items-center">
        <div className="h-px flex-1 bg-[#E2E0D4] dark:bg-[#2C2E23]" />
        <span className="px-3 text-xs text-[#8B8A7D] dark:text-[#71715F]">or</span>
        <div className="h-px flex-1 bg-[#E2E0D4] dark:bg-[#2C2E23]" />
      </div>

      <div className="space-y-5">
        <div className="flex flex-col gap-1.5">
          <label
            htmlFor="email"
            className="text-sm text-[#4A4D45] dark:text-[#9C9F94]"
          >
            Email
          </label>
          <input
            id="email"
            type="email"
            required
            autoComplete="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            className="w-full border-b border-[#C8C5B8] bg-transparent py-1.5 text-[#1E211D] focus:border-[#3B4A6B] focus:outline-none dark:border-[#3A3D30] dark:text-[#E7E5DC] dark:focus:border-[#8CA0D8]"
          />
        </div>

        <div className="flex flex-col gap-1.5">
          <label
            htmlFor="password"
            className="text-sm text-[#4A4D45] dark:text-[#9C9F94]"
          >
            Password
          </label>
          <input
            id="password"
            type="password"
            required
            autoComplete="current-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            className="w-full border-b border-[#C8C5B8] bg-transparent py-1.5 text-[#1E211D] focus:border-[#3B4A6B] focus:outline-none dark:border-[#3A3D30] dark:text-[#E7E5DC] dark:focus:border-[#8CA0D8]"
          />
        </div>
      </div>

      {error && <p className="text-sm text-[#B3452C]">{error}</p>}

      <button
        type="submit"
        disabled={submitting}
        className="w-full rounded-full bg-[#3B4A6B] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#2F3C57] disabled:opacity-50"
      >
        {submitting ? "Signing in…" : "Sign in"}
      </button>
    </form>
  );
}