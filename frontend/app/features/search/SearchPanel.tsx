"use client";

import { useEffect, useRef, useState } from "react";

import { EntryList } from "@/app/features/knowledge/components/EntryList";
import { searchWorkspace, type SearchResults } from "@/lib/search";

interface SearchAttempt {
  forQuery: string;
  forTag: string | null;
  data?: SearchResults;
  error?: boolean;
}

export function SearchPanel({
  workspaceId,
  basePath,
}: {
  workspaceId: number | string;
  basePath: string;
}) {
  const [query, setQuery] = useState("");
  const [activeTag, setActiveTag] = useState<string | null>(null);
  const [attempt, setAttempt] = useState<SearchAttempt | null>(null);
  const timerRef = useRef<number | null>(null);

  useEffect(() => {
    const trimmed = query.trim();

    if (timerRef.current !== null) {
      window.clearTimeout(timerRef.current);
      timerRef.current = null;
    }

    if (trimmed.length === 0) {
      return;
    }

    let cancelled = false;

    timerRef.current = window.setTimeout(async () => {
      try {
        const data = await searchWorkspace(
          workspaceId,
          trimmed,
          activeTag ?? undefined,
        );

        if (!cancelled) {
          setAttempt({ forQuery: trimmed, forTag: activeTag, data });
        }
      } catch {
        if (!cancelled) {
          setAttempt({
            forQuery: trimmed,
            forTag: activeTag,
            error: true,
          });
        }
      }
    }, 300);

    return () => {
      cancelled = true;

      if (timerRef.current !== null) {
        window.clearTimeout(timerRef.current);
        timerRef.current = null;
      }
    };
  }, [query, activeTag, workspaceId]);

  function handleClear() {
    setQuery("");
    setActiveTag(null);
    setAttempt(null);
  }

  const trimmed = query.trim();
  const hasQuery = trimmed.length > 0;
  const current = hasQuery
    ? attempt &&
      attempt.forQuery === trimmed &&
      attempt.forTag === activeTag
      ? attempt
      : null
    : null;
  const results = current?.data ?? null;
  const resultCount =
    (results?.entries.length ?? 0) + (results?.tags.length ?? 0);

  return (
    <section className="rounded-2xl border border-[#DDDCE3] bg-white shadow-sm">
      <div className="border-b border-[#DDDCE3] px-7 py-5 sm:px-8">
        <label htmlFor="workspace-search" className="sr-only">
          Search knowledge
        </label>

        <div className="relative">
          <svg
            className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[#9897A1]"
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
          >
            <circle
              cx="11"
              cy="11"
              r="7"
              stroke="currentColor"
              strokeWidth="1.8"
            />
            <path
              d="M16.5 16.5L21 21"
              stroke="currentColor"
              strokeWidth="1.8"
              strokeLinecap="round"
            />
          </svg>

          <input
            id="workspace-search"
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Search entries and tags…"
            className="w-full rounded-lg border border-[#DDDCE3] bg-white py-3 pl-10 pr-4 text-sm text-[#20202A] outline-none transition placeholder:text-[#A3A2AC] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF]"
          />
        </div>
      </div>

      {hasQuery && (
        <div className="px-7 py-5 sm:px-8">
          {!current && (
            <p className="flex items-center gap-3 text-sm text-[#686873]">
              <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
              Searching…
            </p>
          )}

          {current?.error && (
            <p
              role="alert"
              className="rounded-lg border border-[#F2C9D1] bg-[#FCECEF] px-4 py-3 text-sm leading-relaxed text-[#B13D55]"
            >
              Search failed. Please try again.
            </p>
          )}

          {results && (
            <div>
              <div className="flex flex-wrap items-center gap-3">
                <p className="text-sm text-[#686873]">
                  {resultCount === 0 ? (
                    <>No results for &ldquo;{trimmed}&rdquo;.</>
                  ) : (
                    <>
                      {resultCount}{" "}
                      {resultCount === 1 ? "result" : "results"} for
                      &ldquo;{trimmed}&rdquo;.
                    </>
                  )}
                </p>

                <button
                  type="button"
                  onClick={handleClear}
                  className="text-sm font-medium text-[#5146E5] transition hover:text-[#4338CA]"
                >
                  Clear
                </button>
              </div>

              {results.tags.length > 0 && (
                <div className="mt-4 flex flex-wrap gap-2">
                  {results.tags.map((tag) => {
                    const isActive = activeTag === tag.name;

                    return (
                      <button
                        key={tag.id}
                        type="button"
                        onClick={() =>
                          setActiveTag(isActive ? null : tag.name)
                        }
                        className={
                          isActive
                            ? "inline-flex items-center gap-1 rounded-full bg-[#5146E5] px-3 py-1.5 text-xs font-medium text-white transition"
                            : "inline-flex items-center gap-1 rounded-full bg-[#EEEDFF] px-3 py-1.5 text-xs font-medium text-[#4338CA] transition hover:bg-[#E2E0FA]"
                        }
                      >
                        {tag.name}
                        <span className="opacity-70">{tag.entries_count}</span>
                      </button>
                    );
                  })}
                </div>
              )}

              {results.entries.length > 0 ? (
                <div className="-mx-7 mt-4 sm:-mx-8">
                  <EntryList entries={results.entries} basePath={basePath} />
                </div>
              ) : (
                <div className="mt-6 rounded-lg border border-dashed border-[#DDDCE3] px-5 py-8 text-center text-sm text-[#686873]">
                  No entries match this search yet.
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </section>
  );
}