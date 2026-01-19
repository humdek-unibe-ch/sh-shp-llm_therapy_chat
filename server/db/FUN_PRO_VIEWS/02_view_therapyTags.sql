-- =====================================================
-- Therapy Tags View
-- Combines therapy tags with message details and lookup values
-- =====================================================

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