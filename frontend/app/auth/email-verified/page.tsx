"use client";

import { Suspense } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";

function EmailVerifiedContent() {
  const searchParams = useSearchParams();
  const status = searchParams.get("status");

  const isSuccess = status === "success";
  const isAlreadyVerified = status === "already-verified";
  const isVerified = isSuccess || isAlreadyVerified;

  const title = isSuccess
    ? "Email verified successfully"
    : isAlreadyVerified
      ? "Email already verified"
      : "Verification link is invalid or expired";

  const description = isSuccess
    ? "Your email address has been verified. You can now log in to your Mindbase account."
    : isAlreadyVerified
      ? "Your email address has already been verified. You can continue to your account."
      : "This verification link is no longer valid. Please request a new verification email and try again.";

  return (
    <main className="flex min-h-screen items-center justify-center bg-[#F7F6F2] px-5 py-10 font-sans sm:px-8">
      <div className="w-full max-w-md">
        <div className="rounded-2xl border border-[#DDDCE3] bg-white p-7 shadow-sm sm:p-10">
          {/* Status icon */}
          <div
            className={`mb-7 flex h-12 w-12 items-center justify-center rounded-xl ${
              isVerified
                ? "bg-[#EEEDFF] text-[#5146E5]"
                : "bg-[#FCECEF] text-[#E85D75]"
            }`}
          >
            {isVerified ? (
              <svg
                width="23"
                height="23"
                viewBox="0 0 24 24"
                fill="none"
                aria-hidden="true"
              >
                <path
                  d="M5 12.5L9.5 17L19 7.5"
                  stroke="currentColor"
                  strokeWidth="1.8"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </svg>
            ) : (
              <svg
                width="23"
                height="23"
                viewBox="0 0 24 24"
                fill="none"
                aria-hidden="true"
              >
                <path
                  d="M12 8V12.5"
                  stroke="currentColor"
                  strokeWidth="1.8"
                  strokeLinecap="round"
                />
                <circle
                  cx="12"
                  cy="16"
                  r="0.9"
                  fill="currentColor"
                />
                <path
                  d="M10.3 4.8L3.8 16.2C3.05 17.52 4 19.15 5.52 19.15H18.48C20 19.15 20.95 17.52 20.2 16.2L13.7 4.8C12.95 3.48 11.05 3.48 10.3 4.8Z"
                  stroke="currentColor"
                  strokeWidth="1.5"
                  strokeLinejoin="round"
                />
              </svg>
            )}
          </div>

          {/* Content */}
          <div className="space-y-3">
            <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
              {isVerified ? "Email confirmed" : "Verification issue"}
            </p>

            <h1 className="text-3xl font-semibold leading-tight tracking-[-0.025em] text-[#20202A] sm:text-4xl">
              {title}
            </h1>

            <p className="text-sm leading-6 text-[#686873] sm:text-base">
              {description}
            </p>
          </div>

          {/* Action */}
          <div className="mt-8">
            <Link
              href="/auth/login"
              className="flex w-full items-center justify-center rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition-colors hover:bg-[#4338CA] focus:outline-none focus:ring-2 focus:ring-[#5146E5] focus:ring-offset-2"
            >
              Continue to login
            </Link>
          </div>
        </div>

        {/* Brand */}
        <div className="mt-6 flex justify-center">
          <Link
            href="/"
            className="flex items-center gap-2 text-sm font-semibold tracking-tight text-[#20202A]"
          >
            <svg
              width="20"
              height="20"
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

            Mindbase
          </Link>
        </div>
      </div>
    </main>
  );
}

export default function EmailVerifiedPage() {
  return (
    <Suspense fallback={null}>
      <EmailVerifiedContent />
    </Suspense>
  );
}

