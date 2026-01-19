-- =====================================================
-- SelfHelp Plugin: LLM Therapy Chat
-- Version: 1.0.0
-- Description: Therapy chat extension for sh-shp-llm plugin
-- 
-- DEPENDENCY: Requires sh-shp-llm plugin to be installed first!
-- This plugin extends the llmConversations and llmMessages tables
-- with therapy-specific functionality.
-- =====================================================

-- Add plugin entry
INSERT IGNORE INTO plugins (name, version) 
VALUES ('llm_therapy_chat', 'v1.0.0');

-- =====================================================
-- DEPENDENCY CHECK
-- Verify that the LLM plugin is installed
-- =====================================================

-- Check for llmConversations table (created by sh-shp-llm)
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
-- Add select-page field type
-- =====================================================

INSERT IGNORE INTO fieldType (`name`, `description`) VALUES ('select-page', 'Select Page - Dropdown for selecting pages');

-- =====================================================
-- LOOKUP ENTRIES
-- All ENUM-like values are stored in lookups table
-- =====================================================

-- Chat Modes
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('therapyChatModes', 'ai_hybrid', 'AI Hybrid', 'AI responds with therapist oversight and intervention capability'),
('therapyChatModes', 'human_only', 'Human Only', 'Only human therapist responds, no AI involvement');

-- Conversation Status
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('therapyConversationStatus', 'active', 'Active', 'Active conversation, messages can be sent'),
('therapyConversationStatus', 'paused', 'Paused', 'Paused conversation, temporarily inactive'),
('therapyConversationStatus', 'closed', 'Closed', 'Closed conversation, no new messages');

-- Risk Levels
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('therapyRiskLevels', 'low', 'Low', 'Low risk - normal activity'),
('therapyRiskLevels', 'medium', 'Medium', 'Medium risk - requires attention'),
('therapyRiskLevels', 'high', 'High', 'High risk - needs review'),
('therapyRiskLevels', 'critical', 'Critical', 'Critical risk - immediate attention required');

-- Tag Urgency
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('therapyTagUrgency', 'normal', 'Normal', 'Normal urgency tag'),
('therapyTagUrgency', 'urgent', 'Urgent', 'Urgent tag - needs attention soon'),
('therapyTagUrgency', 'emergency', 'Emergency', 'Emergency tag - immediate attention required');

-- Alert Types
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('therapyAlertTypes', 'danger_detected', 'Danger Detected', 'Dangerous keywords detected in message'),
('therapyAlertTypes', 'tag_received', 'Tag Received', 'Therapist was tagged by subject'),
('therapyAlertTypes', 'high_activity', 'High Activity', 'Unusual high message activity'),
('therapyAlertTypes', 'inactivity', 'Inactivity', 'Extended silence from subject'),
('therapyAlertTypes', 'new_message', 'New Message', 'New message received');

-- Alert Severity
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES
('therapyAlertSeverity', 'info', 'Info', 'Informational alert'),
('therapyAlertSeverity', 'warning', 'Warning', 'Warning - needs attention'),
('therapyAlertSeverity', 'critical', 'Critical', 'Critical - urgent attention required'),
('therapyAlertSeverity', 'emergency', 'Emergency', 'Emergency - immediate action required');

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
(NULL, 'therapy_chat_floating_position', get_field_type_id('select-floating-button-position'), '0'),
(NULL, 'therapy_chat_default_mode', get_field_type_id('select'), '0'),
(NULL, 'therapy_chat_polling_interval', get_field_type_id('number'), '0'),
(NULL, 'therapy_chat_enable_tagging', get_field_type_id('checkbox'), '0'),
(NULL, 'therapy_tag_reasons', get_field_type_id('textarea'), '0');

-- Link fields to page type
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('title'), 'LLM Therapy Chat Configuration', 'Page title'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_subject_group'), (SELECT id FROM `groups` WHERE `name` = 'subject' LIMIT 1), 'Select the group that contains subjects (patients)'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_therapist_group'), (SELECT id FROM `groups` WHERE `name` = 'therapist' LIMIT 1), 'Select the group that contains therapists');

-- Set default values for configuration page fields
INSERT IGNORE INTO `pages_fields` (`id_pages`, `id_fields`, `default_value`, `help`) VALUES
(@id_page_therapy_chat_config, get_field_id('therapy_chat_subject_group'), (SELECT id FROM `groups` WHERE `name` = 'subject' LIMIT 1), 'Select the group that contains subjects (patients)'),
(@id_page_therapy_chat_config, get_field_id('therapy_chat_therapist_group'), (SELECT id FROM `groups` WHERE `name` = 'therapist' LIMIT 1), 'Select the group that contains therapists');

-- Add page translations for configuration fields
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
VALUES
(@id_page_therapy_chat_config, get_field_id('therapy_chat_subject_group'), '0000000001', (SELECT id FROM `groups` WHERE `name` = 'subject' LIMIT 1)),
(@id_page_therapy_chat_config, get_field_id('therapy_chat_therapist_group'), '0000000001', (SELECT id FROM `groups` WHERE `name` = 'therapist' LIMIT 1)),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_subject_page'), (SELECT id FROM pages WHERE keyword = 'therapyChatSubject'), 'Page ID for subject/patient chat interface'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_therapist_page'), (SELECT id FROM pages WHERE keyword = 'therapyChatTherapist'), 'Page ID for therapist dashboard'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_floating_icon'), 'fa-comments', 'Font Awesome icon class for the floating button'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_floating_label'), '', 'Optional text label for the floating button'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm_therapy_chat'), get_field_id('therapy_chat_floating_position'), 'bottom-right', 'Position of the floating button'),
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

-- =====================================================
-- THERAPY EXTENSION TABLES
-- These tables EXTEND the LLM plugin tables with therapy features
-- Uses lookups table for all status/type values
-- =====================================================

-- Therapy metadata for LLM conversations (1:1 relationship with llmConversations)
-- This table adds therapy-specific fields to conversations
CREATE TABLE IF NOT EXISTS `therapyConversationMeta` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llmConversations` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'Reference to llmConversations',
    `id_groups` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'Access group for therapist assignment',
    `id_therapist` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'Assigned therapist user ID',
    `id_chatModes` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'FK to lookups (therapyChatModes)',
    `ai_enabled` TINYINT(1) DEFAULT 1 COMMENT 'Can AI respond in this conversation',
    `id_conversationStatus` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'FK to lookups (therapyConversationStatus)',
    `id_riskLevels` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'FK to lookups (therapyRiskLevels)',
    `therapist_last_seen` TIMESTAMP NULL DEFAULT NULL COMMENT 'When therapist last viewed this conversation',
    `subject_last_seen` TIMESTAMP NULL DEFAULT NULL COMMENT 'When subject last viewed messages',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_llm_conversation` (`id_llmConversations`),
    KEY `idx_group` (`id_groups`),
    KEY `idx_therapist` (`id_therapist`),
    KEY `idx_status` (`id_conversationStatus`),
    KEY `idx_risk_level` (`id_riskLevels`),
    KEY `idx_chat_mode` (`id_chatModes`),
    CONSTRAINT `fk_therapyConversationMeta_llmConversations` FOREIGN KEY (`id_llmConversations`) REFERENCES `llmConversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyConversationMeta_groups` FOREIGN KEY (`id_groups`) REFERENCES `groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyConversationMeta_therapist` FOREIGN KEY (`id_therapist`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyConversationMeta_chatModes` FOREIGN KEY (`id_chatModes`) REFERENCES `lookups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyConversationMeta_status` FOREIGN KEY (`id_conversationStatus`) REFERENCES `lookups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyConversationMeta_riskLevel` FOREIGN KEY (`id_riskLevels`) REFERENCES `lookups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tagging system for @mentions (references llmMessages)
CREATE TABLE IF NOT EXISTS `therapyTags` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llmMessages` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'Reference to llmMessages',
    `id_users` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'Tagged user ID (therapist)',
    `tag_reason` VARCHAR(255) DEFAULT NULL COMMENT 'Tag reason key from JSON config',
    `id_tagUrgency` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'FK to lookups (therapyTagUrgency)',
    `acknowledged` TINYINT(1) DEFAULT 0,
    `acknowledged_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_message` (`id_llmMessages`),
    KEY `idx_tagged_user` (`id_users`),
    KEY `idx_acknowledged` (`acknowledged`),
    KEY `idx_urgency` (`id_tagUrgency`),
    CONSTRAINT `fk_therapyTags_llmMessages` FOREIGN KEY (`id_llmMessages`) REFERENCES `llmMessages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyTags_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyTags_urgency` FOREIGN KEY (`id_tagUrgency`) REFERENCES `lookups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alerts and notifications for therapists
CREATE TABLE IF NOT EXISTS `therapyAlerts` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llmConversations` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `id_users` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'Target therapist (NULL = all in group)',
    `id_alertTypes` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'FK to lookups (therapyAlertTypes)',
    `id_alertSeverity` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'FK to lookups (therapyAlertSeverity)',
    `message` TEXT,
    `metadata` JSON DEFAULT NULL COMMENT 'Additional alert data',
    `is_read` TINYINT(1) DEFAULT 0,
    `read_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_conversation` (`id_llmConversations`),
    KEY `idx_user` (`id_users`),
    KEY `idx_type` (`id_alertTypes`),
    KEY `idx_severity` (`id_alertSeverity`),
    KEY `idx_unread` (`is_read`, `created_at`),
    CONSTRAINT `fk_therapyAlerts_llmConversations` FOREIGN KEY (`id_llmConversations`) REFERENCES `llmConversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyAlerts_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyAlerts_alertTypes` FOREIGN KEY (`id_alertTypes`) REFERENCES `lookups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyAlerts_alertSeverity` FOREIGN KEY (`id_alertSeverity`) REFERENCES `lookups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Therapist notes on conversations (internal, not visible to subjects)
CREATE TABLE IF NOT EXISTS `therapyNotes` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llmConversations` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `id_users` INT(10) UNSIGNED ZEROFILL NOT NULL COMMENT 'Therapist who wrote the note',
    `content` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_conversation` (`id_llmConversations`),
    KEY `idx_therapist` (`id_users`),
    CONSTRAINT `fk_therapyNotes_llmConversations` FOREIGN KEY (`id_llmConversations`) REFERENCES `llmConversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_therapyNotes_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- VIEW FOR EASY QUERYING WITH LOOKUP VALUES
-- =====================================================

CREATE OR REPLACE VIEW `view_therapyConversations` AS
SELECT
    tcm.id,
    tcm.id_llmConversations,
    tcm.id_groups,
    tcm.id_therapist,
    tcm.ai_enabled,
    tcm.therapist_last_seen,
    tcm.subject_last_seen,
    tcm.created_at,
    tcm.updated_at,
    lc.id_users,
    lc.title,
    lc.model,
    lc.deleted,
    lc.blocked,
    u.name as subject_name,
    vc.code as subject_code,
    u.email as subject_email,
    t.name as therapist_name,
    g.name as group_name,
    mode_lookup.lookup_code as mode,
    mode_lookup.lookup_value as mode_label,
    status_lookup.lookup_code as status,
    status_lookup.lookup_value as status_label,
    risk_lookup.lookup_code as risk_level,
    risk_lookup.lookup_value as risk_level_label
FROM therapyConversationMeta tcm
INNER JOIN llmConversations lc ON lc.id = tcm.id_llmConversations
INNER JOIN users u ON u.id = lc.id_users
LEFT JOIN validation_codes vc ON vc.id_users = u.id AND vc.consumed IS NULL
INNER JOIN `groups` g ON g.id = tcm.id_groups
LEFT JOIN users t ON t.id = tcm.id_therapist
LEFT JOIN lookups mode_lookup ON mode_lookup.id = tcm.id_chatModes
LEFT JOIN lookups status_lookup ON status_lookup.id = tcm.id_conversationStatus
LEFT JOIN lookups risk_lookup ON risk_lookup.id = tcm.id_riskLevels;

CREATE OR REPLACE VIEW `view_therapyTags` AS
SELECT 
    tt.id,
    tt.id_llmMessages,
    tt.id_users,
    tt.tag_reason,
    tt.acknowledged,
    tt.acknowledged_at,
    tt.created_at,
    lm.content as message_content,
    lm.timestamp as message_time,
    lm.id_llmConversations as conversation_id,
    u.name as therapist_name,
    urgency_lookup.lookup_code as urgency,
    urgency_lookup.lookup_value as urgency_label
FROM therapyTags tt
INNER JOIN llmMessages lm ON lm.id = tt.id_llmMessages
INNER JOIN users u ON u.id = tt.id_users
LEFT JOIN lookups urgency_lookup ON urgency_lookup.id = tt.id_tagUrgency;

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
    lc.title as conversation_title,
    u.name as subject_name,
    vc.code as subject_code,
    type_lookup.lookup_code as alert_type,
    type_lookup.lookup_value as alert_type_label,
    severity_lookup.lookup_code as severity,
    severity_lookup.lookup_value as severity_label
FROM therapyAlerts ta
INNER JOIN llmConversations lc ON lc.id = ta.id_llmConversations
INNER JOIN users u ON u.id = lc.id_users
LEFT JOIN validation_codes vc ON vc.id_users = u.id AND vc.consumed IS NULL
LEFT JOIN lookups type_lookup ON type_lookup.id = ta.id_alertTypes
LEFT JOIN lookups severity_lookup ON severity_lookup.id = ta.id_alertSeverity;

-- =====================================================
-- STYLE REGISTRATION
-- =====================================================

-- Add therapyChat style for subject chat interface (extends llmChat)
INSERT IGNORE INTO `styles` (`name`, `id_type`, `id_group`, `description`)
VALUES ('therapyChat', (SELECT id FROM styleType WHERE `name` = 'component'), (SELECT id FROM styleGroup WHERE `name` = 'Form'), 'LLM Therapy Chat component for subject-therapist communication with AI support. Extends llmChat with therapy features.');

-- Add therapistDashboard style for therapist interface
INSERT IGNORE INTO `styles` (`name`, `id_type`, `id_group`, `description`)
VALUES ('therapistDashboard', (SELECT id FROM styleType WHERE `name` = 'component'), (SELECT id FROM styleGroup WHERE `name` = 'intern'), 'Therapist dashboard for managing therapy conversations');

-- =====================================================
-- STYLE FIELDS FOR therapyChat
-- Reuses many fields from llmChat, adds therapy-specific ones
-- =====================================================

-- Add therapy-specific label fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'therapy_ai_label', get_field_type_id('text'), '1'),
(NULL, 'therapy_therapist_label', get_field_type_id('text'), '1'),
(NULL, 'therapy_tag_button_label', get_field_type_id('text'), '1'),
(NULL, 'therapy_empty_message', get_field_type_id('text'), '1'),
(NULL, 'therapy_ai_thinking_text', get_field_type_id('text'), '1'),
(NULL, 'therapy_mode_indicator_ai', get_field_type_id('text'), '1'),
(NULL, 'therapy_mode_indicator_human', get_field_type_id('text'), '1');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
-- Inherit core fields from llmChat pattern
(get_style_id('therapyChat'), get_field_id('css'), NULL, 'CSS classes for the chat container'),
(get_style_id('therapyChat'), get_field_id('css_mobile'), NULL, 'CSS classes for mobile view'),
(get_style_id('therapyChat'), get_field_id('therapy_chat_default_mode'), 'ai_hybrid', 'Default chat mode for this instance'),
(get_style_id('therapyChat'), get_field_id('therapy_chat_enable_tagging'), '1', 'Enable @mention tagging'),
(get_style_id('therapyChat'), get_field_id('therapy_chat_polling_interval'), '3', 'Message polling interval in seconds'),
(get_style_id('therapyChat'), get_field_id('therapy_tag_reasons'), '[{"key":"overwhelmed","label":"I am feeling overwhelmed","urgency":"normal"},{"key":"need_talk","label":"I need to talk soon","urgency":"urgent"},{"key":"urgent","label":"This feels urgent","urgency":"urgent"},{"key":"emergency","label":"Emergency - please respond immediately","urgency":"emergency"}]', 'JSON array of tag reasons with keys, labels, and urgency levels'),

-- LLM configuration (uses same fields as llmChat)
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

-- Reuse existing llmChat labels where applicable
(get_style_id('therapyChat'), get_field_id('submit_button_label'), 'Send', 'Send button label'),
(get_style_id('therapyChat'), get_field_id('message_placeholder'), 'Type your message...', 'Input placeholder'),
(get_style_id('therapyChat'), get_field_id('loading_text'), 'Loading...', 'Loading text');

-- =====================================================
-- PAGES FOR SUBJECT AND THERAPIST
-- =====================================================

-- Subject chat page
INSERT IGNORE INTO pages (`id`, `keyword`, `url`, `protocol`, `id_actions`, `id_navigation_section`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`) 
VALUES (NULL, 'therapyChatSubject', '/therapy-chat/subject/[i:gid]?', 'GET|POST', '0000000003', NULL, NULL, '0', NULL, NULL, '0000000003', (SELECT id FROM lookups WHERE lookup_code = "mobile_and_web" LIMIT 0, 1));

INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`) 
VALUES ((SELECT id FROM `groups` WHERE `name` = 'admin'), (SELECT id FROM pages WHERE keyword = 'therapyChatSubject'), '1', '1', '1', '1');

INSERT IGNORE INTO sections (id_styles, name) VALUES(get_style_id('container'), 'therapyChatSubject-container');
INSERT IGNORE INTO sections (id_styles, name) VALUES(get_style_id('therapyChat'), 'therapyChatSubject-chat');
INSERT IGNORE INTO pages_sections (id_pages, id_Sections, position) VALUES((SELECT id FROM pages WHERE keyword = 'therapyChatSubject'), (SELECT id FROM sections WHERE `name` = 'therapyChatSubject-container'), 1);
INSERT IGNORE INTO sections_hierarchy (parent, child, position) VALUES((SELECT id FROM sections WHERE name = 'therapyChatSubject-container'), (SELECT id FROM sections WHERE `name` = 'therapyChatSubject-chat'), 1);

-- Therapist dashboard page
INSERT IGNORE INTO pages (`id`, `keyword`, `url`, `protocol`, `id_actions`, `id_navigation_section`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`) 
VALUES (NULL, 'therapyChatTherapist', '/therapy-chat/therapist/[i:gid]?/[i:uid]?', 'GET|POST', '0000000003', NULL, NULL, '0', NULL, NULL, '0000000003', (SELECT id FROM lookups WHERE lookup_code = "mobile_and_web" LIMIT 0, 1));

INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`) 
VALUES ((SELECT id FROM `groups` WHERE `name` = 'admin'), (SELECT id FROM pages WHERE keyword = 'therapyChatTherapist'), '1', '1', '1', '1');
INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`) 
VALUES ((SELECT id FROM `groups` WHERE `name` = 'therapist'), (SELECT id FROM pages WHERE keyword = 'therapyChatTherapist'), '1', '1', '0', '0');

INSERT IGNORE INTO sections (id_styles, name) VALUES(get_style_id('container'), 'therapyChatTherapist-container');
INSERT IGNORE INTO sections (id_styles, name) VALUES(get_style_id('therapistDashboard'), 'therapyChatTherapist-dashboard');
INSERT IGNORE INTO pages_sections (id_pages, id_Sections, position) VALUES((SELECT id FROM pages WHERE keyword = 'therapyChatTherapist'), (SELECT id FROM sections WHERE `name` = 'therapyChatTherapist-container'), 1);
INSERT IGNORE INTO sections_hierarchy (parent, child, position) VALUES((SELECT id FROM sections WHERE name = 'therapyChatTherapist-container'), (SELECT id FROM sections WHERE `name` = 'therapyChatTherapist-dashboard'), 1);

-- =====================================================
-- HOOKS REGISTRATION
-- =====================================================

-- Register hook for floating chat icon (similar to chat plugin)
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_on_function_execute' LIMIT 0,1), 'outputTherapyChatIcon', 'Output therapy chat icon next to profile. Shows unread message count.', 'NavView', 'output_profile', 'TherapyChatHooks', 'outputTherapyChatIcon');

-- Register hooks for select-page field type
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-select-page-edit', 'Output select page field - edit mode', 'CmsView', 'create_field_form_item', 'TherapyChatHooks', 'outputFieldSelectPageEdit', 5);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-select-page-view', 'Output select page field - view mode', 'CmsView', 'create_field_item', 'TherapyChatHooks', 'outputFieldSelectPageView', 5);

-- =====================================================
-- TRANSACTION LOGGING
-- =====================================================

INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES ('transactionBy', 'by_therapy_chat_plugin', 'By Therapy Chat Plugin', 'Actions performed by the LLM Therapy Chat plugin');
