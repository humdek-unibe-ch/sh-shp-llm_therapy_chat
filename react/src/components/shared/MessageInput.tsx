/**
 * Message Input Component
 * =======================
 * 
 * Input area for composing and sending messages.
 * Supports @therapist mentions and #topic tagging using react-mentions.
 * Built with Bootstrap 4.6 styling.
 */

import React, { useState, useCallback, useRef, useEffect } from 'react';
import { Button } from 'react-bootstrap';
import { MentionsInput, Mention, SuggestionDataItem } from 'react-mentions';
import type { TherapyChatLabels, TherapistDashboardLabels, TagReason, TagUrgency } from '../../types';
import './MessageInput.css';

// Types for therapist and topic suggestions
interface TherapistSuggestion extends SuggestionDataItem {
  id: string | number;
  display: string;
  email?: string;
  name?: string;
}

interface TopicSuggestion extends SuggestionDataItem {
  id: string | number;
  display: string;
  code?: string;
  urgency?: string;
}

interface MessageInputProps {
  onSend: (message: string, mentions?: MentionData) => void;
  disabled?: boolean;
  placeholder?: string;
  buttonLabel?: string;
  labels?: TherapyChatLabels | TherapistDashboardLabels;
  tagReasons?: TagReason[];
  onTagTherapist?: (reason?: string, urgency?: TagUrgency, therapistId?: number) => void;
  therapists?: TherapistSuggestion[];
  onLoadTherapists?: () => Promise<TherapistSuggestion[]>;
  maxLength?: number;
}

export interface MentionData {
  therapists: Array<{ id: string | number; display: string }>;
  topics: Array<{ id: string | number; display: string; code?: string; urgency?: string }>;
}

// Mention style configuration for react-mentions
const mentionStyle = {
  control: {
    fontSize: '1rem',
    lineHeight: '1.5',
    minHeight: '38px',
  },
  input: {
    margin: 0,
    padding: '0.375rem 0',
    border: 'none',
    outline: 'none',
  },
  highlighter: {
    padding: '0.375rem 0',
    border: 'none',
  },
  suggestions: {
    list: {
      backgroundColor: '#ffffff',
      border: '1px solid rgba(0, 0, 0, 0.15)',
      borderRadius: '0.25rem',
      boxShadow: '0 0.5rem 1rem rgba(0, 0, 0, 0.175)',
      fontSize: '0.875rem',
      maxHeight: '250px',
      overflowY: 'auto',
    },
    item: {
      padding: '0.5rem 1rem',
      '&focused': {
        backgroundColor: '#f8f9fa',
      },
    },
  },
};

export const MessageInput: React.FC<MessageInputProps> = ({
  onSend,
  disabled = false,
  placeholder,
  buttonLabel,
  labels,
  tagReasons = [],
  onTagTherapist,
  therapists: externalTherapists = [],
  onLoadTherapists,
  maxLength = 4000,
}) => {
  const [message, setMessage] = useState('');
  const [plainText, setPlainText] = useState('');
  const [therapistSuggestions, setTherapistSuggestions] = useState<TherapistSuggestion[]>(externalTherapists);
  const [isLoadingTherapists, setIsLoadingTherapists] = useState(false);
  const [mentions, setMentions] = useState<MentionData>({ therapists: [], topics: [] });
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const labelsTyped = labels as (TherapyChatLabels & TherapistDashboardLabels) | undefined;

  // Support both direct props and labels object
  const defaultPlaceholder = placeholder || labelsTyped?.placeholder || labelsTyped?.sendPlaceholder || 'Type your message...';
  const sendLabel = buttonLabel || labelsTyped?.send_button || labelsTyped?.sendButton || 'Send';

  // Convert tag reasons to topic suggestions
  const topicSuggestions: TopicSuggestion[] = tagReasons.map(reason => ({
    id: reason.code,
    display: reason.label,
    code: reason.code,
    urgency: reason.urgency,
  }));

  /**
   * Load therapists when @ is triggered
   */
  const loadTherapists = useCallback(async (query: string, callback: (data: TherapistSuggestion[]) => void) => {
    // If we have external therapists or already loaded, filter them
    if (therapistSuggestions.length > 0 || externalTherapists.length > 0) {
      const source = therapistSuggestions.length > 0 ? therapistSuggestions : externalTherapists;
      const filtered = source.filter(t => 
        t.display.toLowerCase().includes(query.toLowerCase()) ||
        (t.email && t.email.toLowerCase().includes(query.toLowerCase()))
      );
      callback(filtered);
      return;
    }

    // Load therapists from API if callback provided
    if (onLoadTherapists && !isLoadingTherapists) {
      setIsLoadingTherapists(true);
      try {
        const loaded = await onLoadTherapists();
        setTherapistSuggestions(loaded);
        const filtered = loaded.filter(t => 
          t.display.toLowerCase().includes(query.toLowerCase()) ||
          (t.email && t.email.toLowerCase().includes(query.toLowerCase()))
        );
        callback(filtered);
      } catch (err) {
        console.error('Failed to load therapists:', err);
        callback([]);
      } finally {
        setIsLoadingTherapists(false);
      }
    } else {
      callback([]);
    }
  }, [therapistSuggestions, externalTherapists, onLoadTherapists, isLoadingTherapists]);

  /**
   * Filter topics/tag reasons
   */
  const loadTopics = useCallback((query: string, callback: (data: TopicSuggestion[]) => void) => {
    const filtered = topicSuggestions.filter(t =>
      t.display.toLowerCase().includes(query.toLowerCase()) ||
      (t.code && t.code.toLowerCase().includes(query.toLowerCase()))
    );
    callback(filtered);
  }, [topicSuggestions]);

  /**
   * Handle message change with mention tracking
   */
  const handleChange = useCallback((
    _event: { target: { value: string } },
    newValue: string,
    newPlainTextValue: string,
    mentionsList: Array<{ id: string | number; display: string; type?: string | null }>
  ) => {
    setMessage(newValue);
    setPlainText(newPlainTextValue);

    // Track mentions by type
    const therapistMentions = mentionsList
      .filter(m => m.type === 'therapist' || !m.type)
      .map(m => ({ id: m.id, display: m.display }));
    
    const topicMentions = mentionsList
      .filter(m => m.type === 'topic')
      .map(m => {
        const topic = topicSuggestions.find(t => t.id === m.id);
        return {
          id: m.id,
          display: m.display,
          code: topic?.code,
          urgency: topic?.urgency,
        };
      });

    setMentions({ therapists: therapistMentions, topics: topicMentions });
  }, [topicSuggestions]);

  /**
   * Handle form submission
   */
  const handleSubmit = useCallback((e: React.FormEvent) => {
    e.preventDefault();
    
    const trimmedMessage = plainText.trim();
    if (trimmedMessage && !disabled && trimmedMessage.length <= maxLength) {
      // Check if there are therapist mentions - trigger tag callback
      if (mentions.therapists.length > 0 && onTagTherapist) {
        const topicWithUrgency = mentions.topics.find(t => t.urgency);
        onTagTherapist(
          topicWithUrgency?.code,
          topicWithUrgency?.urgency as TagUrgency | undefined,
          mentions.therapists[0]?.id as number
        );
      }
      
      onSend(trimmedMessage, mentions);
      setMessage('');
      setPlainText('');
      setMentions({ therapists: [], topics: [] });
    }
  }, [plainText, disabled, maxLength, mentions, onSend, onTagTherapist]);

  /**
   * Handle key press (Enter to send, Shift+Enter for new line)
   */
  const handleKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSubmit(e as unknown as React.FormEvent);
    }
  }, [handleSubmit]);

  /**
   * Clear message
   */
  const handleClear = useCallback(() => {
    setMessage('');
    setPlainText('');
    setMentions({ therapists: [], topics: [] });
  }, []);

  /**
   * Update therapist suggestions when external prop changes
   */
  useEffect(() => {
    if (externalTherapists.length > 0) {
      setTherapistSuggestions(externalTherapists);
    }
  }, [externalTherapists]);

  // Character count styling
  const charCountClass = plainText.length > maxLength * 0.9 
    ? (plainText.length > maxLength ? 'text-danger' : 'text-warning')
    : '';

  // Render therapist suggestion item
  const renderTherapistSuggestion = (
    suggestion: SuggestionDataItem,
    _search: string,
    highlightedDisplay: React.ReactNode,
    _index: number,
    focused: boolean
  ) => (
    <div className={`therapy-suggestion-item ${focused ? 'focused' : ''}`}>
      <div className="suggestion-icon therapist">
        <i className="fas fa-user-md"></i>
      </div>
      <div className="suggestion-content">
        <div className="suggestion-name">{highlightedDisplay}</div>
        {(suggestion as TherapistSuggestion).email && (
          <div className="suggestion-meta">{(suggestion as TherapistSuggestion).email}</div>
        )}
      </div>
    </div>
  );

  // Render topic suggestion item
  const renderTopicSuggestion = (
    suggestion: SuggestionDataItem,
    _search: string,
    highlightedDisplay: React.ReactNode,
    _index: number,
    focused: boolean
  ) => {
    const topic = suggestion as TopicSuggestion;
    const urgencyBadge = topic.urgency === 'emergency' 
      ? 'badge-danger'
      : topic.urgency === 'urgent'
        ? 'badge-warning'
        : 'badge-secondary';

    return (
      <div className={`therapy-suggestion-item ${focused ? 'focused' : ''}`}>
        <div className="suggestion-icon topic">
          <i className="fas fa-hashtag"></i>
        </div>
        <div className="suggestion-content">
          <div className="suggestion-name">{highlightedDisplay}</div>
          {topic.urgency && (
            <span className={`badge ${urgencyBadge} ml-1`} style={{ fontSize: '0.65rem' }}>
              {topic.urgency}
            </span>
          )}
        </div>
      </div>
    );
  };

  return (
    <form onSubmit={handleSubmit} className="therapy-message-input">
      <div className="therapy-input-container">
        <div className="therapy-input-wrapper">
          <div className="therapy-input-area">
            <MentionsInput
              value={message}
              onChange={handleChange}
              onKeyDown={handleKeyDown}
              placeholder={defaultPlaceholder}
              disabled={disabled}
              className="therapy-mentions-input"
              style={mentionStyle as any}
              inputRef={inputRef}
              allowSpaceInQuery
              a11ySuggestionsListLabel="Suggestions"
            >
              {/* Therapist mentions with @ trigger */}
              {/* @ts-ignore */}
              <Mention
                trigger="@"
                data={loadTherapists}
                markup="@[__display__](__id__)"
                displayTransform={(_id, display) => `@${display}`}
                className="therapy-mention-therapist"
                renderSuggestion={renderTherapistSuggestion}
                appendSpaceOnAdd
              />
              {/* Topic/reason mentions with # trigger */}
              {/* @ts-ignore */}
              <Mention
                trigger="#"
                data={loadTopics}
                markup="#[__display__](__id__)"
                displayTransform={(_id, display) => `#${display}`}
                className="therapy-mention-topic"
                renderSuggestion={renderTopicSuggestion}
                appendSpaceOnAdd
              />
            </MentionsInput>
          </div>
          
          {/* Character counter - like reference design */}
          <div className="therapy-input-footer">
            <small className={`therapy-char-counter ${charCountClass}`}>
              {plainText.length}/{maxLength}
            </small>
          </div>
        </div>
        
        {/* Clear button - only show when there's content */}
        {plainText.length > 0 && (
          <Button
            type="button"
            variant="outline-secondary"
            onClick={handleClear}
            className="therapy-clear-button"
            title="Clear"
          >
            <i className="fas fa-times"></i>
          </Button>
        )}
        
        {/* Send button */}
        <Button
          type="submit"
          variant="primary"
          disabled={disabled || !plainText.trim() || plainText.length > maxLength}
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
