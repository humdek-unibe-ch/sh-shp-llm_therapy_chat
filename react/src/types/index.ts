/**
 * TypeScript Types for Therapy Chat
 * ==================================
 */

// Message sender types
export type SenderType = 'subject' | 'therapist' | 'ai' | 'system';

// Risk levels
export type RiskLevel = 'low' | 'medium' | 'high' | 'critical';

// Conversation modes
export type ConversationMode = 'ai_hybrid' | 'human_only';

// Conversation status
export type ConversationStatus = 'active' | 'paused' | 'closed';

// Tag urgency
export type TagUrgency = 'normal' | 'urgent' | 'emergency';

// Alert types
export type AlertType = 'danger_detected' | 'tag_received' | 'high_activity' | 'inactivity' | 'new_message';

// Alert severity
export type AlertSeverity = 'info' | 'warning' | 'critical' | 'emergency';

/**
 * Message interface
 */
export interface Message {
  id: number | string;
  role: 'user' | 'assistant' | 'system';
  content: string;
  sender_type?: SenderType;
  sender_id?: number;
  sender_name?: string;
  label?: string;
  timestamp: string;
  tags?: Tag[];
  attachments?: Attachment[];
}

/**
 * Attachment interface
 */
export interface Attachment {
  id: number;
  name: string;
  type: string;
  size: number;
  url: string;
}

/**
 * Conversation interface
 */
export interface Conversation {
  id: number | string;
  title?: string;
  mode: ConversationMode;
  ai_enabled: boolean;
  status: ConversationStatus;
  risk_level: RiskLevel;
  model?: string;
  created_at: string;
  last_activity?: string;
  // Subject info (for therapist view)
  id_users?: number;
  subject_name?: string;
  subject_code?: string;
  // Therapist info
  id_therapist?: number;
  therapist_name?: string;
  // Stats
  message_count?: number;
  unread_count?: number;
  unread_alerts?: number;
  pending_tags?: number;
}

/**
 * Tag interface
 */
export interface Tag {
  id: number;
  id_llmMessages: number;
  id_users: number;
  tag_reason?: string;
  urgency: TagUrgency;
  acknowledged: boolean;
  acknowledged_at?: string;
  created_at: string;
  tagged_name?: string;
  message_content?: string;
  message_time?: string;
  conversation_id?: number;
  subject_name?: string;
  subject_code?: string;
}

/**
 * Alert interface
 */
export interface Alert {
  id: number;
  id_llmConversations: number;
  id_users?: number;
  alert_type: AlertType;
  severity: AlertSeverity;
  message: string;
  metadata?: Record<string, unknown>;
  is_read: boolean;
  read_at?: string;
  created_at: string;
  conversation_title?: string;
  subject_name?: string;
  subject_code?: string;
}

/**
 * Note interface
 */
export interface Note {
  id: number;
  id_llmConversations: number;
  id_users: number;
  content: string;
  author_name?: string;
  created_at: string;
}

/**
 * Tag reason (predefined)
 */
export interface TagReason {
  code: string;
  label: string;
  urgency: TagUrgency;
}

/**
 * Mention suggestion for @mentions
 */
export interface MentionSuggestion {
  id: string | number;
  display: string;
  type?: 'therapist' | 'topic';
}

/**
 * Therapist suggestion for @mentions
 */
export interface TherapistSuggestion {
  id: number;
  display: string;
  name: string;
  email?: string;
  role?: string;
}

/**
 * Topic suggestion for #hashtag mentions
 */
export interface TopicSuggestion {
  id: string;
  display: string;
  code: string;
  urgency?: TagUrgency;
  description?: string;
}

/**
 * Topic for #hashtag mentions
 */
export interface Topic {
  id: string;
  name: string;
  description?: string;
}

/**
 * Mention data extracted from message
 */
export interface MentionData {
  therapists: Array<{ id: string | number; display: string }>;
  topics: Array<{ id: string | number; display: string; code?: string; urgency?: string }>;
}

/**
 * Unread message counts per subject
 */
export interface UnreadCounts {
  total: number;
  bySubject: Record<number | string, {
    subjectId: number | string;
    subjectName: string;
    subjectCode?: string;
    unreadCount: number;
    lastMessageAt?: string;
  }>;
}

/**
 * Dashboard stats
 */
export interface DashboardStats {
  total: number;
  active: number;
  paused: number;
  closed: number;
  risk_low: number;
  risk_medium: number;
  risk_high: number;
  risk_critical: number;
  unread_alerts: number;
  pending_tags: number;
}

/**
 * UI Labels for TherapyChat
 */
export interface TherapyChatLabels {
  ai_label: string;
  therapist_label: string;
  tag_button_label: string;
  tag_reasons: TagReason[];
  empty_message: string;
  ai_thinking: string;
  mode_ai: string;
  mode_human: string;
  send_button: string;
  placeholder: string;
  loading: string;
}

/**
 * UI Labels for TherapistDashboard
 */
export interface TherapistDashboardLabels {
  // Headings
  title: string;
  conversationsHeading: string;
  alertsHeading: string;
  notesHeading: string;
  statsHeading: string;
  riskHeading: string;
  
  // Empty states
  noConversations: string;
  noAlerts: string;
  selectConversation: string;
  
  // Input labels
  sendPlaceholder: string;
  sendButton: string;
  addNotePlaceholder: string;
  addNoteButton: string;
  loading: string;
  
  // Message labels
  aiLabel: string;
  therapistLabel: string;
  subjectLabel: string;
  
  // Risk labels
  riskLow: string;
  riskMedium: string;
  riskHigh: string;
  riskCritical: string;
  
  // Status labels
  statusActive: string;
  statusPaused: string;
  statusClosed: string;
  
  // AI control labels
  disableAI: string;
  enableAI: string;
  aiModeIndicator: string;
  humanModeIndicator: string;
  
  // Action buttons
  acknowledge: string;
  dismiss: string;
  viewInLlm: string;
  joinConversation: string;
  leaveConversation: string;
  
  // Statistics labels
  statPatients: string;
  statActive: string;
  statCritical: string;
  statAlerts: string;
  statTags: string;
  
  // Filter labels
  filterAll: string;
  filterActive: string;
  filterCritical: string;
  filterUnread: string;
  filterTagged: string;
  
  // Intervention messages
  interventionMessage: string;
  aiPausedNotice: string;
  aiResumedNotice: string;
}

/**
 * Feature toggles for TherapistDashboard
 */
export interface TherapistDashboardFeatures {
  showRiskColumn: boolean;
  showStatusColumn: boolean;
  showAlertsPanel: boolean;
  showNotesPanel: boolean;
  showStatsHeader: boolean;
  enableAiToggle: boolean;
  enableRiskControl: boolean;
  enableStatusControl: boolean;
  enableNotes: boolean;
  enableInvisibleMode: boolean;
}

/**
 * Notification settings for TherapistDashboard
 */
export interface TherapistDashboardNotifications {
  notifyOnTag: boolean;
  notifyOnDanger: boolean;
  notifyOnCritical: boolean;
}

/**
 * TherapyChat configuration
 */
export interface TherapyChatConfig {
  baseUrl?: string;
  userId: number;
  sectionId: number;
  conversationId?: number | string | null;
  groupId?: number | null;
  conversationMode: ConversationMode;
  aiEnabled: boolean;
  riskLevel: RiskLevel;
  isSubject: boolean;
  taggingEnabled: boolean;
  dangerDetectionEnabled: boolean;
  pollingInterval: number;
  labels: TherapyChatLabels;
  tagReasons: TagReason[];
  configuredModel?: string;
  // Speech-to-text configuration
  speechToTextEnabled?: boolean;
  speechToTextModel?: string;
  speechToTextLanguage?: string;
}

/**
 * TherapistDashboard configuration
 */
export interface TherapistDashboardConfig {
  baseUrl?: string;
  userId: number;
  sectionId: number;
  selectedGroupId?: number | null;
  selectedSubjectId?: number | null;
  stats: DashboardStats;
  pollingInterval: number;
  messagesPerPage: number;
  conversationsPerPage: number;
  features: TherapistDashboardFeatures;
  notifications: TherapistDashboardNotifications;
  labels: TherapistDashboardLabels;
  // Speech-to-text configuration
  speechToTextEnabled?: boolean;
  speechToTextModel?: string;
  speechToTextLanguage?: string;
}

/**
 * API Response types
 */
export interface ApiResponse<T = unknown> {
  success?: boolean;
  error?: string;
  data?: T;
}

export interface SendMessageResponse extends ApiResponse {
  message_id?: number;
  conversation_id?: number | string;
  blocked?: boolean;
  type?: string;
  message?: string;
  ai_message?: Message;
}

export interface GetMessagesResponse extends ApiResponse {
  messages: Message[];
  conversation_id?: number | string;
}

export interface GetConversationResponse extends ApiResponse {
  conversation: Conversation;
  messages: Message[];
  notes?: Note[];
  tags?: Tag[];
  alerts?: Alert[];
}

export interface GetConversationsResponse extends ApiResponse {
  conversations: Conversation[];
}

export interface TagTherapistResponse extends ApiResponse {
  tag_id?: number;
  alert_created?: boolean;
}

/**
 * Default configuration values
 */
export const DEFAULT_LABELS: TherapyChatLabels = {
  ai_label: 'AI Assistant',
  therapist_label: 'Therapist',
  tag_button_label: 'Tag Therapist',
  tag_reasons: [
    { code: 'overwhelmed', label: 'I am feeling overwhelmed', urgency: 'normal' },
    { code: 'need_talk', label: 'I need to talk soon', urgency: 'urgent' },
    { code: 'urgent', label: 'This feels urgent', urgency: 'urgent' },
    { code: 'emergency', label: 'Emergency - please respond immediately', urgency: 'emergency' }
  ],
  empty_message: 'No messages yet. Start the conversation!',
  ai_thinking: 'AI is thinking...',
  mode_ai: 'AI-assisted chat',
  mode_human: 'Therapist-only mode',
  send_button: 'Send',
  placeholder: 'Type your message...',
  loading: 'Loading...'
};
