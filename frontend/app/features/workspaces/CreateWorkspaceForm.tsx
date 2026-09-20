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
    <form onSubmit={handleSubmit} className="w-full max-w-sm space-y-5">
      <div className="flex flex-col gap-1.5">
        <label
          htmlFor="workspace-name"
          className="text-sm text-[#4A4D45] dark:text-[#9C9F94]"
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
          className="w-full border-b border-[#C8C5B8] bg-transparent py-1.5 text-[#1E211D] focus:border-[#3B4A6B] focus:outline-none dark:border-[#3A3D30] dark:text-[#E7E5DC] dark:focus:border-[#8CA0D8]"
        />
      </div>

      {error && <p className="text-sm text-[#B3452C]">{error}</p>}

      <button
        type="submit"
        disabled={submitting}
        className="w-full rounded-full bg-[#3B4A6B] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#2F3C57] disabled:opacity-50"
      >
        {submitting ? "Creating…" : "Create workspace"}
      </button>
    </form>
  );
}