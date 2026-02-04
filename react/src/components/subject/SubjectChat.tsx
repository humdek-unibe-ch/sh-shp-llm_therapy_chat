/**
 * Subject Chat Component
 * =======================
 * 
 * Main chat interface for subjects/patients.
 * Features:
 * - Real-time messaging with AI and therapist
 * - @mention tagging for therapist using react-mentions
 * - #topic tagging for therapy reasons
 * - Polling-based message updates
 */

import React, { useEffect, useCallback, useState } from 'react';
import { Container, Card, Alert, Badge } from 'react-bootstrap';
import { MessageList } from '../shared/MessageList';
import { MessageInput, MentionData } from '../shared/MessageInput';
import { LoadingIndicator } from '../shared/LoadingIndicator';
import { TaggingPanel } from '../shared/TaggingPanel';
import { useChatState } from '../../hooks/useChatState';
import { usePolling } from '../../hooks/usePolling';
import { therapyChatApi } from '../../utils/api';
import type { TherapyChatConfig, TagUrgency, TherapistSuggestion } from '../../types';
import './SubjectChat.css';

interface SubjectChatProps {
  config: TherapyChatConfig;
}

export const SubjectChat: React.FC<SubjectChatProps> = ({ config }) => {
  const [therapists, setTherapists] = useState<TherapistSuggestion[]>([]);
  const [therapistsLoaded, setTherapistsLoaded] = useState(false);

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
   * Load therapists for @mention suggestions
   */
  const loadTherapists = useCallback(async (): Promise<TherapistSuggestion[]> => {
    if (therapistsLoaded && therapists.length > 0) {
      return therapists;
    }

    try {
      const response = await therapyChatApi.getTherapists(config.sectionId);
      const loadedTherapists = response.therapists || [];
      setTherapists(loadedTherapists);
      setTherapistsLoaded(true);
      return loadedTherapists;
    } catch (err) {
      console.error('Failed to load therapists:', err);
      return [];
    }
  }, [config.sectionId, therapists, therapistsLoaded]);

  /**
   * Handle tagging therapist (from TaggingPanel)
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
   * Handle tagging therapist from @mention in message
   */
  const handleMentionTag = useCallback(async (reason?: string, urgency?: TagUrgency, therapistId?: number) => {
    if (!conversation?.id) return;

    try {
      const response = await therapyChatApi.tagTherapist(
        config.sectionId,
        conversation.id,
        reason,
        urgency,
        therapistId
      );

      if (response.error) {
        throw new Error(response.error);
      }
      // Don't show alert for @mention tags - the message will be sent
    } catch (err) {
      console.error('Mention tag error:', err);
    }
  }, [config.sectionId, conversation?.id]);

  /**
   * Handle sending message with mentions
   */
  const handleSendMessage = useCallback(async (message: string, mentions?: MentionData) => {
    // Use the existing sendMessage from useChatState
    // The mentions will be processed by the backend
    await sendMessage(message);
    
    // If there are therapist mentions, create tags
    if (mentions?.therapists && mentions.therapists.length > 0) {
      for (const therapist of mentions.therapists) {
        const topicWithUrgency = mentions.topics?.find(t => t.urgency);
        await handleMentionTag(
          topicWithUrgency?.code,
          topicWithUrgency?.urgency as TagUrgency | undefined,
          therapist.id as number
        );
      }
    }
  }, [sendMessage, handleMentionTag]);

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

          {/* Message Input with @mentions, #topics, and speech-to-text support */}
          <MessageInput
            onSend={handleSendMessage}
            disabled={isSending || isLoading}
            labels={config.labels}
            tagReasons={config.tagReasons}
            onTagTherapist={config.taggingEnabled ? handleMentionTag : undefined}
            onLoadTherapists={config.taggingEnabled ? loadTherapists : undefined}
            therapists={therapists.map(t => ({
              id: t.id,
              display: t.name || t.display,
              name: t.name || t.display,
              email: t.email,
            }))}
            speechToTextEnabled={config.speechToTextEnabled}
            speechToTextModel={config.speechToTextModel}
            speechToTextLanguage={config.speechToTextLanguage}
            sectionId={config.sectionId}
          />
        </Card.Footer>
      </Card>
    </Container>
  );
};

export default SubjectChat;
