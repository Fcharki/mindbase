"use client";

import Link from "next/link";

import { htmlToPlainText } from "@/lib/text";
import type { KnowledgeEntry } from "@/lib/entries";

function EntryMark({ pinned }: { pinned: boolean }) {
  return (
    <div
      className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${
        pinned
          ? "bg-[#EEEDFF] text-[#5146E5] dark:bg-[#242450] dark:text-[#a5b4fc]"
          : "bg-[#EAF7F6] text-[#159A9C] dark:bg-[#11312f] dark:text-[#5fd3cf]"
      }`}
    >
      {pinned ? (
        <svg
          width="17"
          height="17"
          viewBox="0 0 24 24"
          fill="none"
          aria-hidden="true"
        >
          <path
            d="M14 4l6 6-3 1-3.5 3.5L15 19l-1.5 1.5-3.8-3.8L5 21l-2-2 4.3-4.7L3.5 10.5 5 9l4.5 1.5L13 7l1-3Z"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinejoin="round"
          />
        </svg>
      ) : (
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
      )}
    </div>
  );
}

export function EntryList({
  entries,
  basePath,
  selectable = false,
  selectedIds,
  onToggleSelect,
  onTogglePin,
  onExport,
  canContribute = false,
}: {
  entries: KnowledgeEntry[];
  basePath: string;
  selectable?: boolean;
  selectedIds?: Set<number>;
  onToggleSelect?: (id: number) => void;
  onTogglePin?: (entry: KnowledgeEntry) => void;
  onExport?: (entry: KnowledgeEntry) => void;
  canContribute?: boolean;
}) {
  return (
    <ul className="divide-y divide-[#EEEDEF] dark:divide-[#242424]">
      {entries.map((entry) => {
        const snippet = htmlToPlainText(entry.content);
        const updated = new Date(entry.updated_at ?? entry.created_at);
        const pinned = Boolean(entry.is_pinned);
        const selected = selectedIds?.has(entry.id) ?? false;

        return (
          <li key={entry.id} className="flex items-stretch">
            {selectable && (
              <label className="flex cursor-pointer items-center pl-7 sm:pl-8">
                <input
                  type="checkbox"
                  checked={selected}
                  onChange={() => onToggleSelect?.(entry.id)}
                  aria-label={`Select ${entry.title}`}
                  className="h-4 w-4 rounded border-[#C9C7D0] text-[#5146E5] focus:ring-[#EEEDFF] dark:focus:ring-[#2a2a3a] dark:border-[#3a3a3a]"
                />
              </label>
            )}

            <Link
              href={`${basePath}/${entry.id}`}
              className="group flex min-w-0 flex-1 items-start gap-4 px-7 py-4 transition hover:bg-[#F7F6F2] sm:px-8 dark:hover:bg-[#1c1c1c]"
            >
              <EntryMark pinned={pinned} />

              <div className="min-w-0 flex-1">
                <span className="flex items-center gap-2">
                  <span className="truncate text-sm font-medium text-[#20202A] transition group-hover:text-[#5146E5] dark:text-[#e5e5e5]">
                    {entry.title}
                  </span>
                  {pinned && (
                    <span className="shrink-0 rounded-full bg-[#EEEDFF] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[#4338CA] dark:bg-[#242450] dark:text-[#a5b4fc]">
                      Pinned
                    </span>
                  )}
                </span>

                <span className="mt-0.5 block truncate text-sm text-[#9897A1] dark:text-[#737373]">
                  {snippet || "No content yet"}
                </span>

                {(entry.tags ?? []).length > 0 && (
                  <span className="mt-1.5 flex flex-wrap gap-1">
                    {(entry.tags ?? []).map((tag) => (
                      <span
                        key={tag.id}
                        className="rounded-full bg-[#EEEDFF] px-2 py-0.5 text-xs font-medium text-[#4338CA] dark:bg-[#242450] dark:text-[#a5b4fc]"
                      >
                        {tag.name}
                      </span>
                    ))}
                  </span>
                )}
              </div>

              <div className="flex shrink-0 items-center gap-2">
                <span className="hidden text-xs text-[#9897A1] sm:block dark:text-[#737373]">
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

            {canContribute && (onTogglePin || onExport) && (
              <div className="flex items-center gap-1 pr-4 sm:pr-6">
                {onTogglePin && (
                  <button
                    type="button"
                    onClick={() => onTogglePin(entry)}
                    aria-label={pinned ? `Unpin ${entry.title}` : `Pin ${entry.title}`}
                    title={pinned ? "Unpin" : "Pin to top"}
                    className={`rounded-md p-1.5 transition hover:bg-[#EEEDEF] dark:hover:bg-[#242424] ${
                      pinned
                        ? "text-[#5146E5] dark:text-[#a5b4fc]"
                        : "text-[#C9C7D0] dark:text-[#4a4a4a]"
                    }`}
                  >
                    <svg
                      width="15"
                      height="15"
                      viewBox="0 0 24 24"
                      fill="none"
                      aria-hidden="true"
                    >
                      <path
                        d="M14 4l6 6-3 1-3.5 3.5L15 19l-1.5 1.5-3.8-3.8L5 21l-2-2 4.3-4.7L3.5 10.5 5 9l4.5 1.5L13 7l1-3Z"
                        stroke="currentColor"
                        strokeWidth="1.5"
                        strokeLinejoin="round"
                      />
                    </svg>
                  </button>
                )}

                {onExport && (
                  <button
                    type="button"
                    onClick={() => onExport(entry)}
                    aria-label={`Export ${entry.title}`}
                    title="Export as Markdown"
                    className="rounded-md p-1.5 text-[#C9C7D0] transition hover:bg-[#EEEDEF] hover:text-[#20202A] dark:text-[#4a4a4a] dark:hover:bg-[#242424]"
                  >
                    <svg
                      width="15"
                      height="15"
                      viewBox="0 0 24 24"
                      fill="none"
                      aria-hidden="true"
                    >
                      <path
                        d="M12 4v10m0 0 4-4m-4 4-4-4M5 18h14"
                        stroke="currentColor"
                        strokeWidth="1.7"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                    </svg>
                  </button>
                )}
              </div>
            )}
          </li>
        );
      })}
    </ul>
  );
}
