/**
 * TypeScript Type Definitions for Therapy Chat Plugin
 * ====================================================
 *
 * All types align with the backend PHP services and database schema.
 * See: server/service/TherapyMessageService.php
 *      server/db/v1.0.0.sql
 */

// ---------------------------------------------------------------------------
// Enums / Union Types
// ---------------------------------------------------------------------------

/** Who sent the message (stored in llmMessages.sent_context.therapy_sender_type) */
export type SenderType = 'subject' | 'therapist' | 'ai' | 'system';

/** Risk levels (therapyRiskLevels lookup) */
export type RiskLevel = 'low' | 'medium' | 'high' | 'critical';

/** Conversation modes (therapyChatModes lookup) */
export type ConversationMode = 'ai_hybrid' | 'human_only';

/** Alert types (therapyAlertTypes lookup) */
export type AlertType =
  | 'danger_detected'
  | 'tag_received'
  | 'high_activity'
  | 'inactivity'
  | 'new_message';

/** Alert severity (therapyAlertSeverity lookup) */
export type AlertSeverity = 'info' | 'warning' | 'critical' | 'emergency';

/** Tag urgency (stored in alert metadata JSON) */
export type TagUrgency = 'normal' | 'urgent' | 'emergency';

/** Draft status (therapyDraftStatus lookup) */
export type DraftStatus = 'draft' | 'sent' | 'discarded';

/** Note types (therapyNoteTypes lookup) */
export type NoteType = 'manual' | 'ai_summary';

// ---------------------------------------------------------------------------
// Core Data Models
// ---------------------------------------------------------------------------

/** A single chat message (from llmMessages + sent_context) */
export interface Message {
  id: number | string;
  role: 'user' | 'assistant' | 'system';
  content: string;
  timestamp: string;
  /** Therapy-specific sender type from sent_context */
  sender_type?: SenderType;
  sender_id?: number;
  sender_name?: string;
  /** Display label resolved by backend (e.g. "Therapist (Dr. Smith)") */
  label?: string;
  /** Whether this message was edited */
  is_edited?: boolean;
  edited_at?: string;
  /** Whether this message was soft-deleted */
  is_deleted?: boolean;
}

/** A therapy conversation (from view_therapyConversations) */
export interface Conversation {
  id: number | string;
  id_llmConversations?: number;
  title?: string;
  mode?: ConversationMode;
  ai_enabled: boolean;
  risk_level?: RiskLevel;
  model?: string;
  created_at: string;
  updated_at?: string;
  /** Patient user ID */
  id_users?: number;
  subject_name?: string;
  subject_code?: string;
  subject_email?: string;
  /** Aggregated counts from backend */
  message_count?: number;
  unread_count?: number;
  unread_alerts?: number;
  /** When true, the patient has no conversation yet (therapist can initialize one) */
  no_conversation?: boolean | number;
}

/** Alert (from view_therapyAlerts) */
export interface Alert {
  id: number;
  id_llmConversations: number;
  id_users?: number;
  alert_type: AlertType;
  alert_type_label?: string;
  severity: AlertSeverity;
  severity_label?: string;
  message: string;
  metadata?: Record<string, unknown>;
  is_read: boolean;
  read_at?: string;
  created_at: string;
  conversation_title?: string;
  subject_name?: string;
  subject_code?: string;
}

/** Clinical note (from therapyNotes) */
export interface Note {
  id: number;
  id_llmConversations: number;
  id_users: number;
  content: string;
  note_type?: NoteType;
  note_status?: string;
  author_name?: string;
  last_edited_by_name?: string;
  created_at: string;
  updated_at?: string;
}

/** AI draft message (from therapyDraftMessages) */
export interface Draft {
  id: number;
  id_llmConversations: number;
  id_users: number;
  ai_content: string;
  edited_content?: string;
  status: DraftStatus;
  created_at: string;
  updated_at?: string;
}

/** A therapist group assignment (from therapyTherapistAssignments) */
export interface TherapistGroup {
  id_groups: number;
  group_name: string;
  patient_count?: number;
}

/** Predefined tag reason (passed from PHP config) */
export interface TagReason {
  code: string;
  label: string;
  urgency: TagUrgency;
}

// ---------------------------------------------------------------------------
// Unread Tracking
// ---------------------------------------------------------------------------

/** Unread counts returned by get_unread_counts endpoint */
export interface UnreadCounts {
  total: number;
  totalAlerts: number;
  bySubject: Record<
    number | string,
    {
      subjectId: number | string;
      subjectName: string;
      subjectCode?: string;
      unreadCount: number;
      lastMessageAt?: string;
    }
  >;
  /** Per-group unread totals: groupId → count */
  byGroup?: Record<number | string, number>;
}

// ---------------------------------------------------------------------------
// Dashboard Statistics
// ---------------------------------------------------------------------------

export interface DashboardStats {
  total: number;
  ai_enabled: number;
  ai_blocked: number;
  risk_low: number;
  risk_medium: number;
  risk_high: number;
  risk_critical: number;
  unread_alerts: number;
}

// ---------------------------------------------------------------------------
// UI Label Interfaces
// ---------------------------------------------------------------------------

/** Labels for the subject/patient chat (snake_case to match PHP output) */
export interface SubjectChatLabels {
  ai_label: string;
  therapist_label: string;
  tag_button_label: string;
  empty_message: string;
  ai_thinking: string;
  mode_ai: string;
  mode_human: string;
  send_button: string;
  placeholder: string;
  loading: string;
  /** Help text explaining @mention and #hashtag usage (from DB field) */
  chat_help_text?: string;
}

/** Labels for the therapist dashboard */
export interface TherapistDashboardLabels {
  title: string;
  conversationsHeading: string;
  alertsHeading: string;
  notesHeading: string;
  statsHeading: string;
  riskHeading: string;
  noConversations: string;
  noAlerts: string;
  selectConversation: string;
  sendPlaceholder: string;
  sendButton: string;
  addNotePlaceholder: string;
  addNoteButton: string;
  loading: string;
  aiLabel: string;
  therapistLabel: string;
  subjectLabel: string;
  riskLow: string;
  riskMedium: string;
  riskHigh: string;
  riskCritical: string;
  disableAI: string;
  enableAI: string;
  aiModeIndicator: string;
  humanModeIndicator: string;
  acknowledge: string;
  dismiss: string;
  statPatients: string;
  statAiEnabled: string;
  statAiBlocked: string;
  statCritical: string;
  statAlerts: string;
  statTags: string;
  filterAll: string;
  filterActive: string;
  filterCritical: string;
  filterUnread: string;
  allGroupsTab: string;
  emptyMessage: string;
  /** Label for the "Start Conversation" button for patients without conversations */
  startConversation?: string;
  /** Label shown for patients who have no conversation yet */
  noConversationYet?: string;
  /** Label shown while a conversation is being initialized */
  initializingConversation?: string;
}

// ---------------------------------------------------------------------------
// Feature Toggles
// ---------------------------------------------------------------------------

export interface TherapistFeatures {
  showRiskColumn: boolean;
  showAlertsPanel: boolean;
  showNotesPanel: boolean;
  showStatsHeader: boolean;
  enableAiToggle: boolean;
  enableRiskControl: boolean;
  enableNotes: boolean;
}

// ---------------------------------------------------------------------------
// Configuration (passed from PHP via data-config JSON)
// ---------------------------------------------------------------------------

/** Config for subject/patient chat */
export interface SubjectChatConfig {
  baseUrl?: string;
  userId: number;
  sectionId: number;
  conversationId?: number | string | null;
  conversationMode: ConversationMode;
  aiEnabled: boolean;
  taggingEnabled: boolean;
  dangerDetectionEnabled: boolean;
  pollingInterval: number;
  labels: SubjectChatLabels;
  tagReasons: TagReason[];
  speechToTextEnabled?: boolean;
  speechToTextModel?: string;
  speechToTextLanguage?: string;
  /** Optional flag for embedded/floating rendering context */
  isFloatingMode?: boolean;
}

/** Config for therapist dashboard */
export interface TherapistDashboardConfig {
  baseUrl?: string;
  userId: number;
  sectionId: number;
  selectedGroupId?: number | null;
  selectedSubjectId?: number | string | null;
  stats: DashboardStats;
  groups?: TherapistGroup[];
  assignedGroups?: TherapistGroup[];
  pollingInterval: number;
  features: TherapistFeatures;
  labels: TherapistDashboardLabels;
  speechToTextEnabled?: boolean;
  speechToTextModel?: string;
  speechToTextLanguage?: string;
}

// ---------------------------------------------------------------------------
// API Response Types
// ---------------------------------------------------------------------------

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
  alerts?: Alert[];
}

export interface GetConversationsResponse extends ApiResponse {
  conversations: Conversation[];
}

export interface TagTherapistResponse extends ApiResponse {
  alert_id?: number;
  alert_created?: boolean;
}

