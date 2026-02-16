/**
 * API Utility Layer for Therapy Chat
 * ====================================
 *
 * All requests go through the current page's controller via ?action=xxx
 * Security is handled by SelfHelp's session + ACL system.
 *
 * Subject endpoints:  TherapyChatController.php
 * Therapist endpoints: TherapistDashboardController.php
 */

import type {
  SubjectChatConfig,
  TherapistDashboardConfig,
  SendMessageResponse,
  GetMessagesResponse,
  GetConversationResponse,
  GetConversationsResponse,
  TagTherapistResponse,
  Conversation,
  Alert,
  Note,
  Draft,
  DashboardStats,
  UnreadCounts,
  TherapistGroup,
} from '../types';

// ---------------------------------------------------------------------------
// Low-level helpers
// ---------------------------------------------------------------------------

/**
 * Build a GET URL for an API action.
 * When `customBaseUrl` is set (floating modal mode), use that instead of
 * `window.location.href` so the request reaches the correct controller.
 */
function buildUrl(action: string, params: Record<string, string> = {}, customBaseUrl?: string): string {
  const base = customBaseUrl || window.location.href;
  const url = new URL(base, window.location.origin);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) {
    url.searchParams.set(k, v);
  }
  return url.toString();
}

async function apiGet<T>(action: string, params: Record<string, string> = {}, customBaseUrl?: string): Promise<T> {
  const res = await fetch(buildUrl(action, params, customBaseUrl), {
    method: 'GET',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'same-origin',
  });
  const data = await res.json().catch(() => {
    throw new Error(`HTTP ${res.status}: ${res.statusText}`);
  });
  if (!res.ok) throw new Error(data?.error ?? `HTTP ${res.status}`);
  return data;
}

async function apiPost<T>(formData: FormData, customBaseUrl?: string): Promise<T> {
  // Use the full current URL (including query string) so SelfHelp can route
  // correctly. The POST body contains action + section_id for the controller.
  const base = customBaseUrl || window.location.href;
  const url = new URL(base, window.location.origin);
  // Remove volatile params that might conflict with form body
  url.searchParams.delete('action');
  const res = await fetch(url.toString(), {
    method: 'POST',
    body: formData,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'same-origin',
  });
  const data = await res.json().catch(() => {
    throw new Error(`HTTP ${res.status}: ${res.statusText}`);
  });
  if (!res.ok) throw new Error(data?.error ?? `HTTP ${res.status}`);
  return data;
}

/** Build a FormData with action + section_id pre-filled */
function postData(action: string, sectionId?: number): FormData {
  const fd = new FormData();
  fd.append('action', action);
  if (sectionId !== undefined) fd.append('section_id', String(sectionId));
  return fd;
}

/** Append optional section_id to GET params */
function withSection(params: Record<string, string>, sectionId?: number): Record<string, string> {
  if (sectionId !== undefined) params.section_id = String(sectionId);
  return params;
}

// ---------------------------------------------------------------------------
// Subject Chat API
// ---------------------------------------------------------------------------

/**
 * Create a Subject (patient) API instance.
 *
 * @param sectionId - The therapy chat section ID
 * @param baseUrl   - Optional custom base URL. Required for floating modal mode
 *                    where `window.location.href` points to a different page.
 */
export function createSubjectApi(sectionId?: number, baseUrl?: string) {
  return {
    async getConfig(): Promise<SubjectChatConfig> {
      return apiGet('get_config', withSection({}, sectionId), baseUrl);
    },

    async getConversation(conversationId?: number | string): Promise<GetConversationResponse> {
      const p: Record<string, string> = {};
      if (conversationId != null) p.conversation_id = String(conversationId);
      return apiGet('get_conversation', withSection(p, sectionId), baseUrl);
    },

    async getMessages(conversationId?: number | string, afterId?: number): Promise<GetMessagesResponse> {
      const p: Record<string, string> = {};
      if (conversationId != null) p.conversation_id = String(conversationId);
      if (afterId != null) p.after_id = String(afterId);
      return apiGet('get_messages', withSection(p, sectionId), baseUrl);
    },

    async sendMessage(conversationId: number | string | undefined, message: string): Promise<SendMessageResponse> {
      const fd = postData('send_message', sectionId);
      fd.append('message', message);
      if (conversationId != null) fd.append('conversation_id', String(conversationId));
      return apiPost(fd, baseUrl);
    },

    async tagTherapist(conversationId: number | string, reason?: string, urgency?: string): Promise<TagTherapistResponse> {
      const fd = postData('tag_therapist', sectionId);
      fd.append('conversation_id', String(conversationId));
      if (reason) fd.append('reason', reason);
      if (urgency) fd.append('urgency', urgency);
      return apiPost(fd, baseUrl);
    },

    async markMessagesRead(conversationId?: number | string): Promise<{ success: boolean; unread_count: number }> {
      const fd = postData('mark_messages_read', sectionId);
      if (conversationId != null) fd.append('conversation_id', String(conversationId));
      return apiPost(fd, baseUrl);
    },

    async checkUpdates(): Promise<{ latest_message_id: number | null; unread_count: number }> {
      return apiGet('check_updates', withSection({}, sectionId), baseUrl);
    },

    async getTherapists(): Promise<{ therapists: Array<{ id: number; display: string; name: string; email?: string }> }> {
      return apiGet('get_therapists', withSection({}, sectionId), baseUrl);
    },
  };
}

// ---------------------------------------------------------------------------
// Therapist Dashboard API
// ---------------------------------------------------------------------------

export function createTherapistApi(sectionId?: number) {
  return {
    async getConfig(): Promise<TherapistDashboardConfig> {
      return apiGet('get_config', withSection({}, sectionId));
    },

    // ---- Conversation list ----

    async getConversations(filters?: Record<string, string | number>): Promise<GetConversationsResponse> {
      const p: Record<string, string> = {};
      if (filters) {
        for (const [k, v] of Object.entries(filters)) {
          if (v != null) p[k] = String(v);
        }
      }
      return apiGet('get_conversations', withSection(p, sectionId));
    },

    async getConversation(conversationId: number | string): Promise<GetConversationResponse> {
      return apiGet('get_conversation', withSection({ conversation_id: String(conversationId) }, sectionId));
    },

    async getMessages(conversationId: number | string, afterId?: number): Promise<GetMessagesResponse> {
      const p: Record<string, string> = { conversation_id: String(conversationId) };
      if (afterId != null) p.after_id = String(afterId);
      return apiGet('get_messages', withSection(p, sectionId));
    },

    // ---- Messaging ----

    async sendMessage(conversationId: number | string, message: string): Promise<SendMessageResponse> {
      const fd = postData('send_message', sectionId);
      fd.append('conversation_id', String(conversationId));
      fd.append('message', message);
      return apiPost(fd);
    },

    async editMessage(messageId: number, newContent: string): Promise<ApiOk> {
      const fd = postData('edit_message', sectionId);
      fd.append('message_id', String(messageId));
      fd.append('content', newContent);
      return apiPost(fd);
    },

    async deleteMessage(messageId: number): Promise<ApiOk> {
      const fd = postData('delete_message', sectionId);
      fd.append('message_id', String(messageId));
      return apiPost(fd);
    },

    // ---- AI Drafts ----

    async createDraft(conversationId: number | string): Promise<{ success: boolean; draft?: Draft }> {
      const fd = postData('create_draft', sectionId);
      fd.append('conversation_id', String(conversationId));
      return apiPost(fd);
    },

    async updateDraft(draftId: number, editedContent: string): Promise<ApiOk> {
      const fd = postData('update_draft', sectionId);
      fd.append('draft_id', String(draftId));
      fd.append('edited_content', editedContent);
      return apiPost(fd);
    },

    async sendDraft(draftId: number, conversationId: number | string): Promise<SendMessageResponse> {
      const fd = postData('send_draft', sectionId);
      fd.append('draft_id', String(draftId));
      fd.append('conversation_id', String(conversationId));
      return apiPost(fd);
    },

    async discardDraft(draftId: number): Promise<ApiOk> {
      const fd = postData('discard_draft', sectionId);
      fd.append('draft_id', String(draftId));
      return apiPost(fd);
    },

    // ---- Conversation initialization ----

    async initializeConversation(patientId: number): Promise<InitializeConversationResponse> {
      const fd = postData('initialize_conversation', sectionId);
      fd.append('patient_id', String(patientId));
      return apiPost(fd);
    },

    // ---- Conversation controls ----

    async toggleAI(conversationId: number | string, enabled: boolean): Promise<{ success: boolean; ai_enabled: boolean }> {
      const fd = postData('toggle_ai', sectionId);
      fd.append('conversation_id', String(conversationId));
      fd.append('enabled', enabled ? '1' : '0');
      return apiPost(fd);
    },

    async setRiskLevel(conversationId: number | string, riskLevel: string): Promise<ApiOk> {
      const fd = postData('set_risk', sectionId);
      fd.append('conversation_id', String(conversationId));
      fd.append('risk_level', riskLevel);
      return apiPost(fd);
    },

    async setStatus(conversationId: number | string, status: string): Promise<ApiOk> {
      const fd = postData('set_status', sectionId);
      fd.append('conversation_id', String(conversationId));
      fd.append('status', status);
      return apiPost(fd);
    },

    // ---- Notes ----

    async addNote(conversationId: number | string, content: string, noteType?: string): Promise<{ success: boolean; note_id: number }> {
      const fd = postData('add_note', sectionId);
      fd.append('conversation_id', String(conversationId));
      fd.append('content', content);
      if (noteType) fd.append('note_type', noteType);
      return apiPost(fd);
    },

    async getNotes(conversationId: number | string): Promise<{ notes: Note[] }> {
      return apiGet('get_notes', withSection({ conversation_id: String(conversationId) }, sectionId));
    },

    async editNote(noteId: number, content: string): Promise<ApiOk> {
      const fd = postData('edit_note', sectionId);
      fd.append('note_id', String(noteId));
      fd.append('content', content);
      return apiPost(fd);
    },

    async deleteNote(noteId: number): Promise<ApiOk> {
      const fd = postData('delete_note', sectionId);
      fd.append('note_id', String(noteId));
      return apiPost(fd);
    },

    // ---- Alerts ----

    async getAlerts(unreadOnly?: boolean): Promise<{ alerts: Alert[] }> {
      const p: Record<string, string> = {};
      if (unreadOnly) p.unread_only = '1';
      return apiGet('get_alerts', withSection(p, sectionId));
    },

    async markAlertRead(alertId: number): Promise<ApiOk> {
      const fd = postData('mark_alert_read', sectionId);
      fd.append('alert_id', String(alertId));
      return apiPost(fd);
    },

    async markAllAlertsRead(conversationId?: number | string): Promise<ApiOk> {
      const fd = postData('mark_all_read', sectionId);
      if (conversationId != null) fd.append('conversation_id', String(conversationId));
      return apiPost(fd);
    },

    // ---- Read receipts ----

    async markMessagesRead(conversationId: number | string): Promise<ApiOk> {
      const fd = postData('mark_messages_read', sectionId);
      fd.append('conversation_id', String(conversationId));
      return apiPost(fd);
    },

    async getUnreadCounts(): Promise<{ unread_counts: UnreadCounts }> {
      return apiGet('get_unread_counts', withSection({}, sectionId));
    },

    // ---- Stats & groups ----

    async getStats(): Promise<{ stats: DashboardStats }> {
      return apiGet('get_stats', withSection({}, sectionId));
    },

    async getGroups(): Promise<{ groups: TherapistGroup[] }> {
      return apiGet('get_groups', withSection({}, sectionId));
    },

    // ---- Export ----

    /**
     * Build a URL for CSV export download.
     * The browser navigates to this URL to trigger the file download.
     */
    getExportUrl(scope: 'patient' | 'group' | 'all', conversationId?: number | string | null, groupId?: number | string | null): string {
      const params: Record<string, string> = { scope };
      if (conversationId != null) params.conversation_id = String(conversationId);
      if (groupId != null) params.group_id = String(groupId);
      return buildUrl('export_csv', withSection(params, sectionId));
    },

    // ---- Lightweight polling ----

    async checkUpdates(): Promise<CheckUpdatesResponse> {
      return apiGet('check_updates', withSection({}, sectionId));
    },

    // ---- Summarization ----

    async generateSummary(conversationId: number | string): Promise<SummaryResponse> {
      const fd = postData('generate_summary', sectionId);
      fd.append('conversation_id', String(conversationId));
      return apiPost(fd);
    },
  };
}

export interface CheckUpdatesResponse {
  unread_messages: number;
  unread_alerts: number;
  latest_message_id: number | null;
}

export interface SummaryResponse {
  success: boolean;
  summary: string;
  summary_conversation_id: number | null;
  tokens_used: number | null;
}

// ---------------------------------------------------------------------------
// Simple OK response type
// ---------------------------------------------------------------------------

interface ApiOk {
  success: boolean;
}

// ---------------------------------------------------------------------------
// Initialize conversation response type
// ---------------------------------------------------------------------------

export interface InitializeConversationResponse {
  success: boolean;
  conversation: Conversation;
  already_exists: boolean;
  error?: string;
}

