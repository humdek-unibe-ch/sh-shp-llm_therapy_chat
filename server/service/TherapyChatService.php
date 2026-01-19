<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

/**
 * Therapy Chat Service - Core conversation management
 * 
 * This service EXTENDS the sh-shp-llm plugin's LlmService to add therapy-specific
 * functionality while reusing all LLM conversation and message management.
 * 
 * All messages are stored in llmMessages, all conversations in llmConversations.
 * This service adds therapy metadata via therapyConversationMeta table.
 * 
 * Uses lookups table for all status/type values via TherapyLookups constants.
 * 
 * @package LLM Therapy Chat Plugin
 * @requires sh-shp-llm plugin
 */

// Include LLM plugin services (dependency) - only if LLM plugin is available
$llmServicePath = __DIR__ . "/../../sh-shp-llm/server/service/LlmService.php";
$llmGlobalsPath = __DIR__ . "/../../sh-shp-llm/server/service/globals.php";

if (file_exists($llmServicePath)) {
    require_once $llmServicePath;
}

if (file_exists($llmGlobalsPath)) {
    require_once $llmGlobalsPath;
}
require_once __DIR__ . "/../constants/TherapyLookups.php";

class TherapyChatService extends LlmService
{
    /** @var object ACL service */
    protected $acl;

    /** @var object Job scheduler service */
    protected $job_scheduler;

    /**
     * Constructor
     *
     * @param object $services Service container
     */
    public function __construct($services)
    {
        parent::__construct($services);
        $this->acl = $services->get_acl();
        $this->job_scheduler = $services->get_job_scheduler();
    }

    /* Conversation Management (extends LlmService) ***************************/

    /**
     * Get or create a therapy conversation for a subject
     * 
     * Creates an llmConversation AND the therapy metadata in therapyConversationMeta.
     *
     * @param int $userId Subject user ID
     * @param int $groupId Group ID for therapist access
     * @param int|null $sectionId Section ID
     * @param string $mode Chat mode (ai_hybrid or human_only)
     * @param string|null $model LLM model to use
     * @return array|null Conversation data with therapy metadata
     */
    public function getOrCreateTherapyConversation($userId, $groupId, $sectionId = null, $mode = THERAPY_MODE_AI_HYBRID, $model = null)
    {
        // Try to find existing active therapy conversation
        $existing = $this->getTherapyConversationBySubject($userId, $groupId);
        
        if ($existing) {
            return $existing;
        }

        // Get LLM config for model
        $config = $this->getLlmConfig();
        $modelToUse = $model ?: $config['llm_default_model'];

        // Create base LLM conversation using parent method
        $conversationId = $this->createConversation(
            $userId,
            'Therapy Chat',
            $modelToUse,
            $config['llm_temperature'],
            $config['llm_max_tokens'],
            $sectionId
        );

        if (!$conversationId) {
            return null;
        }

        // Get lookup IDs for default values
        $modeId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_CHAT_MODES, $mode);
        $statusId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_CONVERSATION_STATUS, THERAPY_STATUS_ACTIVE);
        $riskId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_RISK_LEVELS, THERAPY_RISK_LOW);

        // Add therapy metadata
        $therapyData = array(
            'id_llmConversations' => $conversationId,
            'id_groups' => $groupId,
            'id_chatModes' => $modeId,
            'ai_enabled' => ($mode === THERAPY_MODE_AI_HYBRID) ? 1 : 0,
            'id_conversationStatus' => $statusId,
            'id_riskLevels' => $riskId
        );

        $this->db->insert('therapyConversationMeta', $therapyData);

        // Log transaction
        $this->logTransaction(
            transactionTypes_insert,
            'therapyConversationMeta',
            $conversationId,
            $userId,
            'Therapy conversation created'
        );

        return $this->getTherapyConversation($conversationId);
    }

    /**
     * Get a therapy conversation with its metadata
     * Uses the view_therapyConversations view for easy access to lookup values
     *
     * @param int $conversationId
     * @return array|null
     */
    public function getTherapyConversation($conversationId)
    {
        $sql = "SELECT * FROM view_therapyConversations WHERE id_llmConversations = :id AND deleted = 0";
        return $this->db->query_db_first($sql, array(':id' => $conversationId));
    }

    /**
     * Get therapy conversation by subject and group
     *
     * @param int $subjectId
     * @param int $groupId
     * @return array|null
     */
    public function getTherapyConversationBySubject($subjectId, $groupId)
    {
        $sql = "SELECT * FROM view_therapyConversations
                WHERE id_users = :uid 
                AND id_groups = :gid
                AND status != :closed
                AND deleted = 0
                ORDER BY created_at DESC
                LIMIT 1";
        
        return $this->db->query_db_first($sql, array(
            ':uid' => $subjectId,
            ':gid' => $groupId,
            ':closed' => THERAPY_STATUS_CLOSED
        ));
    }

    /**
     * Get conversations for a therapist based on group access
     *
     * @param int $therapistId Therapist user ID
     * @param array $filters Optional filters (status, risk_level, group_id)
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getTherapyConversationsByTherapist($therapistId, $filters = array(), $limit = 50, $offset = 0)
    {
        // Get groups the therapist has access to
        $groupsSql = "SELECT DISTINCT ug.id_groups 
                      FROM users_groups ug
                      INNER JOIN acl_groups acl ON acl.id_groups = ug.id_groups
                      INNER JOIN pages p ON acl.id_pages = p.id
                      WHERE ug.id_users = :uid 
                      AND p.keyword = 'therapyChatTherapist'
                      AND acl.acl_select = 1";
        
        $groups = $this->db->query_db($groupsSql, array(':uid' => $therapistId));
        
        if (empty($groups)) {
            return array();
        }

        $groupIds = array_column($groups, 'id_groups');
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

        $sql = "SELECT vtc.*,
                       (SELECT COUNT(*) FROM llmMessages lm WHERE lm.id_llmConversations = vtc.id_llmConversations AND lm.deleted = 0) as message_count,
                       (SELECT COUNT(*) FROM therapyAlerts ta WHERE ta.id_llmConversations = vtc.id_llmConversations AND ta.is_read = 0) as unread_alerts,
                       (SELECT COUNT(*) FROM therapyTags tt 
                        INNER JOIN llmMessages lm ON lm.id = tt.id_llmMessages
                        WHERE lm.id_llmConversations = vtc.id_llmConversations AND tt.acknowledged = 0) as pending_tags
                FROM view_therapyConversations vtc
                WHERE vtc.id_groups IN ($placeholders)
                AND vtc.deleted = 0";

        $params = $groupIds;

        // Apply filters using lookup_code values
        if (!empty($filters['status'])) {
            $sql .= " AND vtc.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['risk_level'])) {
            $sql .= " AND vtc.risk_level = ?";
            $params[] = $filters['risk_level'];
        }

        if (!empty($filters['group_id'])) {
            $sql .= " AND vtc.id_groups = ?";
            $params[] = $filters['group_id'];
        }

        // Order by risk level priority (using lookup_code values)
        $sql .= " ORDER BY
                    FIELD(vtc.risk_level, '" . THERAPY_RISK_CRITICAL . "', '" . THERAPY_RISK_HIGH . "', '" . THERAPY_RISK_MEDIUM . "', '" . THERAPY_RISK_LOW . "'),
                    vtc.updated_at DESC
                  LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        return $this->db->query_db($sql, $params);
    }

    /* Access Control *********************************************************/

    /**
     * Check if a user can access a therapy conversation
     *
     * @param int $userId
     * @param int $conversationId
     * @return bool
     */
    public function canAccessTherapyConversation($userId, $conversationId)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        
        if (!$conversation) {
            return false;
        }

        // Subject can access their own conversation
        if ($conversation['id_users'] == $userId) {
            return true;
        }

        // Check if user is admin
        if ($this->acl->is_user_of_group($userId, 'admin')) {
            return true;
        }

        // Check if user is therapist with access to this group
        return $this->isTherapistForGroup($userId, $conversation['id_groups']);
    }

    /**
     * Check if user is a therapist for the given group
     *
     * @param int $userId
     * @param int $groupId
     * @return bool
     */
    public function isTherapistForGroup($userId, $groupId)
    {
        $sql = "SELECT 1 FROM users_groups ug
                INNER JOIN acl_groups acl ON acl.id_groups = ug.id_groups
                INNER JOIN pages p ON acl.id_pages = p.id
                WHERE ug.id_users = :uid 
                AND p.keyword = 'therapyChatTherapist'
                AND acl.acl_select = 1
                AND ug.id_groups = :gid
                LIMIT 1";
        
        $result = $this->db->query_db_first($sql, array(
            ':uid' => $userId,
            ':gid' => $groupId
        ));

        return $result !== false;
    }

    /**
     * Check if user is a subject with therapy chat access
     *
     * @param int $userId
     * @return bool
     */
    public function isSubject($userId)
    {
        $pageId = $this->db->fetch_page_id_by_keyword('therapyChatSubject');
        return $pageId && $this->acl->has_access_select($userId, $pageId);
    }

    /**
     * Check if user is a therapist
     *
     * @param int $userId
     * @return bool
     */
    public function isTherapist($userId)
    {
        $pageId = $this->db->fetch_page_id_by_keyword('therapyChatTherapist');
        return $pageId && $this->acl->has_access_select($userId, $pageId);
    }

    /* Therapy Status & Mode Management ***************************************/

    /**
     * Update therapy conversation status
     *
     * @param int $conversationId
     * @param string $status Status lookup_code (active, paused, closed)
     * @return bool
     */
    public function updateTherapyStatus($conversationId, $status)
    {
        if (!in_array($status, THERAPY_VALID_STATUSES)) {
            return false;
        }

        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) {
            return false;
        }

        $statusId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_CONVERSATION_STATUS, $status);
        if (!$statusId) {
            return false;
        }

        $result = $this->db->update_by_ids(
            'therapyConversationMeta',
            array('id_conversationStatus' => $statusId),
            array('id_llmConversations' => $conversationId)
        );

        if ($result) {
            $this->logTransaction(
                transactionTypes_update,
                'therapyConversationMeta',
                $conversationId,
                $conversation['id_users'],
                "Therapy status changed to: $status"
            );
        }

        return $result;
    }

    /**
     * Set conversation mode
     *
     * @param int $conversationId
     * @param string $mode Mode lookup_code (ai_hybrid, human_only)
     * @return bool
     */
    public function setTherapyMode($conversationId, $mode)
    {
        if (!in_array($mode, THERAPY_VALID_MODES)) {
            return false;
        }

        $modeId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_CHAT_MODES, $mode);
        if (!$modeId) {
            return false;
        }

        $aiEnabled = ($mode === THERAPY_MODE_AI_HYBRID) ? 1 : 0;

        return $this->db->update_by_ids(
            'therapyConversationMeta',
            array('id_chatModes' => $modeId, 'ai_enabled' => $aiEnabled),
            array('id_llmConversations' => $conversationId)
        );
    }

    /**
     * Toggle AI responses in a conversation
     *
     * @param int $conversationId
     * @param bool $enabled
     * @return bool
     */
    public function setAIEnabled($conversationId, $enabled)
    {
        return $this->db->update_by_ids(
            'therapyConversationMeta',
            array('ai_enabled' => $enabled ? 1 : 0),
            array('id_llmConversations' => $conversationId)
        );
    }

    /**
     * Update conversation risk level
     *
     * @param int $conversationId
     * @param string $riskLevel Risk level lookup_code (low, medium, high, critical)
     * @return bool
     */
    public function updateRiskLevel($conversationId, $riskLevel)
    {
        if (!in_array($riskLevel, THERAPY_VALID_RISK_LEVELS)) {
            return false;
        }

        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) {
            return false;
        }

        $riskId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_RISK_LEVELS, $riskLevel);
        if (!$riskId) {
            return false;
        }

        $result = $this->db->update_by_ids(
            'therapyConversationMeta',
            array('id_riskLevels' => $riskId),
            array('id_llmConversations' => $conversationId)
        );

        if ($result) {
            $this->logTransaction(
                transactionTypes_update,
                'therapyConversationMeta',
                $conversationId,
                $conversation['id_users'],
                "Risk level changed to: $riskLevel"
            );
        }

        return $result;
    }

    /**
     * Update last seen timestamp for therapist or subject
     *
     * @param int $conversationId
     * @param string $userType 'therapist' or 'subject'
     * @return bool
     */
    public function updateLastSeen($conversationId, $userType)
    {
        $field = ($userType === 'therapist') ? 'therapist_last_seen' : 'subject_last_seen';
        
        return $this->db->update_by_ids(
            'therapyConversationMeta',
            array($field => date('Y-m-d H:i:s')),
            array('id_llmConversations' => $conversationId)
        );
    }

    /* Therapist Helpers ******************************************************/

    /**
     * Get therapists for a group
     *
     * @param int $groupId
     * @return array
     */
    public function getTherapistsForGroup($groupId)
    {
        $sql = "SELECT DISTINCT u.id, u.name, u.email
                FROM users u
                INNER JOIN users_groups ug ON ug.id_users = u.id
                INNER JOIN acl_groups acl ON acl.id_groups = ug.id_groups
                INNER JOIN pages p ON acl.id_pages = p.id
                WHERE ug.id_groups = :gid
                AND p.keyword = 'therapyChatTherapist'
                AND acl.acl_select = 1
                ORDER BY u.name";
        
        return $this->db->query_db($sql, array(':gid' => $groupId));
    }

    /**
     * Get conversation statistics for a therapist
     *
     * @param int $therapistId
     * @return array
     */
    public function getTherapistStats($therapistId)
    {
        $conversations = $this->getTherapyConversationsByTherapist($therapistId, array(), 1000, 0);

        $stats = array(
            'total' => count($conversations),
            'active' => 0,
            'paused' => 0,
            'risk_critical' => 0,
            'risk_high' => 0,
            'unread_alerts' => 0,
            'pending_tags' => 0
        );

        foreach ($conversations as $conv) {
            if ($conv['status'] === THERAPY_STATUS_ACTIVE) $stats['active']++;
            if ($conv['status'] === THERAPY_STATUS_PAUSED) $stats['paused']++;
            if ($conv['risk_level'] === THERAPY_RISK_CRITICAL) $stats['risk_critical']++;
            if ($conv['risk_level'] === THERAPY_RISK_HIGH) $stats['risk_high']++;
            $stats['unread_alerts'] += intval($conv['unread_alerts'] ?? 0);
            $stats['pending_tags'] += intval($conv['pending_tags'] ?? 0);
        }

        return $stats;
    }

    /* Lookup Helpers *********************************************************/

    /**
     * Get all values for a lookup type
     *
     * @param string $typeCode Lookup type code
     * @return array Array of lookup entries with id, code, value, description
     */
    public function getLookupValues($typeCode)
    {
        return $this->db->get_lookups($typeCode);
    }

    /**
     * Get all chat modes
     *
     * @return array
     */
    public function getChatModes()
    {
        return $this->getLookupValues(THERAPY_LOOKUP_CHAT_MODES);
    }

    /**
     * Get all conversation statuses
     *
     * @return array
     */
    public function getConversationStatuses()
    {
        return $this->getLookupValues(THERAPY_LOOKUP_CONVERSATION_STATUS);
    }

    /**
     * Get all risk levels
     *
     * @return array
     */
    public function getRiskLevels()
    {
        return $this->getLookupValues(THERAPY_LOOKUP_RISK_LEVELS);
    }

    /**
     * Get all tag urgencies
     *
     * @return array
     */
    public function getTagUrgencies()
    {
        return $this->getLookupValues(THERAPY_LOOKUP_TAG_URGENCY);
    }

    /**
     * Get all alert types
     *
     * @return array
     */
    public function getAlertTypes()
    {
        return $this->getLookupValues(THERAPY_LOOKUP_ALERT_TYPES);
    }

    /**
     * Get all alert severities
     *
     * @return array
     */
    public function getAlertSeverities()
    {
        return $this->getLookupValues(THERAPY_LOOKUP_ALERT_SEVERITY);
    }
}
?>
