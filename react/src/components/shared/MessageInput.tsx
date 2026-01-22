/**
 * Message Input Component with Mentions Support
 * =============================================
 * 
 * Input area for composing and sending messages.
 * Supports @mentions for therapists and #hashtags for topics.
 */

import React, { useState, useCallback, useRef } from 'react';
import { MentionsInput, Mention, SuggestionDataItem } from 'react-mentions';
import { Button } from 'react-bootstrap';
import type { TherapyChatLabels, TherapistDashboardLabels, TagReason, TagUrgency } from '../../types';
import './MessageInput.css';

interface MessageInputProps {
  onSend: (message: string) => void;
  disabled?: boolean;
  placeholder?: string;
  buttonLabel?: string;
  labels?: TherapyChatLabels | TherapistDashboardLabels;
  tagReasons?: TagReason[];
  onTagTherapist?: (reason?: string, urgency?: TagUrgency) => void;
}

// Default therapist mention data
const therapistMentions: SuggestionDataItem[] = [
  { id: 'therapist', display: 'Therapist' },
];

export const MessageInput: React.FC<MessageInputProps> = ({
  onSend,
  disabled = false,
  placeholder,
  buttonLabel,
  labels,
  tagReasons = [],
  onTagTherapist,
}) => {
  const [message, setMessage] = useState('');
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const labelsTyped = labels as (TherapyChatLabels & TherapistDashboardLabels) | undefined;

  // Support both direct props and labels object
  const defaultPlaceholder = placeholder || labelsTyped?.placeholder || labelsTyped?.sendPlaceholder || 'Type your message... Use @ to tag therapist, # for topics';
  const sendLabel = buttonLabel || labelsTyped?.send_button || labelsTyped?.sendButton || 'Send';

  // Build topic suggestions from tag reasons
  const topicMentions: SuggestionDataItem[] = tagReasons.map(reason => ({
    id: reason.code,
    display: reason.label,
  }));

  /**
   * Handle form submission
   */
  const handleSubmit = useCallback((e: React.FormEvent) => {
    e.preventDefault();
    
    const trimmedMessage = message.trim();
    if (trimmedMessage && !disabled) {
      // Check if message contains @therapist mention
      if (trimmedMessage.includes('@[Therapist](therapist)') && onTagTherapist) {
        // Extract topic if present
        const topicMatch = trimmedMessage.match(/#\[([^\]]+)\]\(([^)]+)\)/);
        if (topicMatch) {
          const topicCode = topicMatch[2];
          const reason = tagReasons.find(r => r.code === topicCode);
          onTagTherapist(topicCode, reason?.urgency || 'normal');
        } else {
          onTagTherapist();
        }
      }
      
      // Convert mentions to readable format before sending
      const readableMessage = message
        .replace(/@\[([^\]]+)\]\([^)]+\)/g, '@$1')
        .replace(/#\[([^\]]+)\]\([^)]+\)/g, '#$1')
        .trim();
      
      onSend(readableMessage);
      setMessage('');
    }
  }, [message, disabled, onSend, onTagTherapist, tagReasons]);

  /**
   * Handle key press (Enter to send)
   */
  const handleKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSubmit(e);
    }
  }, [handleSubmit]);

  // Render the mentions input with all configured mention types
  const renderMentionsInput = () => {
    if (topicMentions.length > 0) {
      return (
        <MentionsInput
          value={message}
          onChange={(e) => setMessage(e.target.value)}
          onKeyDown={handleKeyDown}
          placeholder={defaultPlaceholder}
          disabled={disabled}
          className="therapy-mentions-input"
          inputRef={inputRef}
          a11ySuggestionsListLabel="Suggestions"
          allowSuggestionsAboveCursor
          forceSuggestionsAboveCursor
        >
          <Mention
            trigger="@"
            data={therapistMentions}
            className="therapy-mention-therapist"
            displayTransform={(_id, display) => `@${display}`}
            markup="@[__display__](__id__)"
            appendSpaceOnAdd
          />
          <Mention
            trigger="#"
            data={topicMentions}
            className="therapy-mention-topic"
            displayTransform={(_id, display) => `#${display}`}
            markup="#[__display__](__id__)"
            appendSpaceOnAdd
          />
        </MentionsInput>
      );
    }

    // Only therapist mentions
    return (
      <MentionsInput
        value={message}
        onChange={(e) => setMessage(e.target.value)}
        onKeyDown={handleKeyDown}
        placeholder={defaultPlaceholder}
        disabled={disabled}
        className="therapy-mentions-input"
        inputRef={inputRef}
        a11ySuggestionsListLabel="Suggestions"
        allowSuggestionsAboveCursor
        forceSuggestionsAboveCursor
      >
        <Mention
          trigger="@"
          data={therapistMentions}
          className="therapy-mention-therapist"
          displayTransform={(_id, display) => `@${display}`}
          markup="@[__display__](__id__)"
          appendSpaceOnAdd
        />
      </MentionsInput>
    );
  };

  return (
    <form onSubmit={handleSubmit} className="therapy-message-input">
      <div className="therapy-input-container">
        {renderMentionsInput()}
        
        <Button
          type="submit"
          variant="primary"
          disabled={disabled || !message.trim()}
          title={sendLabel}
          className="therapy-send-button"
        >
          <i className="fas fa-paper-plane"></i>
          <span className="sr-only">{sendLabel}</span>
        </Button>
      </div>
    </form>
  );
};

export default MessageInput;
