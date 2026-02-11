/**
 * Floating Chat Panel Controller
 *
 * Handles the open/close toggle of the floating therapy chat panel.
 * When the panel opens for the first time it fetches the full React
 * configuration from the therapy-chat page via AJAX, writes it into
 * the `.therapy-chat-root` data-config attribute, then asks the React
 * UMD bundle to mount the component.
 *
 * The React UMD bundle exposes:
 *   window.TherapyChat.mountElement(el)   – mount a single element
 *   window.__TherapyChatMount(el)         – alias
 */
$(document).ready(function () {
    var trigger  = document.getElementById('therapy-chat-floating-trigger');
    var panel    = document.getElementById('therapy-chat-floating-panel');
    var backdrop = document.getElementById('therapy-chat-floating-backdrop');
    var closeBtn = document.getElementById('therapy-chat-floating-close');
    var chatBody = panel ? panel.querySelector('.therapy-chat-floating-body') : null;
    var isOpen = false;
    var contentLoaded = false;

    /* ------------------------------------------------------------------
     * loadChatContent – called once when the panel opens for the first time
     * ------------------------------------------------------------------ */
    function loadChatContent() {
        if (contentLoaded || !chatBody) return;
        contentLoaded = true;

        var root = chatBody.querySelector('.therapy-chat-root');
        if (!root) return;

        // The data-config written by PHP contains:
        //   { userId, sectionId, baseUrl, isFloatingMode }
        var configStr = root.getAttribute('data-config');
        var config = {};
        try { config = JSON.parse(configStr || '{}'); } catch (e) { /* keep empty */ }

        if (config.baseUrl && config.sectionId) {
            // Fetch the full React config from the therapy chat page controller
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
                // Preserve floating-specific fields
                fullConfig.isFloatingMode = true;
                fullConfig.baseUrl = config.baseUrl;
                root.setAttribute('data-config', JSON.stringify(fullConfig));
                mountReact(root);
            })
            .catch(function (err) {
                console.error('Therapy Chat: Failed to load floating config:', err);
                // Mount anyway – the React loader will show the error state
                mountReact(root);
            });
        } else {
            // No baseUrl or sectionId; mount with whatever config we have
            mountReact(root);
        }
    }

    /* ------------------------------------------------------------------
     * mountReact – delegate to the UMD bundle's exposed mount functions
     * ------------------------------------------------------------------ */
    function mountReact(root) {
        // Prefer the explicit mountElement function (mounts a single DOM element)
        if (window.TherapyChat && typeof window.TherapyChat.mountElement === 'function') {
            window.TherapyChat.mountElement(root);
            return;
        }
        if (typeof window.__TherapyChatMount === 'function') {
            window.__TherapyChatMount(root);
            return;
        }
        // Last resort: dispatch custom event that the bundle listens for
        root.dispatchEvent(new CustomEvent('therapy-chat-mount', { bubbles: true }));
    }

    /* ------------------------------------------------------------------
     * toggle – open / close the panel
     * ------------------------------------------------------------------ */
    function toggle() {
        isOpen = !isOpen;
        if (panel) panel.style.display = isOpen ? 'flex' : 'none';
        if (backdrop) backdrop.style.display = isOpen ? 'block' : 'none';
        if (trigger) trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

        if (isOpen && !contentLoaded) {
            loadChatContent();
        }

        // Clear badge when opening
        if (isOpen && trigger) {
            var badge = trigger.querySelector('.therapy-chat-badge');
            if (badge) badge.style.display = 'none';
        }
    }

    /* ------------------------------------------------------------------
     * Event bindings
     * ------------------------------------------------------------------ */
    if (trigger) {
        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            toggle();
        });
    }
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
