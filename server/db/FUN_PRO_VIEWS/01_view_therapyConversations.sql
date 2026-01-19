-- =====================================================
-- Therapy Conversations View
-- Combines therapy metadata with LLM conversations and lookup values
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