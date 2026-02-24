/**
 * Floating Badge Utility
 *
 * Shared logic for updating therapy chat icon badge counts.
 * Used by SubjectChat and TherapistDashboard.
 *
 * Updates both floating and nav badges when present.
 */

/**
 * Update the floating chat icon badge with a new unread count.
 * If count is 0, hides the badge. Otherwise shows it with the count.
 *
 * @param count Unread message count
 */
export function updateFloatingBadge(count: number): void {
  const badges = Array.from(
    document.querySelectorAll<HTMLElement>('.therapy-chat-badge, .therapy-chat-nav-badge')
  );
  if (badges.length === 0) return;

  badges.forEach((badge) => {
    if (count > 0) {
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.style.display = '';
      badge.classList.remove('d-none');
    } else {
      badge.textContent = '';
      badge.style.display = 'none';
      badge.classList.add('d-none');
    }
  });
}
