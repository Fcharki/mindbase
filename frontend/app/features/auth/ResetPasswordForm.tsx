"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";
import axios from "axios";

import { useAuth } from "@/app/Providers/AuthProvider";

export function ResetPasswordForm({
  token,
  email,
}: {
  token: string;
  email: string;
}) {
  const router = useRouter();
  const { resetPassword } = useAuth();

  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    setError("");

    if (password.length < 8) {
      setError("Your password must be at least 8 characters long.");
      return;
    }

    if (password !== confirmPassword) {
      setError("Your passwords do not match.");
      return;
    }

    setLoading(true);

    try {
      await resetPassword(token, email, password, confirmPassword);
      router.replace("/auth/login?reset=success");
    } catch (err) {
      if (axios.isAxiosError(err)) {
        setError(
          err.response?.data?.message ??
            "Unable to reset your password. The link may be invalid or expired.",
        );
      } else {
        setError(
          "Unable to reset your password. The link may be invalid or expired.",
        );
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-5">
      <div className="flex flex-col gap-2">
        <label
          htmlFor="password"
          className="text-sm font-medium text-[#20202A] dark:text-[#ececf1]"
        >
          New password
        </label>

        <input
          id="password"
          name="password"
          type="password"
          autoComplete="new-password"
          required
          minLength={8}
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          placeholder="At least 8 characters"
          className="w-full rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-3 text-sm text-[#20202A] dark:text-[#ececf1] outline-none transition placeholder:text-[#A3A2AC] dark:placeholder:text-[#7e7e88] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
        />
      </div>

      <div className="flex flex-col gap-2">
        <label
          htmlFor="confirmPassword"
          className="text-sm font-medium text-[#20202A] dark:text-[#ececf1]"
        >
          Confirm new password
        </label>

        <input
          id="confirmPassword"
          name="confirmPassword"
          type="password"
          autoComplete="new-password"
          required
          minLength={8}
          value={confirmPassword}
          onChange={(event) => setConfirmPassword(event.target.value)}
          placeholder="Enter your password again"
          className="w-full rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-3 text-sm text-[#20202A] dark:text-[#ececf1] outline-none transition placeholder:text-[#A3A2AC] dark:placeholder:text-[#7e7e88] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
        />
      </div>

      {error && (
        <p
          role="alert"
          className="rounded-lg border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-4 py-3 text-sm leading-relaxed text-[#B13D55] dark:text-[#f0a0b0]"
        >
          {error}
        </p>
      )}

      <button
        type="submit"
        disabled={loading}
        className="flex w-full items-center justify-center rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition-colors hover:bg-[#4338CA] focus:outline-none focus:ring-2 focus:ring-[#5146E5] focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
      >
        {loading ? "Updating password…" : "Update password"}
      </button>
    </form>
  );
}
