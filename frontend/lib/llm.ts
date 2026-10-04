import axios from "axios";

import { api } from "./api";

export interface AskSource {
  entry_id: number;
  title: string | null;
  body: string;
  found_by: string[];
}

export type AskStatus = "pending" | "ready" | "failed";

export interface AskAnswer {
  id: number;
  question: string;
  answer: string | null;
  sources: AskSource[];
  status: AskStatus;
  error: string | null;
  created_at: string;
}

interface AskResponse {
  data: AskAnswer;
}

// Answers take minutes here, so poll sparingly.
export const ASK_POLL_INTERVAL_MS = 3000;

// Past this the answer lands long after the user has moved on. The thread keeps
// the pending turn so it still fills in.
export const ASK_POLL_LIMIT_MS = 45 * 60 * 1000;

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
): Promise<AskAnswer> {
  const { data } = await api.post<AskResponse>(
    `/workspaces/${workspaceId}/ask`,
    { question },
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