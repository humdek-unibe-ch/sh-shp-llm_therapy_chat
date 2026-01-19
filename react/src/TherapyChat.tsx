/**
 * Therapy Chat React Entry Point
 * ===============================
 * 
 * Main entry point for the Therapy Chat React components.
 * Initializes either SubjectChat or TherapistDashboard based on container.
 * 
 * Usage in HTML:
 * ```html
 * <!-- Subject Chat -->
 * <div class="therapy-chat-root" data-user-id="123" data-config="...">
 * </div>
 * 
 * <!-- Therapist Dashboard -->
 * <div class="therapist-dashboard-root" data-user-id="123" data-config="...">
 * </div>
 * 
 * <script src="js/ext/therapy-chat.umd.js"></script>
 * ```
 */

import React, { useState, useEffect } from 'react';
import ReactDOM from 'react-dom/client';
import 'bootstrap/dist/css/bootstrap.min.css';

import { SubjectChat } from './components/subject/SubjectChat';
import { TherapistDashboard } from './components/therapist/TherapistDashboard';
import { therapyChatApi, therapistDashboardApi } from './utils/api';
import type { TherapyChatConfig, TherapistDashboardConfig } from './types';

// Import styles
import './components/shared/MessageList.css';
import './components/shared/LoadingIndicator.css';
import './components/subject/SubjectChat.css';
import './components/therapist/TherapistDashboard.css';

/**
 * Parse configuration from container data attributes
 */
function parseSubjectConfig(container: HTMLElement): TherapyChatConfig | null {
  const configData = container.dataset.config;
  
  if (!configData) {
    console.error('Therapy Chat: No config data found');
    return null;
  }

  try {
    return JSON.parse(configData) as TherapyChatConfig;
  } catch (e) {
    console.error('Therapy Chat: Failed to parse config:', e);
    return null;
  }
}

function parseTherapistConfig(container: HTMLElement): TherapistDashboardConfig | null {
  const configData = container.dataset.config;
  
  if (!configData) {
    console.error('Therapist Dashboard: No config data found');
    return null;
  }

  try {
    return JSON.parse(configData) as TherapistDashboardConfig;
  } catch (e) {
    console.error('Therapist Dashboard: Failed to parse config:', e);
    return null;
  }
}

/**
 * Subject Chat Loader Component
 */
const SubjectChatLoader: React.FC<{ fallbackConfig: TherapyChatConfig | null }> = ({ fallbackConfig }) => {
  const [config, setConfig] = useState<TherapyChatConfig | null>(fallbackConfig);
  const [loading, setLoading] = useState(!fallbackConfig);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!fallbackConfig?.sectionId) {
      setError('Section ID not provided');
      setLoading(false);
      return;
    }

    // Try to load fresh config from API
    async function loadConfig() {
      try {
        const response = await therapyChatApi.getConfig(fallbackConfig!.sectionId);
        setConfig(response.config);
      } catch (err) {
        console.warn('Failed to load config from API, using fallback:', err);
        // Keep using fallback config
      } finally {
        setLoading(false);
      }
    }

    loadConfig();
  }, [fallbackConfig]);

  if (loading) {
    return (
      <div className="d-flex justify-content-center align-items-center p-5">
        <div className="spinner-border text-primary" role="status">
          <span className="sr-only">Loading...</span>
        </div>
      </div>
    );
  }

  if (error || !config) {
    return (
      <div className="alert alert-danger m-3">
        <i className="fas fa-exclamation-circle mr-2"></i>
        {error || 'Configuration not available.'}
      </div>
    );
  }

  if (!config.userId || config.userId === 0) {
    return (
      <div className="alert alert-warning m-3">
        <i className="fas fa-exclamation-triangle mr-2"></i>
        Please log in to use the therapy chat.
      </div>
    );
  }

  return <SubjectChat config={config} />;
};

/**
 * Therapist Dashboard Loader Component
 */
const TherapistDashboardLoader: React.FC<{ fallbackConfig: TherapistDashboardConfig | null }> = ({ fallbackConfig }) => {
  const [config, setConfig] = useState<TherapistDashboardConfig | null>(fallbackConfig);
  const [loading, setLoading] = useState(!fallbackConfig);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!fallbackConfig?.sectionId) {
      setError('Section ID not provided');
      setLoading(false);
      return;
    }

    // Try to load fresh config from API
    async function loadConfig() {
      try {
        const response = await therapistDashboardApi.getConfig(fallbackConfig!.sectionId);
        setConfig(response.config);
      } catch (err) {
        console.warn('Failed to load config from API, using fallback:', err);
        // Keep using fallback config
      } finally {
        setLoading(false);
      }
    }

    loadConfig();
  }, [fallbackConfig]);

  if (loading) {
    return (
      <div className="d-flex justify-content-center align-items-center p-5">
        <div className="spinner-border text-primary" role="status">
          <span className="sr-only">Loading...</span>
        </div>
      </div>
    );
  }

  if (error || !config) {
    return (
      <div className="alert alert-danger m-3">
        <i className="fas fa-exclamation-circle mr-2"></i>
        {error || 'Configuration not available.'}
      </div>
    );
  }

  if (!config.userId || config.userId === 0) {
    return (
      <div className="alert alert-warning m-3">
        <i className="fas fa-exclamation-triangle mr-2"></i>
        Please log in to access the therapist dashboard.
      </div>
    );
  }

  return <TherapistDashboard config={config} />;
};

/**
 * Initialize Subject Chat instances
 */
function initializeSubjectChat(): void {
  const containers = document.querySelectorAll('.therapy-chat-root');
  
  containers.forEach((container, index) => {
    const element = container as HTMLElement;
    const config = parseSubjectConfig(element);

    try {
      const root = ReactDOM.createRoot(element);
      root.render(
        <React.StrictMode>
          <SubjectChatLoader fallbackConfig={config} />
        </React.StrictMode>
      );
      console.debug(`Therapy Chat [${index}]: Initialized successfully`);
    } catch (error) {
      console.error(`Therapy Chat [${index}]: Failed to initialize`, error);
      element.innerHTML = `
        <div class="alert alert-danger m-3">
          <i class="fas fa-exclamation-circle mr-2"></i>
          Failed to load chat interface. Please refresh the page.
        </div>
      `;
    }
  });
}

/**
 * Initialize Therapist Dashboard instances
 */
function initializeTherapistDashboard(): void {
  const containers = document.querySelectorAll('.therapist-dashboard-root');
  
  containers.forEach((container, index) => {
    const element = container as HTMLElement;
    const config = parseTherapistConfig(element);

    try {
      const root = ReactDOM.createRoot(element);
      root.render(
        <React.StrictMode>
          <TherapistDashboardLoader fallbackConfig={config} />
        </React.StrictMode>
      );
      console.debug(`Therapist Dashboard [${index}]: Initialized successfully`);
    } catch (error) {
      console.error(`Therapist Dashboard [${index}]: Failed to initialize`, error);
      element.innerHTML = `
        <div class="alert alert-danger m-3">
          <i class="fas fa-exclamation-circle mr-2"></i>
          Failed to load dashboard. Please refresh the page.
        </div>
      `;
    }
  });
}

/**
 * Main initialization
 */
function initialize(): void {
  initializeSubjectChat();
  initializeTherapistDashboard();
}

/**
 * Auto-initialize when DOM is ready
 */
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initialize);
} else {
  initialize();
}

/**
 * Export components for direct usage
 */
export { SubjectChat, TherapistDashboard };
export type { TherapyChatConfig, TherapistDashboardConfig };
