"use client";

import { useState } from "react";
import Link from "next/link";

const isLoggedIn = false;

function Logomark() {
  return (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M4 20V6.5C4 5.12 5.12 4 6.5 4c1.2 0 2.23.83 2.45 2M9 20V8c0-1.66 1.34-3 3-3s3 1.34 3 3M15 20v-9c0-1.38 1.12-2.5 2.5-2.5S20 9.62 20 11v9"
        stroke="#3B4A6B"
        strokeWidth="1.6"
        strokeLinecap="round"
      />
    </svg>
  );
}

const features: { title: string; body: string }[] = [
  {
    title: "Capture without friction",
    body: "Jot a thought the moment it happens. No tags to choose and no folder to pick — just write, and sort it out later or never.",
  },
  {
    title: "Notes that link themselves",
    body: "Mindbase reads what you write and quietly draws connections to related notes, so your thinking forms a web instead of a pile.",
  },
  {
    title: "Watch an idea take shape",
    body: "Open any note and see the threads running through it — every related entry, source, and earlier version of the thought.",
  },
  {
    title: "Old notes find their way back to you",
    body: "Before something slips from memory, Mindbase resurfaces it — a quiet nudge to revisit, not another inbox to manage.",
  },
];

export default function Home() {
  const [menuOpen, setMenuOpen] = useState(false);

  return (
    <div className="flex min-h-screen flex-col bg-[#F1F0EB] font-sans text-[#1E211D]">
      {/* Navbar */}
      <header className="border-b border-[#D8D5C9]">
        <div className="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-4 sm:px-10">
          <Link href="/" className="flex items-center gap-2">
            <Logomark />
            <span className="font-serif text-lg tracking-tight">Mindbase</span>
          </Link>

          <nav className="hidden items-center gap-8 text-sm sm:flex">
            <a href="#features" className="text-[#4A4D45] hover:text-[#1E211D]">
              Features
            </a>
            <a href="#method" className="text-[#4A4D45] hover:text-[#1E211D]">
              Method
            </a>
            {isLoggedIn ? (
              <Link
                href="/dashboard"
                className="rounded-full bg-[#3B4A6B] px-4 py-2 text-white hover:bg-[#2F3C57]"
              >
                Dashboard
              </Link>
            ) : (
              <div className="flex items-center gap-4">
                <Link href="/auth/login" className="text-[#4A4D45] hover:text-[#1E211D]">
                  Log in
                </Link>
                <Link
                  href="/auth/register"
                  className="rounded-full bg-[#3B4A6B] px-4 py-2 text-white hover:bg-[#2F3C57]"
                >
                  Get started free
                </Link>
              </div>
            )}
          </nav>

          {/* Mobile menu toggle */}
          <button
            onClick={() => setMenuOpen((v) => !v)}
            className="flex flex-col gap-1.5 sm:hidden"
            aria-label="Toggle menu"
          >
            <span className="h-px w-6 bg-[#1E211D]" />
            <span className="h-px w-6 bg-[#1E211D]" />
          </button>
        </div>

        {menuOpen && (
          <div className="flex flex-col gap-4 border-t border-[#D8D5C9] px-6 py-4 text-sm sm:hidden">
            <a href="#features" onClick={() => setMenuOpen(false)}>
              Features
            </a>
            <a href="#method" onClick={() => setMenuOpen(false)}>
              Method
            </a>
            {isLoggedIn ? (
              <Link href="/dashboard" onClick={() => setMenuOpen(false)}>
                Dashboard
              </Link>
            ) : (
              <>
                <Link href="/auth/login" onClick={() => setMenuOpen(false)}>
                  Log in
                </Link>
                <Link href="/auth/register" onClick={() => setMenuOpen(false)} className="font-medium text-[#3B4A6B]">
                  Get started free
                </Link>
              </>
            )}
          </div>
        )}
      </header>

      <main className="flex-1">
        {/* Hero */}
        <section className="mx-auto flex w-full max-w-3xl flex-col items-start gap-6 px-6 py-24 sm:px-10 sm:py-32">
          <h1 className="text-balance font-serif text-4xl leading-tight tracking-tight sm:text-5xl">
            Where scattered thoughts become a body of work.
          </h1>
          <p className="max-w-xl text-lg text-[#4A4D45]">
            Mindbase is a place to capture what you read, connect what you
            learn, and find it again when you need it — without folders
            you&rsquo;ll forget to check.
          </p>
          <div className="flex items-center gap-6">
            <Link
              href="/auth/register"
              className="rounded-full bg-[#3B4A6B] px-6 py-3 text-white hover:bg-[#2F3C57]"
            >
              Get started free
            </Link>
            <a href="#features" className="text-[#1E211D] underline decoration-[#C8C5B8] underline-offset-4 hover:decoration-[#1E211D]">
              See how it works
            </a>
          </div>
        </section>

        {/* Features */}
        <section id="features" className="border-t border-[#D8D5C9]">
          <div className="mx-auto grid w-full max-w-5xl gap-x-16 gap-y-14 px-6 py-24 sm:grid-cols-2 sm:px-10">
            {features.map((f) => (
              <div key={f.title} className="flex flex-col gap-2">
                <h3 className="font-serif text-xl">{f.title}</h3>
                <p className="text-[15px] leading-relaxed text-[#4A4D45]">{f.body}</p>
              </div>
            ))}
          </div>
        </section>

        {/* Pull quote */}
        <section id="method" className="border-t border-[#D8D5C9] bg-[#E7E5DC]">
          <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-24 sm:px-10">
            <p className="text-balance font-serif text-2xl leading-snug sm:text-3xl">
              &ldquo;I finally have a second brain that doesn&rsquo;t feel like
              admin work. I write, and the connections show up on their
              own.&rdquo;
            </p>
            <p className="text-sm text-[#5C6B4F]">Alex Chen, PhD researcher</p>
          </div>
        </section>

        {/* Closing CTA */}
        <section className="border-t border-[#D8D5C9]">
          <div className="mx-auto flex w-full max-w-3xl flex-col items-start gap-6 px-6 py-24 sm:px-10">
            <h2 className="font-serif text-3xl tracking-tight">
              Start your archive today.
            </h2>
            <Link
              href="/auth/register"
              className="rounded-full bg-[#3B4A6B] px-6 py-3 text-white hover:bg-[#2F3C57]"
            >
              Create a free account
            </Link>
          </div>
        </section>
      </main>

      <footer className="border-t border-[#D8D5C9]">
        <div className="mx-auto flex w-full max-w-5xl flex-col items-start justify-between gap-4 px-6 py-10 text-sm text-[#8B8A7D] sm:flex-row sm:items-center sm:px-10">
          <div className="flex items-center gap-2">
            <Logomark />
            <span>Mindbase</span>
          </div>
          <p>© { new Date().getFullYear()} Mindbase. All notes reserved.</p>
        </div>
      </footer>
    </div>
  );
}