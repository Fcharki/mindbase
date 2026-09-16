"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";

export default function EmailVerifiedPage() {
  const searchParams = useSearchParams();
  const status = searchParams.get("status");

  const isSuccess = status === "success";
  const isAlreadyVerified = status === "already-verified";

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
    <main className="flex min-h-screen items-center justify-center bg-[#F1F0EB] px-5 py-12 font-sans dark:bg-[#15170F] sm:px-6">
      <div className="w-full max-w-md">
        <div className="rounded-2xl border border-[#D8D5C9] bg-white p-7 shadow-sm dark:border-[#2C2E23] dark:bg-[#1B1E24] sm:p-10">
          {/* Status icon */}
          <div
            className={`mb-7 flex h-14 w-14 items-center justify-center rounded-full ${
              isSuccess || isAlreadyVerified
                ? "bg-[#E7EDE2] text-[#3B4A6B] dark:bg-[#293127] dark:text-[#9CAE8C]"
                : "bg-[#F5E5E0] text-[#B3452C] dark:bg-[#33231F] dark:text-[#D9826D]"
            }`}
          >
            {isSuccess || isAlreadyVerified ? (
              <svg
                width="25"
                height="25"
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
                width="25"
                height="25"
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
            <h1 className="font-serif text-3xl leading-tight tracking-tight text-[#1E211D] dark:text-[#E7E5DC] sm:text-4xl">
              {title}
            </h1>

            <p className="text-sm leading-6 text-[#6B6F64] dark:text-[#9C9F94] sm:text-base">
              {description}
            </p>
          </div>

          {/* Action */}
          <div className="mt-8">
            <Link
              href="/auth/login"
              className="flex w-full items-center justify-center rounded-full bg-[#3B4A6B] px-5 py-3 text-sm font-medium text-white transition-colors hover:bg-[#2F3C57] focus:outline-none focus:ring-2 focus:ring-[#3B4A6B] focus:ring-offset-2 dark:focus:ring-offset-[#1B1E24]"
            >
              Continue to login
            </Link>
          </div>
        </div>

        {/* Brand */}
        <p className="mt-6 text-center font-serif text-sm tracking-tight text-[#6B6F64] dark:text-[#9C9F94]">
          Mindbase
        </p>
      </div>
    </main>
  );
}
