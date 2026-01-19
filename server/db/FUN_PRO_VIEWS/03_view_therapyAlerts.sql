-- =====================================================
-- Therapy Alerts View
-- Combines therapy alerts with conversation details and lookup values
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