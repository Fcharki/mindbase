"use client";

import type { WorkspaceRole } from "@/lib/workspaces";

const styles: Record<WorkspaceRole, string> = {
  owner: "bg-[#3B4A6B] text-white",
  member: "bg-[#5C6B4F] text-white",
  viewer: "bg-[#D8D5C9] text-[#4A4D45]",
};

export function RoleBadge({ role }: { role: WorkspaceRole }) {
  return (
    <span
      className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${styles[role]}`}
    >
      {role}
    </span>
  );
}