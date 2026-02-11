-- =====================================================
-- SelfHelp Plugin: LLM Therapy Chat
-- Version: 1.0.0
-- Description: Therapy chat extension for sh-shp-llm plugin
--
-- DEPENDENCY: Requires sh-shp-llm plugin to be installed first!
-- This plugin extends the llmConversations and llmMessages tables
-- with therapy-specific functionality.
--
-- =====================================================
-- DATABASE ARCHITECTURE OVERVIEW
-- =====================================================
--
-- Base tables (owned by sh-shp-llm, NOT modified here):
-- ┌─────────────────────────┐   ┌─────────────────────────┐
-- │ llmConversations         │   │ llmMessages              │
-- │ - id                     │◄──│ - id                     │
-- │ - id_users (patient)     │   │ - id_llmConversations    │
-- │ - title, model           │   │ - role (user/assistant/  │
-- │ - deleted, blocked       │   │         system)          │
-- └────────┬────────────────┘   │ - content, timestamp     │
--          │                     │ - sent_context (JSON)    │
--          │                     │   ↳ therapy_sender_type  │
--          │                     │   ↳ therapy_sender_id    │
--          │                     └──────────┬──────────────┘
-- Therapy extension tables:                 │
--          │                                │
-- ┌────────▼────────────────┐  ┌───────────▼──────────────┐
-- │ therapyConversationMeta │  │ therapyMessageRecipients  │
-- │ - id_llmConversations   │  │ - id_llmMessages          │
-- │ - mode/status/risk      │  │ - id_users (recipient)    │
-- │ - ai_enabled            │  │ - is_new (unread flag)    │
-- │ (NO id_therapist -      │  │ - seen_at                 │
-- │  we know who sent what  │  └───────────────────────────┘
-- │  from sent_context in   │
-- │  llmMessages)           │  ┌───────────────────────────┐
-- └────────┬────────────────┘  │ therapyDraftMessages      │
--          │                    │ - id_llmConversations     │
-- ┌────────▼────────────────┐  │ - id_users (therapist)    │
-- │ therapyAlerts            │  │ - ai_content + edited     │
-- │ - id_llmConversations   │  │ - status (draft/sent/...)  │
-- │ - id_users (target)     │  └───────────────────────────┘
-- │ - type/severity         │
-- │ - metadata (JSON)       │  ┌───────────────────────────┐
-- │   (tags absorbed here)  │  │ therapyTherapistAssignments│
-- └─────────────────────────┘  │ - id_users (therapist)    │
--                               │ - id_groups (patient grp) │
-- ┌─────────────────────────┐  │ PURPOSE: who monitors whom│
-- │ therapyNotes             │  └───────────────────────────┘
-- │ - id_llmConversations   │
-- │ - id_users (author)     │
-- │ - content + note_type   │
-- └─────────────────────────┘
--
-- =====================================================
-- ACCESS CONTROL FLOW
-- =====================================================
--
-- "Which conversations can therapist X see?"
--
-- 1. therapyTherapistAssignments: therapist → [group_1, group_2, ...]
-- 2. users_groups (core SelfHelp):  patient  → [group_1, group_3, ...]
-- 3. Intersection: patients in groups the therapist monitors
-- 4. llmConversations: patient's conversation(s)
-- 5. therapyConversationMeta: therapy metadata for each conversation
--
-- "Can patient Y chat?"
--
-- 1. Patient is in therapy_chat_subject_group (config) → sees floating button
-- 2. Opens therapyChatSubject page → getOrCreate conversation
-- 3. Messages stored in llmMessages, recipients tracked in therapyMessageRecipients
--
-- "What happens when patient tags therapist?"
--
-- 1. Patient sends message with @mention
-- 2. System creates therapyAlert (type='tag_received', metadata has reason/urgency)
-- 3. System creates therapyMessageRecipients for all assigned therapists
-- 4. Therapist dashboard shows alert + unread message
--
-- SENDER TRACKING (how we know who sent each message):
--
-- All messages live in llmMessages.sent_context JSON column:
--   { "therapy_sender_type": "subject|therapist|ai|system",
--     "therapy_sender_id": 12345 }
--
-- When building AI context, therapist messages are marked with high
-- importance so the AI knows they are authoritative clinical input.
-- The llmMessages.role field maps:
--   - "user"      = subject OR therapist (distinguished by sent_context)
--   - "assistant"  = AI response
--   - "system"     = system/context messages
--
-- MESSAGE EDITING/DELETION:
-- Therapists can edit or soft-delete messages. We use llmMessages.sent_context
-- to track: { "edited_at": "...", "edited_by": 123, "original_content": "..." }
-- For soft-delete: llmMessages.deleted = 1 (already exists in base table)
--
-- =====================================================

-- =====================================================
-- DEBUG: DROP TABLES (uncomment to re-run script from scratch)
-- WARNING: This will DELETE all therapy data!
-- =====================================================
-- DROP VIEW IF EXISTS view_therapyTherapistAssignments;
-- DROP VIEW IF EXISTS view_therapyAlerts;
-- DROP VIEW IF EXISTS view_therapyConversations;
-- DROP TABLE IF EXISTS therapyDraftMessages;
-- DROP TABLE IF EXISTS therapyNotes;
-- DROP TABLE IF EXISTS therapyAlerts;
-- DROP TABLE IF EXISTS therapyMessageRecipients;
-- DROP TABLE IF EXISTS therapyConversationMeta;
-- DROP TABLE IF EXISTS therapyTherapistAssignments;
-- =====================================================

-- Add plugin entry
INSERT IGNORE INTO plugins (name, version)
VALUES ('llm_therapy_chat', 'v1.0.0');

-- =====================================================
-- DEPENDENCY CHECK
-- Verify that the LLM plugin is installed
-- =====================================================

DELIMITER //
CREATE PROCEDURE check_llm_dependency()
BEGIN
    DECLARE llm_installed INT DEFAULT 0;

    SELECT COUNT(*) INTO llm_installed
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'llmConversations';

    IF llm_installed = 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ERROR: sh-shp-llm plugin must be installed first! The llmConversations table does not exist.';
    END IF;
END //
DELIMITER ;

CALL check_llm_dependency();
DROP PROCEDURE check_llm_dependency;

-- =====================================================
-- FIELD TYPES
-- =====================================================

INSERT IGNORE INTO fieldType (`name`, `position`) VALUES ('select-page', 10);
INSERT IGNORE INTO fieldType (`name`, `position`) VALUES ('select-floating-position', 11);

-- =====================================================
-- LOOKUP ENTRIES
-- All ENUM-like values stored in the lookups table.
-- These are referenced by FK from therapy tables.
-- =====================================================

-- Chat Modes: how the conversation operates
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('therapyChatModes', 'ai_hybrid', 'AI Hybrid', 'AI responds with therapist oversight and intervention capability'),
('therapyChatModes', 'human_only', 'Human Only', 'Only human therapist responds, no AI involvement');

-- Conversation Status: lifecycle state of a conversation
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('therapyConversationStatus', 'active', 'Active', 'Active conversation, messages can be sent'),
('therapyConversationStatus', 'paused', 'Paused', 'Paused conversation, temporarily inactive'),
('therapyConversationStatus', 'closed', 'Closed', 'Closed conversation, no new messages');

-- Risk Levels: therapist-assessed risk level for a conversation
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('therapyRiskLevels', 'low', 'Low', 'Low risk - normal activity'),
('therapyRiskLevels', 'medium', 'Medium', 'Medium risk - requires attention'),
('therapyRiskLevels', 'high', 'High', 'High risk - needs review'),
('therapyRiskLevels', 'critical', 'Critical', 'Critical risk - immediate attention required');

-- Alert Types: what triggered the alert
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('therapyAlertTypes', 'danger_detected', 'Danger Detected', 'Dangerous keywords detected in message'),
('therapyAlertTypes', 'tag_received', 'Tag Received', 'Patient tagged/mentioned a therapist (replaces old therapyTags table)'),
('therapyAlertTypes', 'high_activity', 'High Activity', 'Unusual high message activity'),
('therapyAlertTypes', 'inactivity', 'Inactivity', 'Extended silence from subject'),
('therapyAlertTypes', 'new_message', 'New Message', 'New message received');

-- Alert Severity: urgency level of the alert
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('therapyAlertSeverity', 'info', 'Info', 'Informational alert'),
('therapyAlertSeverity', 'warning', 'Warning', 'Warning - needs attention'),
('therapyAlertSeverity', 'critical', 'Critical', 'Critical - urgent attention required'),
('therapyAlertSeverity', 'emergency', 'Emergency', 'Emergency - immediate action required');

-- Note Types: distinguishes manual notes from AI summaries
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('therapyNoteTypes', 'manual', 'Manual Note', 'Note written manually by the therapist'),
('therapyNoteTypes', 'ai_summary', 'AI Summary', 'Conversation summary generated by AI');

-- Note Status: soft-delete lifecycle
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('therapyNoteStatus', 'active', 'Active', 'Note is active and visible'),
('therapyNoteStatus', 'deleted', 'Deleted', 'Note has been soft-deleted and is hidden');

-- Draft Message Statuses: lifecycle of a therapist draft
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('therapyDraftStatus', 'draft', 'Draft', 'Message is being composed or edited'),
('therapyDraftStatus', 'sent', 'Sent', 'Message has been sent to the patient'),
('therapyDraftStatus', 'discarded', 'Discarded', 'Draft was discarded without sending');

-- Floating Button Positions
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('floatingButtonPositions', 'bottom-right', 'Bottom Right', 'Display floating button in bottom right corner'),
('floatingButtonPositions', 'bottom-left', 'Bottom Left', 'Display floating button in bottom left corner'),
('floatingButtonPositions', 'top-right', 'Top Right', 'Display floating button in top right corner'),
('floatingButtonPositions', 'top-left', 'Top Left', 'Display floating button in top left corner');

-- =====================================================
-- TABLE 1: therapyTherapistAssignments
-- =====================================================
-- PURPOSE: Maps therapist users to patient groups they can monitor.
-- This is the SINGLE SOURCE OF TRUTH for therapist access control.
--
-- A therapist can monitor multiple groups.
-- A group can have multiple therapists.
--
-- HOW IT WORKS:
-- - Admin assigns therapist to group(s) via UI hooks on user management page.
-- - When therapist opens dashboard, system queries:
--     "SELECT id_groups FROM therapyTherapistAssignments WHERE id_users = ?"
-- - Then finds patients in those groups via users_groups.
-- - Then finds conversations for those patients via llmConversations.
--
-- WHY NOT use existing users_groups + ACL?
-- - users_groups defines which SelfHelp groups a user belongs to (for ACL/pages).
-- - A therapist might be in group "therapist" for page access, but needs to
--   MONITOR patients in groups "study_1", "study_2", etc.
-- - This table decouples "page access role" from "patient monitoring scope".
-- =====================================================

CREATE TABLE IF NOT EXISTS `therapyTherapistAssignments` (
    `id_users` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'Therapist user ID',
    `id_groups` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'Patient group this therapist can monitor',
    `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When the assignment was created',
    PRIMARY KEY (`id_users`, `id_groups`),
    KEY `idx_therapist` (`id_users`),
    KEY `idx_group` (`id_groups`),
    CONSTRAINT `fk_therapyAssign_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyAssign_groups` FOREIGN KEY (`id_groups`) REFERENCES `groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE 2: therapyConversationMeta
-- =====================================================
-- PURPOSE: Adds therapy-specific metadata to llmConversations (1:1 relationship).
--
-- NO id_groups: Access control via therapyTherapistAssignments.
-- NO id_therapist: We don't track "who joined". Multiple therapists can
--   interact. Who sent what is tracked in llmMessages.sent_context JSON.
--   This avoids the problem of "what if multiple therapists join?"
--
-- FIELDS:
-- - ai_enabled: Quick boolean toggle. When therapist pauses AI, this goes to 0.
-- - id_chatModes: The overall mode (ai_hybrid or human_only).
-- - id_conversationStatus: Lifecycle status (active/paused/closed).
-- - id_riskLevels: Therapist-assessed risk level.
-- - therapist_last_seen / subject_last_seen: For read tracking at conversation level.
-- =====================================================

CREATE TABLE IF NOT EXISTS `therapyConversationMeta` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llmConversations` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT '1:1 link to llmConversations',
    `id_chatModes` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'FK to lookups (therapyChatModes)',
    `ai_enabled` TINYINT(1) DEFAULT 1 COMMENT '1=AI can respond, 0=AI paused by therapist',
    `id_conversationStatus` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'FK to lookups (therapyConversationStatus)',
    `id_riskLevels` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'FK to lookups (therapyRiskLevels)',
    `therapist_last_seen` TIMESTAMP NULL DEFAULT NULL COMMENT 'When any therapist last viewed this conversation',
    `subject_last_seen` TIMESTAMP NULL DEFAULT NULL COMMENT 'When subject last viewed messages',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_llm_conversation` (`id_llmConversations`),
    KEY `idx_status` (`id_conversationStatus`),
    KEY `idx_risk_level` (`id_riskLevels`),
    KEY `idx_chat_mode` (`id_chatModes`),
    CONSTRAINT `fk_therapyConvMeta_llmConv` FOREIGN KEY (`id_llmConversations`) REFERENCES `llmConversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyConvMeta_chatModes` FOREIGN KEY (`id_chatModes`) REFERENCES `lookups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyConvMeta_status` FOREIGN KEY (`id_conversationStatus`) REFERENCES `lookups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyConvMeta_riskLevel` FOREIGN KEY (`id_riskLevels`) REFERENCES `lookups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE 3: therapyMessageRecipients
-- =====================================================
-- PURPOSE: Tracks per-user message delivery and read status.
--
-- This is the notification backbone. Every message that should be seen
-- by a participant gets a row here.
--
-- FLOW:
-- 1. Patient sends message:
--    → Insert recipient rows for ALL therapists assigned to patient's groups
--      (via therapyTherapistAssignments → users_groups intersection)
--    → is_new = 1 (unread)
--
-- 2. Therapist sends message:
--    → Insert recipient row for the patient (subject)
--    → is_new = 1 (unread)
--
-- 3. AI sends message (assistant role):
--    → Insert recipient row for the patient (they see the AI response)
--    → Optionally insert for therapists monitoring the conversation
--
-- 4. Patient tags therapist:
--    → Same as #1, but also creates a therapyAlert (type='tag_received')
--
-- 5. User opens conversation / sees message:
--    → UPDATE therapyMessageRecipients SET is_new = 0, seen_at = NOW()
--      WHERE id_users = ? AND id_llmMessages IN (visible message IDs)
--
-- QUERYING UNREAD COUNT:
--    SELECT COUNT(*) FROM therapyMessageRecipients
--    WHERE id_users = ? AND is_new = 1
-- =====================================================

CREATE TABLE IF NOT EXISTS `therapyMessageRecipients` (
    `id_llmMessages` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'Reference to llmMessages',
    `id_users` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'Recipient user ID',
    `is_new` TINYINT(1) DEFAULT 1 COMMENT '1=unread, 0=seen',
    `seen_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When user marked message as seen',
    PRIMARY KEY (`id_llmMessages`, `id_users`),
    KEY `idx_user_unread` (`id_users`, `is_new`),
    CONSTRAINT `fk_therapyMsgRecip_llmMsg` FOREIGN KEY (`id_llmMessages`) REFERENCES `llmMessages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyMsgRecip_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE 4: therapyAlerts
-- =====================================================
-- PURPOSE: All notifications/alerts for therapists.
-- This table ABSORBS the old therapyTags functionality.
--
-- Alert types (from lookups 'therapyAlertTypes'):
-- - danger_detected: Danger keywords found in patient message
-- - tag_received:    Patient @mentioned a therapist (was separate therapyTags table)
-- - high_activity:   Unusual volume of messages
-- - inactivity:      Extended silence from patient
-- - new_message:     Generic new message notification
--
-- For tag_received alerts, the `metadata` JSON column stores:
--   {
--     "tag_reason": "overwhelmed",        -- from configured tag reasons
--     "urgency": "urgent",                -- normal/urgent/emergency
--     "message_id": 12345,                -- which message contained the @mention
--     "message_preview": "I feel..."      -- truncated message content
--   }
--
-- WHY NOT keep a separate therapyTags table?
-- - Tags are just a specialized alert with extra metadata.
-- - The acknowledgment workflow (is_read/read_at) is identical.
-- - Having one table for all notifications simplifies:
--   * Dashboard queries (one table to scan)
--   * Unread count aggregation
--   * Email notification triggers
--   * Alert dismissal logic
-- =====================================================

CREATE TABLE IF NOT EXISTS `therapyAlerts` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llmConversations` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'Which conversation triggered this alert',
    `id_users` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'Target therapist (NULL = all assigned therapists)',
    `id_alertTypes` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'FK to lookups (therapyAlertTypes)',
    `id_alertSeverity` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'FK to lookups (therapyAlertSeverity)',
    `message` TEXT COMMENT 'Human-readable alert description',
    `metadata` JSON DEFAULT NULL COMMENT 'Type-specific data (tag reason, urgency, message_id, etc.)',
    `is_read` TINYINT(1) DEFAULT 0 COMMENT '0=unread, 1=read/acknowledged',
    `read_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When alert was read/acknowledged',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_conversation` (`id_llmConversations`),
    KEY `idx_user` (`id_users`),
    KEY `idx_type` (`id_alertTypes`),
    KEY `idx_severity` (`id_alertSeverity`),
    KEY `idx_unread` (`is_read`, `created_at`),
    KEY `idx_user_unread` (`id_users`, `is_read`),
    CONSTRAINT `fk_therapyAlerts_llmConv` FOREIGN KEY (`id_llmConversations`) REFERENCES `llmConversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyAlerts_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyAlerts_alertTypes` FOREIGN KEY (`id_alertTypes`) REFERENCES `lookups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyAlerts_severity` FOREIGN KEY (`id_alertSeverity`) REFERENCES `lookups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE 5: therapyNotes
-- =====================================================
-- PURPOSE: Therapist-only notes and AI-generated summaries.
-- NOT visible to patients. Internal clinical documentation.
--
-- note_type (from lookups 'therapyNoteTypes'):
-- - manual:     Therapist typed this note themselves
-- - ai_summary: AI generated this as a conversation summary
--
-- WORKFLOW:
-- 1. Therapist clicks "Add Note" → manual note saved
-- 2. Therapist clicks "Summarize with AI" → backend calls LLM API →
--    AI summary saved with note_type = 'ai_summary'
-- 3. Therapist can edit AI summary before saving (edited content stored)
-- =====================================================

CREATE TABLE IF NOT EXISTS `therapyNotes` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llmConversations` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'Which conversation this note is about',
    `id_users` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'Therapist who created/saved the note',
    `id_noteTypes` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'FK to lookups (therapyNoteTypes)',
    `id_noteStatus` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'FK to lookups (therapyNoteStatus) - active or deleted',
    `content` TEXT NOT NULL COMMENT 'Note content (or edited AI summary)',
    `ai_original_content` TEXT DEFAULT NULL COMMENT 'Original AI-generated content before editing (NULL for manual notes)',
    `id_lastEditedBy` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'Last therapist who edited this note',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_conversation` (`id_llmConversations`),
    KEY `idx_therapist` (`id_users`),
    KEY `idx_note_type` (`id_noteTypes`),
    KEY `idx_note_status` (`id_noteStatus`),
    CONSTRAINT `fk_therapyNotes_llmConv` FOREIGN KEY (`id_llmConversations`) REFERENCES `llmConversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyNotes_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyNotes_noteTypes` FOREIGN KEY (`id_noteTypes`) REFERENCES `lookups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyNotes_noteStatus` FOREIGN KEY (`id_noteStatus`) REFERENCES `lookups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyNotes_lastEditor` FOREIGN KEY (`id_lastEditedBy`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE 6: therapyDraftMessages
-- =====================================================
-- PURPOSE: AI-assisted message drafting workflow for therapists.
--
-- WORKFLOW:
-- 1. Therapist clicks "Generate AI Response" for a conversation
-- 2. Backend calls LLM API with conversation context
-- 3. AI response saved here as draft (status='draft')
-- 4. Therapist sees AI draft, edits it in the UI
-- 5. Therapist clicks "Send" → content inserted into llmMessages as
--    role='user' (from therapist), draft status → 'sent'
-- 6. OR therapist discards → status → 'discarded'
--
-- WHY a separate table?
-- - Drafts are NOT visible to patients until sent.
-- - Therapist may have multiple drafts, edit over time, discard.
-- - Audit trail: we keep ai_generated_content vs final edited_content.
-- - Once sent, the actual message lives in llmMessages. The draft
--   is linked via id_llmMessages for traceability.
-- =====================================================

CREATE TABLE IF NOT EXISTS `therapyDraftMessages` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llmConversations` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'Which conversation this draft is for',
    `id_users` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'Therapist who created the draft',
    `ai_generated_content` TEXT DEFAULT NULL COMMENT 'Original AI-generated response (NULL if therapist wrote from scratch)',
    `edited_content` TEXT DEFAULT NULL COMMENT 'Therapist-edited version (NULL if not yet edited)',
    `id_draftStatus` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'FK to lookups (therapyDraftStatus)',
    `id_llmMessages` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'Link to sent message in llmMessages (NULL until sent)',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `sent_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When the draft was sent as a real message',
    PRIMARY KEY (`id`),
    KEY `idx_conversation` (`id_llmConversations`),
    KEY `idx_user` (`id_users`),
    KEY `idx_status` (`id_draftStatus`),
    KEY `idx_sent_message` (`id_llmMessages`),
    CONSTRAINT `fk_therapyDraft_llmConv` FOREIGN KEY (`id_llmConversations`) REFERENCES `llmConversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyDraft_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyDraft_status` FOREIGN KEY (`id_draftStatus`) REFERENCES `lookups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyDraft_llmMsg` FOREIGN KEY (`id_llmMessages`) REFERENCES `llmMessages` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- VIEWS
-- =====================================================
-- Views resolve FK lookup IDs to human-readable codes/labels
-- so application code doesn't need to join lookups manually.
-- =====================================================

-- View: Therapy Conversations (main dashboard query source)
-- Joins: therapyConversationMeta + llmConversations + users + lookups
-- NOTE: No therapist info here - therapist identity comes from message sent_context
CREATE OR REPLACE VIEW `view_therapyConversations` AS
SELECT
    tcm.id,
    tcm.id_llmConversations,
    tcm.ai_enabled,
    tcm.therapist_last_seen,
    tcm.subject_last_seen,
    tcm.created_at,
    tcm.updated_at,
    -- From llmConversations (the patient's conversation)
    lc.id_users,
    lc.title,
    lc.model,
    lc.deleted,
    lc.blocked,
    -- Patient info
    u.name AS subject_name,
    vc.code AS subject_code,
    u.email AS subject_email,
    -- Resolved lookup values
    mode_lookup.lookup_code AS mode,
    mode_lookup.lookup_value AS mode_label,
    status_lookup.lookup_code AS status,
    status_lookup.lookup_value AS status_label,
    risk_lookup.lookup_code AS risk_level,
    risk_lookup.lookup_value AS risk_level_label
FROM therapyConversationMeta tcm
INNER JOIN llmConversations lc ON lc.id = tcm.id_llmConversations
INNER JOIN users u ON u.id = lc.id_users
LEFT JOIN validation_codes vc ON vc.id_users = u.id AND vc.consumed IS NULL
LEFT JOIN lookups mode_lookup ON mode_lookup.id = tcm.id_chatModes
LEFT JOIN lookups status_lookup ON status_lookup.id = tcm.id_conversationStatus
LEFT JOIN lookups risk_lookup ON risk_lookup.id = tcm.id_riskLevels;

-- View: Therapy Alerts (dashboard alerts panel)
-- Resolves alert type and severity to readable codes/labels
CREATE OR REPLACE VIEW `view_therapyAlerts` AS
SELECT
    ta.id,
    ta.id_llmConversations,
    ta.id_users,
    ta.message,
    ta.metadata,
    ta.is_read,
    ta.read_at,
    ta.created_at,
    -- Conversation info
    lc.title AS conversation_title,
    -- Patient info (conversation owner)
    u.name AS subject_name,
    vc.code AS subject_code,
    -- Resolved lookup values
    type_lookup.lookup_code AS alert_type,
    type_lookup.lookup_value AS alert_type_label,
    severity_lookup.lookup_code AS severity,
    severity_lookup.lookup_value AS severity_label
FROM therapyAlerts ta
INNER JOIN llmConversations lc ON lc.id = ta.id_llmConversations
INNER JOIN users u ON u.id = lc.id_users
LEFT JOIN validation_codes vc ON vc.id_users = u.id AND vc.consumed IS NULL
LEFT JOIN lookups type_lookup ON type_lookup.id = ta.id_alertTypes
LEFT JOIN lookups severity_lookup ON severity_lookup.id = ta.id_alertSeverity;

-- View: Therapist Assignments (admin/management)
-- Shows which therapists are assigned to which patient groups
CREATE OR REPLACE VIEW `view_therapyTherapistAssignments` AS
SELECT
    tta.id_users,
    tta.id_groups,
    tta.assigned_at,
    u.name AS therapist_name,
    u.email AS therapist_email,
    g.name AS group_name
FROM therapyTherapistAssignments tta
INNER JOIN users u ON u.id = tta.id_users
INNER JOIN `groups` g ON g.id = tta.id_groups;

-- =====================================================
-- PAGE TYPE FOR MODULE CONFIGURATION
-- =====================================================

INSERT IGNORE INTO `pageType` (`name`) VALUES ('sh_module_llm_therapy_chat');

-- =====================================================
-- CONFIGURATION FIELDS
-- =====================================================

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'therapy_chat_subject_group', get_field_type_id('select-group'), '0'),
(NULL, 'therapy_chat_therapist_group', get_field_type_id('select-group'), '0'),
(NULL, 'therapy_chat_subject_page', get_field_type_id('select-page'), '0'),
(NULL, 'therapy_chat_therapist_page', get_field_type_id('select-page'), '0'),
(NULL, 'therapy_chat_floating_icon', get_field_type_id('text'), '0'),
(NULL, 'therapy_chat_floating_label', get_field_type_id('text'), '1'),
(NULL, 'therapy_chat_floating_position', get_field_type_id('select-floating-position'), '0'),
(NULL, 'therapy_chat_default_mode', get_field_type_id('select'), '0'),
(NULL, 'therapy_chat_polling_interval', get_field_type_id('number'), '0'),
(NULL, 'therapy_chat_enable_tagging', get_field_type_id('checkbox'), '0'),
(NULL, 'therapy_tag_reasons', get_field_type_id('json'), '1'),
(NULL, 'therapy_chat_help_text', get_field_type_id('markdown'), '1'),
(NULL, 'therapy_summary_context', get_field_type_id('markdown'), '1'),
(NULL, 'therapy_draft_context', get_field_type_id('markdown'), '1');

-- Link fields to page type
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('title'), 'LLM Therapy Chat Configuration', 'Page title'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_subject_group'), (SELECT id FROM `groups` WHERE `name` = 'subject' LIMIT 1), 'Select the group that contains subjects (patients). Members of this group will see the floating chat button and can access the therapy chat interface.'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_therapist_group'), (SELECT id FROM `groups` WHERE `name` = 'therapist' LIMIT 1), 'Select the group that contains therapists. Members of this group will see the floating dashboard button. NOTE: Actual patient monitoring access is controlled via therapyTherapistAssignments (per-therapist group assignments).'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_subject_page'), NULL, 'Page ID for subject/patient chat interface'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_therapist_page'), NULL, 'Page ID for therapist dashboard'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_floating_icon'), 'fa-comments', 'Font Awesome icon class for the floating button'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_floating_label'), '', 'Optional text label for the floating button'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_floating_position'), 'bottom-right', 'Position of the floating button: bottom-right, bottom-left, top-right, top-left'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_default_mode'), 'ai_hybrid', 'Default chat mode: ai_hybrid (AI responds, therapist can join) or human_only (therapist only)'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_polling_interval'), '3', 'Polling interval in seconds for message updates'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_enable_tagging'), '1', 'Enable @mention tagging for therapists'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_tag_reasons'), '[{"key":"overwhelmed","label":"I am feeling overwhelmed","urgency":"normal"},{"key":"need_talk","label":"I need to talk soon","urgency":"urgent"},{"key":"urgent","label":"This feels urgent","urgency":"urgent"},{"key":"emergency","label":"Emergency - please respond immediately","urgency":"emergency"}]', 'JSON array of tag reasons. Each item has: key (unique identifier), label (displayed text), urgency (normal/urgent/emergency). Use @ to tag therapist, # to select reason.');

-- =====================================================
-- CREATE CONFIGURATION PAGE
-- =====================================================

SET @id_page_modules = (SELECT id FROM pages WHERE keyword = 'sh_modules');

INSERT IGNORE INTO `pages` (`id`, `keyword`, `url`, `protocol`, `id_actions`, `id_navigation_section`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`)
VALUES (NULL, 'sh_module_llm_therapy_chat', '/admin/module_llm_therapy_chat', 'GET|POST', (SELECT id FROM actions WHERE `name` = 'backend'), NULL, @id_page_modules, 0, 210, NULL, (SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), (SELECT id FROM lookups WHERE type_code = "pageAccessTypes" AND lookup_code = "mobile_and_web"));

SET @id_page_therapy_chat_config = (SELECT id FROM pages WHERE keyword = 'sh_module_llm_therapy_chat');

INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`)
VALUES ((SELECT id FROM `groups` WHERE `name` = 'admin'), @id_page_therapy_chat_config, '1', '0', '1', '0');

INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
VALUES
(@id_page_therapy_chat_config, get_field_id('title'), '0000000003', 'LLM Therapy Chat Configuration'),
(@id_page_therapy_chat_config, get_field_id('title'), '0000000002', 'LLM Therapie-Chat Konfiguration');

-- Set default values for configuration page fields
INSERT IGNORE INTO `pages_fields` (`id_pages`, `id_fields`, `default_value`, `help`) VALUES
(@id_page_therapy_chat_config, get_field_id('therapy_chat_subject_group'), (SELECT id FROM `groups` WHERE `name` = 'subject' LIMIT 1), 'Select the group that contains subjects (patients)'),
(@id_page_therapy_chat_config, get_field_id('therapy_chat_therapist_group'), (SELECT id FROM `groups` WHERE `name` = 'therapist' LIMIT 1), 'Select the group that contains therapists');

-- =====================================================
-- STYLE REGISTRATION
-- =====================================================

-- therapyChat: subject/patient chat interface
INSERT IGNORE INTO `styles` (`name`, `id_type`, `id_group`, `description`)
VALUES ('therapyChat', (SELECT id FROM styleType WHERE `name` = 'component'), (SELECT id FROM styleGroup WHERE `name` = 'Form'), 'LLM Therapy Chat component for subject-therapist communication with AI support. Extends llmChat with therapy features.');

-- therapistDashboard: therapist monitoring interface
INSERT IGNORE INTO `styles` (`name`, `id_type`, `id_group`, `description`)
VALUES ('therapistDashboard', (SELECT id FROM styleType WHERE `name` = 'component'), (SELECT id FROM styleGroup WHERE `name` = 'intern'), 'Therapist dashboard for managing therapy conversations');

-- =====================================================
-- STYLE FIELDS FOR therapyChat
-- =====================================================

-- Therapy-specific label fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'therapy_ai_label', get_field_type_id('text'), '1'),
(NULL, 'therapy_therapist_label', get_field_type_id('text'), '1'),
(NULL, 'therapy_tag_button_label', get_field_type_id('text'), '1'),
(NULL, 'therapy_empty_message', get_field_type_id('text'), '1'),
(NULL, 'therapy_ai_thinking_text', get_field_type_id('text'), '1'),
(NULL, 'therapy_mode_indicator_ai', get_field_type_id('text'), '1'),
(NULL, 'therapy_mode_indicator_human', get_field_type_id('text'), '1');

-- Add enable_ai field: when false, AI is completely disabled and it becomes
-- a pure therapist-patient chat (no AI involvement at all).
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'therapy_enable_ai', get_field_type_id('checkbox'), '0');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
-- Core CSS
(get_style_id('therapyChat'), get_field_id('css'), NULL, 'CSS classes for the chat container'),
(get_style_id('therapyChat'), get_field_id('css_mobile'), NULL, 'CSS classes for mobile view'),
(get_style_id('therapyChat'), get_field_id('debug'), '0', 'Enable debug mode for the section'),

-- AI toggle: when disabled, the chat becomes pure human-to-human
(get_style_id('therapyChat'), get_field_id('therapy_enable_ai'), '1', 'Enable AI responses. When disabled, the chat is purely between patient and therapist(s). Default: enabled.'),

-- Therapy mode/feature config
(get_style_id('therapyChat'), get_field_id('therapy_chat_default_mode'), 'ai_hybrid', 'Default chat mode for this instance'),
(get_style_id('therapyChat'), get_field_id('therapy_chat_enable_tagging'), '1', 'Enable @mention tagging'),
(get_style_id('therapyChat'), get_field_id('therapy_chat_polling_interval'), '3', 'Message polling interval in seconds'),
(get_style_id('therapyChat'), get_field_id('therapy_tag_reasons'), '[{"key":"overwhelmed","label":"I am feeling overwhelmed","urgency":"normal"},{"key":"need_talk","label":"I need to talk soon","urgency":"urgent"},{"key":"urgent","label":"This feels urgent","urgency":"urgent"},{"key":"emergency","label":"Emergency - please respond immediately","urgency":"emergency"}]', 'JSON array of tag reasons with keys, labels, and urgency levels'),

-- LLM configuration (reuses fields from llmChat)
(get_style_id('therapyChat'), get_field_id('llm_model'), '', 'Select AI model'),
(get_style_id('therapyChat'), get_field_id('llm_temperature'), '1', 'Temperature setting'),
(get_style_id('therapyChat'), get_field_id('llm_max_tokens'), '2048', 'Max tokens'),
(get_style_id('therapyChat'), get_field_id('conversation_context'), '', 'System context for AI (therapy-specific instructions)'),
(get_style_id('therapyChat'), get_field_id('enable_danger_detection'), '1', 'Enable danger word detection'),
(get_style_id('therapyChat'), get_field_id('danger_keywords'), 'suicide,selbstmord,kill myself,mich umbringen,self-harm,selbstverletzung,harm myself,mir schaden,end my life,mein leben beenden,overdose,überdosis', 'Danger keywords'),
(get_style_id('therapyChat'), get_field_id('danger_notification_emails'), '', 'Alert email addresses'),
(get_style_id('therapyChat'), get_field_id('danger_blocked_message'), 'I noticed some concerning content in your message. While I want to help, please consider reaching out to a trusted person or crisis hotline. Your well-being is important.', 'Message shown when danger detected'),

-- Therapy-specific labels
(get_style_id('therapyChat'), get_field_id('therapy_ai_label'), 'AI Assistant', 'Label for AI messages'),
(get_style_id('therapyChat'), get_field_id('therapy_therapist_label'), 'Therapist', 'Label for therapist messages'),
(get_style_id('therapyChat'), get_field_id('therapy_tag_button_label'), 'Tag Therapist', 'Tag button label'),
(get_style_id('therapyChat'), get_field_id('therapy_empty_message'), 'No messages yet. Start the conversation!', 'Empty state message'),
(get_style_id('therapyChat'), get_field_id('therapy_ai_thinking_text'), 'AI is thinking...', 'AI processing indicator'),
(get_style_id('therapyChat'), get_field_id('therapy_mode_indicator_ai'), 'AI-assisted chat', 'Mode indicator for AI hybrid'),
(get_style_id('therapyChat'), get_field_id('therapy_mode_indicator_human'), 'Therapist-only mode', 'Mode indicator for human only'),

-- Reuse existing llmChat labels
(get_style_id('therapyChat'), get_field_id('submit_button_label'), 'Send', 'Send button label'),
(get_style_id('therapyChat'), get_field_id('message_placeholder'), 'Type your message...', 'Input placeholder'),
(get_style_id('therapyChat'), get_field_id('loading_text'), 'Loading...', 'Loading text'),

-- Help text shown below the chat input to explain @mention and #hashtag usage
(get_style_id('therapyChat'), get_field_id('therapy_chat_help_text'), 'Use @therapist to request your therapist, or #topic to tag a predefined topic.', 'Help text shown below the chat input. Explains @mention and #hashtag functionality. Supports multilingual content via field translations.');

-- =====================================================
-- FIELDS FOR therapistDashboard
-- =====================================================

-- Dashboard UI labels and headings
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'dashboard_conversations_heading', get_field_type_id('text'), '1'),
(NULL, 'dashboard_alerts_heading', get_field_type_id('text'), '1'),
(NULL, 'dashboard_notes_heading', get_field_type_id('text'), '1'),
(NULL, 'dashboard_stats_heading', get_field_type_id('text'), '1'),
(NULL, 'dashboard_no_conversations', get_field_type_id('text'), '1'),
(NULL, 'dashboard_no_alerts', get_field_type_id('text'), '1'),
(NULL, 'dashboard_select_conversation', get_field_type_id('text'), '1'),
(NULL, 'dashboard_send_placeholder', get_field_type_id('text'), '1'),
(NULL, 'dashboard_send_button', get_field_type_id('text'), '1'),
(NULL, 'dashboard_add_note_placeholder', get_field_type_id('text'), '1'),
(NULL, 'dashboard_add_note_button', get_field_type_id('text'), '1'),
(NULL, 'dashboard_loading_text', get_field_type_id('text'), '1'),
(NULL, 'dashboard_ai_label', get_field_type_id('text'), '1'),
(NULL, 'dashboard_therapist_label', get_field_type_id('text'), '1'),
(NULL, 'dashboard_subject_label', get_field_type_id('text'), '1');

-- Risk level labels
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'dashboard_risk_heading', get_field_type_id('text'), '1'),
(NULL, 'dashboard_risk_low', get_field_type_id('text'), '1'),
(NULL, 'dashboard_risk_medium', get_field_type_id('text'), '1'),
(NULL, 'dashboard_risk_high', get_field_type_id('text'), '1'),
(NULL, 'dashboard_risk_critical', get_field_type_id('text'), '1');

-- Status labels
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'dashboard_status_active', get_field_type_id('text'), '1'),
(NULL, 'dashboard_status_paused', get_field_type_id('text'), '1'),
(NULL, 'dashboard_status_closed', get_field_type_id('text'), '1');

-- AI control labels
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'dashboard_disable_ai', get_field_type_id('text'), '1'),
(NULL, 'dashboard_enable_ai', get_field_type_id('text'), '1'),
(NULL, 'dashboard_ai_mode_indicator', get_field_type_id('text'), '1'),
(NULL, 'dashboard_human_mode_indicator', get_field_type_id('text'), '1');

-- Action labels
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'dashboard_acknowledge_button', get_field_type_id('text'), '1'),
(NULL, 'dashboard_dismiss_button', get_field_type_id('text'), '1'),
(NULL, 'dashboard_view_llm_button', get_field_type_id('text'), '1'),
(NULL, 'dashboard_join_conversation', get_field_type_id('text'), '1'),
(NULL, 'dashboard_leave_conversation', get_field_type_id('text'), '1');

-- Statistics labels
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'dashboard_stat_patients', get_field_type_id('text'), '1'),
(NULL, 'dashboard_stat_active', get_field_type_id('text'), '1'),
(NULL, 'dashboard_stat_critical', get_field_type_id('text'), '1'),
(NULL, 'dashboard_stat_alerts', get_field_type_id('text'), '1'),
(NULL, 'dashboard_stat_tags', get_field_type_id('text'), '1');

-- Dashboard functional settings
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'dashboard_polling_interval', get_field_type_id('number'), '0'),
(NULL, 'dashboard_show_risk_column', get_field_type_id('checkbox'), '0'),
(NULL, 'dashboard_show_status_column', get_field_type_id('checkbox'), '0'),
(NULL, 'dashboard_show_alerts_panel', get_field_type_id('checkbox'), '0'),
(NULL, 'dashboard_show_notes_panel', get_field_type_id('checkbox'), '0'),
(NULL, 'dashboard_show_stats_header', get_field_type_id('checkbox'), '0'),
(NULL, 'dashboard_enable_ai_toggle', get_field_type_id('checkbox'), '0'),
(NULL, 'dashboard_enable_risk_control', get_field_type_id('checkbox'), '0'),
(NULL, 'dashboard_enable_status_control', get_field_type_id('checkbox'), '0'),
(NULL, 'dashboard_enable_notes', get_field_type_id('checkbox'), '0'),
(NULL, 'dashboard_enable_invisible_mode', get_field_type_id('checkbox'), '0'),
(NULL, 'dashboard_messages_per_page', get_field_type_id('number'), '0'),
(NULL, 'dashboard_conversations_per_page', get_field_type_id('number'), '0');

-- Filter labels
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'dashboard_filter_all', get_field_type_id('text'), '1'),
(NULL, 'dashboard_filter_active', get_field_type_id('text'), '1'),
(NULL, 'dashboard_filter_critical', get_field_type_id('text'), '1'),
(NULL, 'dashboard_filter_unread', get_field_type_id('text'), '1'),
(NULL, 'dashboard_filter_tagged', get_field_type_id('text'), '1');

-- Notification settings (for email alerts)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'dashboard_notify_on_tag', get_field_type_id('checkbox'), '0'),
(NULL, 'dashboard_notify_on_danger', get_field_type_id('checkbox'), '0'),
(NULL, 'dashboard_notify_on_critical', get_field_type_id('checkbox'), '0'),
(NULL, 'dashboard_notify_email_subject', get_field_type_id('text'), '1'),
(NULL, 'dashboard_notify_email_template', get_field_type_id('markdown'), '1');

-- Intervention messages
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'dashboard_intervention_message', get_field_type_id('text'), '1'),
(NULL, 'dashboard_ai_paused_notice', get_field_type_id('text'), '1'),
(NULL, 'dashboard_ai_resumed_notice', get_field_type_id('text'), '1');

-- Draft message labels
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'dashboard_generate_draft_button', get_field_type_id('text'), '1'),
(NULL, 'dashboard_edit_draft_button', get_field_type_id('text'), '1'),
(NULL, 'dashboard_send_draft_button', get_field_type_id('text'), '1'),
(NULL, 'dashboard_discard_draft_button', get_field_type_id('text'), '1'),
(NULL, 'dashboard_draft_placeholder', get_field_type_id('text'), '1');

-- Summarize labels
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'dashboard_summarize_button', get_field_type_id('text'), '1'),
(NULL, 'dashboard_summarize_save_button', get_field_type_id('text'), '1');

-- =====================================================
-- STYLE FIELDS FOR therapistDashboard
-- =====================================================

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
-- Core CSS
(get_style_id('therapistDashboard'), get_field_id('css'), NULL, 'CSS classes for the dashboard container'),
(get_style_id('therapistDashboard'), get_field_id('css_mobile'), NULL, 'CSS classes for mobile view'),
(get_style_id('therapistDashboard'), get_field_id('debug'), '0', 'Enable debug mode for the section'),

-- Dashboard UI labels and headings
(get_style_id('therapistDashboard'), get_field_id('title'), 'Therapist Dashboard', 'Main dashboard title'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_conversations_heading'), 'Patient Conversations', 'Heading for conversations list'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_alerts_heading'), 'Alerts', 'Heading for alerts panel'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_notes_heading'), 'Clinical Notes', 'Heading for notes section'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_stats_heading'), 'Overview', 'Heading for statistics header'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_no_conversations'), 'No patient conversations found.', 'Message when no conversations exist'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_no_alerts'), 'No alerts at this time.', 'Message when no alerts exist'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_select_conversation'), 'Select a patient conversation to view messages and respond.', 'Message when no conversation selected'),

-- Input labels
(get_style_id('therapistDashboard'), get_field_id('dashboard_send_placeholder'), 'Type your response to the patient...', 'Placeholder for message input'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_send_button'), 'Send Response', 'Label for send button'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_add_note_placeholder'), 'Add a clinical note (not visible to patient)...', 'Placeholder for note input'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_add_note_button'), 'Add Note', 'Label for add note button'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_loading_text'), 'Loading...', 'Loading indicator text'),

-- Message labels
(get_style_id('therapistDashboard'), get_field_id('dashboard_ai_label'), 'AI Assistant', 'Label for AI-generated messages'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_therapist_label'), 'Therapist', 'Label for therapist messages'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_subject_label'), 'Patient', 'Label for patient messages'),

-- Risk level labels
(get_style_id('therapistDashboard'), get_field_id('dashboard_risk_heading'), 'Risk Level', 'Heading for risk control section'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_risk_low'), 'Low', 'Label for low risk level'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_risk_medium'), 'Medium', 'Label for medium risk level'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_risk_high'), 'High', 'Label for high risk level'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_risk_critical'), 'Critical', 'Label for critical risk level'),

-- Status labels
(get_style_id('therapistDashboard'), get_field_id('dashboard_status_active'), 'Active', 'Label for active status'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_status_paused'), 'Paused', 'Label for paused status'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_status_closed'), 'Closed', 'Label for closed status'),

-- AI control labels
(get_style_id('therapistDashboard'), get_field_id('dashboard_disable_ai'), 'Pause AI', 'Button label to disable AI responses'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_enable_ai'), 'Resume AI', 'Button label to enable AI responses'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_ai_mode_indicator'), 'AI-assisted mode', 'Indicator when AI is active'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_human_mode_indicator'), 'Therapist-only mode', 'Indicator when AI is paused'),

-- Action button labels
(get_style_id('therapistDashboard'), get_field_id('dashboard_acknowledge_button'), 'Acknowledge', 'Button to acknowledge an alert/tag'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_dismiss_button'), 'Dismiss', 'Button to dismiss an alert'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_view_llm_button'), 'View in LLM Console', 'Button to open conversation in LLM console'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_join_conversation'), 'Join Conversation', 'Button to actively join a conversation'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_leave_conversation'), 'Leave Conversation', 'Button to exit therapist-only mode'),

-- Statistics labels
(get_style_id('therapistDashboard'), get_field_id('dashboard_stat_patients'), 'Patients', 'Label for total patients stat'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_stat_active'), 'Active', 'Label for active conversations stat'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_stat_critical'), 'Critical', 'Label for critical risk stat'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_stat_alerts'), 'Alerts', 'Label for alerts count stat'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_stat_tags'), 'Tags', 'Label for tags count stat'),

-- Functional settings
(get_style_id('therapistDashboard'), get_field_id('dashboard_polling_interval'), '5', 'Polling interval in seconds for dashboard updates'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_show_risk_column'), '1', 'Show risk level in conversation list'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_show_status_column'), '1', 'Show status in conversation list'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_show_alerts_panel'), '1', 'Show alerts panel in dashboard'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_show_notes_panel'), '1', 'Show clinical notes panel'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_show_stats_header'), '1', 'Show statistics in header'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_enable_ai_toggle'), '1', 'Allow therapist to toggle AI on/off'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_enable_risk_control'), '1', 'Allow therapist to change risk level'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_enable_status_control'), '1', 'Allow therapist to change conversation status'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_enable_notes'), '1', 'Allow therapist to add clinical notes'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_enable_invisible_mode'), '1', 'Allow therapist to observe without patient knowing'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_messages_per_page'), '50', 'Number of messages to load per page'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_conversations_per_page'), '20', 'Number of conversations to show in list'),

-- Filter labels
(get_style_id('therapistDashboard'), get_field_id('dashboard_filter_all'), 'All', 'Label for all filter'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_filter_active'), 'Active', 'Label for active filter'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_filter_critical'), 'Critical', 'Label for critical filter'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_filter_unread'), 'Unread', 'Label for unread filter'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_filter_tagged'), 'Tagged', 'Label for tagged filter'),

-- Notification settings
(get_style_id('therapistDashboard'), get_field_id('dashboard_notify_on_tag'), '1', 'Send email when therapist is tagged'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_notify_on_danger'), '1', 'Send email when danger keywords detected'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_notify_on_critical'), '1', 'Send email when conversation marked critical'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_notify_email_subject'), '[Therapy Chat] Alert: {{alert_type}}', 'Email subject template for notifications'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_notify_email_template'), 'A new alert has been triggered:\n\n**Patient:** {{subject_name}} ({{subject_code}})\n**Type:** {{alert_type}}\n**Severity:** {{severity}}\n**Message:** {{message}}\n\nPlease review in the dashboard.', 'Email body template for notifications'),

-- Intervention messages
(get_style_id('therapistDashboard'), get_field_id('dashboard_intervention_message'), 'Your therapist has joined the conversation.', 'Message shown to patient when therapist joins'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_ai_paused_notice'), 'AI responses have been paused. Your therapist will respond directly.', 'Message when AI is paused'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_ai_resumed_notice'), 'AI-assisted support has been resumed.', 'Message when AI is resumed'),

-- Draft message labels
(get_style_id('therapistDashboard'), get_field_id('dashboard_generate_draft_button'), 'Generate AI Draft', 'Button to generate AI response draft'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_edit_draft_button'), 'Edit Draft', 'Button to edit AI draft'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_send_draft_button'), 'Send Draft', 'Button to send edited draft'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_discard_draft_button'), 'Discard', 'Button to discard draft'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_draft_placeholder'), 'AI-generated response will appear here for your review...', 'Placeholder for draft area'),

-- Summarize labels and context
(get_style_id('therapistDashboard'), get_field_id('dashboard_summarize_button'), 'Summarize Conversation', 'Button to generate AI summary'),
(get_style_id('therapistDashboard'), get_field_id('dashboard_summarize_save_button'), 'Save Summary', 'Button to save AI summary as note'),

-- Summarization context: customizable prompt context for AI summaries
(get_style_id('therapistDashboard'), get_field_id('therapy_summary_context'), 'Focus on the therapeutic relationship, emotional patterns, intervention effectiveness, and patient progress. Highlight any risk factors or concerns.', 'Additional context/instructions for the AI summarization. This text is prepended to the summarization prompt to guide the AI output. Supports multilingual content via field translations.'),

-- Draft context: customizable prompt context for AI draft generation
(get_style_id('therapistDashboard'), get_field_id('therapy_draft_context'), 'Generate a response based on the full conversation history and the patient''s last message. Consider therapeutic best practices, empathy, and clinical appropriateness.', 'Additional context/instructions for AI draft generation. This text is appended to the draft system prompt to guide the AI output. Supports multilingual content via field translations.'),

-- LLM config for draft generation and summarization (shared field names with therapyChat)
(get_style_id('therapistDashboard'), get_field_id('llm_model'), '', 'AI model for draft generation and summarization'),
(get_style_id('therapistDashboard'), get_field_id('llm_temperature'), '0.7', 'Temperature for AI draft/summary generation'),
(get_style_id('therapistDashboard'), get_field_id('llm_max_tokens'), '2048', 'Max tokens for AI draft/summary responses'),
(get_style_id('therapistDashboard'), get_field_id('conversation_context'), '', 'System context for AI responses in draft generation');

-- =====================================================
-- PAGES FOR SUBJECT AND THERAPIST
-- =====================================================

-- Subject chat page
INSERT IGNORE INTO pages (`id`, `keyword`, `url`, `protocol`, `id_actions`, `id_navigation_section`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`)
VALUES (NULL, 'therapyChatSubject', '/therapy-chat/subject/[i:gid]?', 'GET|POST', '0000000003', NULL, NULL, '0', NULL, NULL, '0000000003', (SELECT id FROM lookups WHERE lookup_code = "mobile_and_web" LIMIT 0, 1));

INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`)
VALUES ((SELECT id FROM `groups` WHERE `name` = 'admin'), (SELECT id FROM pages WHERE keyword = 'therapyChatSubject'), '1', '1', '1', '1');

INSERT IGNORE INTO sections (id_styles, name) VALUES(get_style_id('div'), 'therapyChatSubject-div');
INSERT IGNORE INTO sections (id_styles, name) VALUES(get_style_id('therapyChat'), 'therapyChatSubject-chat');
INSERT IGNORE INTO pages_sections (id_pages, id_Sections, position) VALUES((SELECT id FROM pages WHERE keyword = 'therapyChatSubject'), (SELECT id FROM sections WHERE `name` = 'therapyChatSubject-div'), 1);
INSERT IGNORE INTO sections_hierarchy (parent, child, position) VALUES((SELECT id FROM sections WHERE name = 'therapyChatSubject-div'), (SELECT id FROM sections WHERE `name` = 'therapyChatSubject-chat'), 1);

INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
VALUES
((SELECT id FROM pages WHERE keyword = 'therapyChatSubject'), get_field_id('title'), '0000000003', 'Therapy Chat'),
((SELECT id FROM pages WHERE keyword = 'therapyChatSubject'), get_field_id('title'), '0000000002', 'Therapie-Chat');

-- Therapist dashboard page
INSERT IGNORE INTO pages (`id`, `keyword`, `url`, `protocol`, `id_actions`, `id_navigation_section`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`)
VALUES (NULL, 'therapyChatTherapist', '/therapy-chat/therapist/[i:gid]?/[i:uid]?', 'GET|POST', '0000000003', NULL, NULL, '0', NULL, NULL, '0000000003', (SELECT id FROM lookups WHERE lookup_code = "mobile_and_web" LIMIT 0, 1));

INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`)
VALUES ((SELECT id FROM `groups` WHERE `name` = 'admin'), (SELECT id FROM pages WHERE keyword = 'therapyChatTherapist'), '1', '1', '1', '1');
INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`)
VALUES ((SELECT id FROM `groups` WHERE `name` = 'therapist'), (SELECT id FROM pages WHERE keyword = 'therapyChatTherapist'), '1', '1', '0', '0');

INSERT IGNORE INTO sections (id_styles, name) VALUES(get_style_id('div'), 'therapyChatTherapist-div');
INSERT IGNORE INTO sections (id_styles, name) VALUES(get_style_id('therapistDashboard'), 'therapyChatTherapist-dashboard');
INSERT IGNORE INTO pages_sections (id_pages, id_Sections, position) VALUES((SELECT id FROM pages WHERE keyword = 'therapyChatTherapist'), (SELECT id FROM sections WHERE `name` = 'therapyChatTherapist-div'), 1);
INSERT IGNORE INTO sections_hierarchy (parent, child, position) VALUES((SELECT id FROM sections WHERE name = 'therapyChatTherapist-div'), (SELECT id FROM sections WHERE `name` = 'therapyChatTherapist-dashboard'), 1);

INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
VALUES
((SELECT id FROM pages WHERE keyword = 'therapyChatTherapist'), get_field_id('title'), '0000000003', 'Therapist Dashboard'),
((SELECT id FROM pages WHERE keyword = 'therapyChatTherapist'), get_field_id('title'), '0000000002', 'Therapeuten Dashboard');

-- =====================================================
-- HOOKS REGISTRATION
-- =====================================================

-- Floating chat icon hook (shows button in nav for subjects/therapists)
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_on_function_execute' LIMIT 0,1), 'outputTherapyChatIcon', 'Output therapy chat icon next to profile. Shows unread message count.', 'NavView', 'output_profile', 'TherapyChatHooks', 'outputTherapyChatIcon');

-- Hooks for select-page field type (CMS)
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-select-page-edit', 'Output select page field - edit mode', 'CmsView', 'create_field_form_item', 'TherapyChatHooks', 'outputFieldSelectPageEdit', 5);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-select-page-view', 'Output select page field - view mode', 'CmsView', 'create_field_item', 'TherapyChatHooks', 'outputFieldSelectPageView', 5);

-- Hooks for select-floating-position field type (CMS)
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-select-floating-position-edit', 'Output select floating position field - edit mode', 'CmsView', 'create_field_form_item', 'TherapyChatHooks', 'outputFieldSelectFloatingPositionEdit', 5);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-select-floating-position-view', 'Output select floating position field - view mode', 'CmsView', 'create_field_item', 'TherapyChatHooks', 'outputFieldSelectFloatingPositionView', 5);

-- Hook for admin user page: inject therapist group assignment card
-- When viewing a user at /admin/user/{uid}, this adds a card showing
-- which patient groups this user (as therapist) can monitor.
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_on_function_execute' LIMIT 0,1), 'therapyChat-therapist-assignments', 'Show therapy chat group assignments on admin user page. Allows admins to assign patient groups to therapists.', 'UserSelectView', 'output_user_manipulation', 'TherapyChatHooks', 'outputTherapistGroupAssignments');

-- AJAX endpoint for saving therapist group assignments
INSERT IGNORE INTO `pages` (`id`, `keyword`, `url`, `protocol`, `id_actions`, `id_navigation_section`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`)
VALUES (NULL, 'ajax_therapy_chat_save_assignments', '/request/[AjaxTherapyChat:class]/[saveTherapistAssignments:method]', 'POST', (SELECT id FROM actions WHERE `name` = 'ajax' LIMIT 1), NULL, NULL, 0, NULL, NULL, 1, (SELECT id FROM lookups WHERE type_code = "pageAccessTypes" AND lookup_code = "mobile_and_web" LIMIT 1));

-- Grant admin group full ACL access to the AJAX page
INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`)
VALUES ((SELECT id FROM `groups` WHERE `name` = 'admin' LIMIT 1), (SELECT id FROM `pages` WHERE `keyword` = 'ajax_therapy_chat_save_assignments' LIMIT 1), 1, 1, 1, 1);

-- Hook to load JS for therapy assignments on user admin pages
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return' LIMIT 0,1), 'therapyChat-assignments-script', 'Load JS for therapy assignments on user admin pages.', 'BasePage', 'get_js_includes', 'TherapyChatHooks', 'loadTherapyAssignmentsJs');

-- =====================================================
-- TRANSACTION LOGGING
-- =====================================================

INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES ('transactionBy', 'by_therapy_chat_plugin', 'By Therapy Chat Plugin', 'Actions performed by the LLM Therapy Chat plugin');

-- =====================================================
-- CONFIG PAGE FIELD VALUES
-- =====================================================

INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
VALUES
(@id_page_therapy_chat_config, get_field_id('therapy_chat_subject_group'), '0000000001', (SELECT id FROM `groups` WHERE `name` = 'subject' LIMIT 1)),
(@id_page_therapy_chat_config, get_field_id('therapy_chat_therapist_group'), '0000000001', (SELECT id FROM `groups` WHERE `name` = 'therapist' LIMIT 1)),
(@id_page_therapy_chat_config, get_field_id('therapy_chat_subject_page'), '0000000001', (SELECT id FROM pages WHERE keyword = 'therapyChatSubject')),
(@id_page_therapy_chat_config, get_field_id('therapy_chat_therapist_page'), '0000000001', (SELECT id FROM pages WHERE keyword = 'therapyChatTherapist')),
(@id_page_therapy_chat_config, get_field_id('therapy_chat_floating_icon'), '0000000001', 'fa-comments'),
(@id_page_therapy_chat_config, get_field_id('therapy_chat_floating_label'), '0000000001', ''),
(@id_page_therapy_chat_config, get_field_id('therapy_chat_floating_position'), '0000000001', 'bottom-right'),
(@id_page_therapy_chat_config, get_field_id('therapy_chat_default_mode'), '0000000001', 'ai_hybrid'),
(@id_page_therapy_chat_config, get_field_id('therapy_chat_polling_interval'), '0000000001', '3'),
(@id_page_therapy_chat_config, get_field_id('therapy_chat_enable_tagging'), '0000000001', '1'),
(@id_page_therapy_chat_config, get_field_id('therapy_tag_reasons'), '0000000002', '[{"key":"overwhelmed","label":"I am feeling overwhelmed","urgency":"normal"},{"key":"need_talk","label":"I need to talk soon","urgency":"urgent"},{"key":"urgent","label":"This feels urgent","urgency":"urgent"},{"key":"emergency","label":"Emergency - please respond immediately","urgency":"emergency"}]');

-- =====================================================
-- SPEECH-TO-TEXT CONFIGURATION
-- =====================================================

-- Add speech-to-text fields to therapyChat style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('therapyChat'), get_field_id('enable_speech_to_text'), '0',
 'Enable speech-to-text input for patients. When enabled and an audio model is selected, a microphone button appears in the message input area.'),

(get_style_id('therapyChat'), get_field_id('speech_to_text_model'), '',
 'Select the Whisper model for speech recognition. Leave empty to use the default model configured in the LLM plugin.');

-- Add speech-to-text fields to therapistDashboard style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('therapistDashboard'), get_field_id('enable_speech_to_text'), '0',
 'Enable speech-to-text input for therapists. When enabled and an audio model is selected, a microphone button appears.'),

(get_style_id('therapistDashboard'), get_field_id('speech_to_text_model'), '',
 'Select the Whisper model for speech recognition in the therapist dashboard.');

-- Language field for speech recognition
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'speech_to_text_language', get_field_type_id('text'), '0');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('therapyChat'), get_field_id('speech_to_text_language'), 'auto',
 'Language code for speech recognition (e.g., "en", "de", "fr"). Use "auto" for automatic detection.'),

(get_style_id('therapistDashboard'), get_field_id('speech_to_text_language'), 'auto',
 'Language code for speech recognition (e.g., "en", "de", "fr"). Use "auto" for automatic detection.');

-- =====================================================
-- FLOATING CHAT CONFIGURATION FOR therapyChat
-- =====================================================
-- When enable_floating_chat is active, clicking the server-rendered floating
-- icon opens an inline modal instead of navigating to the page.
-- Position, icon, and label are controlled by the main plugin config page
-- (therapy_chat_floating_icon, therapy_chat_floating_position, therapy_chat_floating_label).

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'enable_floating_chat', get_field_type_id('checkbox'), '0');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('therapyChat'), get_field_id('enable_floating_chat'), '0',
 'Enable floating/modal chat interface. When enabled, clicking the global floating icon opens the chat in a modal panel instead of navigating to the page. Icon, position and label are configured in the main plugin config page.');

-- =====================================================
-- EMAIL NOTIFICATION CONFIGURATION
-- =====================================================

-- Field definitions for email notifications
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'enable_patient_email_notification', get_field_type_id('checkbox'), '0'),
(NULL, 'enable_therapist_email_notification', get_field_type_id('checkbox'), '0'),
(NULL, 'patient_notification_email_subject', get_field_type_id('text'), '1'),
(NULL, 'patient_notification_email_body', get_field_type_id('markdown'), '1'),
(NULL, 'therapist_notification_email_subject', get_field_type_id('text'), '1'),
(NULL, 'therapist_notification_email_body', get_field_type_id('markdown'), '1'),
(NULL, 'therapist_tag_email_subject', get_field_type_id('text'), '1'),
(NULL, 'therapist_tag_email_body', get_field_type_id('markdown'), '1'),
(NULL, 'notification_from_email', get_field_type_id('text'), '0'),
(NULL, 'notification_from_name', get_field_type_id('text'), '0');

-- Email notification settings for therapistDashboard style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('therapistDashboard'), get_field_id('enable_patient_email_notification'), '1',
 'Enable email notifications to patients when a therapist sends a message. Default: enabled.'),
(get_style_id('therapistDashboard'), get_field_id('enable_therapist_email_notification'), '1',
 'Enable email notifications to therapists when a patient sends a message or tags them. Default: enabled.'),
(get_style_id('therapistDashboard'), get_field_id('patient_notification_email_subject'), '[Therapy Chat] New message from your therapist',
 'Email subject for patient notifications. Placeholders: @therapist_name'),
(get_style_id('therapistDashboard'), get_field_id('patient_notification_email_body'),
 '<p>Hello @user_name,</p><p>You have received a new message from <strong>@therapist_name</strong> in your therapy chat.</p><p>Please log in to read and respond to the message.</p><p>Best regards,<br>Therapy Chat</p>',
 'Email body for patient notifications. Placeholders: @user_name, @therapist_name. Supports HTML.'),
(get_style_id('therapistDashboard'), get_field_id('therapist_notification_email_subject'), '[Therapy Chat] New message from {{patient_name}}',
 'Email subject for therapist notifications. Placeholders: {{patient_name}}'),
(get_style_id('therapistDashboard'), get_field_id('therapist_notification_email_body'),
 '<p>Hello,</p><p>You have received a new message from <strong>{{patient_name}}</strong> in therapy chat.</p><p>Please log in to the Therapist Dashboard to review.</p><p>Best regards,<br>Therapy Chat</p>',
 'Email body for therapist notifications when a patient sends a message. Placeholders: {{patient_name}}, @user_name. Supports HTML.'),
(get_style_id('therapistDashboard'), get_field_id('therapist_tag_email_subject'), '[Therapy Chat] @therapist tag from {{patient_name}}',
 'Email subject for therapist tag notifications. Placeholders: {{patient_name}}'),
(get_style_id('therapistDashboard'), get_field_id('therapist_tag_email_body'),
 '<p>Hello,</p><p><strong>{{patient_name}}</strong> has tagged you (@therapist) in their therapy chat.</p><p><em>Message preview:</em> {{message_preview}}</p><p>Please log in to the Therapist Dashboard to respond.</p><p>Best regards,<br>Therapy Chat</p>',
 'Email body for therapist tag notifications. Placeholders: {{patient_name}}, {{message_preview}}, @user_name. Supports HTML.'),
(get_style_id('therapistDashboard'), get_field_id('notification_from_email'), 'noreply@selfhelp.local',
 'Sender email address for therapy chat notifications.'),
(get_style_id('therapistDashboard'), get_field_id('notification_from_name'), 'Therapy Chat',
 'Sender display name for therapy chat notifications.');

-- Email notification settings for therapyChat style (patient-side sends notifications to therapists)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('therapyChat'), get_field_id('enable_therapist_email_notification'), '1',
 'Enable email notifications to therapists when a patient sends a message or tags them. Default: enabled.'),
(get_style_id('therapyChat'), get_field_id('therapist_notification_email_subject'), '[Therapy Chat] New message from {{patient_name}}',
 'Email subject for therapist notifications from patient chat. Placeholders: {{patient_name}}'),
(get_style_id('therapyChat'), get_field_id('therapist_notification_email_body'),
 '<p>Hello,</p><p>You have received a new message from <strong>{{patient_name}}</strong> in therapy chat.</p><p>Please log in to the Therapist Dashboard to review.</p><p>Best regards,<br>Therapy Chat</p>',
 'Email body for therapist notifications from patient chat. Placeholders: {{patient_name}}, @user_name. Supports HTML.'),
(get_style_id('therapyChat'), get_field_id('therapist_tag_email_subject'), '[Therapy Chat] @therapist tag from {{patient_name}}',
 'Email subject for therapist tag notifications from patient chat. Placeholders: {{patient_name}}'),
(get_style_id('therapyChat'), get_field_id('therapist_tag_email_body'),
 '<p>Hello,</p><p><strong>{{patient_name}}</strong> has tagged you (@therapist) in their therapy chat.</p><p><em>Message preview:</em> {{message_preview}}</p><p>Please log in to the Therapist Dashboard to respond.</p><p>Best regards,<br>Therapy Chat</p>',
 'Email body for therapist tag notifications from patient chat. Placeholders: {{patient_name}}, {{message_preview}}, @user_name. Supports HTML.'),
(get_style_id('therapyChat'), get_field_id('notification_from_email'), 'noreply@selfhelp.local',
 'Sender email address for therapy chat notifications from patient side.'),
(get_style_id('therapyChat'), get_field_id('notification_from_name'), 'Therapy Chat',
 'Sender display name for therapy chat notifications from patient side.');
