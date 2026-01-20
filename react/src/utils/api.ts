/**
 * API Utilities for Therapy Chat
 * ===============================
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
  DashboardStats
} from '../types';

/**
 * Build API URL with section ID
 */
function buildUrl(sectionId: number, action: string, params: Record<string, string | number | undefined> = {}): string {
  const baseUrl = `/index.php`;
  const searchParams = new URLSearchParams();
  
  searchParams.set('section_id', String(sectionId));
  searchParams.set('action', action);
  
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined) {
      searchParams.set(key, String(value));
    }
  });
  
  return `${baseUrl}?${searchParams.toString()}`;
}

/**
 * Make API request
 */
async function request<T>(
  url: string,
  options: RequestInit = {}
): Promise<T> {
  const response = await fetch(url, {
    ...options,
    headers: {
      'Accept': 'application/json',
      ...options.headers,
    },
  });

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}));
    throw new Error(errorData.error || `HTTP error ${response.status}`);
  }

  return response.json();
}

/**
 * Make POST request with form data
 */
async function postForm<T>(
  url: string,
  data: Record<string, string | number | boolean | undefined>
): Promise<T> {
  const formData = new FormData();
  
  Object.entries(data).forEach(([key, value]) => {
    if (value !== undefined) {
      formData.append(key, String(value));
    }
  });

  return request<T>(url, {
    method: 'POST',
    body: formData,
  });
}

/**
 * TherapyChat API (Subject Interface)
 */
export const therapyChatApi = {
  /**
   * Get configuration
   */
  getConfig: (sectionId: number): Promise<{ config: TherapyChatConfig }> => {
    return request(buildUrl(sectionId, 'get_config'));
  },

  /**
   * Get conversation with messages
   */
  getConversation: (sectionId: number, conversationId?: number | string): Promise<GetConversationResponse> => {
    return request(buildUrl(sectionId, 'get_conversation', { conversation_id: conversationId }));
  },

  /**
   * Get messages (for polling)
   */
  getMessages: (sectionId: number, conversationId?: number | string, afterId?: number): Promise<GetMessagesResponse> => {
    return request(buildUrl(sectionId, 'get_messages', { conversation_id: conversationId, after_id: afterId }));
  },

  /**
   * Send message
   */
  sendMessage: (sectionId: number, conversationId: number | string | undefined, message: string): Promise<SendMessageResponse> => {
    return postForm(buildUrl(sectionId, 'send_message'), {
      section_id: sectionId,
      action: 'send_message',
      conversation_id: conversationId,
      message,
    });
  },

  /**
   * Tag therapist
   */
  tagTherapist: (sectionId: number, conversationId: number | string, reason?: string, urgency?: string): Promise<TagTherapistResponse> => {
    return postForm(buildUrl(sectionId, 'tag_therapist'), {
      section_id: sectionId,
      action: 'tag_therapist',
      conversation_id: conversationId,
      reason,
      urgency,
    });
  },
};

/**
 * TherapistDashboard API
 */
export const therapistDashboardApi = {
  /**
   * Get configuration
   */
  getConfig: (sectionId: number): Promise<{ config: TherapistDashboardConfig }> => {
    return request(buildUrl(sectionId, 'get_config'));
  },

  /**
   * Get all conversations
   */
  getConversations: (sectionId: number, filters?: Record<string, string | number>): Promise<GetConversationsResponse> => {
    return request(buildUrl(sectionId, 'get_conversations', filters));
  },

  /**
   * Get conversation with details
   */
  getConversation: (sectionId: number, conversationId?: number | string): Promise<GetConversationResponse> => {
    return request(buildUrl(sectionId, 'get_conversation', { conversation_id: conversationId }));
  },

  /**
   * Get messages (for polling)
   */
  getMessages: (sectionId: number, conversationId?: number | string, afterId?: number): Promise<GetMessagesResponse> => {
    return request(buildUrl(sectionId, 'get_messages', { conversation_id: conversationId, after_id: afterId }));
  },

  /**
   * Send message
   */
  sendMessage: (sectionId: number, conversationId?: number | string, message?: string): Promise<SendMessageResponse> => {
    return postForm(buildUrl(sectionId, 'send_message'), {
      section_id: sectionId,
      action: 'send_message',
      conversation_id: conversationId,
      message,
    });
  },

  /**
   * Toggle AI
   */
  toggleAI: (sectionId: number, conversationId: number | string, enabled: boolean): Promise<{ success: boolean; ai_enabled: boolean }> => {
    return postForm(buildUrl(sectionId, 'toggle_ai'), {
      section_id: sectionId,
      action: 'toggle_ai',
      conversation_id: conversationId,
      enabled,
    });
  },

  /**
   * Set risk level
   */
  setRiskLevel: (sectionId: number, conversationId: number | string, riskLevel: string): Promise<{ success: boolean; risk_level: string }> => {
    return postForm(buildUrl(sectionId, 'set_risk'), {
      section_id: sectionId,
      action: 'set_risk',
      conversation_id: conversationId,
      risk_level: riskLevel,
    });
  },

  /**
   * Set conversation status
   */
  setStatus: (sectionId: number, conversationId: number | string, status: string): Promise<{ success: boolean; status: string }> => {
    return postForm(buildUrl(sectionId, 'set_status'), {
      section_id: sectionId,
      action: 'set_status',
      conversation_id: conversationId,
      status,
    });
  },

  /**
   * Add note
   */
  addNote: (sectionId: number, conversationId: number | string, content: string): Promise<{ success: boolean; note_id: number }> => {
    return postForm(buildUrl(sectionId, 'add_note'), {
      section_id: sectionId,
      action: 'add_note',
      conversation_id: conversationId,
      content,
    });
  },

  /**
   * Acknowledge tag
   */
  acknowledgeTag: (sectionId: number, tagId: number): Promise<{ success: boolean }> => {
    return postForm(buildUrl(sectionId, 'acknowledge_tag'), {
      section_id: sectionId,
      action: 'acknowledge_tag',
      tag_id: tagId,
    });
  },

  /**
   * Mark alert as read
   */
  markAlertRead: (sectionId: number, alertId: number): Promise<{ success: boolean }> => {
    return postForm(buildUrl(sectionId, 'mark_alert_read'), {
      section_id: sectionId,
      action: 'mark_alert_read',
      alert_id: alertId,
    });
  },

  /**
   * Mark all alerts as read
   */
  markAllRead: (sectionId: number, conversationId?: number | string): Promise<{ success: boolean }> => {
    return postForm(buildUrl(sectionId, 'mark_all_read'), {
      section_id: sectionId,
      action: 'mark_all_read',
      conversation_id: conversationId,
    });
  },

  /**
   * Get alerts
   */
  getAlerts: (sectionId: number, unreadOnly?: boolean): Promise<{ alerts: Alert[] }> => {
    return request(buildUrl(sectionId, 'get_alerts', { unread_only: unreadOnly ? 1 : 0 }));
  },

  /**
   * Get pending tags
   */
  getTags: (sectionId: number): Promise<{ tags: Tag[] }> => {
    return request(buildUrl(sectionId, 'get_tags'));
  },

  /**
   * Get stats
   */
  getStats: (sectionId: number): Promise<{ stats: DashboardStats }> => {
    return request(buildUrl(sectionId, 'get_stats'));
  },

  /**
   * Get notes for conversation
   */
  getNotes: (sectionId: number, conversationId: number | string): Promise<{ notes: Note[] }> => {
    return request(buildUrl(sectionId, 'get_notes', { conversation_id: conversationId }));
  },
};
