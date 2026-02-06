-- =====================================================
-- Therapy Alerts View
-- Combines therapy alerts with conversation details and lookup values.
--
-- This view now covers ALL notification types including tags
-- (tag_received alert type with metadata JSON for reason/urgency).
-- The old therapyTags table has been removed.
-- =====================================================

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
