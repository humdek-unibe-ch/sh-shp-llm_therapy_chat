<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . "/../../../service/TherapyMessageService.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";

// Include LLM plugin services for danger detection - only if LLM plugin is available
$llmDangerDetectionPath = __DIR__ . "/../../../../../sh-shp-llm/server/service/LlmDangerDetectionService.php";
$llmContextServicePath = __DIR__ . "/../../../../../sh-shp-llm/server/service/LlmContextService.php";

if (file_exists($llmDangerDetectionPath)) {
    require_once $llmDangerDetectionPath;
}

if (file_exists($llmContextServicePath)) {
    require_once $llmContextServicePath;
}

/**
 * Therapy Chat Model
 *
 * Data model for the subject/patient chat interface.
 * Wraps therapy services and provides CMS field access.
 *
 * Access control: patients access their own conversation only.
 * No group ID needed on the model -- conversations are per-user.
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapyChatModel extends StyleModel
{
    /** @var TherapyMessageService */
    private $therapyService;

    /** @var LlmDangerDetectionService|null */
    private $dangerDetection;

    /** @var int|null Current user ID */
    private $userId;

    /** @var array|null Cached current conversation */
    private $conversation;

    /**
     * Constructor
     */
    public function __construct($services, $id, $params = array(), $id_page = -1, $entry_record = array())
    {
        parent::__construct($services, $id, $params, $id_page, $entry_record);

        $this->therapyService = new TherapyMessageService($services);
        $this->userId = $_SESSION['id_user'] ?? null;

        // Initialize danger detection if enabled
        if ($this->isDangerDetectionEnabled()) {
            $this->dangerDetection = new LlmDangerDetectionService($services, $this);
        }
    }

    /* =========================================================================
     * ACCESS CONTROL
     * ========================================================================= */

    /**
     * Check if current user has access to the subject chat
     */
    public function hasAccess()
    {
        if (!$this->userId) {
            return false;
        }
        return $this->therapyService->isSubject($this->userId);
    }

    /**
     * Check if user is a subject
     */
    public function isSubject()
    {
        return $this->therapyService->isSubject($this->userId);
    }

    /* =========================================================================
     * CONVERSATION ACCESS
     * ========================================================================= */

    /**
     * Get or create the current conversation for this patient.
     * No group ID required -- conversation is per-user.
     */
    public function getOrCreateConversation()
    {
        if ($this->conversation) {
            return $this->conversation;
        }

        $mode = $this->get_db_field('therapy_chat_default_mode', THERAPY_MODE_AI_HYBRID);
        $model = $this->get_db_field('llm_model', '');
        $aiEnabled = (bool)$this->get_db_field('therapy_enable_ai', '1');

        $this->conversation = $this->therapyService->getOrCreateTherapyConversation(
            $this->userId,
            $this->getSectionId(),
            $mode,
            $model,
            $aiEnabled
        );

        return $this->conversation;
    }

    /**
     * Get conversation by ID
     */
    public function getConversation($conversationId)
    {
        return $this->therapyService->getTherapyConversation($conversationId);
    }

    /**
     * Get messages for current conversation
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

    /* =========================================================================
     * CONFIGURATION ACCESS
     * ========================================================================= */

    public function getSectionId()
    {
        return $this->section_id;
    }

    public function isDangerDetectionEnabled()
    {
        return (bool)$this->get_db_field('enable_danger_detection', '0');
    }

    public function getDangerKeywords()
    {
        return $this->get_db_field('danger_keywords', '');
    }

    public function getDangerNotificationEmails()
    {
        $emails = $this->get_db_field('danger_notification_emails', '');
        if (empty($emails)) {
            return array();
        }
        $parts = preg_split('/[;\n,]+/', $emails);
        return array_filter(array_map('trim', $parts));
    }

    public function getDangerBlockedMessage()
    {
        return $this->get_db_field('danger_blocked_message',
            'I noticed some concerning content. Please consider reaching out to a trusted person or crisis hotline.');
    }

    public function isTaggingEnabled()
    {
        return (bool)$this->get_db_field('therapy_chat_enable_tagging', '0');
    }

    public function isAIEnabled()
    {
        return (bool)$this->get_db_field('therapy_enable_ai', '1');
    }

    public function getPollingInterval()
    {
        return (int)$this->get_db_field('therapy_chat_polling_interval', '3');
    }

    public function getConversationContext()
    {
        return $this->get_db_field('conversation_context', '');
    }

    public function getLlmModel()
    {
        return $this->get_db_field('llm_model', '');
    }

    public function getLlmTemperature()
    {
        return (float)$this->get_db_field('llm_temperature', '1');
    }

    public function getLlmMaxTokens()
    {
        return (int)$this->get_db_field('llm_max_tokens', '2048');
    }

    public function isSpeechToTextEnabled()
    {
        $enabled = (bool)$this->get_db_field('enable_speech_to_text', '0');
        $model = $this->get_db_field('speech_to_text_model', '');
        return $enabled && !empty($model);
    }

    public function getSpeechToTextModel()
    {
        return $this->get_db_field('speech_to_text_model', '');
    }

    public function getSpeechToTextLanguage()
    {
        return $this->get_db_field('speech_to_text_language', 'auto');
    }

    /* =========================================================================
     * LABEL GETTERS
     * ========================================================================= */

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
            'loading' => $this->get_db_field('loading_text', 'Loading...'),
            'chat_help_text' => $this->get_db_field('therapy_chat_help_text', 'Use @therapist to request your therapist, or #topic to tag a predefined topic.')
        );
    }

    public function getTagReasons()
    {
        return $this->get_db_field('therapy_tag_reasons', '');
    }

    /* =========================================================================
     * SERVICE ACCESS
     * ========================================================================= */

    public function getTherapyService()
    {
        return $this->therapyService;
    }

    public function getDangerDetection()
    {
        return $this->dangerDetection;
    }

    public function getUserId()
    {
        return $this->userId;
    }

    /* =========================================================================
     * REACT CONFIGURATION
     * ========================================================================= */

    /**
     * Get React configuration array
     */
    public function getReactConfig()
    {
        $baseUrl = $this->getBaseUrl();

        return array(
            'baseUrl' => $baseUrl,
            'userId' => $this->getUserId(),
            'sectionId' => $this->getSectionId(),
            'conversationId' => null,

            // State defaults
            'conversationMode' => THERAPY_MODE_AI_HYBRID,
            'aiEnabled' => $this->isAIEnabled(),
            'riskLevel' => THERAPY_RISK_LOW,

            // Feature flags
            'isSubject' => $this->isSubject(),
            'taggingEnabled' => $this->isTaggingEnabled(),
            'dangerDetectionEnabled' => $this->isDangerDetectionEnabled(),

            // Polling
            'pollingInterval' => $this->getPollingInterval() * 1000,

            // Labels
            'labels' => $this->getLabels(),

            // Tag reasons
            'tagReasons' => $this->getTagReasonsForReact(),

            // LLM
            'configuredModel' => $this->getLlmModel(),

            // Speech-to-Text
            'speechToTextEnabled' => $this->isSpeechToTextEnabled(),
            'speechToTextModel' => $this->getSpeechToTextModel(),
            'speechToTextLanguage' => $this->getSpeechToTextLanguage(),
        );
    }

    private function getTagReasonsForReact()
    {
        $labels = $this->getLabels();
        $reasons = $labels['tag_reasons'] ?? array();

        if (empty($reasons)) {
            return array(
                array('code' => 'overwhelmed', 'label' => 'I am feeling overwhelmed', 'urgency' => THERAPY_URGENCY_NORMAL),
                array('code' => 'need_talk', 'label' => 'I need to talk soon', 'urgency' => THERAPY_URGENCY_URGENT),
                array('code' => 'urgent', 'label' => 'This feels urgent', 'urgency' => THERAPY_URGENCY_URGENT),
                array('code' => 'emergency', 'label' => 'Emergency - please respond immediately', 'urgency' => THERAPY_URGENCY_EMERGENCY)
            );
        }

        return array_map(function ($r) {
            return array(
                'code' => $r['key'] ?? $r['code'] ?? '',
                'label' => $r['label'] ?? '',
                'urgency' => $r['urgency'] ?? THERAPY_URGENCY_NORMAL
            );
        }, $reasons);
    }

    private function getBaseUrl()
    {
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        if (strpos($scriptPath, '/index.php') !== false) {
            return $scriptPath;
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $pathParts = explode('/', trim($requestUri, '/'));

        $basePath = '';
        foreach ($pathParts as $part) {
            if ($part === 'therapy-chat' || $part === 'therapist-dashboard') {
                break;
            }
            $basePath .= '/' . $part;
        }

        return $basePath . '/index.php';
    }
}
?>
