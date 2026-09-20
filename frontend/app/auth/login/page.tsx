"use client";

import { Suspense } from "react";
import Image from "next/image";
import Link from "next/link";
import { useSearchParams } from "next/navigation";

import { LoginForm } from "@/app/features/auth/LoginForm";

function ResetSuccessNotice() {
  const searchParams = useSearchParams();

  if (searchParams.get("reset") !== "success") {
    return null;
  }

  return (
    <div className="rounded-xl border border-[#C8ECD9] bg-[#E9F9F0] px-4 py-3 text-sm leading-relaxed text-[#1F7A44]">
      Your password has been reset. You can now log in with your new
      password.
    </div>
  );
}

function Logomark() {
  return (
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
  );
}

export default function LoginPage() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-[#F7F6F2] px-5 py-10 font-sans sm:px-8">
      <main className="grid w-full max-w-4xl overflow-hidden rounded-2xl border border-[#DDDCE3] bg-white shadow-sm sm:grid-cols-2">
        {/* Brand panel */}
        <div className="relative hidden min-h-[600px] overflow-hidden sm:flex sm:flex-col sm:justify-between">
          <Image
            src="/images/login_img.jpg"
            alt=""
            fill
            priority
            className="object-cover"
          />

          {/* Subtle overlay */}
          <div className="absolute inset-0 bg-[#20202A]/25" />

          <div className="relative z-10 p-8">
            <Link
              href="/"
              className="flex w-fit items-center gap-2 text-white"
            >
              <Logomark />
              <span className="text-lg font-semibold tracking-tight">
                Mindbase
              </span>
            </Link>
          </div>

          <div className="relative z-10 p-8">
            <div className="max-w-sm rounded-xl border border-white/20 bg-black/20 p-5 backdrop-blur-sm">
              <p className="text-balance text-2xl font-semibold leading-snug tracking-tight text-white">
                “A place to keep what you learn, and find it again when you
                need it.”
              </p>

              <p className="mt-4 text-sm font-medium text-white/75">
                Notes that connect themselves.
              </p>
            </div>
          </div>
        </div>

        {/* Form panel */}
        <div className="flex flex-col justify-center gap-8 p-7 sm:p-12">
          {/* Mobile logo */}
          <div className="sm:hidden">
            <Link
              href="/"
              className="flex items-center gap-2 text-[#20202A]"
            >
              <Logomark />
              <span className="text-lg font-semibold tracking-tight">
                Mindbase
              </span>
            </Link>
          </div>

          <div className="flex flex-col gap-6">
            <Suspense fallback={null}>
              <ResetSuccessNotice />
            </Suspense>

            <div className="flex flex-col gap-2">
              <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
                Welcome back
              </p>

              <h1 className="text-3xl font-semibold tracking-[-0.025em] text-[#20202A]">
                Log in to Mindbase
              </h1>

              <p className="text-sm leading-relaxed text-[#686873]">
                Pick up where your notes left off.
              </p>
            </div>
          </div>

          <Suspense fallback={null}>
            <LoginForm />
          </Suspense>

          <p className="text-sm text-[#686873]">
            New to Mindbase?{" "}
            <Link
              href="/auth/register"
              className="font-medium text-[#5146E5] transition hover:text-[#4338CA]"
            >
              Create an account
            </Link>
          </p>
             <p className="text-sm text-[#686873]">
            Forgot password?{" "}
            <Link
              href="/auth/forgot-password"
              className="font-medium text-[#5146E5] transition hover:text-[#4338CA]"
            >
              Reset it
            </Link>
          </p>
        </div>
      </main>
    </div>
  );
}
