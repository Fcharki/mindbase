"use client";

import { useEffect } from "react";
import { MapPin } from "lucide-react";

import type { WorkspaceOwner } from "@/lib/workspaces";

export function OwnerAvatar({
  owner,
  className = "",
}: {
  owner: WorkspaceOwner;
  className?: string;
}) {
  return (
    <span
      className={`flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#EEEDFF] dark:bg-[#262449] font-semibold text-[#5146E5] ${className}`}
    >
      {owner.photo_url ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={owner.photo_url}
          alt=""
          className="h-full w-full object-cover"
        />
      ) : (
        owner.name.charAt(0).toUpperCase()
      )}
    </span>
  );
}

export function OwnerProfileDialog({
  owner,
  onClose,
}: {
  owner: WorkspaceOwner;
  onClose: () => void;
}) {
  useEffect(() => {
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") {
        onClose();
      }
    }

    document.addEventListener("keydown", handleKeyDown);

    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [onClose]);

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={`${owner.name}'s profile`}
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      onClick={onClose}
    >
      <div
        className="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-xl dark:bg-[#1b1b1f]"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="flex items-center justify-between gap-4 border-b border-[#D7D5DE] px-5 py-3 dark:border-[#34343c]">
          <p className="text-sm font-medium text-[#20202A] dark:text-[#ececf1]">
            Workspace owner
          </p>

          <button
            type="button"
            onClick={onClose}
            className="rounded-lg border border-[#D7D5DE] px-3 py-1.5 text-sm font-medium text-[#686873] transition hover:bg-[#F2F0EA] hover:text-[#20202A] dark:border-[#34343c] dark:text-[#b0b0b9] dark:hover:bg-[#24242a]"
          >
            Close
          </button>
        </div>

        <div className="p-6">
          <div className="flex items-center gap-4">
            <OwnerAvatar owner={owner} className="h-16 w-16 text-xl" />

            <div className="min-w-0">
              <h2 className="truncate text-xl font-semibold tracking-tight">
                {owner.name}
              </h2>

              {owner.location && (
                <p className="mt-1 flex items-center gap-1.5 text-sm text-[#686873] dark:text-[#b0b0b9]">
                  <MapPin className="h-4 w-4 shrink-0" aria-hidden="true" />
                  <span className="truncate">{owner.location}</span>
                </p>
              )}
            </div>
          </div>

          <p className="mt-5 whitespace-pre-wrap text-sm leading-relaxed text-[#20202A] dark:text-[#ececf1]">
            {owner.bio?.trim() || "This owner has not added a bio yet."}
          </p>
        </div>
      </div>
    </div>
  );
}
