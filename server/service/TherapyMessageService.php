<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . '/TherapyAlertService.php';

/**
 * Therapy Message Service
 *
 * Top-level service for messaging in therapy conversations.
 * Inherits: LlmService → TherapyChatService → TherapyAlertService → this
 *
 * Responsibilities:
 * - Send messages (subject, therapist, AI, system)
 * - Get messages with sender type resolution
 * - Edit and soft-delete messages
 * - Process @mentions and create tag alerts
 * - Manage message recipients (unread tracking)
 * - Draft message workflow (generate AI draft, edit, send)
 * - AI response processing with therapist context
 *
 * SENDER TRACKING:
 * All messages use llmMessages.sent_context JSON:
 *   { "therapy_sender_type": "subject|therapist|ai|system", "therapy_sender_id": 123 }
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapyMessageService extends TherapyAlertService
{
    const SENDER_AI = 'ai';
    const SENDER_THERAPIST = 'therapist';
    const SENDER_SUBJECT = 'subject';
    const SENDER_SYSTEM = 'system';

    public function __construct($services)
    {
        parent::__construct($services);
    }

    /* =========================================================================
     * SEND MESSAGE
     * ========================================================================= */

    /**
     * Send a message in a therapy conversation.
     *
     * @param int $conversationId therapyConversationMeta.id
     * @param int $senderId User ID
     * @param string $content Message content
     * @param string $senderType SENDER_* constant
     * @param array|null $metadata Extra context
     * @return array {success, message_id, conversation_id} or {error}
     */
    public function sendTherapyMessage($conversationId, $senderId, $content, $senderType = self::SENDER_SUBJECT, $metadata = null)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) {
            return array('error' => 'Conversation not found');
        }

        $role = $this->mapSenderTypeToRole($senderType);
        $model = ($senderType === self::SENDER_AI) ? $conversation['model'] : null;

        // Build sent_context with sender info
        $sentContext = array(
            'therapy_sender_type' => $senderType,
            'therapy_sender_id' => $senderId
        );
        if ($metadata) {
            $sentContext = array_merge($sentContext, $metadata);
        }

        $llmConversationId = $conversation['id_llmConversations'];

        try {
            $messageId = $this->addMessage(
                $llmConversationId,
                $role,
                $content,
                null, // attachments
                $model,
                null, // tokens
                null, // raw response
                $sentContext
            );
        } catch (Exception $e) {
            return array('error' => 'Failed to save message: ' . $e->getMessage());
        }

        // Update last seen
        if ($senderType === self::SENDER_THERAPIST) {
            $this->updateLastSeen($conversationId, 'therapist');
        } elseif ($senderType === self::SENDER_SUBJECT) {
            $this->updateLastSeen($conversationId, 'subject');
        }

        // Create recipients
        $this->createMessageRecipients($messageId, $conversation, $senderType, $senderId);

        // Process @mentions for subject messages
        if ($senderType === self::SENDER_SUBJECT) {
            $this->processTagsInMessage($messageId, $conversation, $content);
        }

        return array(
            'success' => true,
            'message_id' => $messageId,
            'conversation_id' => $conversationId
        );
    }

    /* =========================================================================
     * GET MESSAGES
     * ========================================================================= */

    /**
     * Get messages for a therapy conversation with sender info.
     *
     * @param int $conversationId therapyConversationMeta.id
     * @param int $limit
     * @param int|null $afterId For polling - only messages after this ID
     * @return array
     */
    public function getTherapyMessages($conversationId, $limit = 100, $afterId = null)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) {
            return array();
        }

        $llmConversationId = $conversation['id_llmConversations'];

        $sql = "SELECT lm.id, lm.role, lm.content, lm.model, lm.tokens_used,
                       lm.timestamp, lm.sent_context, lm.deleted,
                       JSON_UNQUOTE(JSON_EXTRACT(lm.sent_context, '$.therapy_sender_type')) as sender_type,
                       JSON_UNQUOTE(JSON_EXTRACT(lm.sent_context, '$.therapy_sender_id')) as sender_id,
                       JSON_UNQUOTE(JSON_EXTRACT(lm.sent_context, '$.edited_at')) as edited_at,
                       JSON_UNQUOTE(JSON_EXTRACT(lm.sent_context, '$.edited_by')) as edited_by,
                       u.name as sender_name
                FROM llmMessages lm
                LEFT JOIN users u ON u.id = JSON_UNQUOTE(JSON_EXTRACT(lm.sent_context, '$.therapy_sender_id'))
                WHERE lm.id_llmConversations = :cid
                AND lm.is_validated = 1";

        $params = array(':cid' => $llmConversationId);

        if ($afterId) {
            $sql .= " AND lm.id > :after_id";
            $params[':after_id'] = $afterId;
        }

        $sql .= " ORDER BY lm.timestamp ASC LIMIT " . (int)$limit;

        $messages = $this->db->query_db($sql, $params);

        // Add labels and format
        foreach ($messages as &$msg) {
            $msg['label'] = $this->getSenderLabel($msg['sender_type'], $msg['sender_name']);
            $msg['is_deleted'] = (bool)$msg['deleted'];
            $msg['is_edited'] = !empty($msg['edited_at']);
            // Mask deleted message content
            if ($msg['is_deleted']) {
                $msg['content'] = '[Message deleted]';
            }
        }

        return $messages;
    }

    /* =========================================================================
     * EDIT / DELETE MESSAGES
     * ========================================================================= */

    /**
     * Edit a message (therapist only). Stores original content in sent_context.
     *
     * @param int $messageId llmMessages.id
     * @param int $editorId User ID of the editor
     * @param string $newContent New message content
     * @return bool
     */
    public function editMessage($messageId, $editorId, $newContent)
    {
        // Get existing message
        $sql = "SELECT * FROM llmMessages WHERE id = :id";
        $msg = $this->db->query_db_first($sql, array(':id' => $messageId));
        if (!$msg) return false;

        // Parse existing sent_context
        $sentContext = $msg['sent_context'] ? json_decode($msg['sent_context'], true) : array();

        // Store original content on first edit
        if (!isset($sentContext['original_content'])) {
            $sentContext['original_content'] = $msg['content'];
        }
        $sentContext['edited_at'] = date('Y-m-d H:i:s');
        $sentContext['edited_by'] = $editorId;

        // Update message
        $sql = "UPDATE llmMessages SET content = ?, sent_context = ? WHERE id = ?";
        $this->db->query_db($sql, array($newContent, json_encode($sentContext), $messageId));

        return true;
    }

    /**
     * Soft-delete a message (marks as deleted, keeps data).
     *
     * @param int $messageId
     * @param int $deletedBy User ID
     * @return bool
     */
    public function softDeleteMessage($messageId, $deletedBy)
    {
        // Get existing sent_context
        $sql = "SELECT sent_context FROM llmMessages WHERE id = :id";
        $msg = $this->db->query_db_first($sql, array(':id' => $messageId));
        if (!$msg) return false;

        $sentContext = $msg['sent_context'] ? json_decode($msg['sent_context'], true) : array();
        $sentContext['deleted_by'] = $deletedBy;
        $sentContext['deleted_at'] = date('Y-m-d H:i:s');

        $sql = "UPDATE llmMessages SET deleted = 1, sent_context = ? WHERE id = ?";
        $this->db->query_db($sql, array(json_encode($sentContext), $messageId));

        return true;
    }

    /* =========================================================================
     * AI RESPONSE
     * ========================================================================= */

    /**
     * Process AI response for a conversation.
     * Includes therapist messages as high-priority context.
     *
     * @param int $conversationId therapyConversationMeta.id
     * @param array $contextMessages Messages for AI context
     * @param string $model
     * @param float|null $temperature
     * @param int|null $maxTokens
     * @return array {success, message_id, content} or {error}
     */
    public function processAIResponse($conversationId, $contextMessages, $model, $temperature = null, $maxTokens = null)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) {
            return array('error' => 'Conversation not found');
        }

        if (!$conversation['ai_enabled']) {
            return array('error' => 'AI is disabled for this conversation');
        }

        try {
            $response = $this->callLlmApi($contextMessages, $model, $temperature, $maxTokens);

            if (!$response || empty($response['content'])) {
                return array('error' => 'No response from AI');
            }

            $llmConversationId = $conversation['id_llmConversations'];

            $messageId = $this->addMessage(
                $llmConversationId,
                'assistant',
                $response['content'],
                null,
                $model,
                $response['tokens_used'] ?? null,
                $response,
                array('therapy_sender_type' => self::SENDER_AI),
                $response['reasoning'] ?? null,
                true,
                $response['request_payload'] ?? null
            );

            // Create recipient for patient
            $this->createMessageRecipients($messageId, $conversation, self::SENDER_AI, 0);

            return array(
                'success' => true,
                'message_id' => $messageId,
                'content' => $response['content'],
                'tokens_used' => $response['tokens_used'] ?? null
            );
        } catch (Exception $e) {
            return array('error' => 'AI processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Build AI context messages with therapist messages marked as important.
     *
     * @param int $conversationId
     * @param string $systemContext
     * @param int $historyLimit
     * @return array Messages array for LLM API
     */
    public function buildAIContext($conversationId, $systemContext = '', $historyLimit = 50)
    {
        $contextMessages = array();

        // System context
        if ($systemContext) {
            $contextMessages[] = array('role' => 'system', 'content' => $systemContext);
        }

        // Therapy system prompt
        $contextMessages[] = array('role' => 'system', 'content' => $this->getTherapySystemPrompt());

        // Get recent messages
        $messages = $this->getTherapyMessages($conversationId, $historyLimit);

        foreach ($messages as $msg) {
            if ($msg['is_deleted']) continue;

            $role = ($msg['role'] === 'assistant') ? 'assistant' : 'user';
            $content = $msg['content'];

            // Mark therapist messages as authoritative clinical input
            if ($msg['sender_type'] === self::SENDER_THERAPIST) {
                $content = "[THERAPIST - Clinical guidance, treat as authoritative]: " . $content;
            }

            $contextMessages[] = array('role' => $role, 'content' => $content);
        }

        return $contextMessages;
    }

    /* =========================================================================
     * DRAFT MESSAGES
     * ========================================================================= */

    /**
     * Create an AI draft for a therapist.
     *
     * @param int $conversationId
     * @param int $therapistId
     * @param string $aiContent AI-generated content
     * @return int|bool Draft ID
     */
    public function createDraft($conversationId, $therapistId, $aiContent)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) return false;

        $statusId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_DRAFT_STATUS, THERAPY_DRAFT_DRAFT);

        return $this->db->insert('therapyDraftMessages', array(
            'id_llmConversations' => $conversation['id_llmConversations'],
            'id_users' => $therapistId,
            'ai_generated_content' => $aiContent,
            'id_draftStatus' => $statusId
        ));
    }

    /**
     * Update a draft's edited content.
     *
     * @param int $draftId
     * @param string $editedContent
     * @return bool
     */
    public function updateDraft($draftId, $editedContent)
    {
        return $this->db->update_by_ids(
            'therapyDraftMessages',
            array('edited_content' => $editedContent),
            array('id' => $draftId)
        );
    }

    /**
     * Send a draft as a real message.
     *
     * @param int $draftId
     * @param int $therapistId
     * @param int $conversationId therapyConversationMeta.id
     * @return array {success, message_id} or {error}
     */
    public function sendDraft($draftId, $therapistId, $conversationId)
    {
        $sql = "SELECT * FROM therapyDraftMessages WHERE id = ? AND id_users = ?";
        $draft = $this->db->query_db_first($sql, array($draftId, $therapistId));

        if (!$draft) {
            return array('error' => 'Draft not found');
        }

        // Use edited content if available, otherwise AI content
        $content = $draft['edited_content'] ?: $draft['ai_generated_content'];
        if (empty($content)) {
            return array('error' => 'Draft has no content');
        }

        // Send as therapist message
        $result = $this->sendTherapyMessage(
            $conversationId,
            $therapistId,
            $content,
            self::SENDER_THERAPIST,
            array('from_draft' => $draftId)
        );

        if (isset($result['success'])) {
            // Update draft status to sent
            $sentStatusId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_DRAFT_STATUS, THERAPY_DRAFT_SENT);
            $this->db->update_by_ids('therapyDraftMessages', array(
                'id_draftStatus' => $sentStatusId,
                'id_llmMessages' => $result['message_id'],
                'sent_at' => date('Y-m-d H:i:s')
            ), array('id' => $draftId));
        }

        return $result;
    }

    /**
     * Discard a draft.
     *
     * @param int $draftId
     * @param int $therapistId
     * @return bool
     */
    public function discardDraft($draftId, $therapistId)
    {
        $discardedId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_DRAFT_STATUS, THERAPY_DRAFT_DISCARDED);
        return $this->db->update_by_ids('therapyDraftMessages', array(
            'id_draftStatus' => $discardedId
        ), array('id' => $draftId, 'id_users' => $therapistId));
    }

    /**
     * Get active draft for a conversation.
     *
     * @param int $conversationId
     * @param int $therapistId
     * @return array|null
     */
    public function getActiveDraft($conversationId, $therapistId)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) return null;

        $draftStatusId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_DRAFT_STATUS, THERAPY_DRAFT_DRAFT);

        $sql = "SELECT * FROM therapyDraftMessages
                WHERE id_llmConversations = ? AND id_users = ? AND id_draftStatus = ?
                ORDER BY created_at DESC LIMIT 1";

        return $this->db->query_db_first($sql, array(
            $conversation['id_llmConversations'], $therapistId, $draftStatusId
        ));
    }

    /* =========================================================================
     * RECIPIENT MANAGEMENT
     * ========================================================================= */

    /**
     * Get unread message count for a user across all therapy conversations.
     *
     * @param int $userId
     * @return int
     */
    public function getUnreadCountForUser($userId)
    {
        $sql = "SELECT COUNT(*) as cnt FROM therapyMessageRecipients
                WHERE id_users = ? AND is_new = 1";
        $result = $this->db->query_db_first($sql, array($userId));
        return intval($result['cnt'] ?? 0);
    }

    /**
     * Get per-subject unread message counts for a therapist.
     * Returns an associative array keyed by patient user ID.
     *
     * @param int $therapistId
     * @return array [ userId => ['subjectId' => .., 'subjectName' => .., 'unreadCount' => ..] ]
     */
    public function getUnreadBySubjectForTherapist($therapistId)
    {
        $sql = "SELECT tcm.id_users as subject_id,
                       u.name as subject_name,
                       u.code as subject_code,
                       COUNT(tmr.id) as unread_count
                FROM therapyMessageRecipients tmr
                INNER JOIN llmMessages lm ON lm.id = tmr.id_llmMessages
                INNER JOIN therapyConversationMeta tcm ON tcm.id_llmConversations = lm.id_llmConversations
                INNER JOIN users u ON u.id = tcm.id_users
                WHERE tmr.id_users = ? AND tmr.is_new = 1
                GROUP BY tcm.id_users, u.name, u.code";

        $rows = $this->db->query_db($sql, array($therapistId));
        $result = array();
        if ($rows) {
            foreach ($rows as $row) {
                $result[$row['subject_id']] = array(
                    'subjectId' => $row['subject_id'],
                    'subjectName' => $row['subject_name'],
                    'subjectCode' => $row['subject_code'] ?? '',
                    'unreadCount' => intval($row['unread_count'])
                );
            }
        }
        return $result;
    }

    /**
     * Get per-group unread totals for a therapist.
     * Returns [ groupId => totalUnread ].
     *
     * @param int $therapistId
     * @return array
     */
    public function getUnreadByGroupForTherapist($therapistId)
    {
        $sql = "SELECT ug.id_groups, COUNT(tmr.id) as unread_count
                FROM therapyMessageRecipients tmr
                INNER JOIN llmMessages lm ON lm.id = tmr.id_llmMessages
                INNER JOIN therapyConversationMeta tcm ON tcm.id_llmConversations = lm.id_llmConversations
                INNER JOIN users_groups ug ON ug.id_users = tcm.id_users
                INNER JOIN therapyTherapistAssignments tta ON tta.id_groups = ug.id_groups AND tta.id_users = :tid
                WHERE tmr.id_users = :uid AND tmr.is_new = 1
                GROUP BY ug.id_groups";

        $rows = $this->db->query_db($sql, array(':tid' => $therapistId, ':uid' => $therapistId));
        $result = array();
        if ($rows) {
            foreach ($rows as $row) {
                $result[$row['id_groups']] = intval($row['unread_count']);
            }
        }
        return $result;
    }

    /**
     * Mark messages as seen for a user in a conversation.
     *
     * @param int $conversationId therapyConversationMeta.id
     * @param int $userId
     * @return bool
     */
    public function markMessagesAsSeen($conversationId, $userId)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) return false;

        $sql = "UPDATE therapyMessageRecipients tmr
                INNER JOIN llmMessages lm ON lm.id = tmr.id_llmMessages
                SET tmr.is_new = 0, tmr.seen_at = NOW()
                WHERE tmr.id_users = ? AND lm.id_llmConversations = ? AND tmr.is_new = 1";

        $this->db->query_db($sql, array($userId, $conversation['id_llmConversations']));
        return true;
    }

    /* =========================================================================
     * PRIVATE HELPERS
     * ========================================================================= */

    /**
     * Map sender type to LLM message role.
     */
    private function mapSenderTypeToRole($senderType)
    {
        switch ($senderType) {
            case self::SENDER_AI: return 'assistant';
            case self::SENDER_SYSTEM: return 'system';
            default: return 'user'; // subject and therapist both are 'user' role
        }
    }

    /**
     * Get display label for a sender type.
     */
    private function getSenderLabel($senderType, $senderName = null)
    {
        switch ($senderType) {
            case self::SENDER_AI: return 'AI Assistant';
            case self::SENDER_THERAPIST: return $senderName ? "Therapist ($senderName)" : 'Therapist';
            case self::SENDER_SUBJECT: return $senderName ?? 'Patient';
            case self::SENDER_SYSTEM: return 'System';
            default: return $senderName ?? 'Unknown';
        }
    }

    /**
     * Create message recipients based on sender type.
     *
     * @param int $messageId
     * @param array $conversation Conversation data from view
     * @param string $senderType
     * @param int $senderId
     */
    private function createMessageRecipients($messageId, $conversation, $senderType, $senderId)
    {
        $recipients = array();
        $patientId = $conversation['id_users'];

        if ($senderType === self::SENDER_SUBJECT) {
            // Patient sends: notify all assigned therapists
            $therapists = $this->getTherapistsForPatient($patientId);
            foreach ($therapists as $t) {
                $recipients[] = array($messageId, $t['id']);
            }
        } elseif ($senderType === self::SENDER_THERAPIST) {
            // Therapist sends: notify patient
            $recipients[] = array($messageId, $patientId);
        } elseif ($senderType === self::SENDER_AI) {
            // AI sends: notify patient + all assigned therapists
            $recipients[] = array($messageId, $patientId);
            $therapists = $this->getTherapistsForPatient($patientId);
            foreach ($therapists as $t) {
                $recipients[] = array($messageId, $t['id']);
            }
        }

        // Bulk insert (ignore duplicates)
        foreach ($recipients as $r) {
            $sql = "INSERT IGNORE INTO therapyMessageRecipients (id_llmMessages, id_users, is_new) VALUES (?, ?, 1)";
            $this->db->query_db($sql, $r);
        }
    }

    /**
     * Process @mentions in a message and create tag alerts.
     */
    private function processTagsInMessage($messageId, $conversation, $content)
    {
        if (preg_match('/@(?:therapist|Therapist)\b/', $content)) {
            // Tag all therapists for this patient
            $this->createTagAlert(
                $conversation['id_llmConversations'],
                null, // all therapists
                null,
                THERAPY_URGENCY_NORMAL,
                $messageId
            );
        }
    }

    /**
     * Get the therapy-specific AI system prompt.
     */
    private function getTherapySystemPrompt()
    {
        return "You are a supportive AI assistant in a mental health therapy context.\n\n" .
            "Your role:\n" .
            "- Provide empathetic, non-judgmental responses\n" .
            "- Use validation, reflection, and grounding techniques\n" .
            "- Encourage the user while respecting boundaries\n\n" .
            "Important:\n" .
            "- You are NOT a therapist or mental health professional\n" .
            "- You cannot provide diagnoses or treatment recommendations\n" .
            "- Messages marked [THERAPIST] are from the real therapist - follow their clinical guidance\n" .
            "- If a user seems in crisis, encourage contacting their therapist or emergency services";
    }
}
?>
