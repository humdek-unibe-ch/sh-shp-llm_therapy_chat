<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . '/TherapyAlertService.php';

/**
 * Therapy Tagging Service
 * 
 * Handles @mention tagging functionality for subjects to tag therapists.
 * Creates alerts when tags are received.
 * 
 * Tag reasons are configured via JSON in the therapy_tag_reasons field.
 * Format: [{"key": "reason_key", "label": "Display label", "urgency": "normal|urgent|emergency"}]
 * 
 * Uses lookups table for urgency values via TherapyLookups constants.
 * 
 * @package LLM Therapy Chat Plugin
 * @requires sh-shp-llm plugin
 */
class TherapyTaggingService extends TherapyAlertService
{
    /* Tag Reasons Configuration **********************************************/

    /**
     * Get tag reasons from JSON configuration
     * 
     * Returns array of tag reasons with keys, labels, and urgency levels.
     * Falls back to default reasons if not configured.
     * 
     * @param string|null $jsonConfig JSON string from therapy_tag_reasons field
     * @return array Array of tag reason objects
     */
    public function parseTagReasons($jsonConfig)
    {
        if (!empty($jsonConfig)) {
            $parsed = json_decode($jsonConfig, true);
            if (is_array($parsed) && !empty($parsed)) {
                // Validate each reason has required fields
                $valid = array();
                foreach ($parsed as $reason) {
                    if (isset($reason['key']) && isset($reason['label'])) {
                        $valid[] = array(
                            'key' => $reason['key'],
                            'label' => $reason['label'],
                            'urgency' => isset($reason['urgency']) && in_array($reason['urgency'], THERAPY_VALID_URGENCIES) 
                                ? $reason['urgency'] 
                                : THERAPY_URGENCY_NORMAL
                        );
                    }
                }
                if (!empty($valid)) {
                    return $valid;
                }
            }
        }

        // Return default reasons
        return $this->getDefaultTagReasons();
    }

    /**
     * Get default tag reasons
     * 
     * @return array
     */
    public function getDefaultTagReasons()
    {
        return array(
            array('key' => 'overwhelmed', 'label' => 'I am feeling overwhelmed', 'urgency' => THERAPY_URGENCY_NORMAL),
            array('key' => 'need_talk', 'label' => 'I need to talk soon', 'urgency' => THERAPY_URGENCY_URGENT),
            array('key' => 'urgent', 'label' => 'This feels urgent', 'urgency' => THERAPY_URGENCY_URGENT),
            array('key' => 'emergency', 'label' => 'Emergency - please respond immediately', 'urgency' => THERAPY_URGENCY_EMERGENCY)
        );
    }

    /**
     * Get urgency for a tag reason key
     * 
     * @param string $reasonKey
     * @param array $tagReasons Array of tag reasons
     * @return string Urgency lookup_code
     */
    public function getUrgencyForReasonKey($reasonKey, $tagReasons)
    {
        foreach ($tagReasons as $reason) {
            if ($reason['key'] === $reasonKey) {
                return $reason['urgency'];
            }
        }
        return THERAPY_URGENCY_NORMAL;
    }

    /* Tag Creation ***********************************************************/

    /**
     * Create a tag with full alert workflow
     *
     * @param int $messageId LLM message ID where tag was created
     * @param int $conversationId
     * @param int $therapistId Tagged therapist
     * @param string|null $reasonKey Tag reason key from JSON config
     * @param string $urgency Urgency lookup_code (normal, urgent, emergency)
     * @return array Result with success/error and tag ID
     */
    public function createTagWithAlert($messageId, $conversationId, $therapistId, $reasonKey = null, $urgency = THERAPY_URGENCY_NORMAL)
    {
        // Validate urgency
        if (!in_array($urgency, THERAPY_VALID_URGENCIES)) {
            $urgency = THERAPY_URGENCY_NORMAL;
        }

        // Validate the conversation exists and user can tag
        $conversation = $this->getTherapyConversation($conversationId);
        
        if (!$conversation) {
            return array('error' => 'Conversation not found');
        }

        // Check if therapist is valid for this group
        $therapists = $this->getTherapistsForGroup($conversation['id_groups']);
        $validTherapist = false;
        
        foreach ($therapists as $t) {
            if ($t['id'] == $therapistId) {
                $validTherapist = true;
                break;
            }
        }

        if (!$validTherapist) {
            return array('error' => 'Invalid therapist for this conversation');
        }

        // Get urgency lookup ID
        $urgencyId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_TAG_URGENCY, $urgency);

        // Create the tag
        $tagData = array(
            'id_llmMessages' => $messageId,
            'id_users' => $therapistId,
            'tag_reason' => $reasonKey,
            'id_tagUrgency' => $urgencyId
        );

        $tagId = $this->db->insert('therapyTags', $tagData);

        if (!$tagId) {
            return array('error' => 'Failed to create tag');
        }

        // Create alert for the therapist
        $this->createTagAlert($conversationId, $tagId, $therapistId, $reasonKey, $urgency);

        // Update risk level based on urgency
        if ($urgency === THERAPY_URGENCY_EMERGENCY) {
            $this->updateRiskLevel($conversationId, THERAPY_RISK_CRITICAL);
        } elseif ($urgency === THERAPY_URGENCY_URGENT) {
            // Only elevate if not already higher
            if (!in_array($conversation['risk_level'], array(THERAPY_RISK_HIGH, THERAPY_RISK_CRITICAL))) {
                $this->updateRiskLevel($conversationId, THERAPY_RISK_MEDIUM);
            }
        }

        // Log transaction
        $this->logTransaction(
            transactionTypes_insert,
            'therapyTags',
            $tagId,
            $conversation['id_users'],
            "Therapist tagged with urgency: $urgency"
        );

        return array(
            'success' => true,
            'tag_id' => $tagId,
            'alert_created' => true
        );
    }

    /**
     * Tag the assigned therapist (or first available)
     *
     * @param int $conversationId
     * @param int $messageId
     * @param string|null $reasonKey
     * @param string $urgency
     * @return array
     */
    public function tagConversationTherapist($conversationId, $messageId, $reasonKey = null, $urgency = THERAPY_URGENCY_NORMAL)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        
        if (!$conversation) {
            return array('error' => 'Conversation not found');
        }

        // Get therapist to tag
        $therapistId = $conversation['id_therapist'];
        
        if (!$therapistId) {
            // Find first available therapist for the group
            $therapists = $this->getTherapistsForGroup($conversation['id_groups']);
            
            if (empty($therapists)) {
                return array('error' => 'No therapist available');
            }
            
            $therapistId = $therapists[0]['id'];
        }

        return $this->createTagWithAlert($messageId, $conversationId, $therapistId, $reasonKey, $urgency);
    }

    /* Tag Retrieval **********************************************************/

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

        $sql = "SELECT vtt.*, lc.id_users as subject_id, u.name as subject_name, u.code as subject_code
                FROM view_therapyTags vtt
                INNER JOIN llmConversations lc ON lc.id = vtt.conversation_id
                INNER JOIN users u ON u.id = lc.id_users
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

    /* Tag Management *********************************************************/

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
     * Get count of pending tags for a therapist
     *
     * @param int $therapistId
     * @return int
     */
    public function getPendingTagCount($therapistId)
    {
        $tags = $this->getPendingTagsForTherapist($therapistId, 1000);
        return count($tags);
    }
}
?>
