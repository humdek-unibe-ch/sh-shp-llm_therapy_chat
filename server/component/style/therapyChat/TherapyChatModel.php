<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . "/../../../service/TherapyTaggingService.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";

// Include LLM plugin services for danger detection - only if LLM plugin is available
$llmDangerDetectionPath = __DIR__ . "/../../../../sh-shp-llm/server/service/LlmDangerDetectionService.php";
$llmContextServicePath = __DIR__ . "/../../../../sh-shp-llm/server/service/LlmContextService.php";

if (file_exists($llmDangerDetectionPath)) {
    require_once $llmDangerDetectionPath;
}

if (file_exists($llmContextServicePath)) {
    require_once $llmContextServicePath;
}

/**
 * Therapy Chat Model
 *
 * Data model for the subject chat interface.
 * Wraps therapy services and provides CMS field access.
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapyChatModel extends StyleModel
{
    /** @var TherapyTaggingService */
    private $therapyService;

    /** @var LlmDangerDetectionService|null */
    private $dangerDetection;

    /** @var int|null Current user ID */
    private $userId;

    /** @var int|null Group ID from URL params */
    private $groupId;

    /** @var array|null Current conversation */
    private $conversation;

    /**
     * Constructor
     *
     * @param object $services
     * @param int $id
     * @param array $params
     * @param number $id_page
     * @param array $entry_record
     */
    public function __construct($services, $id, $params = array(), $id_page = -1, $entry_record = array())
    {
        parent::__construct($services, $id, $params, $id_page, $entry_record);

        $this->therapyService = new TherapyTaggingService($services);
        $this->userId = $_SESSION['id_user'] ?? null;
        $this->groupId = $params['gid'] ?? null;

        // Initialize danger detection if enabled
        if ($this->isDangerDetectionEnabled()) {
            $this->dangerDetection = new LlmDangerDetectionService($services, $this);
        }
    }

    /* Access Control *********************************************************/

    /**
     * Check if current user has access
     *
     * @return bool
     */
    public function hasAccess()
    {
        if (!$this->userId) {
            return false;
        }

        return $this->therapyService->isSubject($this->userId) || 
               $this->therapyService->isTherapist($this->userId);
    }

    /**
     * Check if user is a subject
     *
     * @return bool
     */
    public function isSubject()
    {
        return $this->therapyService->isSubject($this->userId);
    }

    /* Conversation Access ****************************************************/

    /**
     * Get or create the current conversation
     *
     * @return array|null
     */
    public function getOrCreateConversation()
    {
        if ($this->conversation) {
            return $this->conversation;
        }

        if (!$this->groupId) {
            // Try to get default group from config or first available
            $this->groupId = $this->getDefaultGroupId();
        }

        if (!$this->groupId) {
            return null;
        }

        $mode = $this->get_db_field('therapy_chat_default_mode', THERAPY_MODE_AI_HYBRID);
        $model = $this->get_db_field('llm_model', '');

        $this->conversation = $this->therapyService->getOrCreateTherapyConversation(
            $this->userId,
            $this->groupId,
            $this->getSectionId(),
            $mode,
            $model
        );

        return $this->conversation;
    }

    /**
     * Get conversation by ID
     *
     * @param int $conversationId
     * @return array|null
     */
    public function getConversation($conversationId)
    {
        return $this->therapyService->getTherapyConversation($conversationId);
    }

    /**
     * Get messages for current conversation
     *
     * @param int $limit
     * @param int|null $afterId
     * @return array
     */
    public function getMessages($limit = 100, $afterId = null)
    {
        $conversation = $this->getOrCreateConversation();
        
        if (!$conversation) {
            return array();
        }

        return $this->therapyService->getTherapyMessages(
            $conversation['id'],
            $limit,
            $afterId
        );
    }

    /* Configuration Access ***************************************************/

    /**
     * Get section ID
     *
     * @return int
     */
    public function getSectionId()
    {
        return $this->section_id;
    }


    /**
     * Check if danger detection is enabled
     *
     * @return bool
     */
    public function isDangerDetectionEnabled()
    {
        return (bool)$this->get_db_field('enable_danger_detection', '0');
    }

    /**
     * Get danger keywords
     *
     * @return string
     */
    public function getDangerKeywords()
    {
        return $this->get_db_field('danger_keywords', '');
    }

    /**
     * Get danger notification emails
     *
     * @return array
     */
    public function getDangerNotificationEmails()
    {
        $emails = $this->get_db_field('danger_notification_emails', '');
        if (empty($emails)) {
            return array();
        }

        // Split by semicolons, newlines, or commas
        $parts = preg_split('/[;\n,]+/', $emails);
        return array_filter(array_map('trim', $parts));
    }

    /**
     * Get danger blocked message
     *
     * @return string
     */
    public function getDangerBlockedMessage()
    {
        return $this->get_db_field('danger_blocked_message',
               'I noticed some concerning content. Please consider reaching out to a trusted person or crisis hotline.');
    }

    /**
     * Get tagging enabled status
     *
     * @return bool
     */
    public function isTaggingEnabled()
    {
        return (bool)$this->get_db_field('therapy_chat_enable_tagging', '0');
    }

    /**
     * Get polling interval
     *
     * @return int Seconds
     */
    public function getPollingInterval()
    {
        return (int)$this->get_db_field('therapy_chat_polling_interval', '3');
    }

    /**
     * Get conversation context for AI
     *
     * @return string
     */
    public function getConversationContext()
    {
        return $this->get_db_field('conversation_context', '');
    }

    /**
     * Get LLM model
     *
     * @return string
     */
    public function getLlmModel()
    {
        return $this->get_db_field('llm_model', '');
    }

    /**
     * Get LLM temperature
     *
     * @return float
     */
    public function getLlmTemperature()
    {
        return (float)$this->get_db_field('llm_temperature', '1');
    }

    /**
     * Get LLM max tokens
     *
     * @return int
     */
    public function getLlmMaxTokens()
    {
        return (int)$this->get_db_field('llm_max_tokens', '2048');
    }

    /* Label Getters **********************************************************/

    /**
     * Get all configurable labels
     *
     * @return array
     */
    public function getLabels()
    {
        return array(
            'ai_label' => $this->get_db_field('therapy_ai_label', 'AI Assistant'),
            'therapist_label' => $this->get_db_field('therapy_therapist_label', 'Therapist'),
            'tag_button_label' => $this->get_db_field('therapy_tag_button_label', 'Tag Therapist'),
            'tag_reasons' => $this->getTagReasons(),
            'empty_message' => $this->get_db_field('therapy_empty_message', 'No messages yet. Start the conversation!'),
            'ai_thinking' => $this->get_db_field('therapy_ai_thinking_text', 'AI is thinking...'),
            'mode_ai' => $this->get_db_field('therapy_mode_indicator_ai', 'AI-assisted chat'),
            'mode_human' => $this->get_db_field('therapy_mode_indicator_human', 'Therapist-only mode'),
            'send_button' => $this->get_db_field('submit_button_label', 'Send'),
            'placeholder' => $this->get_db_field('message_placeholder', 'Type your message...'),
            'loading' => $this->get_db_field('loading_text', 'Loading...')
        );
    }

    /**
     * Get tag reasons from JSON configuration
     *
     * @return array
     */
    public function getTagReasons()
    {
        $jsonConfig = $this->get_db_field('therapy_tag_reasons', '');
        return $this->therapyService->parseTagReasons($jsonConfig);
    }

    /* Service Access *********************************************************/

    /**
     * Get therapy service
     *
     * @return TherapyTaggingService
     */
    public function getTherapyService()
    {
        return $this->therapyService;
    }

    /**
     * Get danger detection service
     *
     * @return LlmDangerDetectionService|null
     */
    public function getDangerDetection()
    {
        return $this->dangerDetection;
    }

    /**
     * Get user ID
     *
     * @return int|null
     */
    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * Get group ID
     *
     * @return int|null
     */
    public function getGroupId()
    {
        return $this->groupId;
    }

    /* Private Helpers ********************************************************/

    /**
     * Get default group ID for therapy chat
     *
     * @return int|null
     */
    private function getDefaultGroupId()
    {
        // Try to find first group the user belongs to that has therapy chat access
        $sql = "SELECT ug.id_groups
                FROM users_groups ug
                WHERE ug.id_users = :uid
                LIMIT 1";

        $result = $this->db->query_db_first($sql, array(':uid' => $this->userId));

        return $result ? $result['id_groups'] : null;
    }
}
?>
