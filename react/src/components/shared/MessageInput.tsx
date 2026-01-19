/**
 * Message Input Component
 * ========================
 * 
 * Input area for composing and sending messages.
 */

import React, { useState, useCallback, useRef, useEffect } from 'react';
import { Form, Button, InputGroup } from 'react-bootstrap';
import type { TherapyChatLabels, TherapistDashboardLabels } from '../../types';

interface MessageInputProps {
  onSend: (message: string) => void;
  disabled?: boolean;
  placeholder?: string;
  labels: TherapyChatLabels | TherapistDashboardLabels;
}

export const MessageInput: React.FC<MessageInputProps> = ({
  onSend,
  disabled = false,
  placeholder,
  labels,
}) => {
  const [message, setMessage] = useState('');
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const labelsTyped = labels as TherapyChatLabels & TherapistDashboardLabels;

  const defaultPlaceholder = labelsTyped.placeholder || labelsTyped.sendPlaceholder || 'Type your message...';
  const sendLabel = labelsTyped.send_button || labelsTyped.sendButton || 'Send';

  /**
   * Handle form submission
   */
  const handleSubmit = useCallback((e: React.FormEvent) => {
    e.preventDefault();
    
    const trimmedMessage = message.trim();
    if (trimmedMessage && !disabled) {
      onSend(trimmedMessage);
      setMessage('');
      
      // Reset textarea height
      if (textareaRef.current) {
        textareaRef.current.style.height = 'auto';
      }
    }
  }, [message, disabled, onSend]);

  /**
   * Handle key press (Enter to send)
   */
  const handleKeyPress = useCallback((e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSubmit(e);
    }
  }, [handleSubmit]);

  /**
   * Auto-resize textarea
   */
  const handleInput = useCallback(() => {
    if (textareaRef.current) {
      textareaRef.current.style.height = 'auto';
      const newHeight = Math.min(textareaRef.current.scrollHeight, 150);
      textareaRef.current.style.height = `${newHeight}px`;
    }
  }, []);

  /**
   * Focus textarea on mount
   */
  useEffect(() => {
    if (textareaRef.current && !disabled) {
      textareaRef.current.focus();
    }
  }, [disabled]);

  return (
    <Form onSubmit={handleSubmit} className="therapy-message-input">
      <InputGroup>
        <Form.Control
          as="textarea"
          ref={textareaRef}
          value={message}
          onChange={(e) => setMessage(e.target.value)}
          onKeyPress={handleKeyPress}
          onInput={handleInput}
          placeholder={placeholder || defaultPlaceholder}
          disabled={disabled}
          rows={1}
          className="therapy-input-textarea"
          style={{
            resize: 'none',
            overflow: 'hidden',
            borderRadius: '1.5rem 0 0 1.5rem',
            paddingLeft: '1rem',
          }}
        />
        <InputGroup.Append>
          <Button
            type="submit"
            variant="primary"
            disabled={disabled || !message.trim()}
            title={sendLabel}
            style={{
              borderRadius: '0 1.5rem 1.5rem 0',
              paddingLeft: '1rem',
              paddingRight: '1rem',
            }}
          >
            <i className="fas fa-paper-plane"></i>
          </Button>
        </InputGroup.Append>
      </InputGroup>
    </Form>
  );
};

export default MessageInput;
