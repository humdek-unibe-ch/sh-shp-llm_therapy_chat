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

import React, { useRef, useEffect, useMemo } from 'react';
import type { Message, SenderType, TherapyChatColors, ChatColorEntry } from '../../types';
import { MarkdownRenderer } from './MarkdownRenderer';

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

interface MessageListProps {
  messages: Message[];
  isLoading?: boolean;
  isTherapistView?: boolean;
  currentUserId?: number;
  chatColors?: TherapyChatColors;
  emptyText?: string;
  senderLabels?: {
    ai?: string;
    therapist?: string;
    subject?: string;
    system?: string;
    you?: string;
  };
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

function formatFullTimestamp(ts: string): string {
  try {
    const d = new Date(ts);
    const day = String(d.getDate()).padStart(2, '0');
    const mon = String(d.getMonth() + 1).padStart(2, '0');
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    return `${day}.${mon} ${hh}:${mm}`;
  } catch {
    return '';
  }
}

/**
 * Build a stable sender_id → therapist_N index map.
 * The first therapist (by message order) gets therapist_1, the second therapist_2, etc.
 * The current user's own therapist ID is excluded (they get me_as_therapist).
 */
function buildTherapistIndexMap(messages: Message[], currentUserId?: number): Map<number, number> {
  const map = new Map<number, number>();
  let idx = 1;
  for (const msg of messages) {
    if (msg.sender_type !== 'therapist' || !msg.sender_id) continue;
    if (currentUserId && msg.sender_id === currentUserId) continue;
    if (map.has(msg.sender_id)) continue;
    map.set(msg.sender_id, idx);
    idx++;
    if (idx > 10) idx = 1;
  }
  return map;
}

function getColorForMessage(
  msg: Message,
  isTherapistView: boolean,
  currentUserId: number | undefined,
  chatColors: TherapyChatColors | undefined,
  therapistMap: Map<number, number>,
): React.CSSProperties | undefined {
  if (!chatColors) return undefined;

  let entry: ChatColorEntry | undefined;
  const own = isOwnMessage(msg, isTherapistView, currentUserId);

  if (own) {
    entry = isTherapistView ? chatColors.me_as_therapist : chatColors.me_as_patient;
  } else if (msg.sender_type === 'ai' || msg.role === 'assistant') {
    entry = chatColors.ai;
  } else if (msg.sender_type === 'system') {
    return undefined;
  } else if (msg.sender_type === 'subject') {
    entry = isTherapistView ? chatColors.patient : chatColors.me_as_patient;
  } else if (msg.sender_type === 'therapist') {
    const tIdx = msg.sender_id ? therapistMap.get(msg.sender_id) : 1;
    const key = `therapist_${tIdx || 1}` as keyof TherapyChatColors;
    entry = chatColors[key] || chatColors.therapist_1;
  }

  if (!entry || !entry.bg || !entry.text || !entry.border) return undefined;
  return {
    backgroundColor: entry.bg,
    color: entry.text,
    borderLeft: `3px solid ${entry.border}`,
  };
}

function senderLabel(
  msg: Message,
  isTherapistView: boolean,
  currentUserId?: number,
  senderLabels?: MessageListProps['senderLabels'],
): string {
  const aiLabel = senderLabels?.ai || 'AI Assistant';
  const systemLabel = senderLabels?.system || 'System';
  const therapistLabel = senderLabels?.therapist || 'Therapist';
  const subjectLabel = senderLabels?.subject || 'Patient';
  const youLabel = senderLabels?.you || 'You';

  if (msg.label && msg.label !== 'Unknown') {
    if (isTherapistView && msg.sender_type === 'therapist' && isOwnMessage(msg, isTherapistView, currentUserId)) {
      return youLabel;
    }
    return msg.label;
  }
  const st = msg.sender_type;
  if (st === 'ai') return aiLabel;
  if (st === 'system') return systemLabel;
  if (st === 'therapist') {
    if (isTherapistView && isOwnMessage(msg, isTherapistView, currentUserId)) return youLabel;
    return msg.sender_name ? `${therapistLabel} (${msg.sender_name})` : therapistLabel;
  }
  if (st === 'subject') return isTherapistView ? msg.sender_name || subjectLabel : youLabel;
  if (msg.role === 'assistant') return aiLabel;
  if (msg.role === 'system') return systemLabel;
  return youLabel;
}

function isOwnMessage(msg: Message, isTherapistView: boolean, currentUserId?: number): boolean {
  if (isTherapistView) {
    if (msg.sender_type !== 'therapist') return false;
    if (currentUserId && msg.sender_id) {
      return msg.sender_id === currentUserId;
    }
    return !msg.sender_id;
  }
  return msg.sender_type === 'subject' || (msg.role === 'user' && !msg.sender_type);
}

function isOtherTherapist(msg: Message, isTherapistView: boolean, currentUserId?: number): boolean {
  if (!isTherapistView) return false;
  return msg.sender_type === 'therapist' && !isOwnMessage(msg, isTherapistView, currentUserId);
}

function senderIcon(st?: SenderType): string {
  if (st === 'ai') return 'fas fa-robot';
  if (st === 'therapist') return 'fas fa-user-md';
  if (st === 'system') return 'fas fa-info-circle';
  return 'fas fa-user';
}

function bubbleClass(msg: Message, isTherapistView: boolean, currentUserId?: number): string {
  if (msg.sender_type === 'system') return 'tc-msg tc-msg--system';
  if (isOwnMessage(msg, isTherapistView, currentUserId)) return 'tc-msg tc-msg--self';
  if (isOtherTherapist(msg, isTherapistView, currentUserId)) return 'tc-msg tc-msg--other-therapist';
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
  currentUserId,
  chatColors,
  emptyText = 'No messages yet. Start the conversation!',
  senderLabels,
}) => {
  const endRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages.length]);

  const therapistMap = useMemo(
    () => buildTherapistIndexMap(messages, currentUserId),
    [messages, currentUserId],
  );

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

        const own = isOwnMessage(msg, isTherapistView, currentUserId);
        const colorStyle = getColorForMessage(msg, isTherapistView, currentUserId, chatColors, therapistMap);

        return (
          <div key={msg.id} className={bubbleClass(msg, isTherapistView, currentUserId)} style={colorStyle}>
            {/* Header: sender + time */}
            <div className="tc-msg__header d-flex">
              {!own && (
                <span className="tc-msg__icon mr-1">
                  <i className={senderIcon(msg.sender_type)} />
                </span>
              )}
              <span className="tc-msg__sender">{senderLabel(msg, isTherapistView, currentUserId, senderLabels)}</span>
              <span className="tc-msg__time ml-auto">{formatFullTimestamp(msg.timestamp)}</span>
            </div>

            {/* Content */}
            <div className="tc-msg__body">
              {msg.sender_type === 'ai' || msg.role === 'assistant' || msg.sender_type === 'system' ? (
                <MarkdownRenderer content={msg.content} />
              ) : (
                <span style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{msg.content}</span>
              )}
            </div>

            {/* Footer: timestamp + edited */}
            <div className="tc-msg__footer">
              {msg.is_edited && (
                <small className="tc-msg__edited-tag">
                  <i className="fas fa-pen mr-1" style={{ fontSize: '0.55rem' }} />
                  edited{msg.edited_at ? ` ${formatTime(msg.edited_at)}` : ''}
                </small>
              )}
            </div>
          </div>
        );
      })}
      <div ref={endRef} />
    </div>
  );
};

export default MessageList;
