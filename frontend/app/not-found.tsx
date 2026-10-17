import Link from "next/link";

export default function NotFound() {
  return (
    <div className="flex min-h-screen flex-col bg-[#F7F6F2] dark:bg-[#0f0f0f] font-sans text-[#20202A] dark:text-[#e5e5e5]">
      <main className="flex flex-1 items-center justify-center px-6 py-20">
        <section className="w-full max-w-md rounded-2xl border border-[#DDDCE3] dark:border-[#2a2a2a] bg-white dark:bg-[#141414] p-8 text-center shadow-sm sm:p-10">
          <div className="mx-auto mb-6 flex h-12 w-12 items-center justify-center rounded-xl bg-[#EEEDFF] dark:bg-[#211f3d] text-[#5146E5]">
            <svg
              width="22"
              height="22"
              viewBox="0 0 24 24"
              fill="none"
              aria-hidden="true"
            >
              <circle
                cx="10.5"
                cy="10.5"
                r="6.5"
                stroke="currentColor"
                strokeWidth="1.6"
              />
              <path
                d="M15.5 15.5L20 20"
                stroke="currentColor"
                strokeWidth="1.6"
                strokeLinecap="round"
              />
              <path
                d="M10.5 8v5M10.5 16.2v0"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
              />
            </svg>
          </div>

          <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
            404
          </p>

          <h1 className="mt-1 text-2xl font-semibold tracking-[-0.025em]">
            Page not found
          </h1>

          <p className="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-[#686873] dark:text-[#a3a3a3]">
            The page you&rsquo;re looking for doesn&rsquo;t exist or may have
            been moved.
          </p>

          <Link
            href="/dashboard"
            className="mt-7 inline-flex items-center justify-center rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA]"
          >
            Go to my workspaces
          </Link>
        </section>
      </main>
    </div>
  );
}