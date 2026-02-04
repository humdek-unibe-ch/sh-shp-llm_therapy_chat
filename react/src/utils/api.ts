/**
 * API Utility Functions for Therapy Chat
 * =======================================
 * 
 * Provides API communication layer for the Therapy Chat React components.
 * Uses the same endpoint strategy as the LLM Chat plugin:
 * - All requests go through the current page's controller
 * - Uses window.location for URL construction (security through SelfHelp's ACL)
 * 
 * @module utils/api
 */

import type {
  TherapyChatConfig,
  TherapistDashboardConfig,
  SendMessageResponse,
  GetMessagesResponse,
  GetConversationResponse,
  GetConversationsResponse,
  TagTherapistResponse,
  Alert,
  Tag,
  Note,
  DashboardStats,
  TherapistSuggestion,
  TagReason,
  UnreadCounts,
  MentionData
} from '../types';

// ============================================================================
// CONFIG API RESPONSE TYPES
// ============================================================================

interface GetConfigResponse {
  config?: TherapyChatConfig | TherapistDashboardConfig;
  error?: string;
}

// ============================================================================
// API REQUEST HELPERS
// ============================================================================

/**
 * Build URL with action parameter
 * Uses current window.location to maintain security context
 * 
 * @param action - The action to append as query parameter
 * @param extraParams - Additional query parameters
 * @returns URL string with action parameter
 */
function buildUrl(action: string, extraParams: Record<string, string> = {}): string {
  const url = new URL(window.location.href);
  url.searchParams.set('action', action);
  
  Object.entries(extraParams).forEach(([key, value]) => {
    url.searchParams.set(key, value);
  });
  
  return url.toString();
}

/**
 * Make a GET request to the controller
 *
 * @param action - The action to perform
 * @param params - Additional query parameters
 * @returns Promise resolving to JSON response
 */
async function apiGet<T>(action: string, params: Record<string, string> = {}): Promise<T> {
  const url = buildUrl(action, params);

  const response = await fetch(url, {
    method: 'GET',
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    },
    credentials: 'same-origin'
  });

  let data;
  try {
    data = await response.json();
  } catch (e) {
    // If we can't parse JSON, fall back to status-based error
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    throw e;
  }

  if (!response.ok) {
    // If the response contains an error message, use it
    if (data && typeof data === 'object' && 'error' in data) {
      throw new Error(data.error);
    }
    // Otherwise use the HTTP status
    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
  }

  return data;
}

/**
 * Make a POST request to the controller
 * Supports FormData payloads
 *
 * @param formData - FormData object with request data
 * @returns Promise resolving to JSON response
 */
async function apiPost<T>(formData: FormData): Promise<T> {
  const response = await fetch(window.location.pathname, {
    method: 'POST',
    body: formData,
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    },
    credentials: 'same-origin'
  });

  let data;
  try {
    data = await response.json();
  } catch (e) {
    // If we can't parse JSON, fall back to status-based error
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    throw e;
  }

  if (!response.ok) {
    // If the response contains an error message, use it
    if (data && typeof data === 'object' && 'error' in data) {
      throw new Error(data.error);
    }
    // Otherwise use the HTTP status
    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
  }

  return data;
}

// ============================================================================
// COMPATIBILITY FUNCTION
// ============================================================================

/**
 * Placeholder for compatibility
 * Not used - we use window.location directly
 * @deprecated This function does nothing and is kept for backward compatibility
 */
export function setApiBaseUrl(_baseUrl: string): void {
  // Not used - we use window.location directly
}

// ============================================================================
// CONFIG API
// ============================================================================

/**
 * Create config API with section ID support
 * Each chat instance should use its own API instance with its section ID
 * 
 * @param sectionId - The section ID for this chat instance
 */
export function createConfigApi(sectionId?: number) {
  return {
    /**
     * Load chat configuration for the current user and section
     * Calls: ?action=get_config&section_id={sectionId}
     *
     * @returns Promise resolving to TherapyChatConfig or TherapistDashboardConfig
     */
    async get(): Promise<TherapyChatConfig | TherapistDashboardConfig> {
      const params: Record<string, string> = {};
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      const response = await apiGet<GetConfigResponse>('get_config', params);

      if (response.error) {
        throw new Error(response.error);
      }

      if (!response.config) {
        throw new Error('No configuration returned');
      }

      return response.config;
    }
  };
}

/**
 * Config API namespace (backward compatible)
 * Maintains old signature where sectionId is passed as first parameter
 * @deprecated Use createConfigApi(sectionId) for section-isolated instances
 */
export const configApi = {
  get: (sectionId?: number) => {
    const api = createConfigApi(sectionId);
    return api.get();
  }
};

// ============================================================================
// THERAPY CHAT API (Subject Interface)
// ============================================================================

/**
 * Create therapy chat API with section ID support
 * Each chat instance should use its own API instance with its section ID
 * 
 * @param sectionId - The section ID for this chat instance
 */
export function createTherapyChatApi(sectionId?: number) {
  return {
    /**
     * Get conversation with messages
     * Calls: ?action=get_conversation&conversation_id=XXX&section_id=YYY
     * 
     * @param conversationId - Conversation ID (optional)
     * @returns Promise resolving to conversation and messages
     */
    async getConversation(conversationId?: number | string): Promise<GetConversationResponse> {
      const params: Record<string, string> = {};
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      if (conversationId !== undefined) {
        params.conversation_id = String(conversationId);
      }
      const response = await apiGet<GetConversationResponse>('get_conversation', params);
      
      if (response.error) {
        throw new Error(response.error);
      }
      
      return response;
    },

    /**
     * Get messages (for polling)
     * Calls: ?action=get_messages&conversation_id=XXX&after_id=YYY&section_id=ZZZ
     * 
     * @param conversationId - Conversation ID (optional)
     * @param afterId - Get messages after this ID (optional)
     * @returns Promise resolving to messages
     */
    async getMessages(conversationId?: number | string, afterId?: number): Promise<GetMessagesResponse> {
      const params: Record<string, string> = {};
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      if (conversationId !== undefined) {
        params.conversation_id = String(conversationId);
      }
      if (afterId !== undefined) {
        params.after_id = String(afterId);
      }
      const response = await apiGet<GetMessagesResponse>('get_messages', params);
      
      if (response.error) {
        throw new Error(response.error);
      }
      
      return response;
    },

    /**
     * Send message
     * Calls: POST action=send_message
     * 
     * @param conversationId - Conversation ID (optional, creates new if not provided)
     * @param message - Message content
     * @returns Promise resolving to send result
     */
    async sendMessage(
      conversationId: number | string | undefined,
      message: string
    ): Promise<SendMessageResponse> {
      const formData = new FormData();
      formData.append('action', 'send_message');
      formData.append('message', message);
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }
      if (conversationId !== undefined) {
        formData.append('conversation_id', String(conversationId));
      }

      return apiPost<SendMessageResponse>(formData);
    },

    /**
     * Tag therapist
     * Calls: POST action=tag_therapist
     * 
     * @param conversationId - Conversation ID
     * @param reason - Optional reason for tagging
     * @param urgency - Optional urgency level
     * @param therapistId - Optional specific therapist ID to tag
     * @returns Promise resolving to tag result
     */
    async tagTherapist(
      conversationId: number | string,
      reason?: string,
      urgency?: string,
      therapistId?: number
    ): Promise<TagTherapistResponse> {
      const formData = new FormData();
      formData.append('action', 'tag_therapist');
      formData.append('conversation_id', String(conversationId));
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }
      if (reason) {
        formData.append('reason', reason);
      }
      if (urgency) {
        formData.append('urgency', urgency);
      }
      if (therapistId) {
        formData.append('therapist_id', String(therapistId));
      }

      return apiPost<TagTherapistResponse>(formData);
    },

    /**
     * Get therapists available for tagging in current group
     * Calls: ?action=get_therapists&section_id=XXX
     * 
     * @returns Promise resolving to list of therapists
     */
    async getTherapists(): Promise<{ therapists: TherapistSuggestion[] }> {
      const params: Record<string, string> = {};
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      return apiGet<{ therapists: TherapistSuggestion[] }>('get_therapists', params);
    },

    /**
     * Get tag reasons configured for this chat
     * Calls: ?action=get_tag_reasons&section_id=XXX
     * 
     * @returns Promise resolving to list of tag reasons
     */
    async getTagReasons(): Promise<{ tag_reasons: TagReason[] }> {
      const params: Record<string, string> = {};
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      return apiGet<{ tag_reasons: TagReason[] }>('get_tag_reasons', params);
    },

    /**
     * Send message with mention data
     * Calls: POST action=send_message with mentions
     * 
     * @param conversationId - Conversation ID
     * @param message - Message content
     * @param mentions - Mention data (therapists and topics)
     * @returns Promise resolving to send result
     */
    async sendMessageWithMentions(
      conversationId: number | string | undefined,
      message: string,
      mentions?: MentionData
    ): Promise<SendMessageResponse> {
      const formData = new FormData();
      formData.append('action', 'send_message');
      formData.append('message', message);
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }
      if (conversationId !== undefined) {
        formData.append('conversation_id', String(conversationId));
      }
      if (mentions) {
        formData.append('mentions', JSON.stringify(mentions));
      }

      return apiPost<SendMessageResponse>(formData);
    }
  };
}

/**
 * Therapy Chat API namespace (backward compatible)
 * Maintains old signature where sectionId is passed as first parameter
 * @deprecated Use createTherapyChatApi(sectionId) for section-isolated instances
 */
export const therapyChatApi = {
  getConfig: (sectionId: number): Promise<TherapyChatConfig> => {
    const api = createConfigApi(sectionId);
    return api.get() as Promise<TherapyChatConfig>;
  },
  getConversation: (sectionId: number, conversationId?: number | string) => {
    const api = createTherapyChatApi(sectionId);
    return api.getConversation(conversationId);
  },
  getMessages: (sectionId: number, conversationId?: number | string, afterId?: number) => {
    const api = createTherapyChatApi(sectionId);
    return api.getMessages(conversationId, afterId);
  },
  sendMessage: (sectionId: number, conversationId: number | string | undefined, message: string) => {
    const api = createTherapyChatApi(sectionId);
    return api.sendMessage(conversationId, message);
  },
  sendMessageWithMentions: (sectionId: number, conversationId: number | string | undefined, message: string, mentions?: MentionData) => {
    const api = createTherapyChatApi(sectionId);
    return api.sendMessageWithMentions(conversationId, message, mentions);
  },
  tagTherapist: (sectionId: number, conversationId: number | string, reason?: string, urgency?: string, therapistId?: number) => {
    const api = createTherapyChatApi(sectionId);
    return api.tagTherapist(conversationId, reason, urgency, therapistId);
  },
  getTherapists: (sectionId: number) => {
    const api = createTherapyChatApi(sectionId);
    return api.getTherapists();
  },
  getTagReasons: (sectionId: number) => {
    const api = createTherapyChatApi(sectionId);
    return api.getTagReasons();
  }
};

// ============================================================================
// THERAPIST DASHBOARD API
// ============================================================================

/**
 * Create therapist dashboard API with section ID support
 * Each dashboard instance should use its own API instance with its section ID
 * 
 * @param sectionId - The section ID for this dashboard instance
 */
export function createTherapistDashboardApi(sectionId?: number) {
  return {
    /**
     * Get all conversations
     * Calls: ?action=get_conversations&section_id=XXX&filters...
     * 
     * @param filters - Optional filters (status, risk_level, group_id)
     * @returns Promise resolving to conversations
     */
    async getConversations(filters?: Record<string, string | number>): Promise<GetConversationsResponse> {
      const params: Record<string, string> = {};
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      if (filters) {
        Object.entries(filters).forEach(([key, value]) => {
          if (value !== undefined) {
            params[key] = String(value);
          }
        });
      }
      const response = await apiGet<GetConversationsResponse>('get_conversations', params);
      
      if (response.error) {
        throw new Error(response.error);
      }
      
      return response;
    },

    /**
     * Get conversation with details
     * Calls: ?action=get_conversation&conversation_id=XXX&section_id=YYY
     * 
     * @param conversationId - Conversation ID
     * @returns Promise resolving to conversation with messages, notes, tags, alerts
     */
    async getConversation(conversationId: number | string): Promise<GetConversationResponse> {
      const params: Record<string, string> = { conversation_id: String(conversationId) };
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      const response = await apiGet<GetConversationResponse>('get_conversation', params);
      
      if (response.error) {
        throw new Error(response.error);
      }
      
      return response;
    },

    /**
     * Get messages (for polling)
     * Calls: ?action=get_messages&conversation_id=XXX&after_id=YYY&section_id=ZZZ
     * 
     * @param conversationId - Conversation ID
     * @param afterId - Get messages after this ID (optional)
     * @returns Promise resolving to messages
     */
    async getMessages(conversationId: number | string, afterId?: number): Promise<GetMessagesResponse> {
      const params: Record<string, string> = { conversation_id: String(conversationId) };
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      if (afterId !== undefined) {
        params.after_id = String(afterId);
      }
      const response = await apiGet<GetMessagesResponse>('get_messages', params);
      
      if (response.error) {
        throw new Error(response.error);
      }
      
      return response;
    },

    /**
     * Send message
     * Calls: POST action=send_message
     * 
     * @param conversationId - Conversation ID
     * @param message - Message content
     * @returns Promise resolving to send result
     */
    async sendMessage(
      conversationId: number | string,
      message: string
    ): Promise<SendMessageResponse> {
      const formData = new FormData();
      formData.append('action', 'send_message');
      formData.append('conversation_id', String(conversationId));
      formData.append('message', message);
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }

      return apiPost<SendMessageResponse>(formData);
    },

    /**
     * Toggle AI
     * Calls: POST action=toggle_ai
     * 
     * @param conversationId - Conversation ID
     * @param enabled - Whether AI is enabled
     * @returns Promise resolving to toggle result
     */
    async toggleAI(
      conversationId: number | string,
      enabled: boolean
    ): Promise<{ success: boolean; ai_enabled: boolean }> {
      const formData = new FormData();
      formData.append('action', 'toggle_ai');
      formData.append('conversation_id', String(conversationId));
      formData.append('enabled', enabled ? '1' : '0');
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }

      return apiPost<{ success: boolean; ai_enabled: boolean }>(formData);
    },

    /**
     * Set risk level
     * Calls: POST action=set_risk
     * 
     * @param conversationId - Conversation ID
     * @param riskLevel - Risk level (low, medium, high, critical)
     * @returns Promise resolving to set result
     */
    async setRiskLevel(
      conversationId: number | string,
      riskLevel: string
    ): Promise<{ success: boolean; risk_level: string }> {
      const formData = new FormData();
      formData.append('action', 'set_risk');
      formData.append('conversation_id', String(conversationId));
      formData.append('risk_level', riskLevel);
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }

      return apiPost<{ success: boolean; risk_level: string }>(formData);
    },

    /**
     * Set conversation status
     * Calls: POST action=set_status
     * 
     * @param conversationId - Conversation ID
     * @param status - Status (active, paused, closed)
     * @returns Promise resolving to set result
     */
    async setStatus(
      conversationId: number | string,
      status: string
    ): Promise<{ success: boolean; status: string }> {
      const formData = new FormData();
      formData.append('action', 'set_status');
      formData.append('conversation_id', String(conversationId));
      formData.append('status', status);
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }

      return apiPost<{ success: boolean; status: string }>(formData);
    },

    /**
     * Add note
     * Calls: POST action=add_note
     * 
     * @param conversationId - Conversation ID
     * @param content - Note content
     * @returns Promise resolving to add result
     */
    async addNote(
      conversationId: number | string,
      content: string
    ): Promise<{ success: boolean; note_id: number }> {
      const formData = new FormData();
      formData.append('action', 'add_note');
      formData.append('conversation_id', String(conversationId));
      formData.append('content', content);
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }

      return apiPost<{ success: boolean; note_id: number }>(formData);
    },

    /**
     * Acknowledge tag
     * Calls: POST action=acknowledge_tag
     * 
     * @param tagId - Tag ID
     * @returns Promise resolving to acknowledge result
     */
    async acknowledgeTag(tagId: number): Promise<{ success: boolean }> {
      const formData = new FormData();
      formData.append('action', 'acknowledge_tag');
      formData.append('tag_id', String(tagId));
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }

      return apiPost<{ success: boolean }>(formData);
    },

    /**
     * Mark alert as read
     * Calls: POST action=mark_alert_read
     * 
     * @param alertId - Alert ID
     * @returns Promise resolving to mark result
     */
    async markAlertRead(alertId: number): Promise<{ success: boolean }> {
      const formData = new FormData();
      formData.append('action', 'mark_alert_read');
      formData.append('alert_id', String(alertId));
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }

      return apiPost<{ success: boolean }>(formData);
    },

    /**
     * Mark all alerts as read
     * Calls: POST action=mark_all_read
     * 
     * @param conversationId - Conversation ID (optional)
     * @returns Promise resolving to mark result
     */
    async markAllRead(conversationId?: number | string): Promise<{ success: boolean }> {
      const formData = new FormData();
      formData.append('action', 'mark_all_read');
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }
      if (conversationId !== undefined) {
        formData.append('conversation_id', String(conversationId));
      }

      return apiPost<{ success: boolean }>(formData);
    },

    /**
     * Get alerts
     * Calls: ?action=get_alerts&section_id=XXX&unread_only=YYY
     * 
     * @param unreadOnly - Only get unread alerts (optional)
     * @returns Promise resolving to alerts
     */
    async getAlerts(unreadOnly?: boolean): Promise<{ alerts: Alert[] }> {
      const params: Record<string, string> = {};
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      if (unreadOnly !== undefined) {
        params.unread_only = unreadOnly ? '1' : '0';
      }
      return apiGet<{ alerts: Alert[] }>('get_alerts', params);
    },

    /**
     * Get pending tags
     * Calls: ?action=get_tags&section_id=XXX
     * 
     * @returns Promise resolving to tags
     */
    async getTags(): Promise<{ tags: Tag[] }> {
      const params: Record<string, string> = {};
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      return apiGet<{ tags: Tag[] }>('get_tags', params);
    },

    /**
     * Get stats
     * Calls: ?action=get_stats&section_id=XXX
     * 
     * @returns Promise resolving to dashboard stats
     */
    async getStats(): Promise<{ stats: DashboardStats }> {
      const params: Record<string, string> = {};
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      return apiGet<{ stats: DashboardStats }>('get_stats', params);
    },

    /**
     * Get notes for conversation
     * Calls: ?action=get_notes&conversation_id=XXX&section_id=YYY
     * 
     * @param conversationId - Conversation ID
     * @returns Promise resolving to notes
     */
    async getNotes(conversationId: number | string): Promise<{ notes: Note[] }> {
      const params: Record<string, string> = { conversation_id: String(conversationId) };
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      return apiGet<{ notes: Note[] }>('get_notes', params);
    },

    /**
     * Get unread message counts per subject
     * Calls: ?action=get_unread_counts&section_id=XXX
     * 
     * @returns Promise resolving to unread counts by subject
     */
    async getUnreadCounts(): Promise<{ unread_counts: UnreadCounts }> {
      const params: Record<string, string> = {};
      if (sectionId !== undefined) {
        params.section_id = String(sectionId);
      }
      return apiGet<{ unread_counts: UnreadCounts }>('get_unread_counts', params);
    },

    /**
     * Mark conversation messages as read
     * Calls: POST action=mark_messages_read
     * 
     * @param conversationId - Conversation ID
     * @returns Promise resolving to success
     */
    async markMessagesRead(conversationId: number | string): Promise<{ success: boolean }> {
      const formData = new FormData();
      formData.append('action', 'mark_messages_read');
      formData.append('conversation_id', String(conversationId));
      if (sectionId !== undefined) {
        formData.append('section_id', String(sectionId));
      }
      return apiPost<{ success: boolean }>(formData);
    }
  };
}

/**
 * Therapist Dashboard API namespace (backward compatible)
 * Maintains old signature where sectionId is passed as first parameter
 * @deprecated Use createTherapistDashboardApi(sectionId) for section-isolated instances
 */
export const therapistDashboardApi = {
  getConfig: (sectionId: number): Promise<TherapistDashboardConfig> => {
    const api = createConfigApi(sectionId);
    return api.get() as Promise<TherapistDashboardConfig>;
  },
  getConversations: (sectionId: number, filters?: Record<string, string | number>) => {
    const api = createTherapistDashboardApi(sectionId);
    return api.getConversations(filters);
  },
  getConversation: (sectionId: number, conversationId?: number | string) => {
    const api = createTherapistDashboardApi(sectionId);
    if (conversationId === undefined) {
      throw new Error('Conversation ID is required for therapist dashboard');
    }
    return api.getConversation(conversationId);
  },
  getMessages: (sectionId: number, conversationId?: number | string, afterId?: number) => {
    const api = createTherapistDashboardApi(sectionId);
    if (conversationId === undefined) {
      throw new Error('Conversation ID is required for therapist dashboard');
    }
    return api.getMessages(conversationId, afterId);
  },
  sendMessage: (sectionId: number, conversationId?: number | string, message?: string) => {
    const api = createTherapistDashboardApi(sectionId);
    if (conversationId === undefined) {
      throw new Error('Conversation ID is required for therapist dashboard');
    }
    if (!message) {
      throw new Error('Message is required');
    }
    return api.sendMessage(conversationId, message);
  },
  toggleAI: (sectionId: number, conversationId: number | string, enabled: boolean) => {
    const api = createTherapistDashboardApi(sectionId);
    return api.toggleAI(conversationId, enabled);
  },
  setRiskLevel: (sectionId: number, conversationId: number | string, riskLevel: string) => {
    const api = createTherapistDashboardApi(sectionId);
    return api.setRiskLevel(conversationId, riskLevel);
  },
  setStatus: (sectionId: number, conversationId: number | string, status: string) => {
    const api = createTherapistDashboardApi(sectionId);
    return api.setStatus(conversationId, status);
  },
  addNote: (sectionId: number, conversationId: number | string, content: string) => {
    const api = createTherapistDashboardApi(sectionId);
    return api.addNote(conversationId, content);
  },
  acknowledgeTag: (sectionId: number, tagId: number) => {
    const api = createTherapistDashboardApi(sectionId);
    return api.acknowledgeTag(tagId);
  },
  markAlertRead: (sectionId: number, alertId: number) => {
    const api = createTherapistDashboardApi(sectionId);
    return api.markAlertRead(alertId);
  },
  markAllRead: (sectionId: number, conversationId?: number | string) => {
    const api = createTherapistDashboardApi(sectionId);
    return api.markAllRead(conversationId);
  },
  getAlerts: (sectionId: number, unreadOnly?: boolean) => {
    const api = createTherapistDashboardApi(sectionId);
    return api.getAlerts(unreadOnly);
  },
  getTags: (sectionId: number) => {
    const api = createTherapistDashboardApi(sectionId);
    return api.getTags();
  },
  getStats: (sectionId: number) => {
    const api = createTherapistDashboardApi(sectionId);
    return api.getStats();
  },
  getNotes: (sectionId: number, conversationId: number | string) => {
    const api = createTherapistDashboardApi(sectionId);
    return api.getNotes(conversationId);
  },
  getUnreadCounts: (sectionId: number) => {
    const api = createTherapistDashboardApi(sectionId);
    return api.getUnreadCounts();
  },
  markMessagesRead: (sectionId: number, conversationId: number | string) => {
    const api = createTherapistDashboardApi(sectionId);
    return api.markMessagesRead(conversationId);
  }
};

// ============================================================================
// ERROR HANDLING
// ============================================================================

/**
 * Handle API errors and extract user-friendly message
 * 
 * @param error - The error object
 * @returns User-friendly error message
 */
export function handleApiError(error: unknown): string {
  if (error instanceof Error) {
    return error.message;
  }
  
  if (typeof error === 'string') {
    return error;
  }
  
  if (error && typeof error === 'object') {
    const errorObj = error as Record<string, unknown>;
    if (typeof errorObj.error === 'string') {
      return errorObj.error;
    }
    if (typeof errorObj.message === 'string') {
      return errorObj.message;
    }
  }
  
  return 'An unexpected error occurred';
}
