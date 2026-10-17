"use client";

import { useState, type FormEvent } from "react";

import Tiptap from "@/app/components/Tiptap";

interface EntryFormProps {
  title: string;
  heading: string;
  eyebrow: string;
  initialTitle?: string;
  initialContent?: string;
  submitLabel: string;
  submittingLabel: string;
  error: string | null;
  onError: (error: string | null) => void;
  onSubmit: (title: string, content: string) => Promise<void>;
  onCancel?: () => void;
  cancelLabel?: string;
}

export function EntryForm({
  title,
  heading,
  eyebrow,
  initialTitle = "",
  initialContent = "",
  submitLabel,
  submittingLabel,
  error,
  onError,
  onSubmit,
  onCancel,
  cancelLabel = "Cancel",
}: EntryFormProps) {
  const [entryTitle, setEntryTitle] = useState(initialTitle);
  const [content, setContent] = useState(initialContent);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const trimmedTitle = entryTitle.trim();

    if (!trimmedTitle) {
      onError("Please give your entry a title.");
      return;
    }

    setSubmitting(true);
    onError(null);

    try {
      await onSubmit(trimmedTitle, content);
    } catch {
      onError("Could not save this entry. Please try again.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <section className="rounded-2xl border border-[#DDDCE3] dark:border-[#2a2a2a] bg-white dark:bg-[#141414] shadow-sm">
      <div className="border-b border-[#DDDCE3] dark:border-[#2a2a2a] px-7 py-5 sm:px-8">
        <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
          {eyebrow}
        </p>

        <h2 className="mt-1 text-xl font-semibold tracking-tight">
          {heading}
        </h2>
      </div>

      <form onSubmit={handleSubmit} className="flex flex-col gap-6 px-7 py-7 sm:px-8">
        <div className="flex flex-col gap-2">
          <label
            htmlFor="entry-title"
            className="text-sm font-medium text-[#20202A] dark:text-[#e5e5e5]"
          >
            {title}
          </label>

          <input
            id="entry-title"
            type="text"
            required
            maxLength={255}
            autoComplete="off"
            value={entryTitle}
            onChange={(event) => setEntryTitle(event.target.value)}
            placeholder="Enter a title..."
            className="w-full rounded-lg border border-[#DDDCE3] dark:border-[#2a2a2a] bg-white dark:bg-[#141414] px-4 py-3 text-sm text-[#20202A] dark:text-[#e5e5e5] outline-none transition placeholder:text-[#A3A2AC] dark:placeholder:text-[#6b6b6b] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#2a2a3a]"
          />
        </div>

        <div className="flex flex-col gap-2">
          <label className="text-sm font-medium text-[#20202A] dark:text-[#e5e5e5]">
            Content
          </label>

          <div className="tiptap-editor rounded-lg border border-[#DDDCE3] dark:border-[#2a2a2a] bg-white dark:bg-[#141414] transition focus-within:border-[#5146E5] focus-within:ring-2 focus-within:ring-[#EEEDFF]">
            <Tiptap content={content} onChange={setContent} />
          </div>
        </div>

        {error && (
          <p
            role="alert"
            className="rounded-lg border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#3a1c24] px-4 py-3 text-sm leading-relaxed text-[#B13D55] dark:text-[#f0a0b0]"
          >
            {error}
          </p>
        )}

        <div className="flex items-center justify-end gap-3">
          {onCancel && (
            <button
              type="button"
              onClick={onCancel}
              disabled={submitting}
              className="rounded-lg border border-[#DDDCE3] dark:border-[#2a2a2a] bg-white dark:bg-[#141414] px-5 py-3 text-sm font-medium text-[#686873] dark:text-[#a3a3a3] transition hover:border-[#C9C7D0] dark:hover:border-[#3a3a3a] hover:bg-[#F7F6F2] dark:hover:bg-[#1c1c1c] hover:text-[#20202A] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {cancelLabel}
            </button>
          )}

          <button
            type="submit"
            disabled={submitting || !entryTitle.trim()}
            className="rounded-lg bg-[#5146E5] px-5 py-3 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-50"
          >
            {submitting ? submittingLabel : submitLabel}
          </button>
        </div>
      </form>
    </section>
  );
}