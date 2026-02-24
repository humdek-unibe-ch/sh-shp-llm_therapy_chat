/**
 * Therapy Chat Icon Polling
 *
 * Polls unread counts for both:
 * - floating profile icon/link
 * - navigation chat icon item
 */
$(document).ready(function () {
    var pollTargets = Array.prototype.slice.call(
        document.querySelectorAll(
            '#therapy-chat-floating-link[data-poll-config], .therapy-chat-nav-link[data-poll-config]'
        )
    );
    if (pollTargets.length === 0) return;

    window.__therapyChatFloatingPollingLoaded = true;

    function updateBadge(targetEl, count) {
        var badge = targetEl.querySelector('.therapy-chat-badge, .therapy-chat-nav-badge');
        if (!badge) return;

        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.style.display = '';
            badge.classList.remove('d-none');
        } else {
            badge.textContent = '';
            badge.style.display = 'none';
            badge.classList.add('d-none');
        }
    }

    function buildPollUrl(baseUrl, action, sectionId) {
        if (!baseUrl || !sectionId) return null;
        try {
            var url = new URL(baseUrl, window.location.origin);
            url.searchParams.set('action', action);
            url.searchParams.set('section_id', String(sectionId));
            return url.toString();
        } catch (e) {
            return null;
        }
    }

    function hideDuplicateNavItem(navLink) {
        if (!navLink || !navLink.classList.contains('therapy-chat-nav-link')) return;

        var navItem = navLink.closest('li');
        var chatHref = navLink.getAttribute('href');
        if (!chatHref) return;

        var allNavLinks = document.querySelectorAll('.navbar-nav .nav-link, .nav-item .nav-link');
        allNavLinks.forEach(function (link) {
            if (link === navLink) return;

            var href = link.getAttribute('href') || '';
            if (!href) return;

            if (href === chatHref || href.replace(/\/$/, '') === chatHref.replace(/\/$/, '')) {
                var parentLi = link.closest('li');
                if (parentLi && parentLi !== navItem) {
                    parentLi.style.display = 'none';
                }
            }
        });
    }

    function pollTarget(targetEl, pollConfig) {
        var action = pollConfig.role === 'therapist' ? 'get_unread_counts' : 'check_updates';
        var url = buildPollUrl(pollConfig.baseUrl, action, pollConfig.sectionId);
        if (!url) return;

        fetch(url, {
            method: 'GET',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var count = 0;
                if (pollConfig.role === 'therapist') {
                    var unread = data.unread_counts || {};
                    count = (unread.total || data.unread_messages || 0) +
                        (unread.totalAlerts || data.unread_alerts || 0);
                } else {
                    count = data.unread_count || data.unread_messages || 0;
                }
                updateBadge(targetEl, count);
            })
            .catch(function () {
                // Non-fatal: retry on next interval.
            });
    }

    pollTargets.forEach(function (targetEl) {
        if (targetEl.getAttribute('data-therapy-poll-bound') === '1') return;
        targetEl.setAttribute('data-therapy-poll-bound', '1');

        var config = {};
        try {
            config = JSON.parse(targetEl.getAttribute('data-poll-config') || '{}');
        } catch (e) {
            config = {};
        }

        hideDuplicateNavItem(targetEl);

        var interval = Number(config.interval) || 30000;
        if (!config.baseUrl || !config.sectionId) return;

        setTimeout(function () {
            pollTarget(targetEl, config);
        }, 2000);
        setInterval(function () {
            pollTarget(targetEl, config);
        }, interval);
    });
});
