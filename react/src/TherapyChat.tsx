/**
 * Therapy Chat React Entry Point
 * ===============================
 *
 * Auto-initializes React components on DOM elements:
 *   .therapy-chat-root       -> SubjectChat (patient interface)
 *   .therapist-dashboard-root -> TherapistDashboard (therapist interface)
 *
 * Configuration is read from data-config JSON attribute on each container.
 */

import React, { useState, useEffect } from 'react';
import ReactDOM from 'react-dom/client';
// NOTE: Bootstrap 4.6 CSS is loaded globally by SelfHelp — do NOT import it here.
// Importing it would bundle ~167KB of duplicate CSS into therapy-chat.css.

import { SubjectChat } from './components/subject/SubjectChat';
import { TherapistDashboard } from './components/therapist/TherapistDashboard';
import { createSubjectApi, createTherapistApi } from './utils/api';
import type { SubjectChatConfig, TherapistDashboardConfig } from './types';

// Custom therapy chat styles (tc- prefixed, no Bootstrap)
import './styles/therapy-chat.css';

// ---------------------------------------------------------------------------
// Loaders with config-from-API fallback
// ---------------------------------------------------------------------------

const SubjectChatLoader: React.FC<{ fallback: SubjectChatConfig | null }> = ({ fallback }) => {
  // In floating mode the fallback config is minimal (no labels, etc.)
  // so we MUST fetch the full config from the API before rendering.
  const isFloating = !!fallback?.isFloatingMode;
  const hasFullConfig = !!fallback?.labels;

  const [config, setConfig] = useState<SubjectChatConfig | null>(
    hasFullConfig ? fallback : null,
  );
  const [loading, setLoading] = useState(!hasFullConfig);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!fallback?.sectionId) {
      // No sectionId from data-config — nothing we can fetch
      setLoading(false);
      return;
    }
    if (hasFullConfig && !isFloating) {
      // Full config was provided inline, no need to fetch
      setLoading(false);
      return;
    }
    // Fetch full config from the server.
    // For floating mode, use the baseUrl so the request reaches the
    // correct therapy chat controller instead of the current page.
    (async () => {
      try {
        const api = createSubjectApi(fallback.sectionId, fallback.baseUrl);
        const resp = await api.getConfig();
        // API returns {config: {...}} - extract inner config
        const cfg = (resp as unknown as { config?: SubjectChatConfig })?.config ?? resp;
        // Preserve floating mode flag and baseUrl from the original config
        if (isFloating) {
          cfg.isFloatingMode = true;
          if (fallback.baseUrl) cfg.baseUrl = fallback.baseUrl;
        }
        setConfig(cfg);
      } catch (e) {
        setError(e instanceof Error ? e.message : 'Failed to load configuration');
        // Don't use fallback if it's incomplete (floating mode)
        if (hasFullConfig) setConfig(fallback);
      } finally {
        setLoading(false);
      }
    })();
  }, [fallback?.sectionId, fallback?.baseUrl]);

  if (loading) return <Spinner />;
  if (error || !config) return <ErrorMsg text={error || 'Configuration not available.'} />;
  if (!config.userId) return <WarningMsg text="Please log in to use the therapy chat." />;
  return <SubjectChat config={config} />;
};

const TherapistDashboardLoader: React.FC<{ fallback: TherapistDashboardConfig | null }> = ({ fallback }) => {
  const [config, setConfig] = useState<TherapistDashboardConfig | null>(fallback);
  const [loading, setLoading] = useState(!fallback);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!fallback?.sectionId) {
      // No sectionId from data-config, just use fallback as-is
      setLoading(false);
      return;
    }
    (async () => {
      try {
        const api = createTherapistApi(fallback.sectionId);
        const resp = await api.getConfig();
        // API returns {config: {...}} - extract inner config
        const cfg = (resp as unknown as { config?: TherapistDashboardConfig })?.config ?? resp;
        setConfig(cfg);
      } catch {
        // keep fallback
      } finally {
        setLoading(false);
      }
    })();
  }, [fallback]);

  if (loading) return <Spinner />;
  if (error || !config) return <ErrorMsg text={error || 'Configuration not available.'} />;
  if (!config.userId) return <WarningMsg text="Please log in to access the therapist dashboard." />;
  return <TherapistDashboard config={config} />;
};

// ---------------------------------------------------------------------------
// Small helper components
// ---------------------------------------------------------------------------

const Spinner = () => (
  <div className="d-flex justify-content-center align-items-center p-5">
    <div className="spinner-border text-primary" role="status">
      <span className="sr-only">Loading...</span>
    </div>
  </div>
);

const ErrorMsg: React.FC<{ text: string }> = ({ text }) => (
  <div className="alert alert-danger m-3">
    <i className="fas fa-exclamation-circle mr-2" />
    {text}
  </div>
);

const WarningMsg: React.FC<{ text: string }> = ({ text }) => (
  <div className="alert alert-warning m-3">
    <i className="fas fa-exclamation-triangle mr-2" />
    {text}
  </div>
);

// ---------------------------------------------------------------------------
// Parse JSON config from data attribute
// ---------------------------------------------------------------------------

function parseConfig<T>(el: HTMLElement): T | null {
  try {
    return JSON.parse(el.dataset.config || '') as T;
  } catch {
    console.error('Therapy Chat: Failed to parse data-config');
    return null;
  }
}

// ---------------------------------------------------------------------------
// Mount functions
// ---------------------------------------------------------------------------

/**
 * Mount subject chat components.
 *
 * @param scope  Optional parent element to search within (defaults to document).
 *               Used when mounting inside the floating panel dynamically.
 */
function mountSubjectChats(scope?: HTMLElement | Document): void {
  const root = scope || document;
  root.querySelectorAll<HTMLElement>('.therapy-chat-root').forEach((el, i) => {
    // Skip elements inside the floating panel during auto-init (they mount on open)
    if (!scope && el.closest('#therapy-chat-floating-panel')) return;
    // Skip already-mounted elements
    if (el.dataset.mounted === 'true') return;

    const cfg = parseConfig<SubjectChatConfig>(el);
    try {
      ReactDOM.createRoot(el).render(
        <React.StrictMode>
          <SubjectChatLoader fallback={cfg} />
        </React.StrictMode>,
      );
      el.dataset.mounted = 'true';
    } catch (err) {
      console.error(`SubjectChat [${i}] mount failed`, err);
      el.innerHTML = '<div class="alert alert-danger m-3">Failed to load chat. Please refresh.</div>';
    }
  });
}

function mountTherapistDashboards(): void {
  document.querySelectorAll<HTMLElement>('.therapist-dashboard-root').forEach((el, i) => {
    const cfg = parseConfig<TherapistDashboardConfig>(el);
    try {
      ReactDOM.createRoot(el).render(
        <React.StrictMode>
          <TherapistDashboardLoader fallback={cfg} />
        </React.StrictMode>,
      );
    } catch (err) {
      console.error(`TherapistDashboard [${i}] mount failed`, err);
      el.innerHTML = '<div class="alert alert-danger m-3">Failed to load dashboard. Please refresh.</div>';
    }
  });
}

// ---------------------------------------------------------------------------
// Auto-initialize
// ---------------------------------------------------------------------------

function init(): void {
  mountSubjectChats();
  mountTherapistDashboards();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

// ---------------------------------------------------------------------------
// Expose global mount function for floating modal and dynamic loading
// ---------------------------------------------------------------------------

/**
 * Mount function for use by the floating modal panel.
 *
 * When called without arguments it searches the whole document.
 * When called with a container element, it only mounts within that scope.
 */
function mount(container?: HTMLElement): void {
  const scope = container || document;
  mountSubjectChats(scope);
  mountTherapistDashboards();
}

/** Mount a specific element (used by floating panel's custom event) */
function mountElement(el: HTMLElement): void {
  if (el.classList.contains('therapy-chat-root')) {
    // Reset mounted flag so we can re-mount
    el.dataset.mounted = 'false';
    const cfg = parseConfig<SubjectChatConfig>(el);
    try {
      ReactDOM.createRoot(el).render(
        <React.StrictMode>
          <SubjectChatLoader fallback={cfg} />
        </React.StrictMode>,
      );
      el.dataset.mounted = 'true';
    } catch (err) {
      console.error('SubjectChat mount failed', err);
    }
  } else if (el.classList.contains('therapist-dashboard-root')) {
    const cfg = parseConfig<TherapistDashboardConfig>(el);
    try {
      ReactDOM.createRoot(el).render(
        <React.StrictMode>
          <TherapistDashboardLoader fallback={cfg} />
        </React.StrictMode>,
      );
    } catch (err) {
      console.error('TherapistDashboard mount failed', err);
    }
  }
}

// Expose on window for the floating modal's vanilla JS
if (typeof window !== 'undefined') {
  (window as unknown as Record<string, unknown>).TherapyChat = { mount, mountElement };
  (window as unknown as Record<string, unknown>).__TherapyChatMount = mountElement;
}

// Listen for custom mount events (from floating modal)
document.addEventListener('therapy-chat-mount', (e: Event) => {
  const target = e.target;
  if (target instanceof HTMLElement) {
    mountElement(target);
  }
});

// Exports
export { SubjectChat, TherapistDashboard };
export type { SubjectChatConfig, TherapistDashboardConfig };
