"use client";

import Link from "next/link";

import { htmlToPlainText } from "@/lib/text";
import type { KnowledgeEntry } from "@/lib/entries";

function EntryMark() {
  return (
    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#EAF7F6] text-[#159A9C]">
      <svg
        width="17"
        height="17"
        viewBox="0 0 24 24"
        fill="none"
        aria-hidden="true"
      >
        <path
          d="M6 4.5h9.5L19 8v11.5H6V4.5Z"
          stroke="currentColor"
          strokeWidth="1.6"
          strokeLinejoin="round"
        />
        <path
          d="M15 4.5V8h4M9 12h7M9 15.5h5"
          stroke="currentColor"
          strokeWidth="1.6"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      </svg>
    </div>
  );
}

export function EntryList({
  entries,
  basePath,
}: {
  entries: KnowledgeEntry[];
  basePath: string;
}) {
  return (
    <ul className="divide-y divide-[#EEEDEF]">
      {entries.map((entry) => {
        const snippet = htmlToPlainText(entry.content);
        const updated = new Date(entry.updated_at ?? entry.created_at);

        return (
          <li key={entry.id}>
            <Link
              href={`${basePath}/${entry.id}`}
              className="group flex items-start gap-4 px-7 py-4 transition hover:bg-[#F7F6F2] sm:px-8"
            >
              <EntryMark />

              <div className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium text-[#20202A] transition group-hover:text-[#5146E5]">
                  {entry.title}
                </span>

                <span className="mt-0.5 block truncate text-sm text-[#9897A1]">
                  {snippet || "No content yet"}
                </span>

                {(entry.tags ?? []).length > 0 && (
                  <span className="mt-1.5 flex flex-wrap gap-1">
                    {(entry.tags ?? []).map((tag) => (
                      <span
                        key={tag.id}
                        className="rounded-full bg-[#EEEDFF] px-2 py-0.5 text-xs font-medium text-[#4338CA]"
                      >
                        {tag.name}
                      </span>
                    ))}
                  </span>
                )}
              </div>

              <div className="flex shrink-0 items-center gap-2">
                <span className="hidden text-xs text-[#9897A1] sm:block">
                  {updated.toLocaleDateString("en-US", {
                    month: "short",
                    day: "numeric",
                    year: "numeric",
                  })}
                </span>

                <svg
                  className="h-4 w-4 text-[#C9C7D0] transition group-hover:text-[#5146E5]"
                  width="16"
                  height="16"
                  viewBox="0 0 24 24"
                  fill="none"
                  aria-hidden="true"
                >
                  <path
                    d="M9 6l6 6-6 6"
                    stroke="currentColor"
                    strokeWidth="1.7"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                </svg>
              </div>
            </Link>
          </li>
        );
      })}
    </ul>
  );
}