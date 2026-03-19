/**
 * Unread Count Helper Utilities
 * =============================
 *
 * Centralized unread count calculations used by dashboard components.
 */

import type { UnreadCounts } from '../types';

/**
 * Get unread count for a specific subject (patient)
 * Handles both string and numeric user IDs for compatibility
 */
export function getUnreadForSubject(
  unreadCounts: UnreadCounts | undefined,
  userId: number | string
): number {
  if (!unreadCounts || !unreadCounts.bySubject) return 0;
  
  const bySubject = unreadCounts.bySubject;
  const uid = userId;
  
  // Try both string and numeric keys for compatibility
  const count = bySubject[uid] ?? bySubject[String(uid)] ?? bySubject[Number(uid)] ?? null;
  return count?.unreadCount ?? 0;
}

/**
 * Get total unread count (messages + alerts)
 */
export function getTotalUnread(unreadCounts: UnreadCounts | undefined): number {
  if (!unreadCounts) return 0;
  return (unreadCounts.total ?? 0) + (unreadCounts.totalAlerts ?? 0);
}
