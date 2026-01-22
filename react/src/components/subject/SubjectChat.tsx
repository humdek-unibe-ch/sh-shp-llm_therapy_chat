/**
 * Subject Chat Component
 * =======================
 * 
 * Main chat interface for subjects/patients.
 * Features:
 * - Real-time messaging with AI and therapist
 * - @mention tagging for therapist
 * - Polling-based message updates
 */

import React, { useEffect, useCallback } from 'react';
import { Container, Card, Alert, Badge } from 'react-bootstrap';
import { MessageList } from '../shared/MessageList';
import { MessageInput } from '../shared/MessageInput';
import { LoadingIndicator } from '../shared/LoadingIndicator';
import { TaggingPanel } from '../shared/TaggingPanel';
import { useChatState } from '../../hooks/useChatState';
import { usePolling } from '../../hooks/usePolling';
import { therapyChatApi } from '../../utils/api';
import type { TherapyChatConfig, TagUrgency } from '../../types';
import './SubjectChat.css';

interface SubjectChatProps {
  config: TherapyChatConfig;
}

export const SubjectChat: React.FC<SubjectChatProps> = ({ config }) => {
  const {
    conversation,
    messages,
    isLoading,
    isSending,
    error,
    loadConversation,
    sendMessage,
    clearError,
    pollMessages,
  } = useChatState({ config, isTherapist: false });

  /**
   * Load conversation on mount
   */
  useEffect(() => {
    loadConversation(config.conversationId ?? undefined);
  }, [loadConversation, config.conversationId]);

  /**
   * Set up polling for new messages
   */
  usePolling({
    callback: async () => {
      await pollMessages();
    },
    interval: config.pollingInterval,
    enabled: !!conversation,
    immediate: false,
  });

  /**
   * Handle tagging therapist
   */
  const handleTag = useCallback(async (reason?: string, urgency?: TagUrgency) => {
    if (!conversation?.id) return;

    try {
      const response = await therapyChatApi.tagTherapist(
        config.sectionId,
        conversation.id,
        reason,
        urgency
      );

      if (response.error) {
        throw new Error(response.error);
      }

      // Show success message
      alert('Your therapist has been notified.');
    } catch (err) {
      console.error('Tag error:', err);
      throw err;
    }
  }, [config.sectionId, conversation?.id]);

  /**
   * Get mode badge
   */
  const getModeBadge = () => {
    if (!conversation) return null;
    
    if (conversation.ai_enabled && conversation.mode === 'ai_hybrid') {
      return (
        <Badge variant="info">
          <i className="fas fa-robot mr-1"></i>
          {config.labels.mode_ai}
        </Badge>
      );
    }
    
    return (
      <Badge variant="secondary">
        <i className="fas fa-user-md mr-1"></i>
        {config.labels.mode_human}
      </Badge>
    );
  };

  return (
    <Container fluid className="therapy-chat-container p-0">
      {/* Error Alert */}
      {error && (
        <Alert 
          variant="danger" 
          dismissible 
          onClose={clearError}
          className="m-3 mb-0"
        >
          <i className="fas fa-exclamation-circle mr-2"></i>
          {error}
        </Alert>
      )}

      <Card className="therapy-chat-card h-100 border-0 shadow-sm">
        {/* Header */}
        <Card.Header className="therapy-chat-header bg-primary text-white d-flex justify-content-between align-items-center">
          <div className="d-flex align-items-center">
            <div className="therapy-chat-icon mr-3">
              <i className="fas fa-comments"></i>
            </div>
            <h5 className="mb-0">Therapy Chat</h5>
          </div>
          {getModeBadge()}
        </Card.Header>

        {/* Messages */}
        <Card.Body className="therapy-chat-body p-0 d-flex flex-column">
          <MessageList
            messages={messages}
            isLoading={isLoading}
            labels={config.labels}
            isTherapistView={false}
          />

          {/* Processing Indicator */}
          {isSending && (
            <div className="px-3 pb-2">
              <LoadingIndicator text={config.labels.ai_thinking} />
            </div>
          )}
        </Card.Body>

        {/* Input Area */}
        <Card.Footer className="therapy-chat-footer bg-white border-top">
          {/* Tagging Panel - quick access buttons */}
          <TaggingPanel
            enabled={config.taggingEnabled}
            reasons={config.tagReasons}
            onTag={handleTag}
            buttonLabel={config.labels.tag_button_label}
          />

          {/* Message Input with @mentions and #topics support */}
          <MessageInput
            onSend={sendMessage}
            disabled={isSending || isLoading}
            labels={config.labels}
            tagReasons={config.tagReasons}
            onTagTherapist={config.taggingEnabled ? (reason, urgency) => handleTag(reason, urgency) : undefined}
          />
        </Card.Footer>
      </Card>
    </Container>
  );
};

export default SubjectChat;
