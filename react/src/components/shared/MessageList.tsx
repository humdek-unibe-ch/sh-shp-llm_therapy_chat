/**
 * Message List Component
 * ======================
 * 
 * Renders a list of chat messages with proper styling for different sender types.
 */

import React, { useRef, useEffect } from 'react';
import type { Message, TherapyChatLabels, TherapistDashboardLabels } from '../../types';
import './MessageList.css';

interface MessageListProps {
  messages: Message[];
  isLoading?: boolean;
  labels: TherapyChatLabels | TherapistDashboardLabels;
  isTherapistView?: boolean;
}

/**
 * Format timestamp for display
 */
function formatTime(timestamp: string): string {
  try {
    const date = new Date(timestamp);
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  } catch {
    return '';
  }
}

/**
 * Get sender label - Consistent naming for each sender type
 */
function getSenderLabel(message: Message, labels: TherapyChatLabels | TherapistDashboardLabels, isTherapistView: boolean): string {
  const labelsTyped = labels as TherapyChatLabels & TherapistDashboardLabels;
  
  // Use explicit label if provided
  if (message.label) {
    return message.label;
  }

  switch (message.sender_type) {
    case 'ai':
      return labelsTyped.ai_label || labelsTyped.aiLabel || 'AI Assistant';
    case 'therapist':
      // In therapist view, show "You" for therapist messages
      if (isTherapistView) {
        return 'You';
      }
      return labelsTyped.therapist_label || labelsTyped.therapistLabel || 'Therapist';
    case 'subject':
      // In subject view, show "You" for subject messages
      if (!isTherapistView) {
        return 'You';
      }
      return message.sender_name || labelsTyped.subjectLabel || 'Patient';
    case 'system':
      return 'System';
    default:
      // Handle legacy role-based messages
      if (message.role === 'assistant') {
        return labelsTyped.ai_label || labelsTyped.aiLabel || 'AI Assistant';
      }
      // user role - show "You" consistently
      return 'You';
  }
}

/**
 * Get message CSS class
 * Note: For own messages, we use therapy-message-self which handles alignment (flex-end)
 * For other people's messages, we use type-specific classes (ai, therapist, subject)
 */
function getMessageClass(message: Message, isTherapistView: boolean): string {
  const classes = ['therapy-message'];

  // Determine if this is the current user's own message
  const isOwnMessage = isTherapistView 
    ? message.sender_type === 'therapist' 
    : message.sender_type === 'subject' || (message.role === 'user' && !message.sender_type);

  switch (message.sender_type) {
    case 'ai':
      classes.push('therapy-message-ai');
      break;
    case 'therapist':
      if (isOwnMessage) {
        // Own message - right aligned, blue
        classes.push('therapy-message-self');
      } else {
        // Therapist message in subject view - left aligned, green
        classes.push('therapy-message-therapist');
      }
      break;
    case 'subject':
      if (isOwnMessage) {
        // Own message - right aligned, blue
        classes.push('therapy-message-self');
      } else {
        // Subject message in therapist view - left aligned, light blue
        classes.push('therapy-message-subject');
      }
      break;
    case 'system':
      classes.push('therapy-message-system');
      break;
    default:
      // Handle legacy role-based messages
      if (message.role === 'assistant') {
        classes.push('therapy-message-ai');
      } else if (message.role === 'user') {
        // User's own message
        classes.push('therapy-message-self');
      }
  }

  return classes.join(' ');
}

export const MessageList: React.FC<MessageListProps> = ({
  messages,
  isLoading = false,
  labels,
  isTherapistView = false,
}) => {
  const containerRef = useRef<HTMLDivElement>(null);
  const labelsTyped = labels as TherapyChatLabels & TherapistDashboardLabels;

  // Auto-scroll to bottom when new messages arrive
  useEffect(() => {
    if (containerRef.current) {
      containerRef.current.scrollTop = containerRef.current.scrollHeight;
    }
  }, [messages]);

  if (isLoading && messages.length === 0) {
    return (
      <div className="therapy-message-list d-flex align-items-center justify-content-center">
        <div className="text-center text-muted">
          <div className="spinner-border spinner-border-sm mb-2" role="status">
            <span className="sr-only">Loading...</span>
          </div>
          <p className="mb-0">{labelsTyped.loading || 'Loading...'}</p>
        </div>
      </div>
    );
  }

  if (messages.length === 0) {
    return (
      <div className="therapy-message-list">
        <div className="therapy-message-list-empty">
          <i className="fas fa-comments"></i>
          <p>{labelsTyped.empty_message || 'No messages yet. Start the conversation!'}</p>
        </div>
      </div>
    );
  }

  return (
    <div className="therapy-message-list" ref={containerRef}>
      {messages.map((message) => (
        <div key={message.id} className={getMessageClass(message, isTherapistView)}>
          <div className="therapy-message-header">
            <span className="therapy-message-sender">
              {getSenderLabel(message, labels, isTherapistView)}
            </span>
            <span className="therapy-message-time">
              {formatTime(message.timestamp)}
            </span>
          </div>
          <div className="therapy-message-content">
            {message.content}
          </div>
          {message.tags && message.tags.length > 0 && (
            <div className="therapy-message-tags">
              <span className="badge badge-warning">
                <i className="fas fa-at mr-1"></i>
                Tagged
              </span>
            </div>
          )}
        </div>
      ))}
    </div>
  );
};

export default MessageList;
