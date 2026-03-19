<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Floating Chat Icon Template
 *
 * Template for the therapy chat floating icon with badge.
 * Variables available:
 * - $chatUrl: URL for the chat link
 * - $iconTitle: Title attribute for the link
 * - $icon: FontAwesome icon class
 * - $badgeHtml: HTML for the notification badge
 * - $positionCss: CSS for positioning
 * - $label: Optional text label for the button
 * - $pollConfig: JSON string with polling config for badge updates
 */
?>
<!-- Therapy Chat Floating Icon -->
<a href="<?php echo $chatUrl; ?>" id="therapy-chat-floating-link" class="position-fixed d-flex align-items-center justify-content-center bg-primary text-white <?php echo !empty($label) ? 'rounded-pill px-3' : 'rounded-circle'; ?> shadow therapy-chat-icon" style="min-width: 50px; height: 50px; font-size: 1.5rem; z-index: 1000; text-decoration: none; transition: transform 0.2s, box-shadow 0.2s; <?php echo $positionCss; ?>" title="<?php echo $iconTitle; ?>" data-poll-config="<?php echo htmlspecialchars($pollConfig); ?>">
    <?php if (!empty($label)): ?>
        <span class="d-flex align-items-center">
            <i class="fas <?php echo $icon; ?> me-2"></i>
            <span><?php echo htmlspecialchars($label); ?></span>
        </span>
    <?php else: ?>
        <i class="fas <?php echo $icon; ?>"></i>
    <?php endif; ?>
    <?php echo $badgeHtml; ?>
</a>

<style>
    .therapy-chat-icon:hover {
        transform: scale(1.05);
        box-shadow: 0 0.25rem 0.5rem rgba(0,0,0,0.3) !important;
        color: white !important;
        text-decoration: none !important;
    }
    .therapy-chat-badge {
        top: -5px;
        right: -5px;
        font-size: 0.7rem;
        min-width: 18px;
        height: 18px;
        line-height: 18px;
        text-align: center;
    }
    .therapy-chat-icon span {
        font-size: 0.9rem;
        font-weight: 500;
        white-space: nowrap;
    }
</style>
