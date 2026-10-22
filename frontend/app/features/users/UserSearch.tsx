"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import { searchUsers, UserSearchResult } from "@/lib/users";

export function UserSearch() {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<UserSearchResult[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function handleQueryChange(value: string) {
    setQuery(value);
    setResults([]);
    setError(null);
    setLoading(value.trim().length > 0);
  }

  useEffect(() => {
    const term = query.trim();

    if (!term) {
      return;
    }

    let cancelled = false;

    const timer = window.setTimeout(() => {
      searchUsers(term)
        .then((users) => {
          if (!cancelled) {
            setResults(users);
          }
        })
        .catch(() => {
          if (!cancelled) {
            setResults([]);
            setError("Could not search for people.");
          }
        })
        .finally(() => {
          if (!cancelled) {
            setLoading(false);
          }
        });
    }, 250);

    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [query]);

  const hasQuery = query.trim().length > 0;

  return (
    <section className="rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] p-6 shadow-sm">
      <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
        People
      </p>
      <h2 className="mt-2 text-xl font-semibold tracking-tight">
        Find people
      </h2>
      <p className="mt-2 text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
        Search by username to see the workspaces someone owns.
      </p>

      <div className="relative mt-5">
        <svg
          className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[#9897A1] dark:text-[#8b8b94]"
          width="16"
          height="16"
          viewBox="0 0 24 24"
          fill="none"
          aria-hidden="true"
        >
          <circle
            cx="11"
            cy="11"
            r="6.5"
            stroke="currentColor"
            strokeWidth="1.7"
          />
          <path
            d="m16 16 4 4"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
          />
        </svg>
        <input
          type="search"
          value={query}
          onChange={(event) => handleQueryChange(event.target.value)}
          placeholder="Search by username"
          aria-label="Search people by username"
          className="h-10 w-full rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#121214] pl-10 pr-3 text-sm text-[#20202A] dark:text-[#ececf1] outline-none transition placeholder:text-[#A3A2AC] dark:placeholder:text-[#7e7e88] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
        />
      </div>

      {error && (
        <p role="alert" className="mt-3 text-sm text-[#B13D55] dark:text-[#f0a0b0]">
          {error}
        </p>
      )}

      {loading && (
        <p className="mt-4 text-sm text-[#686873] dark:text-[#b0b0b9]">
          Searching…
        </p>
      )}

      {!loading && hasQuery && !error && results.length === 0 && (
        <p className="mt-4 text-sm text-[#686873] dark:text-[#b0b0b9]">
          No people found.
        </p>
      )}

      {results.length > 0 && (
        <ul className="mt-4 space-y-1.5">
          {results.map((person) => (
            <li key={person.id}>
              <Link
                href={`/users/${person.username}`}
                className="flex items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-[#F2F0EA] dark:hover:bg-[#24242a]"
              >
                <span className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#EEEDFF] dark:bg-[#262449] text-sm font-semibold text-[#5146E5]">
                  {person.photo_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                      src={person.photo_url}
                      alt=""
                      className="h-full w-full object-cover"
                    />
                  ) : (
                    person.username.charAt(0).toUpperCase()
                  )}
                </span>
                <span className="min-w-0">
                  <span className="block truncate text-sm font-medium text-[#20202A] dark:text-[#ececf1]">
                    @{person.username}
                  </span>
                  <span className="block truncate text-xs text-[#686873] dark:text-[#b0b0b9]">
                    {person.owned_workspaces_count}{" "}
                    {person.owned_workspaces_count === 1
                      ? "workspace"
                      : "workspaces"}
                    {person.location ? ` · ${person.location}` : ""}
                  </span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
