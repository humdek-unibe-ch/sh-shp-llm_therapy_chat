/**
 * Floating Chat Icon Controller
 *
 * Two responsibilities:
 *
 * 1. BADGE POLLING — Always active when the floating icon exists (both
 *    modal-mode button and link-mode <a>). Polls the server every N
 *    seconds and updates the `.therapy-chat-badge` counter.
 *
 * 2. MODAL TOGGLE — Only active in modal mode (button with id
 *    `therapy-chat-floating-trigger`). Opens/closes the floating panel,
 *    fetches the React config on first open, and mounts the React app.
 *
 * Configuration is read from `data-poll-config` on the icon element:
 *   { role, baseUrl, sectionId, interval }
 *
 * The React UMD bundle exposes:
 *   window.TherapyChat.mountElement(el) — mount a single element
 *   window.__TherapyChatMount(el)       — alias
 */
$(document).ready(function () {
    // ---- Locate the floating icon (button OR link) ----
    var trigger  = document.getElementById('therapy-chat-floating-trigger');
    var link     = document.getElementById('therapy-chat-floating-link');
    var iconEl   = trigger || link;

    if (!iconEl) return; // no floating icon on this page

    // ---- Parse polling config ----
    var pollConfigStr = iconEl.getAttribute('data-poll-config');
    var pollConfig = {};
    try { pollConfig = JSON.parse(pollConfigStr || '{}'); } catch (e) { /* ignore */ }

    // =====================================================================
    // PART 1: Badge Polling (always active)
    // =====================================================================

    var pollInterval = pollConfig.interval || 30000;
    var badge = iconEl.querySelector('.therapy-chat-badge');

    /**
     * Update the badge element with a new count.
     */
    function updateBadge(count) {
        if (!badge) {
            // Try to find it again (might have been hidden on first load)
            badge = iconEl.querySelector('.therapy-chat-badge');
            if (!badge) return;
        }
        if (count > 0) {
            badge.textContent = String(count);
            badge.style.display = '';
            badge.className = badge.className.replace('badge-secondary', 'badge-danger');
        } else {
            badge.style.display = 'none';
        }
    }

    /**
     * Build a GET URL for polling.
     */
    function buildPollUrl(action) {
        var base = pollConfig.baseUrl;
        if (!base) return null;
        var sep = base.indexOf('?') >= 0 ? '&' : '?';
        var url = base + sep + 'action=' + action;
        if (pollConfig.sectionId) {
            url += '&section_id=' + pollConfig.sectionId;
        }
        return url;
    }

    /**
     * Poll the server for unread counts.
     *
     * Subject: calls `check_updates` → { unread_count }
     * Therapist: calls `get_unread_counts` → { unread_counts: { total, totalAlerts } }
     */
    function pollUnread() {
        var url;
        if (pollConfig.role === 'therapist') {
            url = buildPollUrl('get_unread_counts');
        } else {
            url = buildPollUrl('check_updates');
        }
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
                var uc = data.unread_counts || {};
                count = (uc.total || 0) + (uc.totalAlerts || 0);
            } else {
                count = data.unread_count || 0;
            }
            updateBadge(count);
        })
        .catch(function () {
            // Polling errors are non-fatal; silently retry next interval
        });
    }

    // Start polling if we have a valid baseUrl
    if (pollConfig.baseUrl && pollConfig.sectionId) {
        // Initial poll after a short delay (let the page finish loading)
        setTimeout(pollUnread, 2000);
        setInterval(pollUnread, pollInterval);
    }

    // =====================================================================
    // PART 2: Modal Toggle (only for modal-mode button)
    // =====================================================================

    var panel    = document.getElementById('therapy-chat-floating-panel');
    var backdrop = document.getElementById('therapy-chat-floating-backdrop');
    var closeBtn = document.getElementById('therapy-chat-floating-close');
    var chatBody = panel ? panel.querySelector('.therapy-chat-floating-body') : null;
    var isOpen = false;
    var contentLoaded = false;

    // Only set up modal logic if the trigger button and panel both exist
    if (!trigger || !panel) return;

    /**
     * loadChatContent — called once when the panel opens for the first time.
     * Fetches the full React config from the therapy chat page controller,
     * writes it into the `.therapy-chat-root` data-config, then mounts React.
     */
    function loadChatContent() {
        if (contentLoaded || !chatBody) return;
        contentLoaded = true;

        var root = chatBody.querySelector('.therapy-chat-root');
        if (!root) return;

        var configStr = root.getAttribute('data-config');
        var config = {};
        try { config = JSON.parse(configStr || '{}'); } catch (e) { /* keep empty */ }

        if (config.baseUrl && config.sectionId) {
            var sep = config.baseUrl.indexOf('?') >= 0 ? '&' : '?';
            var url = config.baseUrl + sep
                + 'action=get_config&section_id=' + config.sectionId;

            fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var fullConfig = data.config || data;
                fullConfig.isFloatingMode = true;
                fullConfig.baseUrl = config.baseUrl;
                root.setAttribute('data-config', JSON.stringify(fullConfig));
                mountReact(root);
            })
            .catch(function (err) {
                console.error('Therapy Chat: Failed to load floating config:', err);
                mountReact(root);
            });
        } else {
            mountReact(root);
        }
    }

    /**
     * mountReact — delegate to the UMD bundle's exposed mount functions.
     */
    function mountReact(root) {
        if (window.TherapyChat && typeof window.TherapyChat.mountElement === 'function') {
            window.TherapyChat.mountElement(root);
            return;
        }
        if (typeof window.__TherapyChatMount === 'function') {
            window.__TherapyChatMount(root);
            return;
        }
        root.dispatchEvent(new CustomEvent('therapy-chat-mount', { bubbles: true }));
    }

    /**
     * toggle — open / close the floating panel.
     */
    function toggle() {
        isOpen = !isOpen;
        if (panel) panel.style.display = isOpen ? 'flex' : 'none';
        if (backdrop) backdrop.style.display = isOpen ? 'block' : 'none';
        if (trigger) trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

        if (isOpen && !contentLoaded) {
            loadChatContent();
        }

        // Clear badge when opening (React will handle further updates)
        if (isOpen) {
            updateBadge(0);
        }
    }

    // ---- Event bindings ----
    trigger.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggle();
    });
    if (closeBtn) {
        closeBtn.addEventListener('click', function () { if (isOpen) toggle(); });
    }
    if (backdrop) {
        backdrop.addEventListener('click', function () { if (isOpen) toggle(); });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen) toggle();
    });
});
