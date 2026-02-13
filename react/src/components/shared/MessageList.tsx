/**
 * MessageList Component
 * ======================
 *
 * Renders chat messages with clear visual distinction between sender types:
 *   - Subject (patient): left-aligned, light blue bubble
 *   - Therapist: left-aligned, green bubble with left border
 *   - AI: left-aligned, white bubble with subtle border
 *   - System: centered, yellow banner
 *   - Own messages: right-aligned, primary blue bubble
 *
 * Supports:
 *   - Edited message indicator
 *   - Soft-deleted message placeholder
 *   - Markdown rendering for AI messages
 *   - Auto-scroll to newest message
 */

import React, { useRef, useEffect } from 'react';
import type { Message, SenderType } from '../../types';
import { MarkdownRenderer } from './MarkdownRenderer';

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

interface MessageListProps {
  messages: Message[];
  isLoading?: boolean;
  isTherapistView?: boolean;
  emptyText?: string;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function formatTime(ts: string): string {
  try {
    const d = new Date(ts);
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    return `${hh}:${mm}`;
  } catch {
    return '';
  }
}

function senderLabel(msg: Message, isTherapistView: boolean): string {
  if (msg.label) return msg.label;
  const st = msg.sender_type;
  if (st === 'ai') return 'AI Assistant';
  if (st === 'system') return 'System';
  if (st === 'therapist') return isTherapistView ? 'You' : msg.sender_name || 'Therapist';
  if (st === 'subject') return isTherapistView ? msg.sender_name || 'Patient' : 'You';
  // Legacy role-based fallback
  return msg.role === 'assistant' ? 'AI Assistant' : 'You';
}

function isOwnMessage(msg: Message, isTherapistView: boolean): boolean {
  if (isTherapistView) return msg.sender_type === 'therapist';
  return msg.sender_type === 'subject' || (msg.role === 'user' && !msg.sender_type);
}

function senderIcon(st?: SenderType): string {
  if (st === 'ai') return 'fas fa-robot';
  if (st === 'therapist') return 'fas fa-user-md';
  if (st === 'system') return 'fas fa-info-circle';
  return 'fas fa-user';
}

function bubbleClass(msg: Message, isTherapistView: boolean): string {
  if (msg.sender_type === 'system') return 'tc-msg tc-msg--system';
  if (isOwnMessage(msg, isTherapistView)) return 'tc-msg tc-msg--self';
  if (msg.sender_type === 'ai' || msg.role === 'assistant') return 'tc-msg tc-msg--ai';
  if (msg.sender_type === 'therapist') return 'tc-msg tc-msg--therapist';
  if (msg.sender_type === 'subject') return 'tc-msg tc-msg--subject';
  return 'tc-msg tc-msg--ai';
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export const MessageList: React.FC<MessageListProps> = ({
  messages,
  isLoading = false,
  isTherapistView = false,
  emptyText = 'No messages yet. Start the conversation!',
}) => {
  const endRef = useRef<HTMLDivElement>(null);

  // Auto-scroll on new messages
  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages.length]);

  if (isLoading && messages.length === 0) {
    return (
      <div className="tc-msg-list d-flex align-items-center justify-content-center">
        <div className="text-center text-muted">
          <div className="spinner-border spinner-border-sm mb-2" role="status" />
          <p className="mb-0">Loading messages...</p>
        </div>
      </div>
    );
  }

  if (messages.length === 0) {
    return (
      <div className="tc-msg-list d-flex align-items-center justify-content-center">
        <div className="text-center text-muted p-4">
          <i className="fas fa-comments fa-3x mb-3 d-block" style={{ opacity: 0.3 }} />
          <p className="mb-0">{emptyText}</p>
        </div>
      </div>
    );
  }

  return (
    <div className="tc-msg-list">
      {messages.map((msg) => {
        // Soft-deleted messages
        if (msg.is_deleted) {
          return (
            <div key={msg.id} className="tc-msg tc-msg--deleted text-center">
              <small className="text-muted font-italic">
                <i className="fas fa-ban mr-1" />
                This message was removed.
              </small>
            </div>
          );
        }

        const own = isOwnMessage(msg, isTherapistView);

        return (
          <div key={msg.id} className={bubbleClass(msg, isTherapistView)}>
            {/* Header: sender + time */}
            <div className="tc-msg__header d-flex">
              {!own && (
                <span className="tc-msg__icon mr-1">
                  <i className={senderIcon(msg.sender_type)} />
                </span>
              )}
              <span className="tc-msg__sender">{senderLabel(msg, isTherapistView)}</span>
              <span className="tc-msg__time ml-auto">{formatTime(msg.timestamp)}</span>
            </div>

            {/* Content */}
            <div className="tc-msg__body">
              {msg.sender_type === 'ai' || msg.role === 'assistant' || msg.sender_type === 'system' ? (
                <MarkdownRenderer content={msg.content} />
              ) : (
                <span style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{msg.content}</span>
              )}
            </div>

            {/* Edited indicator */}
            {msg.is_edited && (
              <div className="tc-msg__edited">
                <small className="text-muted font-italic">
                  <i className="fas fa-pen mr-1" style={{ fontSize: '0.6rem' }} />
                  edited{msg.edited_at ? ` ${formatTime(msg.edited_at)}` : ''}
                </small>
              </div>
            )}
          </div>
        );
      })}
      <div ref={endRef} />
    </div>
  );
};

export default MessageList;
