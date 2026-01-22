<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . '/TherapyChatService.php';

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
    /* Constants **************************************************************/
    
    const SENDER_AI = 'ai';
    const SENDER_THERAPIST = 'therapist';
    const SENDER_SUBJECT = 'subject';
    const SENDER_SYSTEM = 'system';

    /* Message Management *****************************************************/

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

        // Build sent context with sender type metadata
        $sentContext = array(
            'therapy_sender_type' => $senderType,
            'therapy_sender_id' => $senderId,
            'therapy_mode' => $conversation['mode']
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
     * Get unread message count for a user across all their conversations
     *
     * @param int $userId
     * @return int
     */
    public function getUnreadCountForUser($userId)
    {
        // Get the lookup ID for active status
        $activeStatusId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_CONVERSATION_STATUS, THERAPY_STATUS_ACTIVE);
        
        // For subjects: count messages since their last_seen
        $sql = "SELECT SUM(
                    (SELECT COUNT(*) FROM llmMessages lm 
                     WHERE lm.id_llmConversations = lc.id 
                     AND lm.deleted = 0 
                     AND lm.is_validated = 1
                     AND lm.timestamp > COALESCE(tcm.subject_last_seen, '1970-01-01'))
                ) as cnt
                FROM llmConversations lc
                INNER JOIN therapyConversationMeta tcm ON tcm.id_llmConversations = lc.id
                WHERE lc.id_users = :uid
                AND lc.deleted = 0
                AND tcm.id_conversationStatus = :active_status_id";
        
        $result = $this->db->query_db_first($sql, array(
            ':uid' => $userId,
            ':active_status_id' => $activeStatusId
        ));

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
        $data = array(
            'id_llmMessages' => $messageId,
            'id_users' => $userId,
            'tag_reason' => $reason,
            'urgency' => $urgency
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
}
?>
