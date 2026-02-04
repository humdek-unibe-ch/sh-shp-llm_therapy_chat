<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . "/TherapyAlertService.php";

/**
 * Therapy Tagging Service
 *
 * Handles tagging operations for therapy conversations.
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapyTaggingService extends TherapyAlertService
{
    /**
     * Constructor
     *
     * @param object $services
     */
    public function __construct($services)
    {
        parent::__construct($services);
    }

    /**
     * Acknowledge a tag
     *
     * @param int $tagId
     * @param int $therapistId
     * @return bool
     */
    public function acknowledgeTag($tagId, $therapistId)
    {
        // Verify therapist owns this tag
        $sql = "SELECT tt.* FROM therapyTags tt WHERE tt.id = :tid AND tt.id_users = :uid";
        $tag = $this->db->query_db_first($sql, array(':tid' => $tagId, ':uid' => $therapistId));

        if (!$tag) {
            return false;
        }

        return $this->db->update_by_ids(
            'therapyTags',
            array(
                'acknowledged' => 1,
                'acknowledged_at' => date('Y-m-d H:i:s')
            ),
            array('id' => $tagId)
        );
    }

    /**
     * Get all tags for a conversation
     * Uses view_therapyTags for easy access to lookup values
     *
     * @param int $conversationId
     * @return array
     */
    public function getTagsForConversation($conversationId)
    {
        $sql = "SELECT * FROM view_therapyTags
                WHERE conversation_id = :cid
                ORDER BY created_at DESC";

        $result = $this->db->query_db($sql, array(':cid' => $conversationId));
        return $result !== false ? $result : array();
    }

    /**
     * Get pending (unacknowledged) tags for a therapist
     * Uses view_therapyTags for easy access to lookup values
     *
     * @param int $therapistId
     * @param int $limit
     * @return array
     */
    public function getPendingTagsForTherapist($therapistId, $limit = 50)
    {
        $conversations = $this->getTherapyConversationsByTherapist($therapistId);

        if (empty($conversations)) {
            return array();
        }

        $conversationIds = array_column($conversations, 'id_llmConversations');
        $placeholders = implode(',', array_fill(0, count($conversationIds), '?'));

        $sql = "SELECT vtt.*, lc.id_users as subject_id, u.name as subject_name, vc.code as subject_code
                FROM view_therapyTags vtt
                INNER JOIN llmConversations lc ON lc.id = vtt.conversation_id
                INNER JOIN users u ON u.id = lc.id_users
                LEFT JOIN validation_codes vc ON vc.id_users = u.id
                WHERE vtt.conversation_id IN ($placeholders)
                AND vtt.id_users = ?
                AND vtt.acknowledged = 0
                ORDER BY
                    FIELD(vtt.urgency, '" . THERAPY_URGENCY_EMERGENCY . "', '" . THERAPY_URGENCY_URGENT . "', '" . THERAPY_URGENCY_NORMAL . "'),
                    vtt.created_at DESC
                LIMIT " . (int)$limit;

        $params = $conversationIds;
        $params[] = $therapistId;

        $result = $this->db->query_db($sql, $params);
        return $result !== false ? $result : array();
    }

    /**
     * Get therapy conversations by therapist
     *
     * @param int $therapistId
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getTherapyConversationsByTherapist($therapistId, $filters = array(), $limit = 50, $offset = 0)
    {
        $sql = "SELECT tcm.* FROM therapyConversationMeta tcm
                INNER JOIN llmConversations lc ON tcm.id_llmConversations = lc.id
                WHERE tcm.id_groups = (SELECT id_groups FROM users_groups WHERE id_users = ? LIMIT 1)";

        return $this->db->query_db($sql, [$therapistId]);
    }
}
?>
