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
 *   - Blocks sending when conversation is paused
 */

import React, { useEffect, useCallback, useMemo, useRef } from 'react';
import { MessageList } from '../shared/MessageList';
import { MessageInput } from '../shared/MessageInput';
import { LoadingIndicator } from '../shared/LoadingIndicator';
import { TaggingPanel } from '../shared/TaggingPanel';
import { useChatState } from '../../hooks/useChatState';
import { usePolling } from '../../hooks/usePolling';
import { createSubjectApi } from '../../utils/api';
import type { SubjectChatConfig } from '../../types';

interface SubjectChatProps {
  config: SubjectChatConfig;
}

/**
 * Update (or hide) the server-rendered floating chat badge in the DOM.
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

  // Is the conversation paused by the therapist?
  const isPaused = conversation?.status === 'paused';

  // Load conversation ONCE on mount + mark messages as read + clear badge
  useEffect(() => {
    if (loadedRef.current) return;
    loadedRef.current = true;

    (async () => {
      await loadConversation(config.conversationId ?? undefined);
      try {
        const res = await api.markMessagesRead(config.conversationId ?? undefined);
        updateFloatingBadge(res.unread_count ?? 0);
      } catch { /* non-critical */ }
    })();
  }, []);  // eslint-disable-line react-hooks/exhaustive-deps

  // Lightweight polling: quick check, only full fetch if new data
  const lastKnownMsgIdRef = useRef<number | null>(null);

  const pollAndMark = useCallback(async () => {
    try {
      const updates = await api.checkUpdates();
      if (updates.latest_message_id === lastKnownMsgIdRef.current) return; // nothing new
      lastKnownMsgIdRef.current = updates.latest_message_id;

      // New messages detected — do the full fetch
      await pollMessages();
      const res = await api.markMessagesRead();
      updateFloatingBadge(res.unread_count ?? 0);
    } catch { /* polling errors are non-fatal */ }
  }, [pollMessages, api]);

  usePolling({
    callback: pollAndMark,
    interval: config.pollingInterval,
    enabled: !!conversation,
  });

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
            <div className="d-flex align-items-center" style={{ gap: '0.5rem' }}>
              {isPaused && (
                <span className="badge badge-warning">
                  <i className="fas fa-pause-circle mr-1" />
                  Paused
                </span>
              )}
              <span className={`badge ${conversation.ai_enabled && !isPaused ? 'badge-light' : 'badge-warning'}`}>
                <i className={`fas ${conversation.ai_enabled && !isPaused ? 'fa-robot' : 'fa-user-md'} mr-1`} />
                {conversation.ai_enabled && !isPaused ? labels.mode_ai : labels.mode_human}
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
          {isPaused ? (
            <div className="text-center text-muted py-2">
              <i className="fas fa-pause-circle mr-1" />
              This conversation is currently paused by your therapist. You will be notified when it resumes.
            </div>
          ) : (
            <>
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
              />
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default SubjectChat;
