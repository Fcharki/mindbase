"use client";

import { usePathname } from "next/navigation";

import { ThemeToggle } from "@/app/components/ThemeToggle";

/**
 * Theme toggle for routes that have no navigation bar of their own (auth,
 * invite, 404). The homepage renders its own toggle inside the navbar, and
 * the workspace/dashboard shell already has one, so those are excluded to
 * avoid showing two.
 */
export function PublicThemeToggle() {
  const pathname = usePathname();

  if (
    pathname === "/" ||
    pathname.startsWith("/workspaces") ||
    pathname.startsWith("/dashboard") ||
    pathname.startsWith("/profile") ||
    pathname.startsWith("/users") ||
    pathname.startsWith("/auth")
  ) {
    return null;
  }

  return (
    <div className="fixed right-5 top-5 z-50">
      <ThemeToggle />
    </div>
  );
}
