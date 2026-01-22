/**
 * Message Input Component
 * =======================
 * 
 * Input area for composing and sending messages.
 * Uses a simple textarea for reliable form submission.
 * Supports @therapist mention detection for tagging.
 */

import React, { useState, useCallback, useRef, useEffect } from 'react';
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

export const MessageInput: React.FC<MessageInputProps> = ({
  onSend,
  disabled = false,
  placeholder,
  buttonLabel,
  labels,
  tagReasons: _tagReasons = [],
  onTagTherapist,
}) => {
  const [message, setMessage] = useState('');
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const labelsTyped = labels as (TherapyChatLabels & TherapistDashboardLabels) | undefined;

  // Support both direct props and labels object
  const defaultPlaceholder = placeholder || labelsTyped?.placeholder || labelsTyped?.sendPlaceholder || 'Type your message...';
  const sendLabel = buttonLabel || labelsTyped?.send_button || labelsTyped?.sendButton || 'Send';

  /**
   * Auto-resize textarea
   */
  const adjustTextareaHeight = useCallback(() => {
    const textarea = textareaRef.current;
    if (textarea) {
      textarea.style.height = 'auto';
      const newHeight = Math.min(Math.max(textarea.scrollHeight, 44), 120);
      textarea.style.height = `${newHeight}px`;
    }
  }, []);

  /**
   * Handle form submission
   */
  const handleSubmit = useCallback((e: React.FormEvent) => {
    e.preventDefault();
    
    const trimmedMessage = message.trim();
    if (trimmedMessage && !disabled) {
      // Check if message contains @therapist mention
      if (trimmedMessage.toLowerCase().includes('@therapist') && onTagTherapist) {
        onTagTherapist();
      }
      
      onSend(trimmedMessage);
      setMessage('');
      
      // Reset textarea height
      if (textareaRef.current) {
        textareaRef.current.style.height = 'auto';
      }
    }
  }, [message, disabled, onSend, onTagTherapist]);

  /**
   * Handle key press (Enter to send, Shift+Enter for new line)
   */
  const handleKeyDown = useCallback((e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSubmit(e as unknown as React.FormEvent);
    }
  }, [handleSubmit]);

  /**
   * Handle input change
   */
  const handleInputChange = useCallback((e: React.ChangeEvent<HTMLTextAreaElement>) => {
    setMessage(e.target.value);
    adjustTextareaHeight();
  }, [adjustTextareaHeight]);

  // Adjust height when message changes externally
  useEffect(() => {
    adjustTextareaHeight();
  }, [message, adjustTextareaHeight]);

  return (
    <form onSubmit={handleSubmit} className="therapy-message-input">
      <div className="therapy-input-container d-flex align-items-end">
        <div className="flex-grow-1 mr-2">
          <textarea
            ref={textareaRef}
            value={message}
            onChange={handleInputChange}
            onKeyDown={handleKeyDown}
            placeholder={defaultPlaceholder}
            disabled={disabled}
            rows={1}
            className="form-control therapy-textarea border-0"
            style={{ resize: 'none', minHeight: '44px', maxHeight: '120px' }}
          />
        </div>
        
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
