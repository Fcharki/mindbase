"use client";

import { useState } from "react";
import Link from "next/link";

import { useAuth } from "@/app/Providers/AuthProvider";

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
        stroke="#5146E5"
        strokeWidth="1.7"
        strokeLinecap="round"
      />
    </svg>
  );
}

const features = [
  {
    number: "01",
    title: "Capture without friction",
    body: "Jot a thought the moment it happens. No tags to choose and no folder to pick — just write, and sort it out later or never.",
    bg: "bg-[#EEEDFF]",
    accent: "text-[#5146E5]",
  },
  {
    number: "02",
    title: "Notes that link themselves",
    body: "Mindbase reads what you write and quietly draws connections to related notes, so your thinking forms a web instead of a pile.",
    bg: "bg-[#FCECEF]",
    accent: "text-[#E85D75]",
  },
  {
    number: "03",
    title: "Watch an idea take shape",
    body: "Open any note and see the threads running through it — every related entry, source, and earlier version of the thought.",
    bg: "bg-[#EAF7F6]",
    accent: "text-[#159A9C]",
  },
  {
    number: "04",
    title: "Old notes find their way back to you",
    body: "Before something slips from memory, Mindbase resurfaces it — a quiet nudge to revisit, not another inbox to manage.",
    bg: "bg-[#F2F1F7]",
    accent: "text-[#5146E5]",
  },
];

export default function Home() {
  const [menuOpen, setMenuOpen] = useState(false);
  const { user, loading } = useAuth();
  const isLoggedIn = Boolean(user) && !loading;

  return (
    <div className="flex min-h-screen flex-col bg-[#F7F6F2] font-sans text-[#20202A]">
      {/* Navbar */}
      <header className="border-b border-[#DDDCE3]">
        <div className="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-4 sm:px-10">
          <Link href="/" className="flex items-center gap-2">
            <Logomark />
            <span className="text-lg font-semibold tracking-tight">
              Mindbase
            </span>
          </Link>

          <nav className="hidden items-center gap-8 text-sm sm:flex">
            <a
              href="#features"
              className="text-[#686873] transition hover:text-[#5146E5]"
            >
              Features
            </a>

            <a
              href="#method"
              className="text-[#686873] transition hover:text-[#5146E5]"
            >
              Method
            </a>

            {isLoggedIn ? (
              <Link
                href="/dashboard"
                className="rounded-lg bg-[#5146E5] px-4 py-2 font-medium text-white transition hover:bg-[#4338CA]"
              >
                Dashboard
              </Link>
            ) : (
              <div className="flex items-center gap-5">
                <Link
                  href="/auth/login"
                  className="text-[#686873] transition hover:text-[#20202A]"
                >
                  Log in
                </Link>

                <Link
                  href="/auth/register"
                  className="rounded-lg bg-[#5146E5] px-4 py-2 font-medium text-white transition hover:bg-[#4338CA]"
                >
                  Get started free
                </Link>
              </div>
            )}
          </nav>

          <button
            onClick={() => setMenuOpen((v) => !v)}
            className="flex flex-col gap-1.5 sm:hidden"
            aria-label="Toggle menu"
          >
            <span className="h-px w-6 bg-[#20202A]" />
            <span className="h-px w-6 bg-[#20202A]" />
          </button>
        </div>

        {menuOpen && (
          <div className="flex flex-col gap-4 border-t border-[#DDDCE3] bg-white px-6 py-4 text-sm sm:hidden">
            <a
              href="#features"
              onClick={() => setMenuOpen(false)}
              className="text-[#686873]"
            >
              Features
            </a>

            <a
              href="#method"
              onClick={() => setMenuOpen(false)}
              className="text-[#686873]"
            >
              Method
            </a>

            {isLoggedIn ? (
              <Link
                href="/dashboard"
                onClick={() => setMenuOpen(false)}
                className="font-medium text-[#5146E5]"
              >
                Dashboard
              </Link>
            ) : (
              <>
                <Link
                  href="/auth/login"
                  onClick={() => setMenuOpen(false)}
                  className="text-[#686873]"
                >
                  Log in
                </Link>

                <Link
                  href="/auth/register"
                  onClick={() => setMenuOpen(false)}
                  className="font-medium text-[#5146E5]"
                >
                  Get started free
                </Link>
              </>
            )}
          </div>
        )}
      </header>

      <main className="flex-1">
        {/* Hero */}
        <section>
          <div className="mx-auto flex w-full max-w-4xl flex-col items-start px-6 py-24 sm:px-10 sm:py-32">
            <div className="mb-6 flex items-center gap-2 text-sm font-medium text-[#5146E5]">
              <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
              A better place for your ideas
            </div>

            <h1 className="max-w-3xl text-balance text-5xl font-semibold leading-[1.08] tracking-[-0.035em] sm:text-6xl">
              Where scattered thoughts become{" "}
              <span className="text-[#5146E5]">a body of work.</span>
            </h1>

            <p className="mt-7 max-w-2xl text-lg leading-relaxed text-[#686873]">
              Mindbase is a place to capture what you read, connect what you
              learn, and find it again when you need it — without folders
              you&rsquo;ll forget to check.
            </p>

            <div className="mt-8 flex flex-col items-start gap-5 sm:flex-row sm:items-center">
              {isLoggedIn ? (
                <Link
                  href="/dashboard"
                  className="rounded-lg bg-[#5146E5] px-6 py-3 font-medium text-white transition hover:bg-[#4338CA]"
                >
                  Open your workspace
                </Link>
              ) : (
                <Link
                  href="/auth/register"
                  className="rounded-lg bg-[#5146E5] px-6 py-3 font-medium text-white transition hover:bg-[#4338CA]"
                >
                  Get started free
                </Link>
              )}

              <a
                href="#features"
                className="font-medium text-[#686873] underline decoration-[#C9C7D0] underline-offset-4 transition hover:text-[#20202A]"
              >
                See how it works
              </a>
            </div>
          </div>
        </section>

        {/* Features */}
        <section
          id="features"
          className="border-t border-[#DDDCE3] bg-white"
        >
          <div className="mx-auto w-full max-w-5xl px-6 py-24 sm:px-10">
            <div className="mb-14 max-w-xl">
              <p className="mb-3 text-sm font-semibold uppercase tracking-[0.12em] text-[#5146E5]">
                Features
              </p>

              <h2 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                Your thoughts, without the busywork.
              </h2>
            </div>

            <div className="grid gap-5 sm:grid-cols-2">
              {features.map((f) => (
                <div
                  key={f.title}
                  className={`rounded-2xl ${f.bg} p-7 sm:p-8`}
                >
                  <div className="mb-12 flex items-center justify-between">
                    <span
                      className={`text-sm font-semibold ${f.accent}`}
                    >
                      {f.number}
                    </span>

                    <span
                      className={`text-xl ${f.accent}`}
                      aria-hidden="true"
                    >
                      ↗
                    </span>
                  </div>

                  <h3 className="mb-3 text-xl font-semibold tracking-tight">
                    {f.title}
                  </h3>

                  <p className="text-[15px] leading-relaxed text-[#686873]">
                    {f.body}
                  </p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Method */}
        <section
          id="method"
          className="border-t border-[#DDDCE3] bg-[#20202A] text-white"
        >
          <div className="mx-auto flex w-full max-w-4xl flex-col gap-7 px-6 py-24 sm:px-10 sm:py-28">
            <p className="text-sm font-semibold uppercase tracking-[0.12em] text-[#A9A4FF]">
              The Mindbase method
            </p>

            <p className="max-w-3xl text-balance text-2xl font-medium leading-snug tracking-tight sm:text-4xl">
              “I finally have a second brain that doesn&rsquo;t feel like
              admin work. I write, and the connections show up on their
              own.”
            </p>

            <p className="text-sm text-[#A7A6B0]">
              Alex Chen, PhD researcher
            </p>
          </div>
        </section>

        {/* CTA */}
        <section className="border-t border-[#DDDCE3]">
          <div className="mx-auto flex w-full max-w-4xl flex-col items-start gap-6 px-6 py-24 sm:px-10 sm:py-28">
            <p className="text-sm font-semibold uppercase tracking-[0.12em] text-[#5146E5]">
              Start today
            </p>

            <h2 className="text-4xl font-semibold tracking-[-0.03em] sm:text-5xl">
              Start your archive today.
            </h2>

            <p className="max-w-xl text-lg text-[#686873]">
              Give your ideas somewhere to grow, connect, and come back to you.
            </p>

            {isLoggedIn ? (
              <Link
                href="/dashboard"
                className="rounded-lg bg-[#5146E5] px-6 py-3 font-medium text-white transition hover:bg-[#4338CA]"
              >
                Open your workspace
              </Link>
            ) : (
              <Link
                href="/auth/register"
                className="rounded-lg bg-[#5146E5] px-6 py-3 font-medium text-white transition hover:bg-[#4338CA]"
              >
                Create a free account
              </Link>
            )}
          </div>
        </section>
      </main>

      {/* Footer */}
      <footer className="border-t border-[#DDDCE3] bg-white">
        <div className="mx-auto flex w-full max-w-5xl flex-col items-start justify-between gap-4 px-6 py-9 text-sm text-[#888793] sm:flex-row sm:items-center sm:px-10">
          <div className="flex items-center gap-2">
            <Logomark />
            <span className="font-medium text-[#20202A]">Mindbase</span>
          </div>

          <p>© {new Date().getFullYear()} Mindbase. All notes reserved.</p>
        </div>
      </footer>
    </div>
  );
}
