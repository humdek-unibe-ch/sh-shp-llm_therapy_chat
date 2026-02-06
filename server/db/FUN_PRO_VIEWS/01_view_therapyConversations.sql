-- =====================================================
-- Therapy Conversations View
-- Combines therapy metadata with LLM conversations and lookup values.
--
-- NOTE: No id_therapist or id_groups columns.
-- - Access control: therapyTherapistAssignments + users_groups
-- - Sender identity: llmMessages.sent_context JSON
-- =====================================================

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
