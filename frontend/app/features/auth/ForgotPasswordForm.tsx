"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";
import axios from "axios";

import { useAuth } from "@/app/Providers/AuthProvider";

export function ForgotPasswordForm() {
  const [email, setEmail] = useState("");
  const [loading, setLoading] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [error, setError] = useState("");

  const { forgotPassword } = useAuth();

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    setLoading(true);
    setError("");

    try {
      await forgotPassword(email);
      setSubmitted(true);
    } catch (err) {
      if (axios.isAxiosError(err)) {
        setError(
          err.response?.data?.message ??
            "Something went wrong while sending the reset link. Please try again.",
        );
      } else {
        setError(
          "Something went wrong while sending the reset link. Please try again.",
        );
      }
    } finally {
      setLoading(false);
    }
  }

  if (submitted) {
    return (
      <div className="rounded-xl border border-[#DDDCE3] bg-[#F7F6F2] p-5">
        <div className="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-[#EEEDFF] text-[#5146E5]">
          <svg
            width="19"
            height="19"
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
        </div>

        <h2 className="text-sm font-semibold text-[#20202A]">
          Check your email
        </h2>

        <p className="mt-1 text-sm leading-relaxed text-[#686873]">
          If an account exists for{" "}
          <span className="font-medium text-[#20202A]">{email}</span>, we&rsquo;ve
          sent instructions to reset your password.
        </p>

        <div className="mt-5 flex items-center gap-4">
          <button
            type="button"
            onClick={() => setSubmitted(false)}
            className="text-sm font-medium text-[#5146E5] transition hover:text-[#4338CA]"
          >
            Try another email
          </button>

          <Link
            href="/auth/login"
            className="text-sm font-medium text-[#686873] transition hover:text-[#20202A]"
          >
            Back to login
          </Link>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-5">
      <div className="flex flex-col gap-2">
        <label
          htmlFor="email"
          className="text-sm font-medium text-[#20202A]"
        >
          Email address
        </label>

        <input
          id="email"
          name="email"
          type="email"
          autoComplete="email"
          required
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          placeholder="you@example.com"
          className="w-full rounded-lg border border-[#DDDCE3] bg-white px-4 py-3 text-sm text-[#20202A] outline-none transition placeholder:text-[#A3A2AC] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF]"
        />
      </div>

      {error && (
        <p
          role="alert"
          className="rounded-lg border border-[#F2C9D1] bg-[#FCECEF] px-4 py-3 text-sm leading-relaxed text-[#B13D55]"
        >
          {error}
        </p>
      )}

      <button
        type="submit"
        disabled={loading}
        className="flex w-full items-center justify-center rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition-colors hover:bg-[#4338CA] focus:outline-none focus:ring-2 focus:ring-[#5146E5] focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
      >
        {loading ? "Sending reset link…" : "Send reset link"}
      </button>
    </form>
  );
}
