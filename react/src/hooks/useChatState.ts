/**
 * Chat State Hook
 * ================
 *
 * Shared state management for both subject and therapist chat views.
 * Handles conversation loading, message sending, optimistic updates,
 * and polling for new messages.
 *
 * IMPORTANT: loadFn / sendFn / pollFn are stored in refs so their
 * identity never affects the dependency arrays of the callbacks
 * returned to the consumer.  This prevents infinite re-render loops.
 */

import { useState, useCallback, useRef, useEffect } from 'react';
import type { Message, Conversation, SendMessageResponse } from '../types';

interface UseChatStateOptions {
  /** Function that loads a conversation with messages */
  loadFn: (conversationId?: number | string) => Promise<{ conversation?: Conversation; messages?: Message[] }>;
  /** Function that sends a message */
  sendFn: (conversationId: number | string, message: string) => Promise<SendMessageResponse>;
  /** Function that polls for new messages */
  pollFn: (conversationId: number | string, afterId?: number) => Promise<{ messages: Message[] }>;
  /** Sender type for optimistic messages */
  senderType: 'subject' | 'therapist';
}

export function useChatState({ loadFn, sendFn, pollFn, senderType }: UseChatStateOptions) {
  const [conversation, setConversation] = useState<Conversation | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [isSending, setIsSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const lastMsgIdRef = useRef<number | null>(null);
  /** Prevents poll from running while a load/send is in flight */
  const busyRef = useRef(false);

  // ---- Stable refs for callback functions ----
  // This ensures loadConversation / sendMessage / pollMessages never change identity
  // even when the parent re-renders with new inline arrow functions.
  const loadFnRef = useRef(loadFn);
  const sendFnRef = useRef(sendFn);
  const pollFnRef = useRef(pollFn);
  const conversationRef = useRef(conversation);

  useEffect(() => { loadFnRef.current = loadFn; }, [loadFn]);
  useEffect(() => { sendFnRef.current = sendFn; }, [sendFn]);
  useEffect(() => { pollFnRef.current = pollFn; }, [pollFn]);
  useEffect(() => { conversationRef.current = conversation; }, [conversation]);

  /** Update the tracked last message ID */
  const trackLastId = useCallback((msgs: Message[]) => {
    if (msgs.length === 0) return;
    const last = msgs[msgs.length - 1];
    const n = typeof last.id === 'number' ? last.id : parseInt(String(last.id), 10);
    if (!isNaN(n)) lastMsgIdRef.current = n;
  }, []);

  /** Load conversation + messages  (STABLE identity) */
  const loadConversation = useCallback(
    async (conversationId?: number | string) => {
      busyRef.current = true;
      setIsLoading(true);
      setError(null);
      try {
        const res = await loadFnRef.current(conversationId);
        if (res.conversation) setConversation(res.conversation);
        if (res.messages) {
          setMessages(res.messages);
          trackLastId(res.messages);
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load conversation');
      } finally {
        setIsLoading(false);
        busyRef.current = false;
      }
    },
    [trackLastId],
  );

  /** Send a message with optimistic update  (STABLE identity) */
  const sendMessage = useCallback(
    async (content: string) => {
      const convId = conversationRef.current?.id;
      if (!content.trim() || !convId) return;

      setIsSending(true);
      setError(null);

      const tempId = `temp-${Date.now()}`;
      const optimistic: Message = {
        id: tempId,
        role: 'user',
        content,
        sender_type: senderType,
        timestamp: new Date().toISOString(),
      };
      setMessages((prev) => [...prev, optimistic]);

      try {
        const res = await sendFnRef.current(convId, content);

        if (res.blocked) {
          // Remove optimistic message and show safety notice
          setMessages((prev) => prev.filter((m) => m.id !== tempId));
          const notice: Message = {
            id: `safety-${Date.now()}`,
            role: 'system',
            content: res.message || 'Your message was blocked for safety reasons.',
            sender_type: 'system',
            timestamp: new Date().toISOString(),
          };
          setMessages((prev) => [...prev, notice]);
          return;
        }

        // Replace temp message with real one
        if (res.message_id) {
          setMessages((prev) => prev.map((m) => (m.id === tempId ? { ...m, id: res.message_id! } : m)));
          lastMsgIdRef.current = res.message_id;
        }

        // Append AI response if present
        if (res.ai_message) {
          setMessages((prev) => [...prev, res.ai_message!]);
          const aiId = typeof res.ai_message.id === 'number'
            ? res.ai_message.id
            : parseInt(String(res.ai_message.id), 10);
          if (!isNaN(aiId)) lastMsgIdRef.current = aiId;
        }
      } catch (err) {
        setMessages((prev) => prev.filter((m) => m.id !== tempId));
        setError(err instanceof Error ? err.message : 'Failed to send message');
      } finally {
        setIsSending(false);
      }
    },
    [senderType],   // <-- only senderType, which is stable
  );

  /** Poll for new messages  (STABLE identity) */
  const pollMessages = useCallback(async () => {
    const convId = conversationRef.current?.id;
    if (!convId || busyRef.current) return;
    try {
      const res = await pollFnRef.current(convId, lastMsgIdRef.current ?? undefined);
      if (res.messages?.length) {
        setMessages((prev) => {
          const existingIds = new Set(prev.map((m) => String(m.id)));
          const fresh = res.messages.filter((m) => !existingIds.has(String(m.id)));
          return fresh.length ? [...prev, ...fresh] : prev;
        });
        trackLastId(res.messages);
      }
    } catch (err) {
      console.error('Poll error:', err);
    }
  }, [trackLastId]);

  const clearError = useCallback(() => setError(null), []);

  return {
    conversation,
    messages,
    isLoading,
    isSending,
    error,
    loadConversation,
    sendMessage,
    pollMessages,
    clearError,
    setError,
    setConversation,
    setMessages,
  };
}
