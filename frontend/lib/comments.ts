import { api } from "./api";

export interface CommentAuthor {
  id: number;
  name: string;
}

export interface EntryReply {
  id: number;
  comment_id: number;
  // Null once the account that wrote it is deleted. The backend keeps the reply
  // rather than removing it, so the discussion does not lose a turn, and the
  // panel falls back to a placeholder name.
  user_id: number | null;
  body: string;
  created_at: string;
  updated_at: string;
  user?: CommentAuthor | null;
}

export interface EntryComment {
  id: number;
  knowledge_entry_id: number;
  user_id: number | null;
  body: string;
  created_at: string;
  updated_at: string;
  user?: CommentAuthor | null;
  // Present on the listing endpoint, which nests replies under their comment.
  // Absent from a single-comment response, since no route returns one.
  replies?: EntryReply[];
}

interface ResourceResponse<T> {
  data: T;
}

function commentsPath(
  workspaceId: number | string,
  entryId: number | string,
) {
  return `/workspaces/${workspaceId}/entries/${entryId}/comments`;
}

function repliesPath(
  workspaceId: number | string,
  entryId: number | string,
  commentId: number | string,
) {
  return `${commentsPath(workspaceId, entryId)}/${commentId}/replies`;
}

/**
 * The whole discussion, oldest first, replies nested under their comment.
 */
export async function listComments(
  workspaceId: number | string,
  entryId: number | string,
): Promise<EntryComment[]> {
  const { data } = await api.get<ResourceResponse<EntryComment[]>>(
    commentsPath(workspaceId, entryId),
  );

  return data.data;
}

export async function createComment(
  workspaceId: number | string,
  entryId: number | string,
  body: string,
): Promise<EntryComment> {
  const { data } = await api.post<ResourceResponse<EntryComment>>(
    commentsPath(workspaceId, entryId),
    { body },
  );

  return data.data;
}

/**
 * Only the author may edit, so this fails with 403 for anyone else rather than
 * being hidden in the UI alone.
 */
export async function updateComment(
  workspaceId: number | string,
  entryId: number | string,
  commentId: number | string,
  body: string,
): Promise<EntryComment> {
  const { data } = await api.patch<ResourceResponse<EntryComment>>(
    `${commentsPath(workspaceId, entryId)}/${commentId}`,
    { body },
  );

  return data.data;
}

export async function deleteComment(
  workspaceId: number | string,
  entryId: number | string,
  commentId: number | string,
): Promise<void> {
  await api.delete(`${commentsPath(workspaceId, entryId)}/${commentId}`);
}

export async function createReply(
  workspaceId: number | string,
  entryId: number | string,
  commentId: number | string,
  body: string,
): Promise<EntryReply> {
  const { data } = await api.post<ResourceResponse<EntryReply>>(
    repliesPath(workspaceId, entryId, commentId),
    { body },
  );

  return data.data;
}

export async function updateReply(
  workspaceId: number | string,
  entryId: number | string,
  commentId: number | string,
  replyId: number | string,
  body: string,
): Promise<EntryReply> {
  const { data } = await api.patch<ResourceResponse<EntryReply>>(
    `${repliesPath(workspaceId, entryId, commentId)}/${replyId}`,
    { body },
  );

  return data.data;
}

export async function deleteReply(
  workspaceId: number | string,
  entryId: number | string,
  commentId: number | string,
  replyId: number | string,
): Promise<void> {
  await api.delete(
    `${repliesPath(workspaceId, entryId, commentId)}/${replyId}`,
  );
}