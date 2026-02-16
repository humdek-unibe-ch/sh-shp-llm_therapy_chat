/**
 * Conversation Actions Hook
 * ==========================
 *
 * Encapsulates conversation-level actions: mark read, toggle AI,
 * set status, set risk, and initialize conversation.
 *
 * After each mutation the relevant conversation state is updated
 * optimistically AND a full data refresh is triggered so the
 * sidebar badges, header stats, and right-panel controls all stay
 * in sync.
 */

import { useState, useCallback } from 'react';
import type { RiskLevel, ConversationStatus, Conversation } from '../types';

interface ConversationApi {
  markMessagesRead: (conversationId: number | string) => Promise<any>;
  toggleAI: (conversationId: number | string, enabled: boolean) => Promise<{ ai_enabled: boolean }>;
  setStatus: (conversationId: number | string, status: string) => Promise<any>;
  setRiskLevel: (conversationId: number | string, risk: string) => Promise<any>;
  initializeConversation: (patientId: number) => Promise<{ conversation: Conversation; already_exists: boolean }>;
}

interface UseConversationActionsOptions {
  api: ConversationApi;
  getConversation: () => Conversation | null;
  updateConversation: (id: number | string, update: Partial<Conversation>) => void;
  refreshUnreadCounts: () => Promise<void>;
  refreshConversations: (groupId?: number | string | null, filter?: string, silent?: boolean) => Promise<void>;
  refreshStats: () => Promise<void>;
  selectConversation: (id: number | string | null) => void;
  activeGroupId: number | string | null;
  activeFilter: string;
  /** Re-load the chat state for the currently selected conversation */
  reloadChat: (convId?: number | string) => Promise<void>;
}

export function useConversationActions({
  api,
  getConversation,
  updateConversation,
  refreshUnreadCounts,
  refreshConversations,
  refreshStats,
  selectConversation,
  activeGroupId,
  activeFilter,
  reloadChat,
}: UseConversationActionsOptions) {
  const [initializingPatientId, setInitializingPatientId] = useState<number | null>(null);

  const markRead = useCallback(async () => {
    const conv = getConversation();
    if (!conv) return;
    try {
      await api.markMessagesRead(conv.id);
      await Promise.all([
        refreshUnreadCounts(),
        refreshConversations(activeGroupId, activeFilter, true),
      ]);
    } catch { /* ignore */ }
  }, [api, getConversation, refreshUnreadCounts, refreshConversations, activeGroupId, activeFilter]);

  const toggleAI = useCallback(async () => {
    const conv = getConversation();
    if (!conv) return;
    const newEnabled = !conv.ai_enabled;
    try {
      await api.toggleAI(conv.id, newEnabled);
      updateConversation(conv.id, { ai_enabled: newEnabled });
      await reloadChat(conv.id);
    } catch (err) {
      console.error('Failed to toggle AI:', err);
    }
  }, [api, getConversation, updateConversation, reloadChat]);

  const setStatus = useCallback(async (status: string) => {
    const conv = getConversation();
    if (!conv) return;
    try {
      await api.setStatus(conv.id, status);
      updateConversation(conv.id, { status: status as ConversationStatus });
      await Promise.all([
        reloadChat(conv.id),
        refreshConversations(activeGroupId, activeFilter, true),
        refreshStats(),
      ]);
    } catch (err) {
      console.error('Failed to set status:', err);
    }
  }, [api, getConversation, updateConversation, reloadChat, refreshConversations, refreshStats, activeGroupId, activeFilter]);

  const setRisk = useCallback(async (risk: RiskLevel) => {
    const conv = getConversation();
    if (!conv) return;
    try {
      await api.setRiskLevel(conv.id, risk);
      updateConversation(conv.id, { risk_level: risk });
      await Promise.all([
        reloadChat(conv.id),
        refreshConversations(activeGroupId, activeFilter, true),
        refreshStats(),
      ]);
    } catch (err) {
      console.error('Failed to set risk:', err);
    }
  }, [api, getConversation, updateConversation, reloadChat, refreshConversations, refreshStats, activeGroupId, activeFilter]);

  const initializeConversation = useCallback(async (patientId: number) => {
    setInitializingPatientId(patientId);
    try {
      const response = await api.initializeConversation(patientId);
      if (response.conversation) {
        updateConversation(response.conversation.id, response.conversation);
        selectConversation(response.conversation.id);
        await refreshConversations(activeGroupId, activeFilter, true);
      }
    } catch (err) {
      console.error('Failed to initialize conversation:', err);
    } finally {
      setInitializingPatientId(null);
    }
  }, [api, updateConversation, selectConversation, refreshConversations, activeGroupId, activeFilter]);

  return {
    markRead,
    toggleAI,
    setStatus,
    setRisk,
    initializeConversation,
    initializingPatientId,
  };
}
