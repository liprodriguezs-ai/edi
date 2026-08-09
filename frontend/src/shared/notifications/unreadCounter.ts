/**
 * Unread-badge counter — derived, NOT polled.
 *
 * The initial value comes from Orion's paginated search meta (`meta.total`) on
 * `POST /notification_user/search` with `is_read=false` + `limit:1` (one cheap
 * call, no dedicated badge endpoint). After that the badge is maintained
 * incrementally in the React Query cache: +1 on each incoming push, 0 on
 * "mark all read", -1 when a single notification is read. Window-focus refetch
 * reconciles any drift (covers users without push permission).
 *
 * These helpers are pure so the maintenance logic is unit-testable.
 */

/** React Query keys for the notification badge + recent list. */
export const UNREAD_COUNT_KEY = ['notifications', 'unread'] as const
export const RECENT_KEY = ['notifications', 'recent'] as const

/** +1 when a new push arrives (an empty cache counts as 0). */
export function incrementUnread(current: number | undefined): number {
  return (current ?? 0) + 1
}

/** -1 when a notification is marked read; never below 0. */
export function decrementUnread(current: number | undefined): number {
  return Math.max(0, (current ?? 0) - 1)
}

/** 0 after "mark all read". */
export function resetUnread(): number {
  return 0
}

/** Reads the unread total from an Orion paginated search response (`meta.total`). */
export function unreadCountFromSearch(
  payload: { meta?: { total?: number } } | undefined | null,
): number {
  return payload?.meta?.total ?? 0
}
