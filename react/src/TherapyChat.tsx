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
import 'bootstrap/dist/css/bootstrap.min.css';

import { SubjectChat } from './components/subject/SubjectChat';
import { FloatingChat } from './components/subject/FloatingChat';
import { TherapistDashboard } from './components/therapist/TherapistDashboard';
import { createSubjectApi, createTherapistApi } from './utils/api';
import type { SubjectChatConfig, TherapistDashboardConfig } from './types';

// Global styles
import './styles/therapy-chat.css';

// ---------------------------------------------------------------------------
// Loaders with config-from-API fallback
// ---------------------------------------------------------------------------

const SubjectChatLoader: React.FC<{ fallback: SubjectChatConfig | null }> = ({ fallback }) => {
  const [config, setConfig] = useState<SubjectChatConfig | null>(fallback);
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
        const api = createSubjectApi(fallback.sectionId);
        const resp = await api.getConfig();
        // API returns {config: {...}} - extract inner config
        const cfg = (resp as unknown as { config?: SubjectChatConfig })?.config ?? resp;
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
  if (!config.userId) return <WarningMsg text="Please log in to use the therapy chat." />;

  // Render as floating chat if enabled (and not already in floating mode to prevent recursion)
  if (config.enableFloatingChat && !config.isFloatingMode) {
    return <FloatingChat config={config} />;
  }

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

function mountSubjectChats(): void {
  document.querySelectorAll<HTMLElement>('.therapy-chat-root').forEach((el, i) => {
    const cfg = parseConfig<SubjectChatConfig>(el);
    try {
      ReactDOM.createRoot(el).render(
        <React.StrictMode>
          <SubjectChatLoader fallback={cfg} />
        </React.StrictMode>,
      );
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

// Exports
export { SubjectChat, FloatingChat, TherapistDashboard };
export type { SubjectChatConfig, TherapistDashboardConfig };
