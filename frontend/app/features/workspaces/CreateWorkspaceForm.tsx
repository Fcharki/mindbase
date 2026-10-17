"use client";

import { useState, type FormEvent } from "react";

import { createWorkspace, Workspace } from "@/lib/workspaces";

export function CreateWorkspaceForm({
  onCreated,
}: {
  onCreated: (workspace: Workspace) => void;
}) {
  const [name, setName] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      const workspace = await createWorkspace(name.trim());
      onCreated(workspace);
    } catch {
      setError("Could not create the workspace. Please try again.");
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="w-full space-y-5">
      <div className="flex flex-col gap-2">
        <label
          htmlFor="workspace-name"
          className="text-sm font-medium text-[#20202A] dark:text-[#e5e5e5]"
        >
          Workspace name
        </label>
        <input
          id="workspace-name"
          type="text"
          required
          maxLength={255}
          autoComplete="off"
          value={name}
          onChange={(event) => setName(event.target.value)}
          placeholder="e.g. Research, Personal, Team"
          className="w-full rounded-lg border border-[#DDDCE3] dark:border-[#2a2a2a] bg-white dark:bg-[#141414] px-4 py-2.5 text-sm text-[#20202A] dark:text-[#e5e5e5] outline-none transition placeholder:text-[#A3A2AC] dark:placeholder:text-[#6b6b6b] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#2a2a3a]"
        />
      </div>

      {error && (
        <p role="alert" className="text-sm leading-relaxed text-[#B13D55] dark:text-[#f0a0b0]">
          {error}
        </p>
      )}

      <button
        type="submit"
        disabled={submitting}
        className="w-full rounded-lg bg-[#5146E5] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-50"
      >
        {submitting ? "Creating…" : "Create workspace"}
      </button>
    </form>
  );
}