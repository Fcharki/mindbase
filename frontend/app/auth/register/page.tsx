"use client";

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import Image from "next/image";
import Link from "next/link";

import { useAuth } from "@/app/Providers/AuthProvider";

function Logomark() {
  return (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M4 20V6.5C4 5.12 5.12 4 6.5 4c1.2 0 2.23.83 2.45 2M9 20V8c0-1.66 1.34-3 3-3s3 1.34 3 3M15 20v-9c0-1.38 1.12-2.5 2.5-2.5S20 9.62 20 11v9"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="round"
      />
    </svg>
  );
}

export default function RegisterPage() {
  const router = useRouter();
  const { register } = useAuth();

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
      await register(name, email, password, passwordConfirmation);
      router.push("/");
    } catch {
      setError("Registration failed. Please check your details and try again.");
      setSubmitting(false);
    }
  }

  return (
    <div className="flex flex-1 items-center justify-center bg-[#F1F0EB] px-6 py-16 font-sans dark:bg-[#15170F]">
      <main className="grid w-full max-w-4xl overflow-hidden rounded-2xl border border-[#D8D5C9] dark:border-[#2C2E23] sm:grid-cols-2">
        {/* Brand panel */}
        <div className="relative hidden overflow-hidden sm:flex sm:flex-col sm:justify-between sm:p-10">
          <Image
            src="/images/register_img.jpg"
            alt=""
            fill
            priority
            className="object-cover"
          />
          <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-transparent" />

          <Link href="/" className="relative z-10 flex items-center gap-2 text-[#E7E5DC]">
            <Logomark />
            <span className="font-serif text-lg tracking-tight">Mindbase</span>
          </Link>

          <div className="relative z-10 flex flex-col gap-3 text-[#E7E5DC]">
            <p className="text-balance font-serif text-2xl leading-snug">
              &ldquo;Where scattered thoughts become a body of work.&rdquo;
            </p>
            <p className="text-sm text-[#9CAE8C]">Start your archive in a minute</p>
          </div>
        </div>

        {/* Form panel */}
        <div className="flex flex-col justify-center gap-8 bg-white p-10 dark:bg-[#1B1E24] sm:p-12">
          <div className="flex sm:hidden">
            <Link
              href="/"
              className="flex items-center gap-2 text-[#1E211D] dark:text-[#E7E5DC]"
            >
              <Logomark />
              <span className="font-serif text-lg tracking-tight">Mindbase</span>
            </Link>
          </div>

          <div className="flex flex-col gap-2">
            <h1 className="font-serif text-3xl tracking-tight text-[#1E211D] dark:text-[#E7E5DC]">
              Create your account
            </h1>
            <p className="text-sm text-[#6B6F64] dark:text-[#9C9F94]">
              A few details and you&rsquo;re ready to start capturing.
            </p>
          </div>

          <form onSubmit={handleSubmit} className="w-full max-w-sm space-y-5">
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
                onChange={(event) => setPasswordConfirmation(event.target.value)}
                className="w-full border-b border-[#C8C5B8] bg-transparent py-1.5 text-[#1E211D] focus:border-[#3B4A6B] focus:outline-none dark:border-[#3A3D30] dark:text-[#E7E5DC] dark:focus:border-[#8CA0D8]"
              />
            </div>

            {error && <p className="text-sm text-[#B3452C]">{error}</p>}

            <button
              type="submit"
              disabled={submitting}
              className="w-full rounded-full bg-[#3B4A6B] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#2F3C57] disabled:opacity-50"
            >
              {submitting ? "Creating account…" : "Create account"}
            </button>
          </form>

          <p className="text-sm text-[#6B6F64] dark:text-[#9C9F94]">
            Already have an account?{" "}
            <Link
              href="/auth/login"
              className="font-medium text-[#3B4A6B] hover:underline dark:text-[#8CA0D8]"
            >
              Log in
            </Link>
          </p>
        </div>
      </main>
    </div>
  );
}