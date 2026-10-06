"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";

import {
  AppNotification,
  listNotifications,
  markAllNotificationsRead,
  notificationHref,
  notificationSummary,
} from "@/lib/notifications";

/**
 * Recent notifications, behind a bell.
 *
 * Opened by the bell and closed by Escape, by clicking outside, or by following
 * one of its links, so there is always a way out that does not require hitting
 * the bell again.
 */
export function NotificationBell() {
  const [open, setOpen] = useState(false);
  const [notifications, setNotifications] = useState<AppNotification[] | null>(
    null,
  );
  const [error, setError] = useState<string | null>(null);
  const [marking, setMarking] = useState(false);

  const containerRef = useRef<HTMLDivElement>(null);

  const load = useCallback(() => {
    let cancelled = false;

    listNotifications()
      .then((result) => {
        if (!cancelled) {
          setNotifications(result);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setNotifications([]);
          setError("Could not load your notifications.");
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  // Loaded on mount rather than on open, so the badge is right before the panel
  // is ever seen. Notifications are not polled: this app has no broadcasting
  // driver, and polling an endpoint that has nothing new to say would be the
  // only way to notice.
  useEffect(() => load(), [load]);

  useEffect(() => {
    if (!open) {
      return;
    }

    function handlePointerDown(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) {
        setOpen(false);
      }
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") {
        setOpen(false);
      }
    }

    document.addEventListener("mousedown", handlePointerDown);
    document.addEventListener("keydown", handleKeyDown);

    return () => {
      document.removeEventListener("mousedown", handlePointerDown);
      document.removeEventListener("keydown", handleKeyDown);
    };
  }, [open]);

  const unreadCount =
    notifications?.filter((notification) => notification.read_at === null)
      .length ?? 0;

  async function handleMarkAllRead() {
    setMarking(true);
    setError(null);

    try {
      await markAllNotificationsRead();

      // Every notification on screen was either already read or just marked
      // read by that call, so the whole list is read now. A notification that
      // arrives after this point is not in the list yet and will be picked up
      // by the next load.
      setNotifications((current) =>
        (current ?? []).map((notification) => ({
          ...notification,
          read_at: notification.read_at ?? new Date().toISOString(),
        })),
      );
    } catch {
      setError("Could not mark your notifications as read.");
    } finally {
      setMarking(false);
    }
  }

  return (
    <div className="relative" ref={containerRef}>
      <button
        type="button"
        onClick={() => setOpen((current) => !current)}
        aria-expanded={open}
        aria-haspopup="true"
        aria-label={
          unreadCount > 0
            ? `Notifications, ${unreadCount} unread`
            : "Notifications"
        }
        className="relative flex h-9 w-9 items-center justify-center rounded-lg border border-[#DDDCE3] bg-white text-[#686873] transition hover:bg-[#F7F6F2] hover:text-[#20202A] focus:outline-none focus:ring-2 focus:ring-[#EEEDFF]"
      >
        <svg
          width="16"
          height="16"
          viewBox="0 0 24 24"
          fill="none"
          aria-hidden="true"
        >
          <path
            d="M18 9a6 6 0 10-12 0c0 4.5-2 6-2 6h16s-2-1.5-2-6M13.7 19a2 2 0 01-3.4 0"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>

        {unreadCount > 0 && (
          <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-[#5146E5] px-1 text-[10px] font-semibold text-white">
            {unreadCount > 9 ? "9+" : unreadCount}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 z-20 mt-2 w-[340px] overflow-hidden rounded-2xl border border-[#DDDCE3] bg-white shadow-lg">
          <div className="flex items-center justify-between gap-3 border-b border-[#DDDCE3] px-4 py-3">
            <p className="text-sm font-semibold text-[#20202A]">
              Notifications
            </p>

            {unreadCount > 0 && (
              <button
                type="button"
                onClick={handleMarkAllRead}
                disabled={marking}
                className="rounded-lg px-2 py-1 text-xs font-medium text-[#5146E5] transition hover:bg-[#EEEDFF] disabled:cursor-not-allowed disabled:opacity-50"
              >
                {marking ? "Marking…" : "Mark all as read"}
              </button>
            )}
          </div>

          <ul className="max-h-[380px] overflow-y-auto">
            {notifications === null && (
              <li className="px-4 py-6 text-center text-sm text-[#9897A1]">
                Loading notifications…
              </li>
            )}

            {notifications !== null && notifications.length === 0 && (
              <li className="px-4 py-6 text-center text-sm text-[#9897A1]">
                You have no notifications yet.
              </li>
            )}

            {notifications?.map((notification) => {
              const href = notificationHref(notification);
              const unread = notification.read_at === null;

              const body = (
                <>
                  <p
                    className={`text-sm leading-snug ${
                      unread
                        ? "font-semibold text-[#20202A]"
                        : "font-medium text-[#686873]"
                    }`}
                  >
                    {notificationSummary(notification)}
                  </p>

                  {notification.excerpt && (
                    <p className="mt-1 line-clamp-2 text-xs leading-relaxed text-[#9897A1]">
                      {notification.excerpt}
                    </p>
                  )}

                  <p className="mt-1 text-xs text-[#9897A1]">
                    {new Date(
                      notification.created_at,
                    ).toLocaleString("en-US", {
                      month: "short",
                      day: "numeric",
                      hour: "numeric",
                      minute: "2-digit",
                    })}
                  </p>
                </>
              );

              return (
                <li
                  key={notification.id}
                  className={`border-b border-[#F0EFF3] last:border-b-0 ${
                    unread ? "bg-[#F7F6FF]" : ""
                  }`}
                >
                  {/* A notification that no longer points at a live post is
                      rendered as text, because linking it would send the reader
                      to an entry that cannot show them what it was about. */}
                  {href ? (
                    <Link
                      href={href}
                      onClick={() => setOpen(false)}
                      className="block px-4 py-3 transition hover:bg-[#EEEDFF]"
                    >
                      {body}
                    </Link>
                  ) : (
                    <div className="px-4 py-3">{body}</div>
                  )}
                </li>
              );
            })}
          </ul>

          {error && (
            <p
              role="alert"
              className="border-t border-[#DDDCE3] px-4 py-2.5 text-xs text-[#B13D55]"
            >
              {error}
            </p>
          )}
        </div>
      )}
    </div>
  );
}