/**
 * SubjectChat Component
 * ======================
 *
 * The patient-facing chat interface. Clean, card-based UI.
 *
 * Features:
 *   - Send / receive messages (AI + therapist)
 *   - Help label for @mention and #hashtag usage
 *   - Mode badge (AI vs human-only)
 *   - Speech-to-text input
 *   - Polling for new messages
 *   - Auto-clears the floating chat badge on load
 *   - AI mode indicator (ai_enabled = true → AI responds, false → human-only)
 */

import React, { useEffect, useCallback, useMemo, useRef, useState } from 'react';
import { MessageList } from '../shared/MessageList';
import { MessageInput } from '../shared/MessageInput';
import type { MentionItem } from '../shared/MessageInput';
import { LoadingIndicator } from '../shared/LoadingIndicator';
import { TaggingPanel } from '../shared/TaggingPanel';
import { useChatState } from '../../hooks/useChatState';
import { usePolling } from '../../hooks/usePolling';
import { createSubjectApi } from '../../utils/api';
import { updateFloatingBadge } from '../../utils/floatingBadge';
import type { SubjectChatConfig } from '../../types';

interface SubjectChatProps {
  config: SubjectChatConfig;
}

/**
 * Check if the floating panel is currently visible.
 * Returns true when the panel's display is not 'none'.
 */
function isFloatingPanelVisible(): boolean {
  const panel = document.getElementById('therapy-chat-floating-panel');
  if (!panel) return false;
  return panel.style.display !== 'none' && panel.style.display !== '';
}

export const SubjectChat: React.FC<SubjectChatProps> = ({ config }) => {
  const api = useMemo(
    () => createSubjectApi(config.sectionId, config.baseUrl),
    [config.sectionId, config.baseUrl],
  );
  const loadedRef = useRef(false);
  const isFloating = !!config.isFloatingMode;

  // Track floating panel visibility so we only mark-as-read when user can see messages
  const [panelVisible, setPanelVisible] = useState(!isFloating);

  useEffect(() => {
    if (!isFloating) return;

    const panel = document.getElementById('therapy-chat-floating-panel');
    if (!panel) return;

    // Sync initial state
    setPanelVisible(isFloatingPanelVisible());

    // Watch for style changes (toggle sets display: flex | none)
    const observer = new MutationObserver(() => {
      setPanelVisible(isFloatingPanelVisible());
    });
    observer.observe(panel, { attributes: true, attributeFilter: ['style'] });

    return () => observer.disconnect();
  }, [isFloating]);

  const {
    conversation,
    messages,
    isLoading,
    isSending,
    error,
    loadConversation,
    sendMessage,
    pollMessages,
    clearError,
  } = useChatState({
    loadFn: (convId) => api.getConversation(convId),
    sendFn: (convId, msg) => api.sendMessage(convId, msg),
    pollFn: (convId, afterId) => api.getMessages(convId, afterId),
    senderType: 'subject',
  });

  // Load conversation ONCE on mount — only mark-as-read when NOT in floating mode
  // (floating mode defers marking until the panel is actually opened/visible)
  useEffect(() => {
    if (loadedRef.current) return;
    loadedRef.current = true;

    (async () => {
      await loadConversation(config.conversationId ?? undefined);
      if (!isFloating) {
        // Normal (non-floating) page: mark read immediately
        try {
          const res = await api.markMessagesRead(config.conversationId ?? undefined);
          updateFloatingBadge(res.unread_count ?? 0);
        } catch { /* non-critical */ }
      }
    })();
  }, []);  // eslint-disable-line react-hooks/exhaustive-deps

  // When floating panel becomes visible, mark messages as read
  const prevVisibleRef = useRef(panelVisible);
  useEffect(() => {
    if (isFloating && panelVisible && !prevVisibleRef.current && conversation) {
      // Panel just became visible — mark messages read
      (async () => {
        try {
          const res = await api.markMessagesRead();
          updateFloatingBadge(res.unread_count ?? 0);
        } catch { /* non-critical */ }
      })();
    }
    prevVisibleRef.current = panelVisible;
  }, [panelVisible, isFloating, conversation, api]);

  // Lightweight polling: quick check, only full fetch if new data.
  // In floating mode: skip polling entirely when the panel is hidden —
  // the standalone JS (therapy_chat_floating.js) handles badge updates.
  const lastKnownMsgIdRef = useRef<number | null>(null);

  const pollAndMark = useCallback(async () => {
    try {
      const updates = await api.checkUpdates();
      if (updates.latest_message_id === lastKnownMsgIdRef.current) return; // nothing new
      lastKnownMsgIdRef.current = updates.latest_message_id;

      // New messages detected — do the full fetch
      await pollMessages();
      // Only mark as read if user can actually see the messages
      if (!isFloating || isFloatingPanelVisible()) {
        const res = await api.markMessagesRead();
        updateFloatingBadge(res.unread_count ?? 0);
      }
    } catch { /* polling errors are non-fatal */ }
  }, [pollMessages, api, isFloating]);

  usePolling({
    callback: pollAndMark,
    interval: config.pollingInterval,
    // In floating mode, only poll when the panel is visible
    enabled: !!conversation && (!isFloating || panelVisible),
  });

  const labels = config.labels;

  // Build @mention fetch callback: loads therapists from the backend
  const fetchMentions = useCallback(async (): Promise<MentionItem[]> => {
    try {
      const data = await api.getTherapists();
      const therapists = (data as unknown as { therapists: Array<{ id: number; display?: string; name?: string }> }).therapists || [];
      const items: MentionItem[] = therapists.map((t) => ({
        id: t.id,
        display: t.display || t.name || 'Therapist',
        insertText: '@' + (t.display || t.name || 'therapist'),
      }));
      // Always include generic @therapist as first option
      if (!items.some(i => i.insertText === '@therapist')) {
        items.unshift({ id: 'therapist', display: 'therapist (all)', insertText: '@therapist' });
      }
      return items;
    } catch {
      return [{ id: 'therapist', display: 'therapist', insertText: '@therapist' }];
    }
  }, [api]);

  // Build #topic suggestions from tag reasons
  const topicSuggestions: MentionItem[] = useMemo(() => {
    if (!config.tagReasons || config.tagReasons.length === 0) return [];
    return config.tagReasons.map((r) => ({
      id: r.code,
      display: r.label || r.code,
      insertText: '#' + r.code,
    }));
  }, [config.tagReasons]);

  return (
    <div className="tc-subject">
      {/* Error banner */}
      {error && (
        <div className="alert alert-danger alert-dismissible fade show m-3 mb-0" role="alert">
          <i className="fas fa-exclamation-circle mr-2" />
          {error}
          <button type="button" className="close" onClick={clearError}>
            <span>&times;</span>
          </button>
        </div>
      )}

      <div className="card border-0 shadow-sm h-100">
        {/* Header */}
        <div className="card-header bg-primary text-white d-flex justify-content-between align-items-center">
          <div className="d-flex align-items-center">
            <i className="fas fa-comments mr-2" />
            <h5 className="mb-0">Therapy Chat</h5>
          </div>
          {conversation && (
            <div className="d-flex align-items-center" style={{ gap: '0.5rem' }}>
              <span className={`badge ${conversation.ai_enabled ? 'badge-light' : 'badge-warning'}`}>
                <i className={`fas ${conversation.ai_enabled ? 'fa-robot' : 'fa-user-md'} mr-1`} />
                {conversation.ai_enabled ? labels.mode_ai : labels.mode_human}
              </span>
            </div>
          )}
        </div>

        {/* Messages area */}
        <div className="card-body p-0 d-flex flex-column" style={{ minHeight: 400 }}>
          <MessageList
            messages={messages}
            isLoading={isLoading}
            isTherapistView={false}
            chatColors={config.chatColors}
            emptyText={labels.empty_message}
          />

          {isSending && (
            <div className="px-3 pb-2">
              <LoadingIndicator text={labels.ai_thinking} />
            </div>
          )}
        </div>

        {/* Input area – patient can always send messages */}
        <div className="card-footer bg-white border-top">
          <TaggingPanel
            enabled={config.taggingEnabled}
            helpText={labels.chat_help_text}
          />
          <MessageInput
            onSend={sendMessage}
            disabled={isSending || isLoading}
            placeholder={labels.placeholder}
            buttonLabel={labels.send_button}
            speechToTextEnabled={config.speechToTextEnabled}
            sectionId={config.sectionId}
            onFetchMentions={config.taggingEnabled ? fetchMentions : undefined}
            topicSuggestions={config.taggingEnabled ? topicSuggestions : undefined}
          />
        </div>
      </div>
    </div>
  );
};

export default SubjectChat;
