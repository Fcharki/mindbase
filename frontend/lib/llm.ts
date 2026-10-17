import axios from "axios";

import { api } from "./api";

export interface AskSource {
  entry_id: number;
  // The exact chunk the model read, so a citation can open the passage rather
  // than the whole entry.
  chunk_id?: number;
  position?: number;
  source?: string;
  title: string | null;
  body: string;
  found_by: string[];
}

export type AskStatus = "pending" | "ready" | "failed";

export type AskFeedback = "up" | "down" | null;

export interface AskAnswer {
  id: number;
  question: string;
  answer: string | null;
  sources: AskSource[];
  status: AskStatus;
  error: string | null;
  feedback?: AskFeedback;
  created_at: string;
}

export interface AskOptions {
  // How many passages to give the model. The backend clamps to 1..20.
  top_k?: number;
  // Try a rewritten query when literal terms find nothing.
  rewrite?: boolean;
}

interface AskResponse {
  data: AskAnswer;
}

interface AskListResponse {
  data: AskAnswer[];
}

// Answers take minutes here, so poll sparingly.
export const ASK_POLL_INTERVAL_MS = 3000;

// Past this the answer lands long after the user has moved on. The thread keeps
// the pending turn so it still fills in.
export const ASK_POLL_LIMIT_MS = 45 * 60 * 1000;

// The backend clamps to the same range; kept here so the control and the clamp
// cannot drift apart.
export const ASK_TOP_K_MIN = 1;
export const ASK_TOP_K_MAX = 20;
export const ASK_TOP_K_DEFAULT = 6;

// One generic message for "nothing matched" (404), "service loading or timed
// out" (503) and "answered empty" (502) hid which one actually happened.
export function askErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const message = (error.response?.data as { message?: string } | undefined)
      ?.message;

    if (message) {
      return message;
    }

    if (!error.response) {
      return "Could not reach the server. Check your connection and try again.";
    }
  }

  return "Could not answer that. Please try again in a moment.";
}

/**
 * Ask a question. Resolves as soon as it is accepted; the answer follows.
 */
export async function askWorkspace(
  workspaceId: number | string,
  question: string,
  options: AskOptions = {},
): Promise<AskAnswer> {
  const { data } = await api.post<AskResponse>(
    `/workspaces/${workspaceId}/ask`,
    { question, ...options },
  );

  return data.data;
}

/**
 * Read one question back to see whether the answer has landed.
 */
export async function fetchAssistantMessage(
  workspaceId: number | string,
  messageId: number,
): Promise<AskAnswer> {
  const { data } = await api.get<AskResponse>(
    `/workspaces/${workspaceId}/ask/${messageId}`,
  );

  return data.data;
}

/**
 * The thread so far, newest first. The backend caps the limit, so a caller
 * cannot ask for an unbounded transcript.
 */
export async function fetchAssistantMessages(
  workspaceId: number | string,
  limit = 30,
): Promise<AskAnswer[]> {
  const { data } = await api.get<AskListResponse>(
    `/workspaces/${workspaceId}/ask/messages`,
    { params: { limit } },
  );

  return data.data;
}

/**
 * Answer an existing question again from freshly retrieved passages.
 */
export async function regenerateAssistantMessage(
  workspaceId: number | string,
  messageId: number,
  options: AskOptions = {},
): Promise<AskAnswer> {
  const { data } = await api.post<AskResponse>(
    `/workspaces/${workspaceId}/ask/${messageId}/regenerate`,
    options,
  );

  return data.data;
}

/**
 * Record whether an answer was useful, or pass null to clear an earlier vote.
 */
export async function setAssistantFeedback(
  workspaceId: number | string,
  messageId: number,
  feedback: AskFeedback,
): Promise<AskAnswer> {
  const { data } = await api.post<AskResponse>(
    `/workspaces/${workspaceId}/ask/${messageId}/feedback`,
    { feedback },
  );

  return data.data;
}

export interface StreamHandlers {
  onDelta?: (text: string) => void;
  onDone?: (full: string) => void;
  onError?: (message: string) => void;
}

/**
 * Tail a pending answer as it is written.
 *
 * The backend endpoint reports the job's progress, so this streams the same
 * text polling would, just sooner. The `done` frame carries nothing but a
 * signal, so the accumulated deltas are the answer; the caller can still
 * re-fetch once it lands if it wants the authoritative copy.
 *
 * Returns a function that closes the connection, for when the component
 * unmounts or the user clears the thread.
 */
export function streamAssistantMessage(
  workspaceId: number | string,
  messageId: number,
  handlers: StreamHandlers,
): () => void {
  const controller = new AbortController();
  const base = process.env.NEXT_PUBLIC_API_URL ?? "";
  let accumulated = "";

  const run = async () => {
    try {
      const response = await fetch(
        `${base}/workspaces/${workspaceId}/ask/${messageId}/stream`,
        {
          credentials: "include",
          headers: { Accept: "text/event-stream" },
          signal: controller.signal,
        },
      );

      if (!response.ok || !response.body) {
        throw new Error("Streaming is unavailable.");
      }

      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let pending = "";

      // Frames are newline-delimited; a chunk can split one, so an incomplete
      // tail is carried into the next read.
      while (true) {
        const { value, done } = await reader.read();

        if (done) {
          break;
        }

        pending += decoder.decode(value, { stream: true });

        let newline: number;

        while ((newline = pending.indexOf("\n")) !== -1) {
          const line = pending.slice(0, newline).trim();
          pending = pending.slice(newline + 1);

          if (!line.startsWith("data:")) {
            continue;
          }

          let event: { type?: string; text?: string; message?: string };

          try {
            event = JSON.parse(line.slice(5).trim());
          } catch {
            continue;
          }

          if (event.type === "delta" && event.text) {
            accumulated += event.text;
            handlers.onDelta?.(accumulated);
          } else if (event.type === "done") {
            handlers.onDone?.(accumulated);
          } else if (event.type === "error" || event.type === "timeout") {
            handlers.onError?.(event.message ?? "The answer could not be delivered.");
          }
        }
      }
    } catch (error) {
      // An abort is the caller closing the stream, not a failure to report.
      if (error instanceof DOMException && error.name === "AbortError") {
        return;
      }

      handlers.onError?.("Lost the connection while the answer was being written.");
    }
  };

  void run();

  return () => controller.abort();
}