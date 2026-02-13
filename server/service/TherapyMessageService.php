<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . '/TherapyAlertService.php';

// Include LLM plugin services for proper JSON schema handling
// Path: from server/service/ → ../../ = sh-shp-llm_therapy_chat/ → ../../../ = plugins/
$llmResponseServicePath = __DIR__ . "/../../../sh-shp-llm/server/service/LlmResponseService.php";
if (file_exists($llmResponseServicePath)) {
    require_once $llmResponseServicePath;
}

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
     * SCHEMA / CONTEXT HELPERS
     * ========================================================================= */

    /**
     * Inject the unified JSON response schema into context messages.
     *
     * Uses the parent LLM plugin's LlmResponseService to prepend the
     * structured response schema (with safety instructions if danger
     * keywords are configured). This ensures every LLM call returns
     * JSON following the schema and includes a safety assessment.
     *
     * If LlmResponseService is not available (parent plugin missing),
     * returns the context messages unchanged.
     *
     * @param array $contextMessages Existing context messages
     * @param array $dangerConfig    ['enabled' => bool, 'keywords' => string[]]
     * @return array Context messages with schema prepended
     */
    public function injectResponseSchema($contextMessages, $dangerConfig = array())
    {
        if (!class_exists('LlmResponseService')) {
            return $contextMessages;
        }

        // LlmResponseService($model, $services) — $model is not used for
        // buildResponseContext, pass null as a safe placeholder.
        $responseService = new \LlmResponseService(null, $this->services);

        return $responseService->buildResponseContext(
            $contextMessages,
            false,       // include_progress
            array(),     // progress_data
            $dangerConfig
        );
    }

    /**
     * Get a LlmResponseService instance for safety assessment.
     *
     * @return LlmResponseService|null
     */
    public function getResponseService()
    {
        if (!class_exists('LlmResponseService')) {
            return null;
        }
        return new \LlmResponseService(null, $this->services);
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

        // Process @mentions BEFORE creating recipients so we know which
        // therapists were explicitly tagged (affects who gets notified).
        $taggedTherapistIds = array();
        if ($senderType === self::SENDER_SUBJECT) {
            $taggedTherapistIds = $this->processTagsInMessage($messageId, $conversation, $content);
        }

        // Create recipients — pass AI state + tagged IDs so the method can
        // decide which therapists (if any) should see the message as unread.
        $aiEnabled = !empty($conversation['ai_enabled']);
        $this->createMessageRecipients($messageId, $conversation, $senderType, $senderId, $aiEnabled, $taggedTherapistIds);

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
    public function getTherapyMessages($conversationId, $limit = THERAPY_DEFAULT_MESSAGE_LIMIT, $afterId = null, $labelOverrides = array())
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
            $msg['label'] = $this->getSenderLabel(
                $msg['sender_type'] ?? null,
                $msg['sender_name'] ?? null,
                $msg['role'] ?? null,
                $labelOverrides
            );
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
     *
     * The caller (TherapyChatModel) is responsible for building the full
     * context including any schema/safety instructions via
     * LlmResponseService::buildResponseContext(). This method simply:
     *  1. Calls the LLM API with the provided context
     *  2. Extracts displayable text from structured JSON responses
     *  3. Saves the message and creates recipient entries
     *
     * @param int $conversationId therapyConversationMeta.id
     * @param array $contextMessages Fully prepared messages (with schema instructions)
     * @param string $model
     * @param float|null $temperature
     * @param int|null $maxTokens
     * @return array {success, message_id, content, raw_content} or {error}
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
            // Call LLM API directly — context already includes schema/safety
            // instructions injected by the caller (TherapyChatModel).
            $response = $this->callLlmApi($contextMessages, $model, $temperature, $maxTokens);

            if (!$response || empty($response['content'])) {
                return array('error' => 'No response from AI');
            }

            // Extract displayable text from structured JSON responses.
            // When LlmResponseService schema is injected, the LLM returns JSON
            // with content.text_blocks[]. We need the human-readable text for
            // the message, while the raw JSON is kept in the response metadata.
            $rawContent = $response['content'];
            $displayContent = $this->extractDisplayContent($rawContent);

            $llmConversationId = $conversation['id_llmConversations'];

            // Build sent_context: full context messages sent to the LLM.
            // This matches the parent sh-shp-llm plugin's behavior where
            // LlmContextService::getContextForTracking() returns the full
            // context array. Stored as JSON in llmMessages.sent_context
            // for debugging and audit purposes.
            $sentContext = $contextMessages;

            $messageId = $this->addMessage(
                $llmConversationId,
                'assistant',
                $displayContent,
                null,
                $model,
                $response['tokens_used'] ?? null,
                $response,
                $sentContext,
                $response['reasoning'] ?? null,
                true,
                $response['request_payload'] ?? null
            );

            // Create recipient for patient
            $this->createMessageRecipients($messageId, $conversation, self::SENDER_AI, 0);

            return array(
                'success' => true,
                'message_id' => $messageId,
                'content' => $displayContent,
                'raw_content' => $rawContent,
                'tokens_used' => $response['tokens_used'] ?? null
            );
        } catch (Exception $e) {
            return array('error' => 'AI processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Extract human-readable display text from an LLM response.
     *
     * Handles structured JSON responses from the base LLM plugin.
     * If safety indicates danger, returns safety message + emergency resources.
     * Otherwise extracts from text_blocks.
     *
     * @param string $content Raw LLM response content
     * @return string Displayable text
     */
    public function extractDisplayContent($content)
    {
        $decoded = self::parseLlmJson($content);

        if ($decoded === null) {
            return $content;
        }

        // Check for safety protocol response from base plugin
        if (isset($decoded['safety']) && isset($decoded['safety']['is_safe']) && !$decoded['safety']['is_safe']) {
            $safetyText = $decoded['safety']['safety_message'] ?? 'Safety protocol activated due to detected concerns.';

            // Check danger level
            $dangerLevel = $decoded['safety']['danger_level'] ?? '';
            if ($dangerLevel === 'emergency') {
                $safetyText .= "\n\nEMERGENCY: Immediate professional help is required.";
            }

            // Add emergency resources
            $safetyText .= "\n\nEmergency Resources:\n" .
                "• Contact your therapist or healthcare provider immediately\n" .
                "• Call emergency services (911 in the US, or your local emergency number)\n" .
                "• Contact a crisis hotline:\n" .
                "  - National Suicide Prevention Lifeline: 988 (US)\n" .
                "  - Crisis Text Line: Text HOME to 741741 (US)\n" .
                "  - International: Find local resources at befrienders.org\n\n" .
                "Your safety is the top priority. Please seek professional help right away.";

            return $safetyText;
        }

        // Standard response: extract from text_blocks
        if (isset($decoded['content']['text_blocks'])) {
            // Extract text from text_blocks
            $textParts = array();
            foreach ($decoded['content']['text_blocks'] as $block) {
                if (isset($block['content']) && is_string($block['content'])) {
                    $textParts[] = $block['content'];
                }
            }

            if (!empty($textParts)) {
                return implode("\n\n", $textParts);
            }
        }

        return $content;
    }

    /**
     * Parse JSON from an LLM response string.
     *
     * Handles raw JSON, JSON wrapped in markdown code blocks, and
     * plain text (returns null). Used by both extractDisplayContent
     * and TherapyChatModel::parseStructuredResponse.
     *
     * @param string $content Raw LLM response
     * @return array|null Decoded JSON array or null if not JSON
     */
    public static function parseLlmJson($content)
    {
        $trimmed = trim($content);

        if (empty($trimmed)) {
            return null;
        }

        // Fast check: not JSON
        if ($trimmed[0] !== '{' && $trimmed[0] !== '[') {
            // Try markdown code block extraction
            if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $trimmed, $matches)) {
                $trimmed = $matches[1];
            } else {
                return null;
            }
        }

        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Build AI context messages with therapist messages marked as important.
     *
     * @param int $conversationId
     * @param string $systemContext
     * @param int $historyLimit
     * @return array Messages array for LLM API
     */
    public function buildAIContext($conversationId, $systemContext = '', $historyLimit = THERAPY_AI_CONTEXT_HISTORY_LIMIT)
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
     * @param int $conversationId therapyConversationMeta.id
     * @param int $therapistId
     * @param string $aiContent AI-generated content
     * @return int|bool Draft ID or false on failure
     */
    public function createDraft($conversationId, $therapistId, $aiContent)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) return false;

        $statusId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_DRAFT_STATUS, THERAPY_DRAFT_DRAFT);

        $data = array(
            'id_llmConversations' => $conversation['id_llmConversations'],
            'id_users' => $therapistId,
            'ai_generated_content' => $aiContent
        );

        // Only include status if lookup resolved successfully
        if ($statusId) {
            $data['id_draftStatus'] = $statusId;
        }

        $draftId = $this->db->insert('therapyDraftMessages', $data);

        if ($draftId) {
            $this->logTransaction(
                transactionTypes_insert, 'therapyDraftMessages', $draftId, $therapistId,
                'AI draft created for conversation #' . $conversationId
            );
        }

        return $draftId;
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
     * For therapists the count excludes AI-generated messages so they only
     * see patient messages (and therapist messages from other therapists)
     * as unread. For patients all unread messages are counted.
     *
     * @param int  $userId
     * @param bool $excludeAI  When true, exclude AI messages from the count
     * @return int
     */
    public function getUnreadCountForUser($userId, $excludeAI = false)
    {
        if ($excludeAI) {
            $sql = "SELECT COUNT(*) as cnt
                    FROM therapyMessageRecipients tmr
                    INNER JOIN llmMessages lm ON lm.id = tmr.id_llmMessages
                    WHERE tmr.id_users = ? AND tmr.is_new = 1
                      AND lm.role != 'assistant'";
        } else {
            $sql = "SELECT COUNT(*) as cnt FROM therapyMessageRecipients
                    WHERE id_users = ? AND is_new = 1";
        }
        $result = $this->db->query_db_first($sql, array($userId));
        return intval($result['cnt'] ?? 0);
    }

    /**
     * Get per-subject unread message counts for a therapist.
     * Only counts patient (subject) messages — AI messages are excluded
     * so therapists see a meaningful unread count.
     *
     * @param int $therapistId
     * @return array [ userId => ['subjectId' => .., 'subjectName' => .., 'unreadCount' => ..] ]
     */
    public function getUnreadBySubjectForTherapist($therapistId)
    {
        $sql = "SELECT lc.id_users as subject_id,
                       u.name as subject_name,
                       vc.code as subject_code,
                       COUNT(tmr.id_llmMessages) as unread_count
                FROM therapyMessageRecipients tmr
                INNER JOIN llmMessages lm ON lm.id = tmr.id_llmMessages
                INNER JOIN therapyConversationMeta tcm ON tcm.id_llmConversations = lm.id_llmConversations
                INNER JOIN llmConversations lc ON lc.id = tcm.id_llmConversations
                INNER JOIN users u ON u.id = lc.id_users
                LEFT JOIN validation_codes vc ON vc.id_users = u.id 
                WHERE tmr.id_users = ? AND tmr.is_new = 1
                  AND lm.role != 'assistant'
                GROUP BY lc.id_users, u.name, vc.code";

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
     * Only counts patient messages — AI messages are excluded.
     *
     * @param int $therapistId
     * @return array [ groupId => totalUnread ]
     */
    public function getUnreadByGroupForTherapist($therapistId)
    {
        $sql = "SELECT ug.id_groups, COUNT(tmr.id_llmMessages) as unread_count
                FROM therapyMessageRecipients tmr
                INNER JOIN llmMessages lm ON lm.id = tmr.id_llmMessages
                INNER JOIN therapyConversationMeta tcm ON tcm.id_llmConversations = lm.id_llmConversations
                INNER JOIN llmConversations lc ON lc.id = tcm.id_llmConversations
                INNER JOIN users_groups ug ON ug.id_users = lc.id_users
                INNER JOIN therapyTherapistAssignments tta ON tta.id_groups = ug.id_groups AND tta.id_users = :tid
                WHERE tmr.id_users = :uid AND tmr.is_new = 1
                  AND lm.role != 'assistant'
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
     *
     * Backward compatibility: if sender type is missing (legacy rows),
     * infer AI/system from the llmMessages role.
     */
    private function getSenderLabel($senderType, $senderName = null, $role = null, $labels = array())
    {
        $aiLabel = $labels['ai'] ?? 'AI Assistant';
        $therapistLabel = $labels['therapist'] ?? 'Therapist';
        $subjectLabel = $labels['subject'] ?? 'Patient';
        $systemLabel = $labels['system'] ?? 'System';

        if (empty($senderType)) {
            if ($role === 'assistant') return $aiLabel;
            if ($role === 'system') return $systemLabel;
        }

        switch ($senderType) {
            case self::SENDER_AI: return $aiLabel;
            case self::SENDER_THERAPIST: return $senderName ? "$therapistLabel ($senderName)" : $therapistLabel;
            case self::SENDER_SUBJECT: return $senderName ?? $subjectLabel;
            case self::SENDER_SYSTEM: return $systemLabel;
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
    /**
     * Create recipient entries that control who sees a message as "unread".
     *
     * Rules:
     *  - SENDER_SUBJECT + AI enabled  → only tagged therapists (if any)
     *  - SENDER_SUBJECT + AI disabled → all assigned therapists
     *  - SENDER_THERAPIST             → patient only
     *  - SENDER_AI                    → patient only (AI conversation is not
     *                                   for the therapist to review)
     *
     * @param int   $messageId
     * @param array $conversation
     * @param string $senderType
     * @param int   $senderId
     * @param bool  $aiEnabled           Whether AI is enabled for this conversation
     * @param int[] $taggedTherapistIds  Therapist IDs explicitly tagged in the message
     */
    private function createMessageRecipients($messageId, $conversation, $senderType, $senderId, $aiEnabled = false, $taggedTherapistIds = array())
    {
        $recipients = array();
        $patientId = $conversation['id_users'];

        if ($senderType === self::SENDER_SUBJECT) {
            if ($aiEnabled) {
                // AI handles the conversation — only notify explicitly tagged therapists
                foreach ($taggedTherapistIds as $tid) {
                    $recipients[] = array($messageId, $tid);
                }
            } else {
                // No AI — every patient message is intended for the therapist(s)
                $therapists = $this->getTherapistsForPatient($patientId);
                foreach ($therapists as $t) {
                    $recipients[] = array($messageId, $t['id']);
                }
            }
        } elseif ($senderType === self::SENDER_THERAPIST) {
            // Therapist sends: notify patient
            $recipients[] = array($messageId, $patientId);
        } elseif ($senderType === self::SENDER_AI) {
            // AI responds: only notify the patient (not the therapist)
            $recipients[] = array($messageId, $patientId);
        }

        // Bulk insert (ignore duplicates)
        foreach ($recipients as $r) {
            $sql = "INSERT IGNORE INTO therapyMessageRecipients (id_llmMessages, id_users, is_new) VALUES (?, ?, 1)";
            $this->db->query_db($sql, $r);
        }
    }

    /**
     * Detect @mentioned therapists in a message (pure detection, no side effects).
     *
     * Checks for:
     *   - @therapist / @Therapist → returns ALL therapists for the patient
     *   - @TherapistName           → returns that specific therapist
     *
     * @param string $content Message text
     * @param int $patientId The patient user ID
     * @return array {isTagAll: bool, isTagSpecific: bool, taggedIds: int[]}
     */
    public function detectMentionedTherapists($content, $patientId)
    {
        $result = array('isTagAll' => false, 'isTagSpecific' => false, 'taggedIds' => array());

        // Check for @therapist (tag all)
        if (preg_match('/@(?:therapist|Therapist)\b/', $content)) {
            $result['isTagAll'] = true;
            $therapists = $this->getTherapistsForPatient($patientId);
            foreach ($therapists as $t) {
                $result['taggedIds'][] = (int)$t['id'];
            }
            return $result;
        }

        // Check for @SpecificTherapistName mentions
        $therapists = $this->getTherapistsForPatient($patientId);
        if (!empty($therapists)) {
            foreach ($therapists as $t) {
                $name = $t['name'] ?? '';
                if (empty($name)) continue;
                $escaped = preg_quote($name, '/');
                if (preg_match('/@' . $escaped . '\b/i', $content)) {
                    $result['isTagSpecific'] = true;
                    $result['taggedIds'][] = (int)$t['id'];
                }
            }
        }

        return $result;
    }

    /**
     * Process @mentions in a message and create tag alerts.
     *
     * Detects both:
     *   - @therapist / @Therapist → tag ALL therapists
     *   - @TherapistName           → tag that specific therapist
     *
     * Returns an array of matched therapist IDs (empty array = no match,
     * null inside = "all").
     *
     * @param int    $messageId
     * @param array  $conversation
     * @param string $content
     * @return array  Therapist user IDs that were tagged (empty = none)
     */
    private function processTagsInMessage($messageId, $conversation, $content)
    {
        $taggedIds = array();

        // 1. Check for @therapist (tag all)
        if (preg_match('/@(?:therapist|Therapist)\b/', $content)) {
            $this->createTagAlert(
                $conversation['id_llmConversations'],
                null, // all therapists
                null,
                THERAPY_URGENCY_NORMAL,
                $messageId
            );
            // Return all therapist IDs
            $patientId = $conversation['id_users'];
            $therapists = $this->getTherapistsForPatient($patientId);
            foreach ($therapists as $t) {
                $taggedIds[] = (int)$t['id'];
            }
            return $taggedIds;
        }

        // 2. Check for @SpecificTherapistName mentions
        $patientId = $conversation['id_users'];
        $therapists = $this->getTherapistsForPatient($patientId);
        if (!empty($therapists)) {
            foreach ($therapists as $t) {
                $name = $t['name'] ?? '';
                if (empty($name)) continue;
                // Match @TherapistName (case-insensitive)
                $escaped = preg_quote($name, '/');
                if (preg_match('/@' . $escaped . '\b/i', $content)) {
                    $taggedIds[] = (int)$t['id'];
                    // Create a tag alert for this specific therapist
                    $this->createTagAlert(
                        $conversation['id_llmConversations'],
                        (int)$t['id'],
                        null,
                        THERAPY_URGENCY_NORMAL,
                        $messageId
                    );
                }
            }
        }

        return $taggedIds;
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
            "- Always follow the response schema provided in the system context\n" .
            "- For crisis situations, set appropriate safety flags in the response schema";
    }

    /* =========================================================================
     * LIGHTWEIGHT POLLING HELPERS
     * ========================================================================= */

    /**
     * Get the latest message ID for a specific therapy conversation.
     *
     * @param int $conversationId therapyConversationMeta.id
     * @return int|null
     */
    public function getLatestMessageIdForConversation($conversationId)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) return null;

        $sql = "SELECT MAX(id) as latest_id FROM llmMessages WHERE id_llmConversations = ?";
        $result = $this->db->query_db_first($sql, array($conversation['id_llmConversations']));
        return $result ? (int)$result['latest_id'] : null;
    }

    /**
     * Get the latest message ID across all conversations the therapist has access to.
     * Used for lightweight polling: frontend compares this to its last known ID.
     *
     * @param int $therapistId
     * @return int|null
     */
    public function getLatestMessageIdForTherapist($therapistId)
    {
        $sql = "SELECT MAX(lm.id) as latest_id
                FROM llmMessages lm
                INNER JOIN therapyConversationMeta tcm ON tcm.id_llmConversations = lm.id_llmConversations
                INNER JOIN llmConversations lc ON lc.id = tcm.id_llmConversations
                INNER JOIN users_groups ug ON ug.id_users = lc.id_users
                INNER JOIN therapyTherapistAssignments tta ON tta.id_groups = ug.id_groups AND tta.id_users = :tid
                WHERE 1";
        $result = $this->db->query_db_first($sql, array(':tid' => $therapistId));
        return $result ? (int)$result['latest_id'] : null;
    }

    /* =========================================================================
     * SPEECH-TO-TEXT (shared by both models)
     * ========================================================================= */

    /**
     * Transcribe audio to text using the LLM plugin's speech service.
     * Centralised here to avoid code duplication between TherapyChatModel
     * and TherapistDashboardModel.
     *
     * @param string $tempPath Path to uploaded audio file
     * @param string $model    Speech-to-text model name
     * @param string $language Language code ('auto' is converted to null)
     * @return array {success, text} or {error}
     */
    public function transcribeSpeech($tempPath, $model, $language)
    {
        $llmSpeechServicePath = __DIR__ . "/../../../sh-shp-llm/server/service/LlmSpeechToTextService.php";
        if (!file_exists($llmSpeechServicePath)) {
            return array('error' => 'Speech-to-text service not available.');
        }

        require_once $llmSpeechServicePath;

        $speechService = new LlmSpeechToTextService($this->services);
        $result = $speechService->transcribeAudio(
            $tempPath,
            $model,
            $language !== 'auto' ? $language : null
        );

        if (isset($result['error'])) {
            return array('error' => $result['error']);
        }
        return array('success' => true, 'text' => $result['text'] ?? '');
    }

    /* =========================================================================
     * THERAPIST TOOLS CONVERSATION (shared for drafts + summaries)
     * ========================================================================= */

    /**
     * Get or create a dedicated LLM conversation for therapist tools
     * (AI drafts, summaries) per section + user. This prevents draft/summary
     * messages from appearing in the patient's conversation.
     *
     * @param int $therapistId
     * @param int $sectionId The dashboard section
     * @param string $purpose 'draft' or 'summary' (for title only)
     * @return int The LLM conversation ID
     */
    public function getOrCreateTherapistToolsConversation($therapistId, $sectionId, $purpose = 'tools')
    {
        $title = 'Therapist Tools - Section #' . $sectionId;

        // Look for an existing tools conversation for this therapist + section
        $sql = "SELECT id FROM llmConversations
                WHERE id_users = :uid AND id_sections = :sid AND title = :title
                AND (deleted IS NULL OR deleted = 0)
                ORDER BY id DESC LIMIT 1";
        $existing = $this->db->query_db_first($sql, array(
            ':uid' => $therapistId,
            ':sid' => $sectionId,
            ':title' => $title
        ));

        if ($existing) {
            return (int)$existing['id'];
        }

        // Create a new one
        $config = $this->getLlmConfig();
        $llmConvId = $this->db->insert('llmConversations', array(
            'id_users' => $therapistId,
            'id_sections' => $sectionId,
            'title' => $title,
            'model' => $config['llm_default_model'],
            'temperature' => $config['llm_temperature'],
            'max_tokens' => $config['llm_max_tokens']
        ));

        if ($llmConvId) {
            $this->logTransaction(
                transactionTypes_insert, 'llmConversations', $llmConvId, $therapistId,
                'Therapist tools conversation created for section #' . $sectionId
            );
        }

        return $llmConvId;
    }

    /* =========================================================================
     * SUMMARIZATION
     * ========================================================================= */

    /**
     * Store a summary request/response in the therapist's tools conversation.
     * All summaries for the same therapist + section are appended to the
     * same conversation instead of creating a new one each time.
     *
     * @param int $therapyConvId The therapy conversation being summarized
     * @param int $therapistId
     * @param int $sectionId The therapist dashboard section
     * @param string $summaryContent The AI-generated summary
     * @param array $requestMessages The messages sent to the LLM
     * @param array $response The raw LLM response
     * @return int|null The LLM conversation ID
     */
    public function createSummaryConversation($therapyConvId, $therapistId, $sectionId, $summaryContent, $requestMessages, $response)
    {
        // Use the shared therapist tools conversation
        $llmConvId = $this->getOrCreateTherapistToolsConversation($therapistId, $sectionId, 'summary');
        if (!$llmConvId) return null;

        // Log the user request (the summarization request)
        $this->addMessage(
            $llmConvId,
            'user',
            'Generate clinical summary for therapy conversation #' . $therapyConvId,
            null, null, null, null,
            array(
                'therapy_sender_type' => self::SENDER_THERAPIST,
                'therapy_sender_id' => $therapistId,
                'summary_for_conversation' => $therapyConvId
            )
        );

        // Log the AI response (the generated summary)
        $this->addMessage(
            $llmConvId,
            'assistant',
            $summaryContent,
            null,
            $response['model'] ?? null,
            $response['tokens_used'] ?? null,
            $response,
            array(
                'therapy_sender_type' => self::SENDER_AI,
                'summary_for_conversation' => $therapyConvId
            ),
            $response['reasoning'] ?? null,
            true,
            $response['request_payload'] ?? null
        );

        // Log transaction
        $this->logTransaction(
            transactionTypes_insert, 'llmMessages', $llmConvId, $therapistId,
            'Summary appended for therapy conversation #' . $therapyConvId
        );

        return $llmConvId;
    }
}
?>
