"use client";

import { useTheme } from "next-themes";

/**
 * Switches between light and dark.
 *
 * The icon is chosen in CSS (both are rendered, one hidden by the `dark` class)
 * rather than by reading the resolved theme during render: the server has no
 * way to know it, so reading it would either mismatch on hydration or need a
 * mounted flag set from an effect. The click handler can safely read it, since
 * by then hydration is long done.
 */
export function ThemeToggle() {
  const { resolvedTheme, setTheme } = useTheme();

  return (
    <button
      type="button"
      onClick={() => setTheme(resolvedTheme === "dark" ? "light" : "dark")}
      className="rounded-lg border border-[#DDDCE3] px-3 py-1.5 text-sm transition hover:bg-[#F7F6F2] dark:border-[#333] dark:hover:bg-[#1a1a1a]"
      aria-label="Toggle theme"
    >
      <span className="dark:hidden" aria-hidden="true">
        ☀️
      </span>
      <span className="hidden dark:inline" aria-hidden="true">
        🌙
      </span>
    </button>
  );
}
