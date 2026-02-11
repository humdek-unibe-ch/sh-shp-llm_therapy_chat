/**
 * Floating Chat Component
 * =======================
 *
 * Renders the therapy chat as a floating button + modal panel.
 * When `enableFloatingChat` is true in the config, this component renders
 * instead of the inline SubjectChat.
 *
 * Features:
 * - Configurable button position (bottom-right, bottom-left, etc.)
 * - Configurable icon and label
 * - Smooth open/close animation
 * - Unread badge on the button
 * - Mobile-responsive with backdrop overlay
 * - Passes modified config to SubjectChat with isFloatingMode: true to prevent recursion
 */

import React, { useState, useCallback, useEffect, useRef, useMemo } from 'react';
import { SubjectChat } from './SubjectChat';
import type { SubjectChatConfig } from '../../types';
import { createSubjectApi } from '../../utils/api';

interface FloatingChatProps {
  config: SubjectChatConfig;
}

export const FloatingChat: React.FC<FloatingChatProps> = ({ config }) => {
  const [isOpen, setIsOpen] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);
  const panelRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);

  const position = config.floatingChatPosition || 'bottom-right';
  const icon = config.floatingChatIcon || 'fa-comments';
  const label = config.floatingChatLabel || 'Chat';
  const title = config.floatingChatTitle || 'Therapy Chat';

  // Generate unique ID for this instance
  const instanceId = useMemo(() => `tc-floating-${config.sectionId}`, [config.sectionId]);

  // Poll for unread count when closed
  useEffect(() => {
    if (isOpen) return;

    const api = createSubjectApi(config.sectionId);
    let mounted = true;

    const fetchUnread = async () => {
      try {
        const resp = await api.checkUpdates() as { unread_count?: number };
        if (mounted) {
          setUnreadCount(resp?.unread_count ?? 0);
        }
      } catch {
        // ignore
      }
    };

    fetchUnread();
    const interval = setInterval(fetchUnread, config.pollingInterval || 10000);

    return () => {
      mounted = false;
      clearInterval(interval);
    };
  }, [isOpen, config.sectionId, config.pollingInterval]);

  // Clear badge when opening
  const handleToggle = useCallback(() => {
    setIsOpen(prev => {
      if (!prev) setUnreadCount(0); // clear on open
      return !prev;
    });
  }, []);

  // Close on Escape
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && isOpen) {
        setIsOpen(false);
      }
    };
    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen]);

  // Build a modified config for the inner SubjectChat
  const innerConfig: SubjectChatConfig = useMemo(() => ({
    ...config,
    enableFloatingChat: false,  // prevent recursion
    isFloatingMode: true,
  }), [config]);

  // Position classes
  const positionStyles = getPositionStyles(position);

  return (
    <>
      {/* Floating button */}
      <button
        ref={buttonRef}
        id={instanceId}
        className="tc-floating-btn"
        style={positionStyles.button}
        onClick={handleToggle}
        title={label}
        aria-label={label}
        aria-expanded={isOpen}
      >
        <i className={`fas ${icon}`} />
        {label && <span className="tc-floating-btn-label">{label}</span>}
        {unreadCount > 0 && (
          <span className="tc-floating-badge">{unreadCount > 99 ? '99+' : unreadCount}</span>
        )}
      </button>

      {/* Backdrop (mobile) */}
      {isOpen && (
        <div
          className="tc-floating-backdrop"
          onClick={() => setIsOpen(false)}
        />
      )}

      {/* Chat panel */}
      <div
        ref={panelRef}
        className={`tc-floating-panel ${isOpen ? 'tc-floating-panel--open' : ''}`}
        style={positionStyles.panel}
        role="dialog"
        aria-label={title}
      >
        {/* Header */}
        <div className="tc-floating-header">
          <h6 className="tc-floating-title mb-0">{title}</h6>
          <button
            className="tc-floating-close"
            onClick={() => setIsOpen(false)}
            aria-label="Close chat"
          >
            <i className="fas fa-times" />
          </button>
        </div>

        {/* Chat content — only render SubjectChat when open to save resources */}
        <div className="tc-floating-body">
          {isOpen && <SubjectChat config={innerConfig} />}
        </div>
      </div>
    </>
  );
};

// ---------------------------------------------------------------------------
// Position helpers
// ---------------------------------------------------------------------------

function getPositionStyles(position: string): { button: React.CSSProperties; panel: React.CSSProperties } {
  const base: React.CSSProperties = { position: 'fixed', zIndex: 10000 };
  const panelBase: React.CSSProperties = { position: 'fixed', zIndex: 10001 };

  switch (position) {
    case 'bottom-left':
      return {
        button: { ...base, bottom: 20, left: 20 },
        panel: { ...panelBase, bottom: 80, left: 20 },
      };
    case 'top-right':
      return {
        button: { ...base, top: 20, right: 20 },
        panel: { ...panelBase, top: 80, right: 20 },
      };
    case 'top-left':
      return {
        button: { ...base, top: 20, left: 20 },
        panel: { ...panelBase, top: 80, left: 20 },
      };
    case 'bottom-center':
      return {
        button: { ...base, bottom: 20, left: '50%', transform: 'translateX(-50%)' },
        panel: { ...panelBase, bottom: 80, left: '50%', transform: 'translateX(-50%)' },
      };
    case 'top-center':
      return {
        button: { ...base, top: 20, left: '50%', transform: 'translateX(-50%)' },
        panel: { ...panelBase, top: 80, left: '50%', transform: 'translateX(-50%)' },
      };
    case 'bottom-right':
    default:
      return {
        button: { ...base, bottom: 20, right: 20 },
        panel: { ...panelBase, bottom: 80, right: 20 },
      };
  }
}
