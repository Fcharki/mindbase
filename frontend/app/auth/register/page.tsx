"use client";

import Image from "next/image";
import Link from "next/link";

import { RegisterForm } from "@/app/features/auth/RegisterForm";

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

          <RegisterForm />

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