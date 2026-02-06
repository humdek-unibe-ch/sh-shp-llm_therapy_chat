<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

/**
 * Therapy Chat Service - Core conversation and access control
 *
 * Extends sh-shp-llm's LlmService with therapy-specific functionality.
 * This is the BASE service. TherapyAlertService and TherapyMessageService extend it.
 *
 * Responsibilities:
 * - Conversation CRUD (create, get, update status/mode/risk)
 * - Access control via therapyTherapistAssignments table
 * - Therapist assignment management (for admin hooks)
 * - Lookup helpers
 *
 * NO id_therapist on conversations - sender identity tracked in llmMessages.sent_context.
 * NO id_groups on conversations - access control via therapyTherapistAssignments.
 *
 * @package LLM Therapy Chat Plugin
 * @requires sh-shp-llm plugin
 */

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

    /** @var object Job scheduler */
    protected $job_scheduler;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->acl = $services->get_acl();
        $this->job_scheduler = $services->get_job_scheduler();
    }

    /* =========================================================================
     * CONVERSATION MANAGEMENT
     * ========================================================================= */

    /**
     * Get or create a therapy conversation for a subject.
     *
     * @param int $userId Subject user ID
     * @param int|null $sectionId Section ID
     * @param string $mode Chat mode (ai_hybrid or human_only)
     * @param string|null $model LLM model to use
     * @param bool $aiEnabled Whether AI is enabled for this conversation
     * @return array|null Conversation data with therapy metadata
     */
    public function getOrCreateTherapyConversation($userId, $sectionId = null, $mode = THERAPY_MODE_AI_HYBRID, $model = null, $aiEnabled = true)
    {
        // Try to find existing active therapy conversation for this user
        $existing = $this->getTherapyConversationBySubject($userId);

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

        // Get lookup IDs
        $modeId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_CHAT_MODES, $mode);
        $statusId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_CONVERSATION_STATUS, THERAPY_STATUS_ACTIVE);
        $riskId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_RISK_LEVELS, THERAPY_RISK_LOW);

        // Add therapy metadata - NO id_groups, NO id_therapist
        $therapyData = array(
            'id_llmConversations' => $conversationId,
            'id_chatModes' => $modeId,
            'ai_enabled' => $aiEnabled ? 1 : 0,
            'id_conversationStatus' => $statusId,
            'id_riskLevels' => $riskId
        );

        $therapyMetaId = $this->db->insert('therapyConversationMeta', $therapyData);

        if (!$therapyMetaId) {
            return null;
        }

        $this->logTransaction(
            transactionTypes_insert,
            'therapyConversationMeta',
            $therapyMetaId,
            $userId,
            'Therapy conversation created'
        );

        return $this->getTherapyConversation($therapyMetaId);
    }

    /**
     * Get a therapy conversation by its meta ID.
     * Uses view_therapyConversations for resolved lookup values.
     *
     * @param int $conversationId therapyConversationMeta.id
     * @return array|null
     */
    public function getTherapyConversation($conversationId)
    {
        $sql = "SELECT * FROM view_therapyConversations WHERE id = :id AND deleted = 0";
        return $this->db->query_db_first($sql, array(':id' => $conversationId));
    }

    /**
     * Get therapy conversation by LLM conversation ID.
     *
     * @param int $llmConversationId llmConversations.id
     * @return array|null
     */
    public function getTherapyConversationByLlmId($llmConversationId)
    {
        $sql = "SELECT * FROM view_therapyConversations WHERE id_llmConversations = :id AND deleted = 0";
        return $this->db->query_db_first($sql, array(':id' => $llmConversationId));
    }

    /**
     * Get active therapy conversation for a subject.
     * A subject has at most one active conversation.
     *
     * @param int $subjectId
     * @return array|null
     */
    public function getTherapyConversationBySubject($subjectId)
    {
        $sql = "SELECT * FROM view_therapyConversations
                WHERE id_users = :uid
                AND status != :closed
                AND deleted = 0
                ORDER BY created_at DESC
                LIMIT 1";

        return $this->db->query_db_first($sql, array(
            ':uid' => $subjectId,
            ':closed' => THERAPY_STATUS_CLOSED
        ));
    }

    /**
     * Get all conversations a therapist can access.
     * Uses therapyTherapistAssignments to determine which groups they monitor.
     *
     * @param int $therapistId
     * @param array $filters Optional (status, risk_level, group_id)
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getTherapyConversationsByTherapist($therapistId, $filters = array(), $limit = 50, $offset = 0)
    {
        // Get groups this therapist is assigned to via therapyTherapistAssignments
        $assignedGroups = $this->getTherapistAssignedGroups($therapistId);

        if (empty($assignedGroups)) {
            return array();
        }

        $groupIds = array_column($assignedGroups, 'id_groups');

        // Find all patients in these groups
        $groupPlaceholders = implode(',', array_fill(0, count($groupIds), '?'));

        $sql = "SELECT vtc.*,
                       (SELECT COUNT(*) FROM llmMessages lm
                        WHERE lm.id_llmConversations = vtc.id_llmConversations AND lm.deleted = 0) as message_count,
                       (SELECT COUNT(*) FROM therapyAlerts ta
                        WHERE ta.id_llmConversations = vtc.id_llmConversations AND ta.is_read = 0
                        AND (ta.id_users IS NULL OR ta.id_users = ?)) as unread_alerts
                FROM view_therapyConversations vtc
                INNER JOIN users_groups ug ON ug.id_users = vtc.id_users
                WHERE ug.id_groups IN ($groupPlaceholders)
                AND vtc.deleted = 0";

        // First param is therapist ID for alert count
        $params = array($therapistId);
        $params = array_merge($params, $groupIds);

        // Apply filters
        if (!empty($filters['status'])) {
            $sql .= " AND vtc.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['risk_level'])) {
            $sql .= " AND vtc.risk_level = ?";
            $params[] = $filters['risk_level'];
        }
        if (!empty($filters['group_id'])) {
            $sql .= " AND ug.id_groups = ?";
            $params[] = $filters['group_id'];
        }

        $sql .= " GROUP BY vtc.id
                   ORDER BY
                    FIELD(vtc.risk_level, '" . THERAPY_RISK_CRITICAL . "', '" . THERAPY_RISK_HIGH . "', '" . THERAPY_RISK_MEDIUM . "', '" . THERAPY_RISK_LOW . "'),
                    vtc.updated_at DESC
                  LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $result = $this->db->query_db($sql, $params);
        return $result !== false ? $result : array();
    }

    /* =========================================================================
     * ACCESS CONTROL (via therapyTherapistAssignments)
     * ========================================================================= */

    /**
     * Check if a user can access a therapy conversation.
     *
     * @param int $userId
     * @param int $conversationId therapyConversationMeta.id
     * @return bool
     */
    public function canAccessTherapyConversation($userId, $conversationId)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) {
            return false;
        }

        // Subject can access their own conversation
        if ((int)$conversation['id_users'] === (int)$userId) {
            return true;
        }

        // Admin always has access
        $adminPageId = $this->db->fetch_page_id_by_keyword('admin');
        if ($adminPageId && $this->acl->has_access_select($userId, $adminPageId)) {
            return true;
        }

        // Therapist with assignment to any of the patient's groups
        return $this->canTherapistAccessPatient($userId, $conversation['id_users']);
    }

    /**
     * Check if therapist can access a patient's conversations.
     * Checks if the therapist is assigned to any group the patient belongs to.
     *
     * @param int $therapistId
     * @param int $patientId
     * @return bool
     */
    public function canTherapistAccessPatient($therapistId, $patientId)
    {
        $sql = "SELECT 1 FROM therapyTherapistAssignments tta
                INNER JOIN users_groups ug ON ug.id_groups = tta.id_groups
                WHERE tta.id_users = :therapist_id
                AND ug.id_users = :patient_id
                LIMIT 1";

        $result = $this->db->query_db_first($sql, array(
            ':therapist_id' => $therapistId,
            ':patient_id' => $patientId
        ));

        return $result !== false;
    }

    /**
     * Check if user is a subject (has access to subject page).
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
     * Check if user is a therapist (has access to therapist page).
     *
     * @param int $userId
     * @return bool
     */
    public function isTherapist($userId)
    {
        $pageId = $this->db->fetch_page_id_by_keyword('therapyChatTherapist');
        return $pageId && $this->acl->has_access_select($userId, $pageId);
    }

    /* =========================================================================
     * THERAPIST ASSIGNMENT MANAGEMENT (therapyTherapistAssignments)
     * ========================================================================= */

    /**
     * Get groups a therapist is assigned to monitor.
     *
     * @param int $therapistId
     * @return array [{id_groups, group_name, assigned_at}, ...]
     */
    public function getTherapistAssignedGroups($therapistId)
    {
        $sql = "SELECT tta.id_groups, g.name as group_name, tta.assigned_at
                FROM therapyTherapistAssignments tta
                INNER JOIN `groups` g ON g.id = tta.id_groups
                WHERE tta.id_users = :uid
                ORDER BY g.name";

        $result = $this->db->query_db($sql, array(':uid' => $therapistId));
        return $result !== false ? $result : array();
    }

    /**
     * Assign a therapist to monitor a group.
     *
     * @param int $therapistId
     * @param int $groupId
     * @return bool
     */
    public function assignTherapistToGroup($therapistId, $groupId)
    {
        $sql = "INSERT IGNORE INTO therapyTherapistAssignments (id_users, id_groups) VALUES (?, ?)";
        $this->db->query_db($sql, array($therapistId, $groupId));
        return true;
    }

    /**
     * Remove a therapist's assignment to a group.
     *
     * @param int $therapistId
     * @param int $groupId
     * @return bool
     */
    public function removeTherapistFromGroup($therapistId, $groupId)
    {
        $sql = "DELETE FROM therapyTherapistAssignments WHERE id_users = ? AND id_groups = ?";
        $this->db->query_db($sql, array($therapistId, $groupId));
        return true;
    }

    /**
     * Set all group assignments for a therapist (replaces existing).
     *
     * @param int $therapistId
     * @param array $groupIds Array of group IDs
     * @return bool
     */
    public function setTherapistAssignments($therapistId, $groupIds)
    {
        // Remove all existing
        $sql = "DELETE FROM therapyTherapistAssignments WHERE id_users = ?";
        $this->db->query_db($sql, array($therapistId));

        // Insert new
        foreach ($groupIds as $gid) {
            $this->assignTherapistToGroup($therapistId, $gid);
        }

        return true;
    }

    /**
     * Get therapists assigned to a specific group.
     *
     * @param int $groupId
     * @return array [{id, name, email}, ...]
     */
    public function getTherapistsForGroup($groupId)
    {
        $sql = "SELECT u.id, u.name, u.email
                FROM therapyTherapistAssignments tta
                INNER JOIN users u ON u.id = tta.id_users
                WHERE tta.id_groups = :gid
                ORDER BY u.name";

        $result = $this->db->query_db($sql, array(':gid' => $groupId));
        return $result !== false ? $result : array();
    }

    /**
     * Get all therapists assigned to any group that a patient belongs to.
     *
     * @param int $patientId
     * @return array [{id, name, email}, ...]
     */
    public function getTherapistsForPatient($patientId)
    {
        $sql = "SELECT DISTINCT u.id, u.name, u.email
                FROM therapyTherapistAssignments tta
                INNER JOIN users u ON u.id = tta.id_users
                INNER JOIN users_groups ug ON ug.id_groups = tta.id_groups
                WHERE ug.id_users = :patient_id
                ORDER BY u.name";

        $result = $this->db->query_db($sql, array(':patient_id' => $patientId));
        return $result !== false ? $result : array();
    }

    /**
     * Get all available groups (for admin assignment UI).
     *
     * @return array [{id, name}, ...]
     */
    public function getAllGroups()
    {
        $sql = "SELECT id, name FROM `groups` ORDER BY name";
        $result = $this->db->query_db($sql);
        return $result !== false ? $result : array();
    }

    /* =========================================================================
     * CONVERSATION STATUS / MODE / RISK
     * ========================================================================= */

    /**
     * Update conversation status.
     *
     * @param int $conversationId therapyConversationMeta.id
     * @param string $status Lookup code (active, paused, closed)
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
            array('id' => $conversationId)
        );

        if ($result) {
            $this->logTransaction(
                transactionTypes_update,
                'therapyConversationMeta',
                $conversationId,
                $conversation['id_users'],
                "Status changed to: $status"
            );
        }

        return $result;
    }

    /**
     * Set conversation chat mode.
     *
     * @param int $conversationId
     * @param string $mode Lookup code (ai_hybrid, human_only)
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
            array('id' => $conversationId)
        );
    }

    /**
     * Toggle AI on/off for a conversation.
     *
     * @param int $conversationId
     * @param bool $enabled
     * @return bool
     */
    public function setAIEnabled($conversationId, $enabled)
    {
        $result = $this->db->update_by_ids(
            'therapyConversationMeta',
            array('ai_enabled' => $enabled ? 1 : 0),
            array('id' => $conversationId)
        );

        if ($result) {
            $conversation = $this->getTherapyConversation($conversationId);
            $uid = $conversation ? $conversation['id_users'] : 0;
            $this->logTransaction(
                transactionTypes_update, 'therapyConversationMeta', $conversationId, $uid,
                'AI ' . ($enabled ? 'enabled' : 'disabled')
            );
        }

        return $result;
    }

    /**
     * Update conversation risk level.
     *
     * @param int $conversationId
     * @param string $riskLevel Lookup code
     * @return bool
     */
    public function updateRiskLevel($conversationId, $riskLevel)
    {
        if (!in_array($riskLevel, THERAPY_VALID_RISK_LEVELS)) {
            return false;
        }

        $riskId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_RISK_LEVELS, $riskLevel);
        if (!$riskId) {
            return false;
        }

        $result = $this->db->update_by_ids(
            'therapyConversationMeta',
            array('id_riskLevels' => $riskId),
            array('id' => $conversationId)
        );

        if ($result) {
            $conversation = $this->getTherapyConversation($conversationId);
            $uid = $conversation ? $conversation['id_users'] : 0;
            $this->logTransaction(
                transactionTypes_update, 'therapyConversationMeta', $conversationId, $uid,
                "Risk level changed to: $riskLevel"
            );
        }

        return $result;
    }

    /**
     * Update last seen timestamp.
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
            array('id' => $conversationId)
        );
    }

    /* =========================================================================
     * NOTES MANAGEMENT
     * ========================================================================= */

    /**
     * Add a note to a conversation.
     *
     * @param int $conversationId therapyConversationMeta.id
     * @param int $therapistId
     * @param string $content
     * @param string $noteType THERAPY_NOTE_MANUAL or THERAPY_NOTE_AI_SUMMARY
     * @param string|null $aiOriginalContent For AI summaries - the unedited AI output
     * @return int|bool Note ID or false
     */
    public function addNote($conversationId, $therapistId, $content, $noteType = THERAPY_NOTE_MANUAL, $aiOriginalContent = null)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) {
            return false;
        }

        $noteTypeId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_NOTE_TYPES, $noteType);

        $noteStatusId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_NOTE_STATUS, THERAPY_NOTE_STATUS_ACTIVE);

        $data = array(
            'id_llmConversations' => $conversation['id_llmConversations'],
            'id_users' => $therapistId,
            'id_noteTypes' => $noteTypeId,
            'id_noteStatus' => $noteStatusId,
            'content' => $content,
            'ai_original_content' => $aiOriginalContent
        );

        $noteId = $this->db->insert('therapyNotes', $data);

        if ($noteId) {
            $this->logTransaction(
                transactionTypes_insert, 'therapyNotes', $noteId, $therapistId,
                'Clinical note added'
            );
        }

        return $noteId;
    }

    /**
     * Get notes for a conversation.
     *
     * @param int $conversationId
     * @param int $limit
     * @return array
     */
    public function getNotesForConversation($conversationId, $limit = 50)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) {
            return array();
        }

        $activeStatusId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_NOTE_STATUS, THERAPY_NOTE_STATUS_ACTIVE);

        $sql = "SELECT tn.*, u.name as author_name,
                       nt.lookup_code as note_type, nt.lookup_value as note_type_label,
                       ns.lookup_code as note_status,
                       editor.name as last_edited_by_name
                FROM therapyNotes tn
                INNER JOIN users u ON u.id = tn.id_users
                LEFT JOIN lookups nt ON nt.id = tn.id_noteTypes
                LEFT JOIN lookups ns ON ns.id = tn.id_noteStatus
                LEFT JOIN users editor ON editor.id = tn.id_lastEditedBy
                WHERE tn.id_llmConversations = :llm_id
                  AND (tn.id_noteStatus = :active_status OR tn.id_noteStatus IS NULL)
                ORDER BY tn.created_at DESC
                LIMIT " . (int)$limit;

        $result = $this->db->query_db($sql, array(
            ':llm_id' => $conversation['id_llmConversations'],
            ':active_status' => $activeStatusId
        ));
        return $result !== false ? $result : array();
    }

    /**
     * Update a note's content. Logs the edit via transactions.
     *
     * @param int $noteId
     * @param int $therapistId ID of the therapist performing the edit
     * @param string $newContent
     * @return bool
     */
    public function updateNote($noteId, $therapistId, $newContent)
    {
        $this->db->update_by_ids(
            'therapyNotes',
            array(
                'content' => $newContent,
                'id_lastEditedBy' => $therapistId
            ),
            array('id' => $noteId)
        );

        // Transaction logging
        $this->logTransaction(
            transactionTypes_update, 'therapyNotes', $noteId, $therapistId,
            'Note edited by therapist'
        );

        return true;
    }

    /**
     * Soft-delete a note (set id_noteStatus to 'deleted' lookup). Logs via transactions.
     *
     * @param int $noteId
     * @param int $therapistId
     * @return bool
     */
    public function softDeleteNote($noteId, $therapistId)
    {
        $deletedStatusId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_NOTE_STATUS, THERAPY_NOTE_STATUS_DELETED);

        $this->db->update_by_ids(
            'therapyNotes',
            array(
                'id_noteStatus' => $deletedStatusId,
                'id_lastEditedBy' => $therapistId
            ),
            array('id' => $noteId)
        );

        $this->logTransaction(
            transactionTypes_delete, 'therapyNotes', $noteId, $therapistId,
            'Note soft-deleted by therapist'
        );

        return true;
    }

    /* =========================================================================
     * STATISTICS
     * ========================================================================= */

    /**
     * Get dashboard stats for a therapist.
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
            'unread_alerts' => 0
        );

        foreach ($conversations as $conv) {
            if ($conv['status'] === THERAPY_STATUS_ACTIVE) $stats['active']++;
            if ($conv['status'] === THERAPY_STATUS_PAUSED) $stats['paused']++;
            if ($conv['risk_level'] === THERAPY_RISK_CRITICAL) $stats['risk_critical']++;
            if ($conv['risk_level'] === THERAPY_RISK_HIGH) $stats['risk_high']++;
            $stats['unread_alerts'] += intval($conv['unread_alerts'] ?? 0);
        }

        return $stats;
    }

    /* =========================================================================
     * LOOKUP HELPERS
     * ========================================================================= */

    public function getLookupValues($typeCode)
    {
        return $this->db->get_lookups($typeCode);
    }
}
?>
