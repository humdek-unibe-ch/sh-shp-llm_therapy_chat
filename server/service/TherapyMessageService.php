<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . '/TherapyChatService.php';
require_once __DIR__ . '/TherapyTaggingService.php';

/**
 * Therapy Message Service
 * 
 * Extends LLM message handling with therapy-specific features like:
 * - Sender type tracking (AI, therapist, subject)
 * - Message attribution and labeling
 * - @mention tag detection and processing
 * 
 * All messages are stored in llmMessages table (from sh-shp-llm plugin).
 * 
 * @package LLM Therapy Chat Plugin
 * @requires sh-shp-llm plugin
 */
class TherapyMessageService extends TherapyChatService
{
    /* Properties **************************************************************/

    /** @var TherapyTaggingService */
    private $taggingService;
    /* Constants **************************************************************/

    const SENDER_AI = 'ai';
    const SENDER_THERAPIST = 'therapist';
    const SENDER_SUBJECT = 'subject';
    const SENDER_SYSTEM = 'system';

    /**
     * Constructor
     *
     * @param object $services Service container
     */
    public function __construct($services)
    {
        parent::__construct($services);
        $this->taggingService = new TherapyTaggingService($services);
    }

    /**
     * Send a message in a therapy conversation
     * 
     * Uses the parent LlmService::addMessage() to store in llmMessages,
     * then adds therapy-specific processing (tag detection, alerts).
     *
     * @param int $conversationId
     * @param int $senderId User ID of the sender
     * @param string $content Message content
     * @param string $senderType One of SENDER_* constants
     * @param array|null $attachments Optional file attachments
     * @param array|null $metadata Additional metadata
     * @return array Message data with ID, or error
     */
    public function sendTherapyMessage($conversationId, $senderId, $content, $senderType = self::SENDER_SUBJECT, $attachments = null, $metadata = null)
    {
        // Get the therapy conversation
        $conversation = $this->getTherapyConversation($conversationId);

        if (!$conversation) {
            return array('error' => 'Conversation not found');
        }

        // TEMPORARILY BYPASS ACCESS CONTROL FOR DEBUGGING
        // Verify access
        // if (!$this->canAccessTherapyConversation($senderId, $conversationId)) {
        //     return array('error' => 'Access denied');
        // }

        // Map sender type to LLM role
        $role = $this->mapSenderTypeToRole($senderType);

        // Get model for therapist/AI messages
        $model = ($senderType === self::SENDER_AI) ? $conversation['model'] : null;

        // For subject messages: check if therapist is tagged
        $skipAI = false;
        if ($senderType === self::SENDER_SUBJECT) {
            // Check for @therapist tag in content
            if (preg_match('/@(?:therapist|Therapist)\b/', $content)) {
                $skipAI = true; // Skip AI processing, send to therapist
            }
        }

        // Build sent context with sender type metadata
        $sentContext = array(
            'therapy_sender_type' => $senderType,
            'therapy_sender_id' => $senderId,
            'therapy_mode' => $conversation['mode'],
            'therapy_skip_ai' => $skipAI
        );

        if ($metadata) {
            $sentContext = array_merge($sentContext, $metadata);
        }

        // Use parent addMessage() to store in llmMessages
        // Note: addMessage expects the LLM conversation ID, not therapy meta ID
        $llmConversationId = $conversation['id_llmConversations'];

        try {
            $messageId = $this->addMessage(
                $llmConversationId,
                $role,
                $content,
                $attachments,
                $model,
                null, // tokens (calculated by API for AI messages)
                null, // raw response
                $sentContext
            );
        } catch (Exception $e) {
            return array('error' => 'Failed to save message: ' . $e->getMessage());
        }

        // Update last seen timestamp
        if ($senderType === self::SENDER_THERAPIST) {
            $this->updateLastSeen($conversationId, 'therapist');
        } else if ($senderType === self::SENDER_SUBJECT) {
            $this->updateLastSeen($conversationId, 'subject');
        }

        // Create message recipients based on sender type and skipAI flag
        $this->createMessageRecipients($messageId, $conversationId, $senderType, $senderId, $skipAI);

        // Check for @mentions (tags) in subject messages
        if ($senderType === self::SENDER_SUBJECT) {
            $this->processTagsInMessage($messageId, $conversationId, $content);
        }

        return array(
            'success' => true,
            'message_id' => $messageId,
            'conversation_id' => $conversationId
        );
    }

    /**
     * Process AI response for a therapy message
     * 
     * Gets AI response from LLM plugin and stores it as AI message.
     *
     * @param int $conversationId
     * @param array $contextMessages Messages to send to AI
     * @param string $model AI model to use
     * @param float|null $temperature
     * @param int|null $maxTokens
     * @return array AI message data, or error
     */
    public function processAIResponse($conversationId, $contextMessages, $model, $temperature = null, $maxTokens = null)
    {
        $conversation = $this->getTherapyConversation($conversationId);

        if (!$conversation) {
            return array('error' => 'Conversation not found');
        }

        // Check if AI is enabled for this conversation
        if (!$conversation['ai_enabled']) {
            return array('error' => 'AI responses are disabled for this conversation');
        }

        // Get the LLM conversation ID
        $llmConversationId = $conversation['id_llmConversations'];

        try {
            // Call LLM API using parent method
            $response = $this->callLlmApi($contextMessages, $model, $temperature, $maxTokens);

            if (!$response || empty($response['content'])) {
                return array('error' => 'No response from AI');
            }

            // Store AI response in llmMessages (using LLM conversation ID)
            $messageId = $this->addMessage(
                $llmConversationId,
                'assistant',
                $response['content'],
                null,
                $model,
                $response['tokens_used'] ?? null,
                $response, // raw response
                array('therapy_sender_type' => self::SENDER_AI),
                $response['reasoning'] ?? null,
                true, // is_validated
                $response['request_payload'] ?? null
            );

            return array(
                'success' => true,
                'message_id' => $messageId,
                'content' => $response['content'],
                'tokens_used' => $response['tokens_used'] ?? null,
                'model' => $model
            );
        } catch (Exception $e) {
            return array('error' => 'AI processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Get messages for a therapy conversation with sender info
     *
     * @param int $conversationId The therapy meta ID (from therapyConversationMeta)
     * @param int $limit
     * @param int|null $afterId Only get messages after this ID (for polling)
     * @return array
     */
    public function getTherapyMessages($conversationId, $limit = 100, $afterId = null)
    {
        // Get the LLM conversation ID from therapy meta
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) {
            return array();
        }
        $llmConversationId = $conversation['id_llmConversations'];

        $sql = "SELECT lm.id, lm.role, lm.content, lm.attachments, lm.model, 
                       lm.tokens_used, lm.timestamp, lm.sent_context,
                       JSON_UNQUOTE(JSON_EXTRACT(lm.sent_context, '$.therapy_sender_type')) as sender_type,
                       JSON_UNQUOTE(JSON_EXTRACT(lm.sent_context, '$.therapy_sender_id')) as sender_id,
                       u.name as sender_name
                FROM llmMessages lm
                LEFT JOIN users u ON u.id = JSON_UNQUOTE(JSON_EXTRACT(lm.sent_context, '$.therapy_sender_id'))
                WHERE lm.id_llmConversations = :cid
                AND lm.deleted = 0
                AND lm.is_validated = 1";

        $params = array(':cid' => $llmConversationId);

        if ($afterId) {
            $sql .= " AND lm.id > :after_id";
            $params[':after_id'] = $afterId;
        }

        $sql .= " ORDER BY lm.timestamp ASC LIMIT " . (int)$limit;

        $messages = $this->db->query_db($sql, $params);

        // Process messages to add labels
        foreach ($messages as &$msg) {
            $msg['label'] = $this->getSenderLabel($msg['sender_type'], $msg['sender_name']);

            // Check for tags
            $msg['tags'] = $this->getMessageTags($msg['id']);
        }

        return $messages;
    }

    /**
     * Get count of new messages since a timestamp
     *
     * @param int $conversationId The therapy meta ID
     * @param string $since Timestamp
     * @return int
     */
    public function getNewMessageCount($conversationId, $since)
    {
        // Get the LLM conversation ID from therapy meta
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) {
            return 0;
        }
        $llmConversationId = $conversation['id_llmConversations'];

        $sql = "SELECT COUNT(*) as cnt FROM llmMessages 
                WHERE id_llmConversations = :cid 
                AND deleted = 0 
                AND is_validated = 1
                AND timestamp > :since";

        $result = $this->db->query_db_first($sql, array(
            ':cid' => $llmConversationId,
            ':since' => $since
        ));

        return intval($result['cnt'] ?? 0);
    }

    /**
     * Get count of unread messages since last seen timestamp
     * Used for therapist dashboard to show unread counts per subject
     *
     * @param int $conversationId The therapy meta ID (NOT llmConversations ID)
     * @param string $userType 'therapist' or 'subject'
     * @return int Number of unread messages
     */
    public function getUnreadCountSinceLastSeen($conversationId, $userType = 'therapist')
    {
        // Get the therapy conversation meta (which includes last_seen timestamps)
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) {
            return 0;
        }

        $llmConversationId = $conversation['id_llmConversations'];
        $lastSeenField = ($userType === 'therapist') ? 'therapist_last_seen' : 'subject_last_seen';
        $lastSeen = $conversation[$lastSeenField] ?? null;

        // Build query to count messages after last seen
        // Exclude messages sent by the user type we're checking (therapist doesn't see own messages as unread)
        $excludeSenderType = ($userType === 'therapist') ? 'therapist' : 'subject';

        $sql = "SELECT COUNT(*) as cnt FROM llmMessages lm
                WHERE lm.id_llmConversations = :cid 
                AND lm.deleted = 0 
                AND lm.is_validated = 1
                AND (
                    JSON_UNQUOTE(JSON_EXTRACT(lm.sent_context, '$.therapy_sender_type')) IS NULL
                    OR JSON_UNQUOTE(JSON_EXTRACT(lm.sent_context, '$.therapy_sender_type')) != :exclude_type
                )";

        $params = array(
            ':cid' => $llmConversationId,
            ':exclude_type' => $excludeSenderType
        );

        // If we have a last seen timestamp, only count messages after it
        if ($lastSeen) {
            $sql .= " AND lm.timestamp > :last_seen";
            $params[':last_seen'] = $lastSeen;
        }

        $result = $this->db->query_db_first($sql, $params);

        return intval($result['cnt'] ?? 0);
    }

    /* Private Helpers ********************************************************/

    /**
     * Map therapy sender type to LLM role
     *
     * @param string $senderType
     * @return string
     */
    private function mapSenderTypeToRole($senderType)
    {
        switch ($senderType) {
            case self::SENDER_AI:
                return 'assistant';
            case self::SENDER_SYSTEM:
                return 'system';
            case self::SENDER_THERAPIST:
            case self::SENDER_SUBJECT:
            default:
                return 'user';
        }
    }

    /**
     * Get display label for a sender
     *
     * @param string|null $senderType
     * @param string|null $senderName
     * @return string
     */
    private function getSenderLabel($senderType, $senderName = null)
    {
        switch ($senderType) {
            case self::SENDER_AI:
                return 'AI Assistant';
            case self::SENDER_THERAPIST:
                return $senderName ? "Therapist ($senderName)" : 'Therapist';
            case self::SENDER_SUBJECT:
                return $senderName ?? 'You';
            case self::SENDER_SYSTEM:
                return 'System';
            default:
                return $senderName ?? 'Unknown';
        }
    }

    /**
     * Process @mentions in a message and create tags
     *
     * @param int $messageId
     * @param int $conversationId
     * @param string $content
     */
    private function processTagsInMessage($messageId, $conversationId, $content)
    {
        // Check for @therapist or @Therapist tag
        if (preg_match('/@(?:therapist|Therapist)\b/', $content)) {
            // Get assigned therapist or first available for this group
            $conversation = $this->getTherapyConversation($conversationId);

            if ($conversation) {
                $therapistId = $conversation['id_therapist'];

                // If no assigned therapist, try to find one
                if (!$therapistId) {
                    $therapists = $this->getTherapistsForGroup($conversation['id_groups']);
                    if (!empty($therapists)) {
                        $therapistId = $therapists[0]['id'];
                    }
                }

                if ($therapistId) {
                    $this->createTag($messageId, $therapistId, null, 'normal');
                }
            }
        }
    }

    /**
     * Create a tag entry
     *
     * @param int $messageId
     * @param int $userId Tagged user ID
     * @param string|null $reason
     * @param string $urgency
     * @return int|bool Tag ID or false
     */
    private function createTag($messageId, $userId, $reason = null, $urgency = 'normal')
    {
        $urgencyId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_TAG_URGENCY, $urgency);
        $data = array(
            'id_llmMessages' => $messageId,
            'id_users' => $userId,
            'tag_reason' => $reason,
            'id_tagUrgency' => $urgencyId
        );

        return $this->db->insert('therapyTags', $data);
    }

    /**
     * Get tags for a message
     *
     * @param int $messageId
     * @return array
     */
    private function getMessageTags($messageId)
    {
        $sql = "SELECT tt.*, u.name as tagged_name
                FROM therapyTags tt
                INNER JOIN users u ON u.id = tt.id_users
                WHERE tt.id_llmMessages = :mid";

        return $this->db->query_db($sql, array(':mid' => $messageId));
    }

    /* Helper Methods for Message Recipients ******************************/

    /**
     * Get all therapists in the configured therapist group
     *
     * @param int $conversationId
     * @return array Array of therapist user records
     */
    private function getTherapistsForConversation($conversationId)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation || !$conversation['id_groups']) {
            return [];
        }

        // Get therapist group ID from configuration
        $therapistGroupId = $this->getConfigValue('therapy_chat_therapist_group');
        if (!$therapistGroupId) {
            return [];
        }

        $sql = "SELECT u.id, u.name, u.email FROM users u
                INNER JOIN users_groups ug ON u.id = ug.id_users
                WHERE ug.id_groups = ? AND u.id != ?"; // Exclude the subject if they're in the group

        $subjectId = $conversation['id_users']; // Subject is the conversation owner
        return $this->db->query_db($sql, [$therapistGroupId, $subjectId]);
    }

    /**
     * Get all therapy conversations a user can access
     *
     * @param int $userId
     * @return array Array of therapy conversation meta records
     */
    private function getUserTherapyConversations($userId)
    {
        // Check if user is a subject
        $isSubject = $this->taggingService->isSubject($userId);
        $isTherapist = $this->taggingService->isTherapist($userId);

        if (!$isSubject && !$isTherapist) {
            return [];
        }

        $sql = "SELECT tcm.* FROM therapyConversationMeta tcm
                INNER JOIN llmConversations lc ON tcm.id_llmConversations = lc.id";

        $params = [];
        if ($isSubject && !$isTherapist) {
            // Subject can only see their own conversations
            $sql .= " WHERE lc.id_users = ?";
            $params[] = $userId;
        } elseif ($isTherapist) {
            // Therapists can see conversations in their assigned group
            $sql .= " WHERE tcm.id_groups = (SELECT id_groups FROM users_groups WHERE id_users = ? LIMIT 1)";
            $params[] = $userId;
        }

        return $this->db->query_db($sql, $params);
    }

    /**
     * Create message recipients based on sender type
     *
     * @param int $messageId LLM message ID
     * @param int $conversationId Therapy conversation ID
     * @param string $senderType SENDER_* constant
     * @param int $senderId User ID of sender
     * @param bool $skipAI Whether to skip AI processing (for tagged messages)
     */
    private function createMessageRecipients($messageId, $conversationId, $senderType, $senderId, $skipAI = false)
    {
        $recipients = [];

        if ($senderType === self::SENDER_SUBJECT) {
            // Subject messages: conditional based on skipAI flag
            if ($skipAI) {
                // Tagged message (@therapist): send to all therapists in group
                $therapists = $this->getTherapistsForConversation($conversationId);
                foreach ($therapists as $therapist) {
                    $recipients[] = [
                        'id_llmMessages' => $messageId,
                        'id_users' => $therapist['id'],
                        'is_new' => 1,
                        'seen_at' => null
                    ];
                }
            }
            // If not skipAI, don't create recipients (AI-only conversation)
        } elseif ($senderType === self::SENDER_THERAPIST) {
            // Therapist sends to subject
            $conversation = $this->getTherapyConversation($conversationId);
            if ($conversation) {
                $recipients[] = [
                    'id_llmMessages' => $messageId,
                    'id_users' => $conversation['id_users'], // Subject is conversation owner
                    'is_new' => 1,
                    'seen_at' => null
                ];
            }
        } elseif ($senderType === self::SENDER_AI) {
            // AI sends to subject (and assigned therapist if exists)
            $conversation = $this->getTherapyConversation($conversationId);
            if ($conversation) {
                $recipients[] = [
                    'id_llmMessages' => $messageId,
                    'id_users' => $conversation['id_users'], // Subject
                    'is_new' => 1,
                    'seen_at' => null
                ];

                // Also send to assigned therapist if exists
                if ($conversation['id_therapist']) {
                    $recipients[] = [
                        'id_llmMessages' => $messageId,
                        'id_users' => $conversation['id_therapist'],
                        'is_new' => 1,
                        'seen_at' => null
                    ];
                }
            }
        }

        // Bulk insert recipients
        if (!empty($recipients)) {
            $values = [];
            foreach ($recipients as $recipient) {
                $values[] = [$recipient['id_llmMessages'], $recipient['id_users'], $recipient['is_new'], $recipient['seen_at']];
            }
            $this->db->insert_mult(
                'therapyMessageRecipients',
                ['id_llmMessages', 'id_users', 'is_new', 'seen_at'],
                $values
            );
        }
    }

    /**
     * Mark messages as seen for a user in a conversation
     *
     * @param int $conversationId Therapy conversation ID
     * @param int $userId User ID
     * @param int|null $upToMessageId Mark messages up to this ID as seen
     * @return bool Success status
     */
    public function markMessagesAsSeen($conversationId, $userId, $upToMessageId = null)
    {
        // Verify user can access this conversation
        if (!$this->canAccessTherapyConversation($userId, $conversationId)) {
            return false;
        }

        $sql = "UPDATE therapyMessageRecipients
                SET is_new = 0, seen_at = NOW()
                WHERE id_users = ? AND id_llmMessages IN (
                    SELECT lm.id FROM llmMessages lm
                    INNER JOIN therapyConversationMeta tcm ON lm.id_llmConversations = tcm.id_llmConversations
                    WHERE tcm.id = ?";

        $params = [$userId, $conversationId];

        if ($upToMessageId) {
            $sql .= " AND lm.id <= ?";
            $params[] = $upToMessageId;
        }
        $sql .= ")";

        try {
            $this->db->query_db($sql, $params);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get unread message count for a user across all their therapy conversations
     *
     * @param int $userId User ID
     * @return int Number of unread messages
     */
    public function getUnreadCountForUser($userId)
    {
        // Get all therapy conversations this user can access
        $conversations = $this->getUserTherapyConversations($userId);
        if (empty($conversations)) {
            return 0;
        }

        $conversationIds = array_column($conversations, 'id');

        $placeholders = str_repeat('?,', count($conversationIds) - 1) . '?';

        $sql = "SELECT COUNT(*) as count FROM therapyMessageRecipients tmr
                INNER JOIN llmMessages lm ON tmr.id_llmMessages = lm.id
                INNER JOIN therapyConversationMeta tcm ON lm.id_llmConversations = tcm.id_llmConversations
                WHERE tmr.id_users = ? AND tmr.is_new = 1 AND tcm.id IN ($placeholders)";

        $params = array_merge([$userId], $conversationIds);
        $result = $this->db->query_db_first($sql, $params);

        return $result ? intval($result['count']) : 0;
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
        return $this->taggingService->tagConversationTherapist($conversationId, $messageId, $reasonKey, $urgency);
    }

    /**
     * Get configuration value from therapy chat module settings
     *
     * @param string $fieldName Field name
     * @param string $defaultValue Default value if not found
     * @return string Configuration value
     */
    private function getConfigValue($fieldName, $defaultValue = '')
    {
        try {
            // Get the therapy chat configuration page info
            $configPage = $this->db->fetch_page_info('sh_module_llm_therapy_chat');

            if ($configPage && isset($configPage[$fieldName])) {
                return $configPage[$fieldName] ?: $defaultValue;
            }
        } catch (Exception $e) {
            // Fall back to default if there's any error
        }

        return $defaultValue;
    }
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
}
?>
