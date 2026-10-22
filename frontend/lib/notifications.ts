import { api } from "./api";

export type NotificationKind =
  | "comment"
  | "reply"
  | "join_request"
  | "unknown";

export interface AppNotification {
  id: string;
  // The notification class name. Kept so an unknown type is recognisable, but
  // the UI branches on `kind` instead.
  type: string;
  kind: NotificationKind;
  // Null when the notification was written before these fields existed, or when
  // the account that caused it has since been deleted.
  actor_username: string | null;
  entry_title: string | null;
  excerpt: string | null;
  // The ids that make the notification a link to a post rather than to a page.
  // Null when the notification cannot be linked to, which happens when the
  // entry or the comment it referred to has since been deleted.
  workspace_id: number | null;
  workspace_name: string | null;
  membership_request_id: number | null;
  requested_role: string | null;
  entry_id: number | null;
  comment_id: number | null;
  reply_id: number | null;
  read_at: string | null;
  created_at: string;
}

interface ResourceResponse<T> {
  data: T;
}

export async function listNotifications(): Promise<AppNotification[]> {
  const { data } = await api.get<ResourceResponse<AppNotification[]>>(
    "/notifications",
  );

  return data.data;
}

/**
 * Mark every unread notification as read.
 *
 * There is no per-notification read endpoint: a notification exists to be read,
 * and a panel where each row needs its own button is a worse trade than one
 * button for the whole list.
 */
export async function markAllNotificationsRead(): Promise<number> {
  const { data } = await api.post<
    ResourceResponse<{ marked: number }>
  >("/notifications/read-all");

  return data.data.marked;
}

/**
 * Where a notification takes you: the entry, with the comment or reply it is
 * about selected in the URL so the discussion panel can scroll to it.
 *
 * Returns null when the notification is not linkable, which the caller renders
 * as plain text rather than as a link to a page that would land somewhere
 * arbitrary.
 */
export function notificationHref(
  notification: AppNotification,
): string | null {
  if (notification.workspace_id === null) {
    return null;
  }

  if (notification.kind === "join_request") {
    return `/workspaces/${notification.workspace_id}#join-requests`;
  }

  if (notification.entry_id === null || notification.comment_id === null) {
    return null;
  }

  const query = new URLSearchParams({
    comment: String(notification.comment_id),
  });

  // A reply names its parent comment as well, because the reply is rendered
  // underneath it and scrolling to a reply without its comment would leave the
  // reader looking at the wrong thread.
  if (notification.reply_id !== null) {
    query.set("reply", String(notification.reply_id));
  }

  return `/workspaces/${notification.workspace_id}/entries/${notification.entry_id}?${query.toString()}`;
}

/**
 * One line describing what happened, for the panel and for the accessible name.
 */
export function notificationSummary(notification: AppNotification): string {
  const actor = notification.actor_username ?? "Someone";

  if (notification.kind === "reply") {
    return `${actor} replied to your comment`;
  }

  if (notification.kind === "comment") {
    return `${actor} commented on “${notification.entry_title ?? "an entry"}”`;
  }

  if (notification.kind === "join_request") {
    return `${actor} requested to join “${notification.workspace_name ?? "your workspace"}”`;
  }

  return "You have a new notification";
}
