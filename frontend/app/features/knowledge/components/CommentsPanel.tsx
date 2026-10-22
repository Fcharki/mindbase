"use client";

import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type FormEvent,
} from "react";

import { useAuth } from "@/app/Providers/AuthProvider";
import {
  createComment,
  createReply,
  deleteComment,
  deleteReply,
  EntryComment,
  EntryReply,
  listComments,
  updateComment,
  updateReply,
} from "@/lib/comments";

interface CommentsPanelProps {
  workspaceId: string;
  entryId: string;
  // Whether the user may post in the discussion. Every member can, viewer
  // included; the flag exists to cover non-members rather than to gate viewers
  // out of it.
  canPost: boolean;
  isOwner: boolean;
  // The comment, or the reply, a notification link pointed at. Both are strings
  // because they arrive from the query string, and both are compared as numbers
  // against the posts once loaded.
  highlightCommentId?: string;
  highlightReplyId?: string;
}

const MAX_BODY = 5000;

/**
 * One post in the discussion, comment or reply.
 *
 * A comment and a reply differ only in indentation and in the header above
 * them, so they share this rather than being rendered by two near-identical
 * trees that would drift apart on the next styling change.
 */
function Post({
  author,
  body,
  createdAt,
  canEdit,
  canDelete,
  editing,
  editBody,
  onEditBodyChange,
  onStartEdit,
  onCancelEdit,
  onSaveEdit,
  onDelete,
  busy,
  children,
}: {
  author: string;
  body: string;
  createdAt: string;
  canEdit: boolean;
  canDelete: boolean;
  editing: boolean;
  editBody: string;
  onEditBodyChange: (value: string) => void;
  onStartEdit: () => void;
  onCancelEdit: () => void;
  onSaveEdit: () => void;
  onDelete: () => void;
  busy: boolean;
  children?: React.ReactNode;
}) {
  return (
    <div className="min-w-0">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <p className="text-sm font-medium text-[#20202A] dark:text-[#ececf1]">{author}</p>

        <p className="text-xs text-[#9897A1] dark:text-[#8b8b94]">
          {new Date(createdAt).toLocaleString("en-US", {
            month: "short",
            day: "numeric",
            year: "numeric",
            hour: "numeric",
            minute: "2-digit",
          })}
        </p>
      </div>

      {editing ? (
        <div className="mt-2 flex flex-col gap-2">
          <textarea
            value={editBody}
            onChange={(event) => onEditBodyChange(event.target.value)}
            rows={3}
            maxLength={MAX_BODY}
            className="w-full resize-y rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-3 py-2 text-sm leading-relaxed text-[#20202A] dark:text-[#ececf1] outline-none transition focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
          />

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={onSaveEdit}
              disabled={busy || editBody.trim() === ""}
              className="rounded-lg bg-[#5146E5] px-3 py-1.5 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {busy ? "Saving…" : "Save"}
            </button>

            <button
              type="button"
              onClick={onCancelEdit}
              className="rounded-lg px-3 py-1.5 text-sm font-medium text-[#686873] dark:text-[#b0b0b9] transition hover:text-[#20202A]"
            >
              Cancel
            </button>
          </div>
        </div>
      ) : (
        <p className="mt-1 whitespace-pre-wrap break-words text-sm leading-relaxed text-[#20202A] dark:text-[#ececf1]">
          {body}
        </p>
      )}

      {!editing && (canEdit || canDelete) && (
        <div className="mt-1.5 flex items-center gap-3">
          {canEdit && (
            <button
              type="button"
              onClick={onStartEdit}
              className="text-xs font-medium text-[#686873] dark:text-[#b0b0b9] transition hover:text-[#20202A]"
            >
              Edit
            </button>
          )}

          {canDelete && (
            <button
              type="button"
              onClick={onDelete}
              disabled={busy}
              className="text-xs font-medium text-[#B13D55] dark:text-[#f0a0b0] transition hover:text-[#E85D75] disabled:cursor-not-allowed disabled:opacity-50"
            >
              Delete
            </button>
          )}
        </div>
      )}

      {children}
    </div>
  );
}

export function CommentsPanel({
  workspaceId,
  entryId,
  canPost,
  isOwner,
  highlightCommentId,
  highlightReplyId,
}: CommentsPanelProps) {
  const { user } = useAuth();

  const [comments, setComments] = useState<EntryComment[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busyKey, setBusyKey] = useState<string | null>(null);

  const [draft, setDraft] = useState("");
  const [posting, setPosting] = useState(false);

  // One post at a time can be edited, keyed by kind and id rather than by id
  // alone: comments and replies come from separate id sequences, so a comment 5
  // and a reply 5 can both be on screen and an id-only key would open both.
  const [editingKey, setEditingKey] = useState<string | null>(null);
  const [editBody, setEditBody] = useState("");

  const [replyingTo, setReplyingTo] = useState<number | null>(null);
  const [replyBody, setReplyBody] = useState("");

  /**
   * Which post a notification asked to see, in the same `kind-id` form the
   * editing state uses.
   *
   * A reply is preferred over its comment when both are given, because the
   * reader arrived for the reply. The ids are parsed as numbers because they
   * arrive as strings and must be compared against the numeric ids on the posts.
   */
  const highlightKey = useMemo(() => {
    const replyId = toId(highlightReplyId);
    if (replyId !== null) {
      return `reply-${replyId}`;
    }

    const commentId = toId(highlightCommentId);
    if (commentId !== null) {
      return `comment-${commentId}`;
    }

    return null;
  }, [highlightCommentId, highlightReplyId]);

  // The post to scroll to, set by the matching post during render so the effect
  // below can find it once the discussion has loaded.
  const highlightRef = useRef<HTMLElement | null>(null);

  const [highlightedKey, setHighlightedKey] = useState<string | null>(null);

  /**
   * Scrolled to and outlined once, after the discussion arrives.
   *
   * Waiting for `comments` is the whole point: the target is not in the DOM
   * until the request completes, so an effect that ran on mount would find
   * nothing and the reader would land at the top of a page whose subject is
   * below the fold.
   */
  useEffect(() => {
    if (comments === null || highlightKey === null) {
      return;
    }

    const node = highlightRef.current;

    if (node === null) {
      // The post named by the link is gone: deleted, or on a page the reader may
      // not see. The discussion is still shown rather than refused.
      return;
    }

    setHighlightedKey(highlightKey);

    node.scrollIntoView({ behavior: "smooth", block: "center" });
  }, [comments, highlightKey]);

  /**
   * A query-string id, or null if it is absent or not a number.
   */
  function toId(value: string | undefined): number | null {
    if (value === undefined) {
      return null;
    }

    const parsed = Number(value);

    return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
  }

  const load = useCallback(() => {
    let cancelled = false;

    listComments(workspaceId, entryId)
      .then((result) => {
        if (!cancelled) {
          setComments(result);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setError("Could not load the discussion.");
        }
      });

    return () => {
      cancelled = true;
    };
  }, [workspaceId, entryId]);

  useEffect(() => load(), [load]);

  /**
   * The backend is the authority here: it allows exactly these two, so the
   * buttons are shown from the same rule rather than from an optimistic guess.
   * A null user_id means the account was deleted, which grants neither.
   */
  function canEditPost(userId: number | null) {
    return userId !== null && user?.id === userId;
  }

  function canDeletePost(userId: number | null) {
    return canEditPost(userId) || isOwner;
  }

  function authorName(post: EntryComment | EntryReply) {
    return post.user?.username ?? "Deleted user";
  }

  async function handlePost(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (draft.trim() === "") {
      return;
    }

    setPosting(true);
    setError(null);

    try {
      const created = await createComment(workspaceId, entryId, draft);

      setComments((current) => [...(current ?? []), created]);
      setDraft("");
    } catch {
      setError("Could not post your comment. Please try again.");
    } finally {
      setPosting(false);
    }
  }

  async function handleDeleteComment(comment: EntryComment) {
    const confirmed = window.confirm(
      comment.replies && comment.replies.length > 0
        ? "Delete this comment? Its replies will be deleted with it."
        : "Delete this comment? This cannot be undone.",
    );

    if (!confirmed) {
      return;
    }

    setBusyKey(`comment-${comment.id}`);
    setError(null);

    try {
      await deleteComment(workspaceId, entryId, comment.id);
      setComments((current) =>
        (current ?? []).filter((item) => item.id !== comment.id),
      );
    } catch {
      setError("Could not delete this comment.");
    } finally {
      setBusyKey(null);
    }
  }

  async function handlePostReply(
    event: FormEvent<HTMLFormElement>,
    commentId: number,
  ) {
    event.preventDefault();

    if (replyBody.trim() === "") {
      return;
    }

    setBusyKey(`reply-new-${commentId}`);
    setError(null);

    try {
      const created = await createReply(
        workspaceId,
        entryId,
        commentId,
        replyBody,
      );

      setComments((current) =>
        (current ?? []).map((comment) =>
          comment.id === commentId
            ? { ...comment, replies: [...(comment.replies ?? []), created] }
            : comment,
        ),
      );

      setReplyBody("");
      setReplyingTo(null);
    } catch {
      setError("Could not post your reply. Please try again.");
    } finally {
      setBusyKey(null);
    }
  }

  async function handleDeleteReply(commentId: number, reply: EntryReply) {
    if (!window.confirm("Delete this reply? This cannot be undone.")) {
      return;
    }

    setBusyKey(`reply-${reply.id}`);
    setError(null);

    try {
      await deleteReply(workspaceId, entryId, commentId, reply.id);
      setComments((current) =>
        (current ?? []).map((comment) =>
          comment.id === commentId
            ? {
                ...comment,
                replies: (comment.replies ?? []).filter(
                  (item) => item.id !== reply.id,
                ),
              }
            : comment,
        ),
      );
    } catch {
      setError("Could not delete this reply.");
    } finally {
      setBusyKey(null);
    }
  }

  function startEditing(key: string, body: string) {
    setEditingKey(key);
    setEditBody(body);
  }

  function cancelEditing() {
    setEditingKey(null);
    setEditBody("");
  }

  async function handleSaveCommentEdit(comment: EntryComment) {
    if (editBody.trim() === "") {
      return;
    }

    setBusyKey(`comment-${comment.id}`);
    setError(null);

    try {
      const updated = await updateComment(
        workspaceId,
        entryId,
        comment.id,
        editBody,
      );

      setComments((current) =>
        (current ?? []).map((item) =>
          item.id === comment.id
            ? { ...item, body: updated.body, updated_at: updated.updated_at }
            : item,
        ),
      );

      cancelEditing();
    } catch {
      setError("Could not save your changes.");
    } finally {
      setBusyKey(null);
    }
  }

  async function handleSaveReplyEdit(commentId: number, reply: EntryReply) {
    if (editBody.trim() === "") {
      return;
    }

    setBusyKey(`reply-${reply.id}`);
    setError(null);

    try {
      const updated = await updateReply(
        workspaceId,
        entryId,
        commentId,
        reply.id,
        editBody,
      );

      setComments((current) =>
        (current ?? []).map((comment) =>
          comment.id === commentId
            ? {
                ...comment,
                replies: (comment.replies ?? []).map((item) =>
                  item.id === reply.id
                    ? {
                        ...item,
                        body: updated.body,
                        updated_at: updated.updated_at,
                      }
                    : item,
                ),
              }
            : comment,
        ),
      );

      cancelEditing();
    } catch {
      setError("Could not save your changes.");
    } finally {
      setBusyKey(null);
    }
  }

  const count = comments?.length ?? 0;

  return (
    <section className="rounded-2xl border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-4 border-b border-[#D7D5DE] dark:border-[#34343c] px-7 py-5 sm:px-8">
        <div>
          <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
            Conversation
          </p>

          <h2 className="mt-1 text-xl font-semibold tracking-tight">
            Discussion
          </h2>
        </div>

        {comments !== null && count > 0 && (
          <span className="text-sm text-[#9897A1] dark:text-[#8b8b94]">
            {count} {count === 1 ? "comment" : "comments"}
          </span>
        )}
      </div>

      <div className="px-7 py-7 sm:px-8">
        {canPost && (
          <form onSubmit={handlePost} className="mb-7 flex flex-col gap-2">
            <textarea
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              rows={3}
              maxLength={MAX_BODY}
              placeholder="Add a comment for your team…"
              className="w-full resize-y rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-4 py-3 text-sm leading-relaxed text-[#20202A] dark:text-[#ececf1] outline-none transition placeholder:text-[#A3A2AC] dark:placeholder:text-[#7e7e88] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
            />

            <div className="flex items-center justify-between gap-3">
              <span className="text-xs text-[#9897A1] dark:text-[#8b8b94]">
                {draft.length > MAX_BODY - 200
                  ? `${MAX_BODY - draft.length} characters left`
                  : ""}
              </span>

              <button
                type="submit"
                disabled={posting || draft.trim() === ""}
                className="shrink-0 rounded-lg bg-[#5146E5] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-50"
              >
                {posting ? "Posting…" : "Post comment"}
              </button>
            </div>
          </form>
        )}

        {comments === null && (
          <span className="text-sm text-[#9897A1] dark:text-[#8b8b94]">Loading discussion…</span>
        )}

        {comments !== null && comments.length === 0 && (
          <span className="text-sm text-[#9897A1] dark:text-[#8b8b94]">
            No comments yet.
          </span>
        )}

        {comments !== null && comments.length > 0 && (
          <ul className="flex flex-col gap-6">
            {comments.map((comment) => {
              const editingComment = editingKey === `comment-${comment.id}`;
              const commentBusy = busyKey === `comment-${comment.id}`;
              const commentHighlighted = highlightedKey === `comment-${comment.id}`;
              const replies = comment.replies ?? [];

              return (
                <li
                  key={comment.id}
                  ref={(node) => {
                    if (commentHighlighted) {
                      highlightRef.current = node;
                    }
                  }}
                  className={`border-b border-[#F0EFF3] dark:border-[#2c2c33] pb-6 last:border-b-0 last:pb-0 ${
                    commentHighlighted
                      ? "-mx-3 rounded-xl bg-[#F7F6FF] dark:bg-[#1b1b1f] px-3 ring-2 ring-[#5146E5]"
                      : ""
                  }`}
                >
                  <Post
                    author={authorName(comment)}
                    body={comment.body}
                    createdAt={comment.created_at}
                    canEdit={canEditPost(comment.user_id)}
                    canDelete={canDeletePost(comment.user_id)}
                    editing={editingComment}
                    editBody={editBody}
                    onEditBodyChange={setEditBody}
                    busy={commentBusy}
                    onStartEdit={() => startEditing(`comment-${comment.id}`, comment.body)}
                    onCancelEdit={cancelEditing}
                    onSaveEdit={() => handleSaveCommentEdit(comment)}
                    onDelete={() => handleDeleteComment(comment)}
                  >
                    {replies.length > 0 && (
                      <ul className="mt-4 flex flex-col gap-4 border-l-2 border-[#EEEDFF] dark:border-[#32324a] pl-4">
                        {replies.map((reply) => {
                          const replyHighlighted =
                            highlightedKey === `reply-${reply.id}`;

                          return (
                          <li
                            key={reply.id}
                            ref={(node) => {
                              if (replyHighlighted) {
                                highlightRef.current = node;
                              }
                            }}
                            className={
                              replyHighlighted
                                ? "rounded-lg bg-[#F7F6FF] dark:bg-[#1b1b1f] p-2 ring-2 ring-[#5146E5]"
                                : ""
                            }
                          >
                            <Post
                              author={authorName(reply)}
                              body={reply.body}
                              createdAt={reply.created_at}
                              canEdit={canEditPost(reply.user_id)}
                              canDelete={canDeletePost(reply.user_id)}
                              editing={editingKey === `reply-${reply.id}`}
                              editBody={editBody}
                              onEditBodyChange={setEditBody}
                              busy={busyKey === `reply-${reply.id}`}
                              onStartEdit={() =>
                                startEditing(`reply-${reply.id}`, reply.body)
                              }
                              onCancelEdit={cancelEditing}
                              onSaveEdit={() =>
                                handleSaveReplyEdit(comment.id, reply)
                              }
                              onDelete={() =>
                                handleDeleteReply(comment.id, reply)
                              }
                            />
                          </li>
                          );
                        })}
                      </ul>
                    )}

                    {canPost && !editingComment && (
                      <div className="mt-3">
                        {replyingTo === comment.id ? (
                          <form
                            onSubmit={(event) =>
                              handlePostReply(event, comment.id)
                            }
                            className="flex flex-col gap-2"
                          >
                            <textarea
                              value={replyBody}
                              onChange={(event) =>
                                setReplyBody(event.target.value)
                              }
                              rows={2}
                              maxLength={MAX_BODY}
                              autoFocus
                              placeholder="Write a reply…"
                              className="w-full resize-y rounded-lg border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-3 py-2 text-sm leading-relaxed text-[#20202A] dark:text-[#ececf1] outline-none transition placeholder:text-[#A3A2AC] dark:placeholder:text-[#7e7e88] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
                            />

                            <div className="flex items-center gap-2">
                              <button
                                type="submit"
                                disabled={
                                  busyKey === `reply-new-${comment.id}` ||
                                  replyBody.trim() === ""
                                }
                                className="rounded-lg bg-[#5146E5] px-3 py-1.5 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-50"
                              >
                                {busyKey === `reply-new-${comment.id}`
                                  ? "Posting…"
                                  : "Reply"}
                              </button>

                              <button
                                type="button"
                                onClick={() => {
                                  setReplyingTo(null);
                                  setReplyBody("");
                                }}
                                className="rounded-lg px-3 py-1.5 text-sm font-medium text-[#686873] dark:text-[#b0b0b9] transition hover:text-[#20202A]"
                              >
                                Cancel
                              </button>
                            </div>
                          </form>
                        ) : (
                          <button
                            type="button"
                            onClick={() => {
                              setReplyingTo(comment.id);
                              setReplyBody("");
                            }}
                            className="text-xs font-medium text-[#5146E5] transition hover:text-[#4338CA]"
                          >
                            Reply
                          </button>
                        )}
                      </div>
                    )}
                  </Post>
                </li>
              );
            })}
          </ul>
        )}

        {error && (
          <p
            role="alert"
            className="mt-4 rounded-lg border border-[#F2C9D1] dark:border-[#4a2630] bg-[#FCECEF] dark:bg-[#43212b] px-4 py-3 text-sm leading-relaxed text-[#B13D55] dark:text-[#f0a0b0]"
          >
            {error}
          </p>
        )}
      </div>
    </section>
  );
}