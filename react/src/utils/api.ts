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
 * When `customBaseUrl` is set (embedded/floating context), use that instead of
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

/** Generic POST action helper to avoid repetitive fd.append boilerplate */
async function postAction<T>(
  action: string,
  sectionId: number | undefined,
  params: Record<string, string | number | boolean | null | undefined>,
  customBaseUrl?: string
): Promise<T> {
  const fd = postData(action, sectionId);
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null) continue;
    if (typeof value === 'boolean') {
      fd.append(key, value ? '1' : '0');
    } else {
      fd.append(key, String(value));
    }
  }
  return apiPost<T>(fd, customBaseUrl);
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
 * @param baseUrl   - Optional custom base URL for embedded/floating contexts
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
      return postAction<SendMessageResponse>(
        'send_message',
        sectionId,
        { message, conversation_id: conversationId },
        baseUrl
      );
    },

    async tagTherapist(conversationId: number | string, reason?: string, urgency?: string): Promise<TagTherapistResponse> {
      return postAction<TagTherapistResponse>(
        'tag_therapist',
        sectionId,
        { conversation_id: conversationId, reason, urgency },
        baseUrl
      );
    },

    async markMessagesRead(conversationId?: number | string): Promise<{ success: boolean; unread_count: number }> {
      return postAction<{ success: boolean; unread_count: number }>(
        'mark_messages_read',
        sectionId,
        { conversation_id: conversationId },
        baseUrl
      );
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
      return postAction<SendMessageResponse>('send_message', sectionId, {
        conversation_id: conversationId,
        message,
      });
    },

    async editMessage(messageId: number, newContent: string): Promise<ApiOk> {
      return postAction<ApiOk>('edit_message', sectionId, {
        message_id: messageId,
        content: newContent,
      });
    },

    async deleteMessage(messageId: number): Promise<ApiOk> {
      return postAction<ApiOk>('delete_message', sectionId, { message_id: messageId });
    },

    // ---- AI Drafts ----

    async createDraft(conversationId: number | string): Promise<{ success: boolean; draft?: Draft }> {
      return postAction<{ success: boolean; draft?: Draft }>('create_draft', sectionId, {
        conversation_id: conversationId,
      });
    },

    async updateDraft(draftId: number, editedContent: string): Promise<ApiOk> {
      return postAction<ApiOk>('update_draft', sectionId, {
        draft_id: draftId,
        edited_content: editedContent,
      });
    },

    async sendDraft(draftId: number, conversationId: number | string): Promise<SendMessageResponse> {
      return postAction<SendMessageResponse>('send_draft', sectionId, {
        draft_id: draftId,
        conversation_id: conversationId,
      });
    },

    async discardDraft(draftId: number): Promise<ApiOk> {
      return postAction<ApiOk>('discard_draft', sectionId, { draft_id: draftId });
    },

    // ---- Conversation initialization ----

    async initializeConversation(patientId: number): Promise<InitializeConversationResponse> {
      return postAction<InitializeConversationResponse>('initialize_conversation', sectionId, {
        patient_id: patientId,
      });
    },

    // ---- Conversation controls ----

    async toggleAI(conversationId: number | string, enabled: boolean): Promise<{ success: boolean; ai_enabled: boolean }> {
      return postAction<{ success: boolean; ai_enabled: boolean }>('toggle_ai', sectionId, {
        conversation_id: conversationId,
        enabled,
      });
    },

    async setRiskLevel(conversationId: number | string, riskLevel: string): Promise<ApiOk> {
      return postAction<ApiOk>('set_risk', sectionId, {
        conversation_id: conversationId,
        risk_level: riskLevel,
      });
    },

    // ---- Notes ----

    async addNote(conversationId: number | string, content: string, noteType?: string): Promise<{ success: boolean; note_id: number }> {
      return postAction<{ success: boolean; note_id: number }>('add_note', sectionId, {
        conversation_id: conversationId,
        content,
        note_type: noteType,
      });
    },

    async getNotes(conversationId: number | string): Promise<{ notes: Note[] }> {
      return apiGet('get_notes', withSection({ conversation_id: String(conversationId) }, sectionId));
    },

    async editNote(noteId: number, content: string): Promise<ApiOk> {
      return postAction<ApiOk>('edit_note', sectionId, {
        note_id: noteId,
        content,
      });
    },

    async deleteNote(noteId: number): Promise<ApiOk> {
      return postAction<ApiOk>('delete_note', sectionId, { note_id: noteId });
    },

    // ---- Alerts ----

    async getAlerts(unreadOnly?: boolean): Promise<{ alerts: Alert[] }> {
      const p: Record<string, string> = {};
      if (unreadOnly) p.unread_only = '1';
      return apiGet('get_alerts', withSection(p, sectionId));
    },

    async markAlertRead(alertId: number): Promise<ApiOk> {
      return postAction<ApiOk>('mark_alert_read', sectionId, { alert_id: alertId });
    },

    async markAllAlertsRead(conversationId?: number | string): Promise<ApiOk> {
      return postAction<ApiOk>('mark_all_read', sectionId, {
        conversation_id: conversationId,
      });
    },

    // ---- Read receipts ----

    async markMessagesRead(conversationId: number | string): Promise<ApiOk> {
      return postAction<ApiOk>('mark_messages_read', sectionId, {
        conversation_id: conversationId,
      });
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
      return postAction<SummaryResponse>('generate_summary', sectionId, {
        conversation_id: conversationId,
      });
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

