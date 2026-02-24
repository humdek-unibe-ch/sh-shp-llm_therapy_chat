<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . "/../../../service/TherapyMessageService.php";
require_once __DIR__ . "/../../../service/TherapyEmailHelper.php";
require_once __DIR__ . "/../../../service/TherapyPushHelper.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";

// Include LLM plugin services - only if LLM plugin is available
$llmDangerDetectionPath = __DIR__ . "/../../../../../sh-shp-llm/server/service/LlmDangerDetectionService.php";
$llmResponseServicePath = __DIR__ . "/../../../../../sh-shp-llm/server/service/LlmResponseService.php";

if (file_exists($llmDangerDetectionPath)) {
    require_once $llmDangerDetectionPath;
}

if (file_exists($llmResponseServicePath)) {
    require_once $llmResponseServicePath;
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

        // Initialize danger detection service (used only for conversation blocking,
        // NOT for keyword scanning — safety detection is context-based via LLM).
        if ($this->isDangerDetectionEnabled()) {
            $this->dangerDetection = new LlmDangerDetectionService($services, $this);
        }
    }

    /* =========================================================================
     * ACCESS CONTROL
     * ========================================================================= */

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

        // Auto-start context: if enabled, pass the configured message to be
        // inserted as the first message when the conversation is created.
        // This is a plain text insert, no LLM calls.
        $autoStartContext = null;
        if ((bool)$this->get_db_field('therapy_auto_start', '0')) {
            $autoStartContext = $this->get_db_field('therapy_auto_start_context', '');
        }

        $this->conversation = $this->therapyService->getOrCreateTherapyConversation(
            $this->userId,
            $this->getSectionId(),
            $mode,
            $model,
            $aiEnabled,
            $autoStartContext
        );

        return $this->conversation;
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
            $afterId,
            $this->getMessageLabelOverrides()
        );
    }

    /**
     * Message label overrides sourced from subject style DB fields.
     */
    public function getMessageLabelOverrides()
    {
        return array(
            'ai' => $this->get_db_field('therapy_ai_label', 'AI Assistant'),
            'therapist' => $this->get_db_field('therapy_therapist_label', 'Therapist'),
            'subject' => 'Patient',
            'system' => 'System',
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


    public function getDangerBlockedMessage()
    {
        return $this->get_db_field('danger_blocked_message',
            'I noticed some concerning content. Please consider reaching out to a trusted person or crisis hotline.');
    }

    /**
     * Get configured danger notification email addresses.
     * These are additional emails (e.g., clinical supervisors) that receive
     * urgent notifications when danger is detected.
     *
     * Returns an array to match the interface expected by LlmDangerDetectionService.
     *
     * @return array Array of validated email addresses
     */
    public function getDangerNotificationEmails()
    {
        $raw = $this->get_db_field('danger_notification_emails', '');
        if (empty($raw)) {
            return array();
        }
        // Support comma, semicolon, and newline separators
        $emails = preg_split('/[,;\n]+/', $raw);
        return array_values(array_filter(array_map('trim', $emails)));
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
     * LABELS
     * ========================================================================= */

    /**
     * Get UI labels for the React frontend.
     * Note: tag_reasons is NOT included here - it is a data structure, not a label.
     * Use getTagReasonsForReact() for tag reasons data.
     */
    public function getLabels()
    {
        return array(
            'ai_label' => $this->get_db_field('therapy_ai_label', 'AI Assistant'),
            'therapist_label' => $this->get_db_field('therapy_therapist_label', 'Therapist'),
            'tag_button_label' => $this->get_db_field('therapy_tag_button_label', 'Tag Therapist'),
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

    /* =========================================================================
     * TAG REASONS
     * ========================================================================= */

    /**
     * Parse the raw tag reasons JSON from the database into a PHP array.
     * Single source of truth: used by getTagReasonsForReact() and tagTherapist().
     *
     * @return array Array of raw reason items with 'key', 'label', 'urgency'
     */
    public function getTagReasons()
    {
        $raw = $this->get_db_field('therapy_tag_reasons', '');
        if (empty($raw)) {
            return array();
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : array();
        }
        return is_array($raw) ? $raw : array();
    }

    /* =========================================================================
     * FORMATTED DATA FOR REACT (API responses)
     * ========================================================================= */

    /**
     * Format therapist list for the @mention autocomplete.
     * Called by the controller for the get_therapists endpoint.
     *
     * @param int $patientId
     * @return array [{id, display, name, email}, ...]
     */
    public function getFormattedTherapists($patientId)
    {
        $therapists = $this->therapyService->getTherapistsForPatient($patientId);
        $formatted = array();
        foreach ($therapists as $t) {
            $formatted[] = array(
                'id' => (int)$t['id'],
                'display' => $t['name'],
                'name' => $t['name'],
                'email' => $t['email'] ?? null
            );
        }
        return $formatted;
    }

    /* =========================================================================
     * SERVICE ACCESS
     * ========================================================================= */

    public function getTherapyService()
    {
        return $this->therapyService;
    }

    public function getUserId()
    {
        return $this->userId;
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
        $conversation = $this->resolveConversation($userId, $conversationId);
        if (!$conversation) {
            return array('error' => 'Could not create conversation');
        }
        $conversationId = $conversation['id'];

        // NOTE: When a conversation is blocked (danger detection), the patient
        // can still send messages. These go to therapists only (manual mode)
        // because ai_enabled is set to false by handlePostLlmSafetyDetection().
        // The isConversationAIActive() check below ensures no AI response is
        // generated, and notifyTherapistsNewMessage() delivers the message to
        // the assigned therapists.

        // Send user message (normal flow)
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

        // Detect @mentions once (used for notifications and AI decision)
        $mentionResult = $this->therapyService->detectMentionedTherapists($message, $userId);
        $isTag = $mentionResult['isTagAll'] || $mentionResult['isTagSpecific'];

        // Refresh conversation state for AI check
        $conversation = $this->therapyService->getTherapyConversation($conversationId);
        $aiActive = $this->isConversationAIActive($conversation);

        // Notify therapists when tagged or when AI is off
        if ($isTag || !$aiActive) {
            $this->notifyTherapistsNewMessage($conversationId, $userId, $message, $isTag);
            $this->notifyTherapistsPush($conversationId, $userId, $message, $isTag);
        }

        // Process AI response only if active and no tag
        if ($aiActive && !$isTag) {
            $aiResponse = $this->processAIResponse($conversationId, $conversation);
            if ($aiResponse && !isset($aiResponse['error'])) {
                $messageLabels = $this->getMessageLabelOverrides();
                $response['ai_message'] = array(
                    'id' => $aiResponse['message_id'],
                    'role' => 'assistant',
                    'content' => $aiResponse['content'],
                    'sender_type' => 'ai',
                    'label' => $messageLabels['ai'] ?? 'AI Assistant',
                    'timestamp' => date('c')
                );
            }
        }

        return $response;
    }

    /**
     * Resolve or create the conversation for a patient message.
     *
     * @param int $userId
     * @param int|null $conversationId
     * @return array|null
     */
    private function resolveConversation($userId, $conversationId)
    {
        $conversation = null;
        if ($conversationId) {
            $conversation = $this->therapyService->getTherapyConversation($conversationId);
        }
        if (!$conversation) {
            $conversation = $this->getOrCreateConversation();
        }
        return $conversation;
    }

    /**
     * Check if a conversation has AI actively processing messages.
     *
     * @param array|null $conversation
     * @return bool
     */
    private function isConversationAIActive($conversation)
    {
        return $conversation
            && $conversation['ai_enabled']
            && $conversation['mode'] === THERAPY_MODE_AI_HYBRID;
    }


    /**
     * Process AI response for a conversation.
     *
     * Injects the LLM structured response schema + safety instructions into the
     * context (matching the parent sh-shp-llm plugin). After the LLM responds,
     * the structured JSON is parsed and the safety assessment is evaluated.
     * Critical/emergency danger levels trigger conversation blocking + notification.
     *
     * @param int $conversationId therapyConversationMeta.id
     * @param array $conversation Therapy conversation record
     * @return array|null
     */
    public function processAIResponse($conversationId, $conversation)
    {
        $systemContext = $this->getConversationContext();
        $contextMessages = $this->therapyService->buildAIContext($conversationId, $systemContext, 50);

        // Build danger config for the response service (same structure as parent plugin)
        $dangerConfig = $this->buildDangerConfig();

        // Inject the unified JSON response schema (with safety instructions).
        // Uses the centralized helper in TherapyMessageService which loads
        // LlmResponseService from the parent LLM plugin.
        $contextMessages = $this->therapyService->injectResponseSchema($contextMessages, $dangerConfig);
        $responseService = $this->therapyService->getResponseService();

        // Fallback: if LlmResponseService is not available, inject a minimal
        // safety instruction so the LLM still returns a structured response.
        if (!$responseService && $this->isDangerDetectionEnabled()) {
            $contextMessages[] = array(
                'role' => 'system',
                'content' => '[SAFETY] You are a mental health assistant. Assess ALL user messages for '
                    . 'safety concerns (suicidal ideation, self-harm, harm to others, crisis situations). '
                    . 'If you detect danger, include a safety warning in your response and recommend '
                    . 'professional help and crisis resources. Do NOT engage with dangerous content.'
            );
        }

        // Reinforce JSON schema compliance at end of context.
        // After long conversations (especially after pause/resume), models may
        // lose track of the schema instruction at the top of context. Adding a
        // brief reminder just before the API call significantly improves compliance.
        $contextMessages[] = array(
            'role' => 'system',
            'content' => 'IMPORTANT: You MUST respond with valid JSON matching the required schema. '
                . 'Your response must be a JSON object with "type", "safety", "content", and "metadata" fields. '
                . 'Do NOT include any text outside the JSON object.'
        );

        $result = $this->therapyService->processAIResponse(
            $conversationId,
            $contextMessages,
            $this->getLlmModel() ?: $conversation['model'],
            $this->getLlmTemperature(),
            $this->getLlmMaxTokens()
        );

        // Post-LLM safety detection: parse the structured response
        if ($responseService && $result && !isset($result['error'])) {
            $this->handlePostLlmSafetyDetection(
                $result, $conversationId, $conversation, $responseService
            );
        }

        return $result;
    }

    /**
     * Build safety config for LlmResponseService.
     *
     * Provides topic hints to the LLM so it knows which safety areas to
     * monitor. The LLM performs contextual assessment — no keyword matching
     * is done server-side. Returns the structure expected by
     * LlmResponseService::buildResponseContext().
     *
     * @return array ['enabled' => bool, 'keywords' => string[]]
     */
    private function buildDangerConfig()
    {
        if (!$this->isDangerDetectionEnabled()) {
            return array('enabled' => false, 'keywords' => array());
        }

        $topicsStr = $this->getDangerKeywords();
        if (empty($topicsStr)) {
            return array('enabled' => true, 'keywords' => array());
        }

        $topics = array_map('trim', preg_split('/[,;\n]+/', $topicsStr));
        $topics = array_filter($topics);
        $topics = array_unique($topics);

        return array(
            'enabled' => true,
            'keywords' => array_values($topics)
        );
    }

    /**
     * Evaluate the LLM's structured safety assessment after receiving a response.
     *
     * If the LLM returns danger_level = critical or emergency, block the
     * conversation and send urgent notifications — same logic as the parent
     * plugin's LlmChatController::handleSafetyDetection().
     *
     * @param array $result The processAIResponse result (has 'content')
     * @param int $conversationId therapyConversationMeta.id
     * @param array $conversation Therapy conversation record
     * @param LlmResponseService $responseService
     */
    private function handlePostLlmSafetyDetection($result, $conversationId, $conversation, $responseService)
    {
        // Use raw_content (the original JSON from the LLM) for safety parsing,
        // since 'content' has already been converted to display text.
        $content = $result['raw_content'] ?? $result['content'] ?? '';
        if (empty($content)) return;

        // The LLM should return structured JSON; try to parse it
        $parsed = $this->parseStructuredResponse($content);
        if (!$parsed) return;

        $safety = $responseService->assessSafety($parsed);

        // If safe, nothing to do
        if ($safety['is_safe'] && $safety['danger_level'] === null) {
            return;
        }

        $detectedConcerns = $safety['detected_concerns'] ?? array();

        // Block and notify for critical/emergency levels
        if (in_array($safety['danger_level'], array('critical', 'emergency'))) {
            $llmConversationId = $conversation['id_llmConversations'];
            $reason = strtoupper($safety['danger_level'])
                . ': LLM safety assessment detected danger: '
                . implode(', ', $detectedConcerns);

            // Block the underlying llmConversations record
            if ($this->dangerDetection) {
                $this->dangerDetection->blockConversation($llmConversationId, $detectedConcerns, $reason);
            } else {
                $this->therapyService->blockConversation($conversationId, $reason);
            }

            // Create danger alert + escalate risk + disable AI + send urgent
            // email to assigned therapists AND extra notification addresses.
            // NOTE: Do NOT also call $this->dangerDetection->sendNotifications()
            // because createDangerAlert already sends to all recipients. Calling
            // both would produce duplicate emails.
            //
            // Pass the human-readable safety message (not raw JSON) for the
            // alert text. Fall back to the display content from the result.
            $alertMessage = $safety['safety_message']
                ?? $result['content']
                ?? implode(', ', $detectedConcerns);
            $extraEmails = implode(',', $this->getDangerNotificationEmails());
            $this->therapyService->createDangerAlert(
                $conversationId, $detectedConcerns, $alertMessage, $extraEmails
            );
            $this->therapyService->setAIEnabled($conversationId, false);

            // Log to transactions via the service container (logTransaction is
            // protected in the LlmLoggingTrait, so we use the services directly)
            try {
                $transaction = $this->get_services()->get_transaction();
                $transaction->add_transaction(
                    transactionTypes_update,
                    transactionBy_by_system,
                    $conversation['id_users'] ?? 0,
                    'llmConversations',
                    $llmConversationId,
                    false,
                    'Post-LLM safety detection: ' . $reason
                );
            } catch (Exception $e) {
                error_log('TherapyChat: Failed to log post-LLM safety transaction: ' . $e->getMessage());
            }
        }
    }

    /**
     * Try to parse a structured JSON response from the LLM.
     *
     * The parent plugin requires the LLM to return JSON with at minimum:
     * { "type": "response", "safety": {...}, "content": {...} }
     *
     * If the LLM returns plain text (not JSON), returns null.
     *
     * @param string $content The raw LLM response content
     * @return array|null Parsed JSON data, or null if not structured
     */
    private function parseStructuredResponse($content)
    {
        $decoded = TherapyMessageService::parseLlmJson($content);

        // Must have at least a safety field to be a structured response
        if ($decoded === null || !isset($decoded['safety'])) {
            return null;
        }

        return $decoded;
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
     * Delegates to TherapyMessageService to avoid code duplication.
     *
     * @param string $tempPath
     * @return array {success, text} or {error}
     */
    public function transcribeSpeech($tempPath)
    {
        return $this->therapyService->transcribeSpeech(
            $tempPath,
            $this->getSpeechToTextModel(),
            $this->getSpeechToTextLanguage()
        );
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

            TherapyEmailHelper::scheduleEmail(
                $db,
                $jobScheduler,
                $therapist['email'],
                $subject,
                $body,
                $fromEmail,
                $fromName,
                ($isTag ? "Therapy Chat: tag" : "Therapy Chat: message") . " notification to therapist #" . $therapist['id'],
                array($therapist['id'])
            );
        }
    }

    /**
     * Send push notification to therapist(s) when patient sends a message.
     *
     * @param int $conversationId
     * @param int $patientId
     * @param string $messageContent
     * @param bool $isTag
     */
    public function notifyTherapistsPush($conversationId, $patientId, $messageContent, $isTag = false)
    {
        $enabled = (bool)$this->get_db_field('enable_therapist_push_notification', '1');
        if (!$enabled) return;

        $conversation = $this->therapyService->getTherapyConversation($conversationId);
        if (!$conversation) return;

        $services = $this->get_services();
        $db = $services->get_db();
        $jobScheduler = $services->get_job_scheduler();

        $patient = $db->select_by_uid('users', $patientId);
        $patientName = $patient ? $patient['name'] : 'Patient';

        $therapists = $this->therapyService->getTherapistsForPatient($patientId);
        if (empty($therapists)) return;

        $titleTemplate = $isTag
            ? $this->get_db_field('therapist_tag_push_notification_title', '@therapist tag from {{patient_name}}')
            : $this->get_db_field('therapist_push_notification_title', 'New message from {{patient_name}}');

        $bodyTemplate = $isTag
            ? $this->get_db_field('therapist_tag_push_notification_body', '{{patient_name}} has tagged you in therapy chat: {{message_preview}}')
            : $this->get_db_field('therapist_push_notification_body', 'You have a new therapy chat message from {{patient_name}}. Tap to open.');

        $preview = mb_substr(strip_tags($messageContent), 0, 100);
        if (mb_strlen($messageContent) > 100) $preview .= '...';

        $therapistPageId = null;
        try {
            $therapistPageId = $this->get_db_field('therapy_chat_therapist_page', null);
        } catch (Exception $e) {}

        $chatUrl = '';
        if ($therapistPageId) {
            $pageInfo = $db->select_by_uid('pages', $therapistPageId);
            if ($pageInfo && isset($pageInfo['keyword'])) {
                $chatUrl = '/' . $pageInfo['keyword'];
            }
        }

        $recipientIds = array();
        foreach ($therapists as $therapist) {
            $recipientIds[] = (int)$therapist['id'];
        }

        $title = str_replace('{{patient_name}}', $patientName, $titleTemplate);
        $body = str_replace(
            array('{{patient_name}}', '{{message_preview}}'),
            array($patientName, $preview),
            $bodyTemplate
        );

        TherapyPushHelper::schedulePush(
            $db,
            $jobScheduler,
            $title,
            $body,
            $chatUrl,
            $recipientIds,
            ($isTag ? "Therapy Chat: tag" : "Therapy Chat: message") . " push to therapists"
        );
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

    /**
     * Format tag reasons for the React frontend config.
     *
     * Returns a normalized array of {code, label, urgency}.
     * Falls back to sensible defaults when no reasons are configured.
     *
     * @return array
     */
    public function getTagReasonsForReact()
    {
        $reasons = $this->getTagReasons();

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
        // Use the router to get the correct page URL based on the current page keyword.
        // This ensures the React app sends API requests to the correct SelfHelp page,
        // not to index.php which would result in a "page not found" response.
        try {
            $router = $this->get_services()->get_router();
            $keyword = $router->current_keyword ?? null;
            if ($keyword) {
                $url = $router->get_link_url($keyword);
                if (!empty($url)) {
                    return $url;
                }
            }
        } catch (Exception $e) {
            // Fall through to fallback
        }

        // Fallback: use the current REQUEST_URI (without query string)
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $pos = strpos($requestUri, '?');
        return $pos !== false ? substr($requestUri, 0, $pos) : $requestUri;
    }
}
?>
