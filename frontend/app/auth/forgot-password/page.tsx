"use client";

import Image from "next/image";
import Link from "next/link";

import { ForgotPasswordForm } from "@/app/features/auth/ForgotPasswordForm";
import { ThemeToggle } from "@/app/components/ThemeToggle";


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

export default function ForgotPasswordPage() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-[#F2F0EA] dark:bg-[#121214] px-5 py-10 font-sans sm:px-8">
      <main className="grid w-full max-w-4xl overflow-hidden rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] shadow-sm sm:grid-cols-2">
        {/* Brand panel */}
        <div className="relative hidden min-h-[600px] overflow-hidden sm:flex sm:flex-col sm:justify-between">
          <Image
            src="/images/login_img.webp"
            alt=""
            fill
            priority
            className="object-cover"
          />

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
                “Your ideas are still here. Let&rsquo;s get you back in.”
              </p>

              <p className="mt-4 text-sm font-medium text-white/75">
                Reset your password and continue where you left off.
              </p>
            </div>
          </div>
        </div>

        {/* Form panel */}
        <div className="flex flex-col justify-center gap-8 p-7 sm:p-12">
          <div className="flex items-center justify-between gap-3">
            <Link
              href="/"
              className="flex items-center gap-2 text-[#20202A] dark:text-[#ececf1] sm:hidden"
            >
              <Logomark />
              <span className="text-lg font-semibold tracking-tight">
                Mindbase
              </span>
            </Link>

            <div className="ml-auto">
              <ThemeToggle />
            </div>
          </div>

          <div className="flex flex-col gap-2">
            <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
              Account recovery
            </p>

            <h1 className="text-3xl font-semibold tracking-[-0.025em] text-[#20202A] dark:text-[#ececf1]">
              Forgot your password?
            </h1>

            <p className="text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
              Enter the email address linked to your account and we&rsquo;ll
              send you a link to create a new password.
            </p>
          </div>

          <ForgotPasswordForm />

          <p className="text-sm text-[#686873] dark:text-[#b0b0b9]">
            Remember your password?{" "}
            <Link
              href="/auth/login"
              className="font-medium text-[#5146E5] transition hover:text-[#4338CA]"
            >
              Log in
            </Link>
          </p>
        </div>
      </main>
    </div>
  );
}
