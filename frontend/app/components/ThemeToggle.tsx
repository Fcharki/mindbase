"use client";

import { Moon, Sun } from "lucide-react";
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
      className="inline-flex items-center justify-center rounded-lg border border-[#D7D5DE] p-2 text-[#5C5A66] transition hover:bg-[#F2F0EA] hover:text-[#1A1A1A] dark:border-[#333] dark:text-[#B8B6C2] dark:hover:bg-[#232329] dark:hover:text-white"
      aria-label="Toggle theme"
      title="Toggle theme"
    >
      <Sun className="h-4 w-4 dark:hidden" aria-hidden="true" />
      <Moon className="hidden h-4 w-4 dark:block" aria-hidden="true" />
    </button>
  );
}
