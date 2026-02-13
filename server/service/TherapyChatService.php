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

$llmServicePath = __DIR__ . "/../../../sh-shp-llm/server/service/LlmService.php";
$llmGlobalsPath = __DIR__ . "/../../../sh-shp-llm/server/service/globals.php";

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
    public function getOrCreateTherapyConversation($userId, $sectionId = null, $mode = THERAPY_MODE_AI_HYBRID, $model = null, $aiEnabled = true, $autoStartContext = null)
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

        $therapyMetaId = $this->createTherapyMetadata(
            $conversationId, $mode, $aiEnabled, $userId, 'Therapy conversation created'
        );

        if (!$therapyMetaId) {
            return null;
        }

        $conversation = $this->getTherapyConversation($therapyMetaId);

        // If auto-start context is provided, insert it as the first message.
        // This is a plain text insert — no LLM calls.
        if ($conversation && !empty($autoStartContext)) {
            $this->sendAutoStartMessage($conversation, $autoStartContext, $userId);
        }

        return $conversation;
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
     * Get all patients a therapist can access, including those without conversations.
     * Uses therapyTherapistAssignments to determine which groups they monitor.
     * Patients without conversations are returned with null conversation fields
     * so the therapist can initialize a conversation for them.
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

        // LEFT JOIN to view_therapyConversations so patients without conversations
        // are still included. We use users_groups as the base to find all patients,
        // then optionally join their therapy conversation data.
        $sql = "SELECT
                    u.id as id_users,
                    u.name as subject_name,
                    u.email as subject_email,
                    vc.code as subject_code,
                    vtc.id,
                    vtc.id_llmConversations,
                    vtc.ai_enabled,
                    vtc.mode,
                    vtc.mode_label,
                    vtc.status,
                    vtc.status_label,
                    vtc.risk_level,
                    vtc.risk_level_label,
                    vtc.title,
                    vtc.model,
                    vtc.created_at,
                    vtc.updated_at,
                    vtc.therapist_last_seen,
                    vtc.subject_last_seen,
                    CASE WHEN vtc.id IS NOT NULL THEN
                        (SELECT COUNT(*) FROM llmMessages lm
                         WHERE lm.id_llmConversations = vtc.id_llmConversations AND lm.deleted = 0)
                    ELSE 0 END as message_count,
                    CASE WHEN vtc.id IS NOT NULL THEN
                        (SELECT COUNT(*) FROM therapyAlerts ta
                         WHERE ta.id_llmConversations = vtc.id_llmConversations AND ta.is_read = 0
                         AND (ta.id_users IS NULL OR ta.id_users = ?))
                    ELSE 0 END as unread_alerts,
                    CASE WHEN vtc.id IS NULL THEN 1 ELSE 0 END as no_conversation
                FROM users u
                INNER JOIN users_groups ug ON ug.id_users = u.id
                LEFT JOIN view_therapyConversations vtc ON vtc.id_users = u.id AND (vtc.deleted = 0 OR vtc.deleted IS NULL)
                LEFT JOIN validation_codes vc ON vc.id_users = u.id AND vc.consumed IS NULL
                WHERE ug.id_groups IN ($groupPlaceholders)";

        // First param is therapist ID for alert count
        $params = array($therapistId);
        $params = array_merge($params, $groupIds);

        // Exclude the therapist themselves from the patient list
        $sql .= " AND u.id != ?";
        $params[] = $therapistId;

        // Apply filters - these only apply to patients WITH conversations
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

        $sql .= " GROUP BY u.id
                   ORDER BY
                    no_conversation ASC,
                    CASE vtc.risk_level
                        WHEN '" . THERAPY_RISK_CRITICAL . "' THEN 1
                        WHEN '" . THERAPY_RISK_HIGH . "' THEN 2
                        WHEN '" . THERAPY_RISK_MEDIUM . "' THEN 3
                        WHEN '" . THERAPY_RISK_LOW . "' THEN 4
                        ELSE 5
                    END ASC,
                    vtc.updated_at DESC,
                    u.name ASC
                  LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $result = $this->db->query_db($sql, $params);
        return $result !== false ? $result : array();
    }

    /**
     * Initialize a therapy conversation for a patient by the therapist.
     * Creates the conversation as if the patient owns it, but triggered by the therapist.
     *
     * @param int $patientId The patient user ID
     * @param int $therapistId The therapist who is initializing
     * @param int|null $sectionId Section ID
     * @param string $mode Chat mode (ai_hybrid or human_only)
     * @param string|null $model LLM model to use
     * @param bool $aiEnabled Whether AI is enabled
     * @param string|null $autoStartContext Context for the auto-start system message
     * @return array|null Conversation data with therapy metadata
     */
    public function initializeConversationForPatient($patientId, $therapistId, $sectionId = null, $mode = THERAPY_MODE_AI_HYBRID, $model = null, $aiEnabled = true, $autoStartContext = null)
    {
        // Check if patient already has an active conversation
        $existing = $this->getTherapyConversationBySubject($patientId);
        if ($existing) {
            return $existing;
        }

        // Verify therapist has access to this patient
        if (!$this->canTherapistAccessPatient($therapistId, $patientId)) {
            return null;
        }

        // Get LLM config for model
        $config = $this->getLlmConfig();
        $modelToUse = $model ?: $config['llm_default_model'];

        // Create base LLM conversation owned by the patient
        $conversationId = $this->createConversation(
            $patientId,
            'Therapy Chat',
            $modelToUse,
            $config['llm_temperature'],
            $config['llm_max_tokens'],
            $sectionId
        );

        if (!$conversationId) {
            return null;
        }

        $therapyMetaId = $this->createTherapyMetadata(
            $conversationId, $mode, $aiEnabled, $therapistId,
            'Therapy conversation initialized by therapist for patient #' . $patientId
        );

        if (!$therapyMetaId) {
            return null;
        }

        $conversation = $this->getTherapyConversation($therapyMetaId);

        // If auto-start context is provided, send an initial system message
        if ($conversation && !empty($autoStartContext)) {
            $this->sendAutoStartMessage($conversation, $autoStartContext, $therapistId);
        }

        return $conversation;
    }

    /**
     * Send an auto-start system message when a conversation is initialized.
     * This allows the system/therapist to set up the conversation context.
     *
     * @param array $conversation The conversation data
     * @param string $autoStartContext The auto-start context/message
     * @param int $initiatorId The user who triggered the initialization
     */
    protected function sendAutoStartMessage($conversation, $autoStartContext, $initiatorId)
    {
        try {
            $llmConvId = $conversation['id_llmConversations'];

            // Add a system message to establish conversation context
            $this->addMessage(
                $llmConvId,
                'system',
                $autoStartContext,
                null, null, null, null,
                array(
                    'therapy_sender_type' => 'system',
                    'therapy_sender_id' => $initiatorId,
                    'auto_start' => true
                )
            );
        } catch (Exception $e) {
            error_log("TherapyChat: Failed to send auto-start message: " . $e->getMessage());
        }
    }

    /**
     * Create therapy metadata record for a new conversation.
     *
     * @param int $conversationId llmConversations.id
     * @param string $mode Chat mode code
     * @param bool $aiEnabled
     * @param int $logUserId User ID for transaction logging
     * @param string $logMessage Transaction log description
     * @return int|null therapyConversationMeta.id or null on failure
     */
    private function createTherapyMetadata($conversationId, $mode, $aiEnabled, $logUserId, $logMessage)
    {
        $modeId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_CHAT_MODES, $mode);
        $statusId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_CONVERSATION_STATUS, THERAPY_STATUS_ACTIVE);
        $riskId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_RISK_LEVELS, THERAPY_RISK_LOW);

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
            $logUserId,
            $logMessage
        );

        return $therapyMetaId;
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
     * Toggle AI on/off for a conversation.
     *
     * When enabling AI, also unblocks the underlying llmConversation
     * (which may have been blocked by danger detection). This allows
     * the therapist to review and resume AI after a safety flag.
     *
     * @param int $conversationId therapyConversationMeta.id
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

            // When re-enabling AI, unblock the underlying llmConversation
            // so that the LLM API can process messages again.
            if ($enabled && $conversation) {
                $this->unblockConversation($conversationId);
            }

            $this->logTransaction(
                transactionTypes_update, 'therapyConversationMeta', $conversationId, $uid,
                'AI ' . ($enabled ? 'enabled (conversation unblocked)' : 'disabled')
            );
        }

        return $result;
    }

    /**
     * Unblock the underlying llmConversation for a therapy conversation.
     * Clears blocked flag, reason, and timestamp.
     *
     * @param int $conversationId therapyConversationMeta.id
     * @return bool
     */
    public function unblockConversation($conversationId)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) {
            return false;
        }

        $llmConvId = $conversation['id_llmConversations'];
        $result = $this->db->update_by_ids(
            'llmConversations',
            array(
                'blocked' => 0,
                'blocked_reason' => null,
                'blocked_at' => null,
                'blocked_by' => null
            ),
            array('id' => $llmConvId)
        );

        if ($result) {
            $this->logTransaction(
                transactionTypes_update, 'llmConversations', $llmConvId,
                $conversation['id_users'] ?? 0,
                'Conversation unblocked by therapist (AI re-enabled)'
            );
        }

        return $result;
    }

    /**
     * Block the underlying llmConversation for a therapy conversation.
     * Sets blocked=1, blocked_at, and blocked_reason on llmConversations.
     *
     * @param int $conversationId therapyConversationMeta.id
     * @param string $reason Reason for blocking
     * @return bool
     */
    public function blockConversation($conversationId, $reason = 'Danger keywords detected')
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) {
            return false;
        }

        $llmConvId = $conversation['id_llmConversations'];
        $result = $this->db->update_by_ids(
            'llmConversations',
            array(
                'blocked' => 1,
                'blocked_reason' => $reason,
                'blocked_at' => date('Y-m-d H:i:s')
            ),
            array('id' => $llmConvId)
        );

        if ($result) {
            $this->logTransaction(
                transactionTypes_update, 'llmConversations', $llmConvId,
                $conversation['id_users'] ?? 0,
                'Conversation blocked: ' . $reason
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
        $result = $this->db->update_by_ids(
            'therapyNotes',
            array(
                'content' => $newContent,
                'id_lastEditedBy' => $therapistId
            ),
            array('id' => $noteId)
        );

        if ($result) {
            $this->logTransaction(
                transactionTypes_update, 'therapyNotes', $noteId, $therapistId,
                'Note edited by therapist'
            );
        }

        return $result;
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

        $result = $this->db->update_by_ids(
            'therapyNotes',
            array(
                'id_noteStatus' => $deletedStatusId,
                'id_lastEditedBy' => $therapistId
            ),
            array('id' => $noteId)
        );

        if ($result) {
            $this->logTransaction(
                transactionTypes_delete, 'therapyNotes', $noteId, $therapistId,
                'Note soft-deleted by therapist'
            );
        }

        return $result;
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
        $conversations = $this->getTherapyConversationsByTherapist($therapistId, array(), THERAPY_STATS_LIMIT, 0);

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

}
?>
