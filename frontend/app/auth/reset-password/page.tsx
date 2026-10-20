"use client";

import { Suspense } from "react";
import Image from "next/image";
import Link from "next/link";
import { useSearchParams } from "next/navigation";

import { ResetPasswordForm } from "@/app/features/auth/ResetPasswordForm";
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

function InvalidLinkCard() {
  return (
    <div className="rounded-xl border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] p-5">
      <div className="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-[#F2C9D1] dark:bg-[#43212b] text-[#B13D55] dark:text-[#f0a0b0]">
        <svg
          width="19"
          height="19"
          viewBox="0 0 24 24"
          fill="none"
          aria-hidden="true"
        >
          <path
            d="M10.3 4.8L3.8 16.2C3.05 17.52 4 19.15 5.52 19.15H18.48C20 19.15 20.95 17.52 20.2 16.2L13.7 4.8C12.95 3.48 11.05 3.48 10.3 4.8Z"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinejoin="round"
          />
          <path
            d="M12 8V12"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
          />
          <circle
            cx="12"
            cy="15.5"
            r="0.9"
            fill="currentColor"
          />
        </svg>
      </div>

      <h2 className="text-sm font-semibold text-[#20202A] dark:text-[#ececf1]">
        Reset link is invalid or expired
      </h2>

      <p className="mt-1 text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
        This password reset link is no longer valid. Please request a new
        link and try again.
      </p>

      <div className="mt-5 flex flex-wrap items-center gap-4">
        <Link
          href="/auth/forgot-password"
          className="text-sm font-medium text-[#5146E5] transition hover:text-[#4338CA]"
        >
          Request a new link
        </Link>

        <Link
          href="/auth/login"
          className="text-sm font-medium text-[#686873] dark:text-[#b0b0b9] transition hover:text-[#20202A]"
        >
          Back to login
        </Link>
      </div>
    </div>
  );
}

function ResetPasswordPanelContent() {
  const searchParams = useSearchParams();

  const token = searchParams.get("token");
  const email = searchParams.get("email");

  if (!token || !email) {
    return (
      <>
        <div className="flex flex-col gap-2">
          <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
            Account recovery
          </p>

          <h1 className="text-3xl font-semibold tracking-[-0.025em] text-[#20202A] dark:text-[#ececf1]">
            Unable to reset your password
          </h1>

          <p className="text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
            This password reset link is invalid or has expired. Request a
            new one to continue.
          </p>
        </div>

        <InvalidLinkCard />

        <p className="text-sm text-[#686873] dark:text-[#b0b0b9]">
          Remember your password?{" "}
          <Link
            href="/auth/login"
            className="font-medium text-[#5146E5] transition hover:text-[#4338CA]"
          >
            Back to login
          </Link>
        </p>
      </>
    );
  }

  return (
    <>
      <div className="flex flex-col gap-2">
        <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
          Account recovery
        </p>

        <h1 className="text-3xl font-semibold tracking-[-0.025em] text-[#20202A] dark:text-[#ececf1]">
          Create a new password
        </h1>

        <p className="text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
          Choose a new password for your Mindbase account. Make sure it&rsquo;s
          something you&rsquo;ll remember.
        </p>
      </div>

      <ResetPasswordForm token={token} email={email} />

      <p className="text-sm text-[#686873] dark:text-[#b0b0b9]">
        Remember your password?{" "}
        <Link
          href="/auth/login"
          className="font-medium text-[#5146E5] transition hover:text-[#4338CA]"
        >
          Back to login
        </Link>
      </p>
    </>
  );
}

export default function ResetPasswordPage() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-[#F2F0EA] dark:bg-[#121214] px-5 py-10 font-sans sm:px-8">
      <main className="grid w-full max-w-4xl overflow-hidden rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] shadow-sm sm:grid-cols-2">
        {/* Brand panel */}
        <div className="relative hidden min-h-[600px] overflow-hidden sm:flex sm:flex-col sm:justify-between">
          <Image
            src="/images/register_img.jpg"
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
                “A fresh start for the thoughts you&rsquo;ve been collecting.”
              </p>

              <p className="mt-4 text-sm font-medium text-white/75">
                Choose a new password and get back to your archive.
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

            <Suspense fallback={null}>
              <ResetPasswordPanelContent />
            </Suspense>
          </div>
      </main>
    </div>
  );
}
