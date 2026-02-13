/**
 * Floating Badge Utility
 *
 * Shared logic for updating the floating chat icon badge count.
 * Used by SubjectChat, TherapistDashboard, and therapy_chat_floating.js.
 *
 * Uses .therapy-chat-badge (server-rendered) for compatibility.
 */

/**
 * Update the floating chat icon badge with a new unread count.
 * If count is 0, hides the badge. Otherwise shows it with the count.
 *
 * @param count Unread message count
 */
export function updateFloatingBadge(count: number): void {
  const badge = document.querySelector('.therapy-chat-badge');
  if (!badge) return;

  if (count > 0) {
    (badge as HTMLElement).textContent = count > 99 ? '99+' : String(count);
    (badge as HTMLElement).style.display = '';
  } else {
    (badge as HTMLElement).textContent = '';
    (badge as HTMLElement).style.display = 'none';
  }
}

/**
 * Hide the floating chat icon when chat panel is visible.
 * Restores it when the panel closes.
 * Targets the trigger/link (therapy-chat-floating-trigger or therapy-chat-floating-link).
 *
 * @param isVisible Whether the chat panel is currently visible
 */
export function setFloatingIconVisibility(isVisible: boolean): void {
  const container =
    document.querySelector('.therapy-chat-floating-container') ??
    document.getElementById('therapy-chat-floating-trigger') ??
    document.getElementById('therapy-chat-floating-link');
  if (container) {
    (container as HTMLElement).style.display = isVisible ? 'none' : '';
  }
}
