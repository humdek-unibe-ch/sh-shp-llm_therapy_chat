/**
 * SubjectChat Component
 * ======================
 *
 * The patient-facing chat interface. Clean, card-based UI.
 *
 * Features:
 *   - Send / receive messages (AI + therapist)
 *   - @mention tagging to alert therapist
 *   - Mode badge (AI vs human-only)
 *   - Speech-to-text input
 *   - Polling for new messages
 *   - Auto-clears the floating chat badge on load
 */

import React, { useEffect, useCallback, useMemo, useRef } from 'react';
import { MessageList } from '../shared/MessageList';
import { MessageInput } from '../shared/MessageInput';
import { LoadingIndicator } from '../shared/LoadingIndicator';
import { TaggingPanel } from '../shared/TaggingPanel';
import { useChatState } from '../../hooks/useChatState';
import { usePolling } from '../../hooks/usePolling';
import { createSubjectApi } from '../../utils/api';
import type { SubjectChatConfig, TagUrgency } from '../../types';

interface SubjectChatProps {
  config: SubjectChatConfig;
}

/**
 * Update (or hide) the server-rendered floating chat badge in the DOM.
 * The badge is rendered by TherapyChatHooks::outputTherapyChatIcon() and
 * has the class `.therapy-chat-badge`.
 */
function updateFloatingBadge(count: number): void {
  const badge = document.querySelector('.therapy-chat-badge');
  if (!badge) return;
  if (count <= 0) {
    (badge as HTMLElement).style.display = 'none';
  } else {
    (badge as HTMLElement).textContent = String(count);
    (badge as HTMLElement).style.display = '';
  }
}

export const SubjectChat: React.FC<SubjectChatProps> = ({ config }) => {
  const api = useMemo(() => createSubjectApi(config.sectionId), [config.sectionId]);
  const loadedRef = useRef(false);

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

  // Load conversation ONCE on mount + mark messages as read + clear badge
  useEffect(() => {
    if (loadedRef.current) return;
    loadedRef.current = true;

    (async () => {
      await loadConversation(config.conversationId ?? undefined);
      // Mark messages as read and update the floating badge
      try {
        const res = await api.markMessagesRead(config.conversationId ?? undefined);
        updateFloatingBadge(res.unread_count ?? 0);
      } catch {
        // non-critical – badge stays as-is
      }
    })();
  }, []);  // eslint-disable-line react-hooks/exhaustive-deps

  // Also mark read after each poll (clears badge for new arriving messages)
  const pollAndMark = useCallback(async () => {
    await pollMessages();
    try {
      const res = await api.markMessagesRead();
      updateFloatingBadge(res.unread_count ?? 0);
    } catch { /* ignore */ }
  }, [pollMessages, api]);

  // Poll for new messages
  usePolling({
    callback: pollAndMark,
    interval: config.pollingInterval,
    enabled: !!conversation,
  });

  // Tag therapist handler
  const handleTag = useCallback(
    async (reason?: string, urgency?: TagUrgency) => {
      if (!conversation?.id) return;
      await api.tagTherapist(conversation.id, reason, urgency);
    },
    [api, conversation?.id],
  );

  const labels = config.labels;

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
            <span className={`badge ${conversation.ai_enabled ? 'badge-light' : 'badge-warning'}`}>
              <i className={`fas ${conversation.ai_enabled ? 'fa-robot' : 'fa-user-md'} mr-1`} />
              {conversation.ai_enabled ? labels.mode_ai : labels.mode_human}
            </span>
          )}
        </div>

        {/* Messages area */}
        <div className="card-body p-0 d-flex flex-column" style={{ minHeight: 400 }}>
          <MessageList
            messages={messages}
            isLoading={isLoading}
            isTherapistView={false}
            emptyText={labels.empty_message}
          />

          {isSending && (
            <div className="px-3 pb-2">
              <LoadingIndicator text={labels.ai_thinking} />
            </div>
          )}
        </div>

        {/* Input area */}
        <div className="card-footer bg-white border-top">
          <TaggingPanel
            enabled={config.taggingEnabled}
            reasons={config.tagReasons}
            onTag={handleTag}
            buttonLabel={labels.tag_button_label}
          />
          <MessageInput
            onSend={sendMessage}
            disabled={isSending || isLoading}
            placeholder={labels.placeholder}
            buttonLabel={labels.send_button}
            speechToTextEnabled={config.speechToTextEnabled}
            sectionId={config.sectionId}
          />
        </div>
      </div>
    </div>
  );
};

export default SubjectChat;
