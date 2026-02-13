/**
 * Message Input Component
 * =======================
 *
 * Redesigned input area matching the sh-shp-llm plugin pattern:
 *   - Textarea sits in a bordered container (no border on the textarea itself)
 *   - Action bar below textarea with: mic button | char count | send button
 *   - Speech-to-text inserts at cursor position (never overwrites)
 *   - Auto-grow up to 120 px then scroll
 *   - Recording animation (pulsing red) + processing spinner
 *   - @mention autocomplete: type `@` to see available therapists
 *   - #topic autocomplete: type `#` to see predefined tag reasons
 *
 * Bootstrap 4.6 classes + minimal custom CSS.
 */

import React, { useState, useCallback, useRef, useEffect } from 'react';
import { VoiceRecorder } from './VoiceRecorder';

/** A mention/topic suggestion entry */
interface MentionItem {
  id: string | number;
  display: string;
  /** The text inserted into the message (e.g. "@Dr. Smith" or "#anxiety") */
  insertText: string;
}

interface MessageInputProps {
  onSend: (message: string) => void;
  disabled?: boolean;
  placeholder?: string;
  buttonLabel?: string;
  /** Speech-to-text configuration */
  speechToTextEnabled?: boolean;
  sectionId?: number;
  /** Callback to fetch therapist list for @mentions */
  onFetchMentions?: () => Promise<MentionItem[]>;
  /** Static list of #topic suggestions (from tag reasons) */
  topicSuggestions?: MentionItem[];
}

const MAX_LENGTH = 4000;

export const MessageInput: React.FC<MessageInputProps> = ({
  onSend,
  disabled = false,
  placeholder = 'Type your message...',
  buttonLabel = 'Send',
  speechToTextEnabled = false,
  sectionId,
  onFetchMentions,
  topicSuggestions = [],
}) => {
  // ---- State ----
  const [text, setText] = useState('');

  // ---- Mention/Topic autocomplete state ----
  const [mentionQuery, setMentionQuery] = useState<string | null>(null);
  const [mentionType, setMentionType] = useState<'@' | '#' | null>(null);
  const [mentionStartPos, setMentionStartPos] = useState<number>(0);
  const [mentionItems, setMentionItems] = useState<MentionItem[]>([]);
  const [mentionIndex, setMentionIndex] = useState(0);
  const [mentionLoading, setMentionLoading] = useState(false);
  const mentionCacheRef = useRef<MentionItem[] | null>(null);

  // ---- Refs ----
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const messageRef = useRef(text);
  const mentionDropdownRef = useRef<HTMLDivElement>(null);

  // Keep ref in sync
  useEffect(() => { messageRef.current = text; }, [text]);

  // ---- Textarea helpers ----

  const autoResize = useCallback((el: HTMLTextAreaElement) => {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
  }, []);

  // ---- Mention/Topic autocomplete logic ----

  /** Detect @mention or #topic trigger in the text at the current cursor position. */
  const detectTrigger = useCallback((value: string, cursorPos: number) => {
    // Search backwards from cursor for @ or # trigger
    const textBefore = value.substring(0, cursorPos);
    // Find the last @ or # that starts a word (preceded by space/start-of-string)
    const atMatch = textBefore.match(/(?:^|\s)@([^\s@#]*)$/);
    const hashMatch = textBefore.match(/(?:^|\s)#([^\s@#]*)$/);

    if (atMatch) {
      const query = atMatch[1] || '';
      const startPos = cursorPos - query.length - 1; // -1 for the @ character
      return { type: '@' as const, query, startPos };
    }
    if (hashMatch) {
      const query = hashMatch[1] || '';
      const startPos = cursorPos - query.length - 1;
      return { type: '#' as const, query, startPos };
    }
    return null;
  }, []);

  /** Filter mention items by query string */
  const filterItems = useCallback((items: MentionItem[], query: string): MentionItem[] => {
    if (!query) return items;
    const lower = query.toLowerCase();
    return items.filter(item => item.display.toLowerCase().includes(lower));
  }, []);

  /** Load @mention suggestions (therapists) */
  const loadMentionSuggestions = useCallback(async (query: string) => {
    if (!onFetchMentions) {
      // If no fetch callback, provide a default "@therapist" mention
      const defaults: MentionItem[] = [{ id: 'therapist', display: 'therapist', insertText: '@therapist' }];
      setMentionItems(filterItems(defaults, query));
      return;
    }

    // Use cache if available
    if (mentionCacheRef.current) {
      setMentionItems(filterItems(mentionCacheRef.current, query));
      return;
    }

    setMentionLoading(true);
    try {
      const items = await onFetchMentions();
      mentionCacheRef.current = items;
      setMentionItems(filterItems(items, query));
    } catch {
      // On error, provide default
      const defaults: MentionItem[] = [{ id: 'therapist', display: 'therapist', insertText: '@therapist' }];
      mentionCacheRef.current = defaults;
      setMentionItems(filterItems(defaults, query));
    } finally {
      setMentionLoading(false);
    }
  }, [onFetchMentions, filterItems]);

  /** Load #topic suggestions (from tag reasons) */
  const loadTopicSuggestions = useCallback((query: string) => {
    if (topicSuggestions.length === 0) return;
    setMentionItems(filterItems(topicSuggestions, query));
  }, [topicSuggestions, filterItems]);

  /** Insert a selected mention/topic into the text */
  const insertMention = useCallback((item: MentionItem) => {
    const textarea = textareaRef.current;
    if (!textarea || mentionType === null) return;

    const currentText = messageRef.current;
    // Replace from the trigger character position to the current cursor
    const before = currentText.substring(0, mentionStartPos);
    const after = currentText.substring(textarea.selectionStart ?? currentText.length);
    const newText = before + item.insertText + ' ' + after;
    const newCursor = before.length + item.insertText.length + 1;

    setText(newText);
    messageRef.current = newText;

    // Close the dropdown
    setMentionQuery(null);
    setMentionType(null);
    setMentionItems([]);
    setMentionIndex(0);

    // Restore focus and cursor position
    requestAnimationFrame(() => {
      if (textarea) {
        textarea.focus();
        textarea.setSelectionRange(newCursor, newCursor);
        autoResize(textarea);
      }
    });
  }, [mentionType, mentionStartPos, autoResize]);

  /** Close the mention dropdown */
  const closeMentionDropdown = useCallback(() => {
    setMentionQuery(null);
    setMentionType(null);
    setMentionItems([]);
    setMentionIndex(0);
  }, []);

  const handleInputChange = useCallback(
    (e: React.ChangeEvent<HTMLTextAreaElement>) => {
      const value = e.target.value;
      const cursorPos = e.target.selectionStart ?? value.length;
      setText(value);
      autoResize(e.target);

      // Check for @mention or #topic trigger
      const trigger = detectTrigger(value, cursorPos);
      if (trigger) {
        setMentionType(trigger.type);
        setMentionQuery(trigger.query);
        setMentionStartPos(trigger.startPos);
        setMentionIndex(0);
        if (trigger.type === '@') {
          loadMentionSuggestions(trigger.query);
        } else {
          loadTopicSuggestions(trigger.query);
        }
      } else {
        closeMentionDropdown();
      }
    },
    [autoResize, detectTrigger, loadMentionSuggestions, loadTopicSuggestions, closeMentionDropdown],
  );

  // ---- Send ----

  const handleSend = useCallback(() => {
    const trimmed = text.trim();
    if (!trimmed || disabled) return;
    onSend(trimmed);
    setText('');
    closeMentionDropdown();
    if (textareaRef.current) {
      textareaRef.current.style.height = 'auto';
    }
  }, [text, disabled, onSend, closeMentionDropdown]);

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      // When mention dropdown is open, handle arrow keys, Enter, and Escape
      if (mentionType !== null && mentionItems.length > 0) {
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          setMentionIndex(prev => Math.min(prev + 1, mentionItems.length - 1));
          return;
        }
        if (e.key === 'ArrowUp') {
          e.preventDefault();
          setMentionIndex(prev => Math.max(prev - 1, 0));
          return;
        }
        if (e.key === 'Enter' || e.key === 'Tab') {
          e.preventDefault();
          insertMention(mentionItems[mentionIndex]);
          return;
        }
        if (e.key === 'Escape') {
          e.preventDefault();
          closeMentionDropdown();
          return;
        }
      }

      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSend();
      }
    },
    [handleSend, mentionType, mentionItems, mentionIndex, insertMention, closeMentionDropdown],
  );

  // ---- Speech-to-Text: append transcribed text at cursor ----
  /**
   * Safely append transcribed text at cursor position.
   * RULES: NEVER overwrite; ALWAYS append at cursor; add proper spacing.
   */
  const appendTranscribedText = useCallback((transcribedText: string) => {
    const textarea = textareaRef.current;
    if (!textarea) return;

    const currentMessage = messageRef.current;
    const cursorPos = textarea.selectionStart ?? currentMessage.length;

    const textBefore = currentMessage.substring(0, cursorPos);
    const textAfter = currentMessage.substring(cursorPos);

    const needsSpaceBefore = textBefore.length > 0 && !/[\s]$/.test(textBefore);
    const spaceBefore = needsSpaceBefore ? ' ' : '';
    const trailingSpace = ' ';

    const newMessage = textBefore + spaceBefore + transcribedText + trailingSpace + textAfter;
    const newCursorPos =
      textBefore.length + spaceBefore.length + transcribedText.length + trailingSpace.length;

    setText(newMessage);
    messageRef.current = newMessage;

    requestAnimationFrame(() => {
      if (textarea) {
        textarea.focus();
        textarea.setSelectionRange(newCursorPos, newCursorPos);
        autoResize(textarea);
      }
    });
  }, [autoResize]);

  // ---- Derived ----
  const charCount = text.length;
  const isNearLimit = charCount > MAX_LENGTH * 0.9;

  // ---- Render ----
  return (
    <div className="tc-input">
      {/* Input container (border wraps textarea + action bar) */}
      <div className="tc-input-container border rounded">
        {/* Textarea */}
        <textarea
          ref={textareaRef}
          className="form-control border-0 tc-input-textarea tc-input-textarea-resize"
          value={text}
          onChange={handleInputChange}
          onKeyDown={handleKeyDown}
          onBlur={() => {
            // Delay close to allow click on dropdown item
            setTimeout(() => closeMentionDropdown(), 200);
          }}
          placeholder={placeholder}
          disabled={disabled}
          rows={1}
          maxLength={MAX_LENGTH}
        />

        {/* Mention/Topic autocomplete dropdown */}
        {mentionType !== null && mentionItems.length > 0 && (
          <div
            ref={mentionDropdownRef}
            className="tc-mention-dropdown"
          >
            <div className="list-group list-group-flush">
              {mentionLoading && (
                <div className="list-group-item text-muted small py-2">
                  <i className="fas fa-spinner fa-spin mr-1" />
                  Loading...
                </div>
              )}
              {mentionItems.map((item, idx) => (
                <button
                  key={item.id}
                  type="button"
                  className={`list-group-item list-group-item-action py-2 px-3 small ${idx === mentionIndex ? 'active' : ''}`}
                  onMouseDown={(e) => {
                    e.preventDefault(); // Prevent blur
                    insertMention(item);
                  }}
                  onMouseEnter={() => setMentionIndex(idx)}
                >
                  <i className={`fas ${mentionType === '@' ? 'fa-user-md' : 'fa-hashtag'} mr-2 ${idx === mentionIndex ? 'text-white' : 'text-muted'}`} />
                  {item.display}
                </button>
              ))}
            </div>
          </div>
        )}

        {/* Action bar */}
        <div className="d-flex justify-content-between align-items-center px-2 py-1 border-top bg-light tc-input-actions">
          {/* Left: mic (VoiceRecorder handles its own UI + error) */}
          <div className="d-flex align-items-center">
            <VoiceRecorder
              onTranscription={appendTranscribedText}
              speechToTextEnabled={speechToTextEnabled}
              sectionId={sectionId}
              disabled={disabled}
            />
          </div>

          {/* Center: char count */}
          <small className={isNearLimit ? 'text-warning' : 'text-muted'}>
            {charCount}/{MAX_LENGTH}
          </small>

          {/* Right: send */}
          <button
            type="button"
            className="btn btn-primary btn-sm tc-action-btn tc-send-btn"
            onClick={handleSend}
            disabled={disabled || !text.trim()}
            title="Send message"
          >
            {disabled ? (
              <i className="fas fa-spinner fa-spin" />
            ) : (
              <i className="fas fa-paper-plane" />
            )}
          </button>
        </div>
      </div>
    </div>
  );
};

export type { MentionItem };
export default MessageInput;
