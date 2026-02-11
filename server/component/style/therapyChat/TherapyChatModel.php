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
     * FLOATING CHAT CONFIGURATION
     * ========================================================================= */

    /**
     * Whether the chat should render as a floating modal.
     * When enabled, the server-rendered floating icon opens an inline modal
     * instead of navigating to the page. Icon/position/label are in the
     * main plugin config (therapy_chat_floating_icon, etc.).
     */
    public function isFloatingChatEnabled()
    {
        return (bool)$this->get_db_field('enable_floating_chat', '0');
    }

    /* =========================================================================
     * BUSINESS LOGIC — message sending
     * ========================================================================= */

    /**
     * Send a patient message and optionally process AI response.
     * All logic that was previously in the controller lives here.
     *
     * @param int $userId Patient ID
     * @param string $message Message content
     * @param int|null $conversationId
     * @return array Response for frontend
     */
    public function sendPatientMessage($userId, $message, $conversationId = null)
    {
        // Danger detection
        if ($this->dangerDetection && $this->dangerDetection->isEnabled()) {
            $dangerResult = $this->dangerDetection->checkMessage($message, $userId, $conversationId);
            if (!$dangerResult['safe']) {
                $this->therapyService->createDangerAlert(
                    $conversationId,
                    $dangerResult['detected_keywords'],
                    $message
                );
                return array(
                    'blocked' => true,
                    'type' => 'danger_detected',
                    'message' => $this->getDangerBlockedMessage(),
                    'detected_keywords' => $dangerResult['detected_keywords']
                );
            }
        }

        // Get or create conversation
        $conversation = null;
        if ($conversationId) {
            $conversation = $this->therapyService->getTherapyConversation($conversationId);
        }
        if (!$conversation) {
            $conversation = $this->getOrCreateConversation();
            if (!$conversation) {
                return array('error' => 'Could not create conversation');
            }
        }
        $conversationId = $conversation['id'];

        // Send user message
        $result = $this->therapyService->sendTherapyMessage(
            $conversationId, $userId, $message, TherapyMessageService::SENDER_SUBJECT
        );

        if (isset($result['error'])) {
            return $result;
        }

        $response = array(
            'success' => true,
            'message_id' => $result['message_id'],
            'conversation_id' => $conversationId
        );

        // Determine if therapist should be emailed:
        //  - when the patient tags @therapist
        //  - when AI is disabled (all messages go to therapist)
        $isTag = (bool)preg_match('/@(?:therapist|Therapist)\b/', $message);
        $conversation = $this->therapyService->getTherapyConversation($conversationId);
        $aiActive = $conversation && $conversation['ai_enabled']
            && $conversation['mode'] === THERAPY_MODE_AI_HYBRID
            && ($conversation['status'] ?? '') !== THERAPY_STATUS_PAUSED;

        if ($isTag || !$aiActive) {
            $this->notifyTherapistsNewMessage($conversationId, $userId, $message, $isTag);
        }

        // Process AI response if enabled and conversation is not paused
        if ($aiActive) {
            $aiResponse = $this->processAIResponse($conversationId, $conversation);
            if ($aiResponse && !isset($aiResponse['error'])) {
                $response['ai_message'] = array(
                    'id' => $aiResponse['message_id'],
                    'role' => 'assistant',
                    'content' => $aiResponse['content'],
                    'sender_type' => 'ai',
                    'timestamp' => date('c')
                );
            }
        }

        return $response;
    }

    /**
     * Process AI response for a conversation.
     *
     * @param int $conversationId
     * @param array $conversation
     * @return array|null
     */
    public function processAIResponse($conversationId, $conversation)
    {
        $systemContext = $this->getConversationContext();
        $contextMessages = $this->therapyService->buildAIContext($conversationId, $systemContext, 50);

        // Add danger detection context if enabled
        if ($this->dangerDetection && $this->dangerDetection->isEnabled()) {
            $safetyContext = $this->dangerDetection->getCriticalSafetyContext();
            if ($safetyContext) {
                array_splice($contextMessages, 2, 0, [
                    array('role' => 'system', 'content' => $safetyContext)
                ]);
            }
        }

        return $this->therapyService->processAIResponse(
            $conversationId,
            $contextMessages,
            $this->getLlmModel() ?: $conversation['model'],
            $this->getLlmTemperature(),
            $this->getLlmMaxTokens()
        );
    }

    /**
     * Handle therapist tagging from patient.
     *
     * @param int $userId Patient ID
     * @param int $conversationId
     * @param string|null $reason
     * @param string $urgency
     * @return array
     */
    public function tagTherapist($userId, $conversationId, $reason = null, $urgency = THERAPY_URGENCY_NORMAL)
    {
        $conversation = $this->therapyService->getTherapyConversation($conversationId);
        if (!$conversation) {
            return array('error' => 'Conversation not found');
        }

        // Build tag message
        $tagMessage = "@therapist I would like to speak with my therapist";
        if ($reason) {
            $tagReasons = $this->getTagReasons();
            if (is_array($tagReasons)) {
                foreach ($tagReasons as $r) {
                    if (isset($r['key']) && $r['key'] === $reason) {
                        $tagMessage .= " #" . $r['key'] . ": " . $r['label'];
                        break;
                    }
                }
            }
        }

        // Send the tag message
        $msgResult = $this->therapyService->sendTherapyMessage(
            $conversationId, $userId, $tagMessage, TherapyMessageService::SENDER_SUBJECT
        );

        if (isset($msgResult['error'])) {
            return $msgResult;
        }

        // Create tag alert
        $alertId = $this->therapyService->createTagAlert(
            $conversation['id_llmConversations'],
            null,
            $reason,
            $urgency,
            $msgResult['message_id']
        );

        // Send email notification to therapists (tag type)
        $this->notifyTherapistsNewMessage($conversationId, $userId, $tagMessage, true);

        return array(
            'success' => true,
            'message_id' => $msgResult['message_id'],
            'alert_id' => $alertId
        );
    }

    /**
     * Transcribe audio to text.
     *
     * @param string $tempPath
     * @return array
     */
    public function transcribeSpeech($tempPath)
    {
        $llmSpeechServicePath = __DIR__ . "/../../../../../sh-shp-llm/server/service/LlmSpeechToTextService.php";
        if (!file_exists($llmSpeechServicePath)) {
            return array('error' => 'Speech-to-text service not available.');
        }

        require_once $llmSpeechServicePath;
        $speechService = new LlmSpeechToTextService($this->get_services(), $this);
        $result = $speechService->transcribeAudio(
            $tempPath,
            $this->getSpeechToTextModel(),
            $this->getSpeechToTextLanguage() !== 'auto' ? $this->getSpeechToTextLanguage() : null
        );

        if (isset($result['error'])) {
            return array('error' => $result['error']);
        }
        return array('success' => true, 'text' => $result['text'] ?? '');
    }

    /* =========================================================================
     * EMAIL NOTIFICATIONS (business logic)
     * ========================================================================= */

    /**
     * Send email notification to therapist(s) when patient sends a message.
     *
     * @param int $conversationId
     * @param int $patientId
     * @param string $messageContent
     * @param bool $isTag
     */
    public function notifyTherapistsNewMessage($conversationId, $patientId, $messageContent, $isTag = false)
    {
        $enabled = (bool)$this->get_db_field('enable_therapist_email_notification', '1');
        if (!$enabled) return;

        $conversation = $this->therapyService->getTherapyConversation($conversationId);
        if (!$conversation) return;

        $services = $this->get_services();
        $db = $services->get_db();
        $jobScheduler = $services->get_job_scheduler();

        // Get patient info
        $patient = $db->select_by_uid('users', $patientId);
        $patientName = $patient ? $patient['name'] : 'Patient';

        // Get all assigned therapists
        $therapists = $this->therapyService->getTherapistsForPatient($patientId);
        if (empty($therapists)) return;

        $subjectTemplate = $isTag
            ? $this->get_db_field('therapist_tag_email_subject', '[Therapy Chat] @therapist tag from {{patient_name}}')
            : $this->get_db_field('therapist_notification_email_subject', '[Therapy Chat] New message from {{patient_name}}');

        $bodyTemplate = $isTag
            ? $this->get_db_field('therapist_tag_email_body',
                '<p>Hello,</p><p><strong>{{patient_name}}</strong> has tagged you (@therapist) in their therapy chat.</p><p><em>Message preview:</em> {{message_preview}}</p><p>Please log in to the Therapist Dashboard to respond.</p>')
            : $this->get_db_field('therapist_notification_email_body',
                '<p>Hello,</p><p>You have received a new message from <strong>{{patient_name}}</strong> in therapy chat.</p><p>Please log in to the Therapist Dashboard to review.</p>');

        $fromEmail = $this->get_db_field('notification_from_email', 'noreply@selfhelp.local');
        $fromName = $this->get_db_field('notification_from_name', 'Therapy Chat');

        $preview = mb_substr(strip_tags($messageContent), 0, 200);
        if (mb_strlen($messageContent) > 200) $preview .= '...';

        foreach ($therapists as $therapist) {
            if (empty($therapist['email'])) continue;

            $subject = str_replace('{{patient_name}}', htmlspecialchars($patientName), $subjectTemplate);
            $body = str_replace(
                array('{{patient_name}}', '{{message_preview}}', '@user_name'),
                array(htmlspecialchars($patientName), htmlspecialchars($preview), htmlspecialchars($therapist['name'] ?? '')),
                $bodyTemplate
            );

            $mail = array(
                "id_jobTypes" => $db->get_lookup_id_by_value(jobTypes, jobTypes_email),
                "id_jobStatus" => $db->get_lookup_id_by_value(scheduledJobsStatus, scheduledJobsStatus_queued),
                "date_to_be_executed" => date('Y-m-d H:i:s'),
                "from_email" => $fromEmail,
                "from_name" => $fromName,
                "reply_to" => $fromEmail,
                "recipient_emails" => $therapist['email'],
                "subject" => $subject,
                "body" => $body,
                "description" => ($isTag ? "Therapy Chat: tag" : "Therapy Chat: message") . " notification to therapist #" . $therapist['id'],
                "is_html" => 1,
                "id_users" => array($therapist['id']),
                "attachments" => array()
            );

            try {
                $jobScheduler->schedule_job($mail, transactionBy_by_system);
            } catch (Exception $e) {
                error_log("TherapyChat: Failed to schedule therapist notification: " . $e->getMessage());
            }
        }
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

            // Floating mode flag (set by server when rendered inside modal)
            'isFloatingMode' => false,

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
