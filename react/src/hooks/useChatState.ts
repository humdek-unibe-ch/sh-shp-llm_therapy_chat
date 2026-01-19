/**
 * Chat State Hook
 * ================
 * 
 * Manages chat state for both subject and therapist interfaces.
 */

import { useState, useCallback, useRef } from 'react';
import type { Message, Conversation, TherapyChatConfig, TherapistDashboardConfig } from '../types';
import { therapyChatApi, therapistDashboardApi } from '../utils/api';

interface UseChatStateOptions {
  config: TherapyChatConfig | TherapistDashboardConfig;
  isTherapist?: boolean;
}

interface UseChatStateReturn {
  // State
  conversation: Conversation | null;
  messages: Message[];
  isLoading: boolean;
  isSending: boolean;
  error: string | null;
  
  // Actions
  loadConversation: (conversationId?: number | string) => Promise<void>;
  sendMessage: (content: string) => Promise<void>;
  clearError: () => void;
  setError: (error: string) => void;
  
  // For polling
  lastMessageId: number | null;
  pollMessages: () => Promise<Message[]>;
}

export function useChatState({ config, isTherapist = false }: UseChatStateOptions): UseChatStateReturn {
  const [conversation, setConversation] = useState<Conversation | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [isSending, setIsSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  
  const lastMessageIdRef = useRef<number | null>(null);

  /**
   * Update last message ID from messages array
   */
  const updateLastMessageId = useCallback((msgs: Message[]) => {
    if (msgs.length > 0) {
      const lastMsg = msgs[msgs.length - 1];
      const msgId = typeof lastMsg.id === 'number' ? lastMsg.id : parseInt(String(lastMsg.id), 10);
      if (!isNaN(msgId)) {
        lastMessageIdRef.current = msgId;
      }
    }
  }, []);

  /**
   * Load conversation with messages
   */
  const loadConversation = useCallback(async (conversationId?: number | string) => {
    setIsLoading(true);
    setError(null);

    try {
      const api = isTherapist ? therapistDashboardApi : therapyChatApi;
      const convIdToUse = conversationId ?? undefined;
      const response = await api.getConversation(config.sectionId, convIdToUse);
      
      if (response.conversation) {
        setConversation(response.conversation);
      }
      if (response.messages) {
        setMessages(response.messages);
        updateLastMessageId(response.messages);
      }
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to load conversation';
      setError(message);
      console.error('Load conversation error:', err);
    } finally {
      setIsLoading(false);
    }
  }, [config.sectionId, isTherapist, updateLastMessageId]);

  /**
   * Send a message
   */
  const sendMessage = useCallback(async (content: string) => {
    if (!content.trim() || isSending) return;

    setIsSending(true);
    setError(null);

    // Optimistically add user message
    const tempMessage: Message = {
      id: `temp-${Date.now()}`,
      role: 'user',
      content,
      sender_type: isTherapist ? 'therapist' : 'subject',
      timestamp: new Date().toISOString(),
    };
    setMessages(prev => [...prev, tempMessage]);

    try {
      const api = isTherapist ? therapistDashboardApi : therapyChatApi;
      const conversationId = conversation?.id || ('conversationId' in config ? config.conversationId : undefined);
      
      const convIdToSend = conversationId ?? undefined;
      const response = await api.sendMessage(config.sectionId, convIdToSend, content);

      if (response.error) {
        throw new Error(response.error);
      }

      if (response.blocked) {
        // Message was blocked by danger detection
        // Remove optimistic message and show safety response
        setMessages(prev => prev.filter(m => m.id !== tempMessage.id));
        
        // Add system message about the block
        const safetyMessage: Message = {
          id: `safety-${Date.now()}`,
          role: 'assistant',
          content: response.message || 'Your message was blocked for safety reasons.',
          sender_type: 'system',
          timestamp: new Date().toISOString(),
        };
        setMessages(prev => [...prev, safetyMessage]);
        return;
      }

      // Update temp message with real ID
      if (response.message_id) {
        setMessages(prev => prev.map(m => 
          m.id === tempMessage.id ? { ...m, id: response.message_id! } : m
        ));
        lastMessageIdRef.current = response.message_id;
      }

      // Add AI response if present
      if (response.ai_message) {
        setMessages(prev => [...prev, response.ai_message!]);
        if (typeof response.ai_message.id === 'number') {
          lastMessageIdRef.current = response.ai_message.id;
        }
      }

    } catch (err) {
      // Remove optimistic message on error
      setMessages(prev => prev.filter(m => m.id !== tempMessage.id));
      const message = err instanceof Error ? err.message : 'Failed to send message';
      setError(message);
      console.error('Send message error:', err);
    } finally {
      setIsSending(false);
    }
  }, [config.sectionId, conversation?.id, config, isTherapist, isSending]);

  /**
   * Poll for new messages
   */
  const pollMessages = useCallback(async (): Promise<Message[]> => {
    const conversationId = conversation?.id || ('conversationId' in config ? config.conversationId : undefined);
    
    if (!conversationId) return [];

    try {
      const api = isTherapist ? therapistDashboardApi : therapyChatApi;
      const response = await api.getMessages(
        config.sectionId, 
        conversationId, 
        lastMessageIdRef.current ?? undefined
      );

      if (response.messages && response.messages.length > 0) {
        setMessages(prev => {
          // Add only new messages
          const existingIds = new Set(prev.map(m => m.id));
          const newMsgs = response.messages.filter(m => !existingIds.has(m.id));
          return [...prev, ...newMsgs];
        });
        updateLastMessageId(response.messages);
        return response.messages;
      }
    } catch (err) {
      console.error('Poll messages error:', err);
    }

    return [];
  }, [config.sectionId, conversation?.id, config, isTherapist, updateLastMessageId]);

  /**
   * Clear error
   */
  const clearError = useCallback(() => {
    setError(null);
  }, []);

  return {
    conversation,
    messages,
    isLoading,
    isSending,
    error,
    loadConversation,
    sendMessage,
    clearError,
    setError,
    lastMessageId: lastMessageIdRef.current,
    pollMessages,
  };
}
