<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Inline Profile Chat Icon Template
 *
 * Renders a small icon next to the user profile in the navigation bar.
 * Used when enable_floating_chat is OFF.
 *
 * Variables available:
 * - $chatUrl: URL for the chat page
 * - $iconTitle: Title attribute
 * - $icon: FontAwesome icon class (e.g. fa-comments)
 * - $badgeHtml: HTML for unread count badge (includes .therapy-chat-badge)
 * - $unreadCount: Number of unread messages
 * - $pollConfig: JSON string for polling config (read by therapy_chat_floating.js)
 */
?>
<a href="<?php echo $chatUrl; ?>"
   id="therapy-chat-floating-link"
   class="nav-link d-inline-block position-relative therapy-chat-profile-icon"
   title="<?php echo htmlspecialchars($iconTitle); ?>"
   data-poll-config="<?php echo htmlspecialchars($pollConfig); ?>"
   style="padding: 0.25rem 0.5rem; font-size: 1.2rem;">
    <i class="fas <?php echo $icon; ?>"></i><?php echo $badgeHtml; ?>
</a>
