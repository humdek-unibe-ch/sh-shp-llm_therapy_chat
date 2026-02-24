<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Therapy Chat Navigation Item Template
 *
 * Renders a navigation bar icon for the therapy chat with unread badge.
 * Variables:
 * - $chatUrl: URL for the chat page
 * - $iconTitle: Tooltip text
 * - $icon: FontAwesome icon class
 * - $unreadCount: Number of unread messages
 * - $label: Optional text label
 * - $pollConfig: JSON config for polling
 */
?>
<li class="nav-item therapy-chat-nav-item" id="therapy-chat-nav-item">
    <a href="<?php echo $chatUrl; ?>" class="nav-link therapy-chat-nav-link"
       title="<?php echo htmlspecialchars($iconTitle); ?>"
       data-poll-config="<?php echo htmlspecialchars($pollConfig); ?>">
        <i class="fas <?php echo $icon; ?> therapy-chat-nav-icon"></i>
        <?php if (!empty($label)): ?>
            <span class="therapy-chat-nav-label"><?php echo htmlspecialchars($label); ?></span>
        <?php endif; ?>
        <span class="therapy-chat-nav-badge badge badge-danger badge-pill<?php echo $unreadCount > 0 ? '' : ' d-none'; ?>"
              id="therapy-chat-nav-badge">
            <?php echo $unreadCount > 0 ? $unreadCount : ''; ?>
        </span>
    </a>
</li>

<style>
.therapy-chat-nav-item {
    position: relative;
}
.therapy-chat-nav-link {
    position: relative;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 0.4rem 0.6rem !important;
    color: var(--nav-link-color, rgba(255,255,255,0.85)) !important;
    transition: color 0.2s;
}
.therapy-chat-nav-link:hover {
    color: #fff !important;
}
.therapy-chat-nav-icon {
    font-size: 1.15rem;
}
.therapy-chat-nav-label {
    font-size: 0.85rem;
    font-weight: 500;
    white-space: nowrap;
}
.therapy-chat-nav-badge {
    position: absolute;
    top: 2px;
    right: -2px;
    font-size: 0.6rem;
    min-width: 16px;
    height: 16px;
    line-height: 16px;
    text-align: center;
    border-radius: 50%;
    padding: 0 4px;
    animation: therapy-badge-pulse 2s ease-in-out infinite;
}
@keyframes therapy-badge-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}
</style>

<script>
(function() {
    var navItem = document.getElementById('therapy-chat-nav-item');
    var navLink = navItem ? navItem.querySelector('.therapy-chat-nav-link[data-poll-config]') : null;
    if (!navLink) return;

    // Remove duplicate: hide any regular nav item that links to the same URL
    var chatHref = navLink.getAttribute('href');
    if (chatHref) {
        var allNavLinks = document.querySelectorAll('.navbar-nav .nav-link, .nav-item .nav-link');
        allNavLinks.forEach(function(link) {
            if (link === navLink) return;
            var href = link.getAttribute('href') || '';
            if (href && (href === chatHref || href.replace(/\/$/, '') === chatHref.replace(/\/$/, ''))) {
                var parentLi = link.closest('li');
                if (parentLi && parentLi !== navItem) {
                    parentLi.style.display = 'none';
                }
            }
        });
    }

    var badge = document.getElementById('therapy-chat-nav-badge');
    if (!badge) return;

    var config;
    try {
        config = JSON.parse(navLink.getAttribute('data-poll-config'));
    } catch(e) { return; }

    if (!config || !config.sectionId) return;

    var interval = config.interval || 5000;
    var basePath = document.querySelector('meta[name="base-path"]');
    var baseUrl = basePath ? basePath.getAttribute('content') : '';

    function pollUnread() {
        var action = config.role === 'therapist' ? 'get_unread_counts' : 'check_updates';
        var url = baseUrl + config.baseUrl + '?action=' + action + '&section_id=' + config.sectionId;

        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var count = 0;
                if (config.role === 'therapist') {
                    var uc = data.unread_counts || {};
                    if (uc.total !== undefined) count = uc.total;
                    else if (data.unread_messages !== undefined) count = (data.unread_messages || 0) + (data.unread_alerts || 0);
                } else {
                    count = data.unread_count || data.unread_messages || 0;
                }
                updateBadge(count);
            })
            .catch(function() {});
    }

    function updateBadge(count) {
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.classList.remove('d-none');
        } else {
            badge.textContent = '';
            badge.classList.add('d-none');
        }
    }

    setInterval(pollUnread, interval);
    setTimeout(pollUnread, 2000);
})();
</script>
