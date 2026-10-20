"use client";

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";

import { useAuth } from "@/app/Providers/AuthProvider";

export function RegisterForm() {
  const router = useRouter();
  const { register, loginWithGoogle } = useAuth();

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    setSubmitting(true);
    setError(null);

    try {
      await register(
        name,
        email,
        password,
        passwordConfirmation,
      );

      router.push("/");
    } catch {
      setError(
        "Registration failed. Please check your details and try again.",
      );
      setSubmitting(false);
    }
  }

  async function handleGoogleRegister() {
    setError(null);

    try {
      await loginWithGoogle();
    } catch {
      setError("Google registration failed. Please try again.");
    }
  }

  return (
    <div className="w-full max-w-sm space-y-5">
      <button
        type="button"
        onClick={handleGoogleRegister}
        className="flex w-full items-center justify-center gap-3 rounded-full border border-[#C8C5B8] bg-white px-4 py-2.5 text-sm font-medium text-[#1E211D] transition-colors hover:bg-[#F2F0EA] dark:border-[#3A3D30] dark:bg-[#1B1E24] dark:text-[#E7E5DC] dark:hover:bg-[#22252C]"
      >
        <svg
          width="18"
          height="18"
          viewBox="0 0 24 24"
          aria-hidden="true"
        >
          <path
            fill="#4285F4"
            d="M21.35 12.23c0-.72-.06-1.42-.18-2.09H12v3.96h5.23a4.47 4.47 0 0 1-1.94 2.94v2.45h3.14c1.84-1.69 2.92-4.18 2.92-7.26Z"
          />
          <path
            fill="#34A853"
            d="M12 21.5c2.63 0 4.84-.87 6.45-2.36l-3.14-2.45c-.87.58-1.98.92-3.31.92-2.54 0-4.69-1.72-5.46-4.03H3.3v2.53A9.74 9.74 0 0 0 12 21.5Z"
          />
          <path
            fill="#FBBC05"
            d="M6.54 13.58A5.86 5.86 0 0 1 6.24 12c0-.55.1-1.09.3-1.58V7.89H3.3A9.74 9.74 0 0 0 2.25 12c0 1.57.38 3.05 1.05 4.11l3.24-2.53Z"
          />
          <path
            fill="#EA4335"
            d="M12 6.39c1.43 0 2.72.49 3.73 1.46l2.79-2.79C16.83 3.48 14.63 2.5 12 2.5a9.74 9.74 0 0 0-8.7 5.39l3.24 2.53C7.31 8.11 9.46 6.39 12 6.39Z"
          />
        </svg>

        Continue with Google
      </button>

      <div className="flex items-center gap-3">
        <div className="h-px flex-1 bg-[#D8D5C9] dark:bg-[#2C2E23]" />
        <span className="text-xs text-[#8A8D83] dark:text-[#777B70]">
          or
        </span>
        <div className="h-px flex-1 bg-[#D8D5C9] dark:bg-[#2C2E23]" />
      </div>

      <form onSubmit={handleSubmit} className="space-y-5">
        <div className="flex flex-col gap-1.5">
          <label
            htmlFor="name"
            className="text-sm text-[#4A4D45] dark:text-[#9C9F94]"
          >
            Name
          </label>

          <input
            id="name"
            type="text"
            required
            autoComplete="name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            className="w-full border-b border-[#C8C5B8] bg-transparent py-1.5 text-[#1E211D] focus:border-[#3B4A6B] focus:outline-none dark:border-[#3A3D30] dark:text-[#E7E5DC] dark:focus:border-[#8CA0D8]"
          />
        </div>

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
            autoComplete="new-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            className="w-full border-b border-[#C8C5B8] bg-transparent py-1.5 text-[#1E211D] focus:border-[#3B4A6B] focus:outline-none dark:border-[#3A3D30] dark:text-[#E7E5DC] dark:focus:border-[#8CA0D8]"
          />
        </div>

        <div className="flex flex-col gap-1.5">
          <label
            htmlFor="passwordConfirmation"
            className="text-sm text-[#4A4D45] dark:text-[#9C9F94]"
          >
            Confirm password
          </label>

          <input
            id="passwordConfirmation"
            type="password"
            required
            autoComplete="new-password"
            value={passwordConfirmation}
            onChange={(event) =>
              setPasswordConfirmation(event.target.value)
            }
            className="w-full border-b border-[#C8C5B8] bg-transparent py-1.5 text-[#1E211D] focus:border-[#3B4A6B] focus:outline-none dark:border-[#3A3D30] dark:text-[#E7E5DC] dark:focus:border-[#8CA0D8]"
          />
        </div>

        {error && (
          <p className="text-sm text-[#B3452C]">
            {error}
          </p>
        )}

        <button
          type="submit"
          disabled={submitting}
          className="w-full rounded-full bg-[#3B4A6B] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#2F3C57] disabled:opacity-50"
        >
          {submitting ? "Creating account…" : "Create account"}
        </button>
      </form>
    </div>
  );
}
