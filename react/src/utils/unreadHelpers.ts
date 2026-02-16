/**
 * Unread Count Helper Utilities
 * =============================
 *
 * Centralized unread count calculations and formatting.
 * Eliminates duplication across components.
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

/**
 * Get unread count for a specific group
 */
export function getUnreadForGroup(
  unreadCounts: UnreadCounts | undefined,
  groupId: number
): number {
  if (!unreadCounts || !unreadCounts.byGroup) return 0;
  
  const groupData = unreadCounts.byGroup[groupId] ?? unreadCounts.byGroup[String(groupId)];
  return typeof groupData === 'number' ? groupData : 0;
}

/**
 * Format count for badge display
 * Shows "99+" for counts >= 100
 */
export function formatBadgeCount(count: number): string {
  if (count <= 0) return '0';
  return count > 99 ? '99+' : String(count);
}

/**
 * Check if there are any unread alerts
 */
export function hasUnreadAlerts(unreadCounts: UnreadCounts | undefined): boolean {
  return (unreadCounts?.totalAlerts ?? 0) > 0;
}

/**
 * Check if there are any unread messages
 */
export function hasUnreadMessages(unreadCounts: UnreadCounts | undefined): boolean {
  return (unreadCounts?.total ?? 0) > 0;
}

/**
 * Get unread breakdown by type
 */
export function getUnreadBreakdown(unreadCounts: UnreadCounts | undefined): {
  messages: number;
  alerts: number;
  total: number;
} {
  if (!unreadCounts) {
    return { messages: 0, alerts: 0, total: 0 };
  }
  
  const messages = unreadCounts.total ?? 0;
  const alerts = unreadCounts.totalAlerts ?? 0;
  const total = messages + alerts;
  
  return { messages, alerts, total };
}

/**
 * Check if a subject has any unread items (messages or alerts)
 */
export function subjectHasUnread(
  unreadCounts: UnreadCounts | undefined,
  userId: number | string
): boolean {
  return getUnreadForSubject(unreadCounts, userId) > 0;
}

/**
 * Get subjects with unread counts (for sorting/filtering)
 */
export function getSubjectsWithUnread(unreadCounts: UnreadCounts | undefined): Array<{
  userId: string | number;
  unreadCount: number;
  subjectName: string;
}> {
  if (!unreadCounts?.bySubject) return [];
  
  return Object.entries(unreadCounts.bySubject)
    .map(([userId, data]) => ({
      userId: isNaN(Number(userId)) ? userId : Number(userId),
      unreadCount: data.unreadCount,
      subjectName: data.subjectName,
    }))
    .filter(item => item.unreadCount > 0)
    .sort((a, b) => b.unreadCount - a.unreadCount);
}
