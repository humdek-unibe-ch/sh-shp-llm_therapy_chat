<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . "/../../../service/TherapyMessageService.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";

/**
 * Therapist Dashboard Model
 *
 * Contains ALL business logic for the therapist dashboard.
 * The controller delegates to methods here; no logic lives in the controller.
 *
 * Responsibilities:
 * - Data access (conversations, messages, notes, alerts, stats)
 * - AI draft generation (builds context, calls LLM, saves to llmMessages + draft table)
 * - Conversation summarization (builds context, calls LLM, saves to llmMessages)
 * - Message send/edit/delete
 * - Risk/status/AI toggle management
 * - Email notifications on new messages
 * - React configuration
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapistDashboardModel extends StyleModel
{
    /** @var TherapyMessageService */
    private $messageService;

    /** @var int|null */
    private $userId;

    /** @var int|null Group filter from URL */
    private $selectedGroupId;

    /** @var int|null Subject filter from URL */
    private $selectedSubjectId;

    public function __construct($services, $id, $params = array(), $id_page = -1, $entry_record = array())
    {
        parent::__construct($services, $id, $params, $id_page, $entry_record);

        $this->messageService = new TherapyMessageService($services);
        $this->userId = $_SESSION['id_user'] ?? null;
        $this->selectedGroupId = $params['gid'] ?? null;
        $this->selectedSubjectId = $params['uid'] ?? null;
    }

    /* =========================================================================
     * ACCESS CONTROL
     * ========================================================================= */

    public function hasAccess()
    {
        if (!$this->userId) {
            return false;
        }
        return $this->messageService->isTherapist($this->userId);
    }

    /**
     * Check if therapist can access a specific conversation
     *
     * @param int $therapistId
     * @param int $conversationId
     * @return bool
     */
    public function canAccessConversation($therapistId, $conversationId)
    {
        return $this->messageService->canAccessTherapyConversation($therapistId, $conversationId);
    }

    /* =========================================================================
     * DATA ACCESS
     * ========================================================================= */

    /**
     * Get all conversations for this therapist
     */
    public function getConversations($filters = array())
    {
        if ($this->selectedGroupId) {
            $filters['group_id'] = $this->selectedGroupId;
        }

        return $this->messageService->getTherapyConversationsByTherapist(
            $this->userId,
            $filters,
            100,
            0
        );
    }

    /**
     * Get messages for a conversation
     */
    public function getMessages($conversationId, $limit = 100, $afterId = null)
    {
        return $this->messageService->getTherapyMessages($conversationId, $limit, $afterId);
    }

    /**
     * Get alerts for this therapist
     */
    public function getAlerts($filters = array())
    {
        return $this->messageService->getAlertsForTherapist($this->userId, $filters);
    }

    /**
     * Get unread alert count
     */
    public function getUnreadAlertCount()
    {
        return $this->messageService->getUnreadAlertCount($this->userId);
    }

    /**
     * Get therapist statistics
     */
    public function getStats()
    {
        return $this->messageService->getTherapistStats($this->userId);
    }

    /**
     * Get notes for a conversation
     */
    public function getNotes($conversationId)
    {
        return $this->messageService->getNotesForConversation($conversationId);
    }

    /**
     * Get groups this therapist is assigned to
     */
    public function getAssignedGroups()
    {
        return $this->messageService->getTherapistAssignedGroups($this->userId);
    }

    /* =========================================================================
     * MESSAGE OPERATIONS (business logic)
     * ========================================================================= */

    /**
     * Send a message as therapist
     *
     * @param int $conversationId
     * @param int $therapistId
     * @param string $message
     * @return array {success, message_id} or {error}
     */
    public function sendMessage($conversationId, $therapistId, $message)
    {
        $result = $this->messageService->sendTherapyMessage(
            $conversationId, $therapistId, $message, TherapyMessageService::SENDER_THERAPIST
        );

        if (isset($result['success'])) {
            $this->messageService->updateLastSeen($conversationId, 'therapist');

            // Schedule email notification to the patient
            $this->notifyPatientNewMessage($conversationId, $therapistId, $message);
        }

        return $result;
    }

    /**
     * Edit a message
     *
     * @param int $messageId
     * @param int $editorId
     * @param string $newContent
     * @return bool
     */
    public function editMessage($messageId, $editorId, $newContent)
    {
        return $this->messageService->editMessage($messageId, $editorId, $newContent);
    }

    /**
     * Soft-delete a message
     *
     * @param int $messageId
     * @param int $userId
     * @return bool
     */
    public function deleteMessage($messageId, $userId)
    {
        return $this->messageService->softDeleteMessage($messageId, $userId);
    }

    /* =========================================================================
     * CONVERSATION CONTROLS (business logic)
     * ========================================================================= */

    /**
     * Toggle AI on/off for a conversation
     */
    public function toggleAI($conversationId, $enabled)
    {
        return $this->messageService->setAIEnabled($conversationId, $enabled);
    }

    /**
     * Set risk level
     */
    public function setRiskLevel($conversationId, $riskLevel)
    {
        return $this->messageService->updateRiskLevel($conversationId, $riskLevel);
    }

    /**
     * Set conversation status
     */
    public function setStatus($conversationId, $status)
    {
        return $this->messageService->updateTherapyStatus($conversationId, $status);
    }

    /* =========================================================================
     * NOTES (business logic)
     * ========================================================================= */

    /**
     * Add a clinical note
     */
    public function addNote($conversationId, $therapistId, $content, $noteType = THERAPY_NOTE_MANUAL)
    {
        return $this->messageService->addNote($conversationId, $therapistId, $content, $noteType);
    }

    /**
     * Edit a note
     */
    public function editNote($noteId, $therapistId, $content)
    {
        return $this->messageService->updateNote($noteId, $therapistId, $content);
    }

    /**
     * Delete a note
     */
    public function deleteNote($noteId, $therapistId)
    {
        return $this->messageService->softDeleteNote($noteId, $therapistId);
    }

    /* =========================================================================
     * ALERTS (business logic)
     * ========================================================================= */

    public function markAlertRead($alertId)
    {
        return $this->messageService->markAlertRead($alertId);
    }

    public function markAllAlertsRead($therapistId, $conversationId = null)
    {
        return $this->messageService->markAllAlertsRead($therapistId, $conversationId);
    }

    /**
     * Mark messages as read and update last seen
     */
    public function markMessagesRead($conversationId, $therapistId)
    {
        $this->messageService->updateLastSeen($conversationId, 'therapist');
        $this->messageService->markMessagesAsSeen($conversationId, $therapistId);
    }

    /* =========================================================================
     * AI DRAFT GENERATION (business logic)
     * ========================================================================= */

    /**
     * Generate an AI draft response for a conversation.
     *
     * Builds context from conversation history, calls the LLM API using
     * the model configured on this style, and saves both to llmMessages
     * (via the parent LLM plugin's addMessage) and to therapyDraftMessages.
     *
     * @param int $conversationId
     * @param int $therapistId
     * @return array {success, draft: {id, ai_content, edited_content, status}} or {error}
     */
    public function generateDraft($conversationId, $therapistId)
    {
        // Build AI context from the conversation history
        $systemContext = $this->get_db_field('conversation_context', '');
        $contextMessages = $this->messageService->buildAIContext($conversationId, $systemContext, 50);

        // Add a draft-specific instruction using the configurable context field
        $draftContext = $this->get_db_field('therapy_draft_context', '');
        $draftInstruction = 'Generate a thoughtful, empathetic therapeutic response draft for the therapist to review and edit before sending to the patient. Focus on being supportive and clinically appropriate.';
        if (!empty($draftContext)) {
            $draftInstruction .= "\n\nAdditional context and instructions from the therapist:\n" . $draftContext;
        }
        $contextMessages[] = array(
            'role' => 'system',
            'content' => $draftInstruction
        );

        // Inject the unified JSON response schema so the LLM returns
        // structured JSON with safety assessment (same as patient chat).
        $contextMessages = $this->messageService->injectResponseSchema($contextMessages);

        // Get LLM config from style fields
        $model = $this->getLlmModel();
        $temperature = $this->getLlmTemperature();
        $maxTokens = $this->getLlmMaxTokens();

        // Call LLM API to generate draft content
        $response = $this->messageService->callLlmApi($contextMessages, $model, $temperature, $maxTokens);

        if (!$response || empty($response['content'])) {
            return array('error' => 'AI did not generate a response. Please try again.');
        }

        // Extract human-readable text from structured JSON response.
        // The raw content may be JSON with content.text_blocks[] when the
        // schema is active; extractDisplayContent handles both cases.
        $rawContent = $response['content'];
        $aiContent = $this->messageService->extractDisplayContent($rawContent);

        // Save to llmMessages via the therapist's tools conversation (NOT the patient's)
        // This prevents draft messages from appearing in the patient's chat
        $toolsConvId = $this->messageService->getOrCreateTherapistToolsConversation(
            $therapistId, $this->getSectionId(), 'draft'
        );
        if ($toolsConvId) {
            $this->messageService->addMessage(
                $toolsConvId,
                'user',
                'Generate draft response for therapy conversation #' . $conversationId,
                null, null, null, null,
                array(
                    'therapy_sender_type' => 'therapist',
                    'draft_for_conversation' => $conversationId,
                    'is_draft' => true
                )
            );
            $this->messageService->addMessage(
                $toolsConvId,
                'assistant',
                $aiContent,
                null,
                $model,
                $response['tokens_used'] ?? null,
                $response,
                array(
                    'therapy_sender_type' => 'ai',
                    'draft_for_therapist' => $therapistId,
                    'draft_for_conversation' => $conversationId,
                    'is_draft' => true
                ),
                $response['reasoning'] ?? null,
                true,
                $response['request_payload'] ?? null
            );
        }

        // Also save in therapyDraftMessages for draft workflow tracking
        $draftId = $this->messageService->createDraft($conversationId, $therapistId, $aiContent);

        if (!$draftId) {
            return array('error' => 'Failed to save draft to database. Check lookup values for therapyDraftStatus.');
        }

        return array(
            'success' => true,
            'draft' => array(
                'id' => (int)$draftId,
                'ai_content' => $aiContent,
                'edited_content' => null,
                'status' => THERAPY_DRAFT_DRAFT
            )
        );
    }

    /**
     * Update a draft's edited content
     */
    public function updateDraft($draftId, $editedContent)
    {
        return $this->messageService->updateDraft($draftId, $editedContent);
    }

    /**
     * Send a draft as a real message
     */
    public function sendDraft($draftId, $therapistId, $conversationId)
    {
        $result = $this->messageService->sendDraft($draftId, $therapistId, $conversationId);

        // Send email notification to patient when draft is sent
        if (isset($result['success'])) {
            $draft = $this->messageService->getActiveDraft($conversationId, $therapistId);
            $content = $draft ? ($draft['edited_content'] ?: $draft['ai_generated_content']) : '';
            $this->notifyPatientNewMessage($conversationId, $therapistId, $content);
        }

        return $result;
    }

    /**
     * Discard a draft
     */
    public function discardDraft($draftId, $therapistId)
    {
        return $this->messageService->discardDraft($draftId, $therapistId);
    }

    /* =========================================================================
     * SUMMARIZATION (business logic)
     * ========================================================================= */

    /**
     * Generate a conversation summary using LLM.
     * Uses the LLM model configured on this therapistDashboard style.
     * Saves to llmMessages for full audit trail.
     *
     * @param int $conversationId
     * @param int $therapistId
     * @return array {success, summary, summary_conversation_id, tokens_used} or {error}
     */
    public function generateSummary($conversationId, $therapistId)
    {
        // Get the customizable summarization context from the style field
        $summaryContext = $this->get_db_field('therapy_summary_context', '');

        // Build a complete conversation history for summarization
        $messages = $this->messageService->getTherapyMessages($conversationId, 200);
        $conversation = $this->messageService->getTherapyConversation($conversationId);

        if (!$conversation) {
            return array('error' => 'Conversation not found');
        }

        // Build LLM messages for summarization
        $llmMessages = array();

        // System instruction for summarization
        $systemPrompt = "You are a clinical summarization assistant. Your task is to produce a concise, professional therapeutic summary of the conversation below.\n\n";
        if (!empty($summaryContext)) {
            $systemPrompt .= "Additional context and instructions from the therapist:\n" . $summaryContext . "\n\n";
        }
        $systemPrompt .= "Include: key topics discussed, patient emotional state, therapeutic interventions used, progress indicators, risk flags if any, and recommended next steps.";

        $llmMessages[] = array('role' => 'system', 'content' => $systemPrompt);

        // Add conversation history
        foreach ($messages as $msg) {
            if (!empty($msg['is_deleted'])) continue;

            $senderLabel = '';
            switch ($msg['sender_type'] ?? '') {
                case 'subject': $senderLabel = '[Patient]'; break;
                case 'therapist': $senderLabel = '[Therapist]'; break;
                case 'ai': $senderLabel = '[AI Assistant]'; break;
                case 'system': $senderLabel = '[System]'; break;
                default: $senderLabel = '[Unknown]';
            }

            $role = ($msg['role'] === 'assistant') ? 'assistant' : 'user';
            $llmMessages[] = array(
                'role' => $role,
                'content' => $senderLabel . ' ' . $msg['content']
            );
        }

        // Final user prompt requesting the summary
        $llmMessages[] = array(
            'role' => 'user',
            'content' => 'Please generate a clinical summary of the above therapy conversation.'
        );

        // Inject the unified JSON response schema so the LLM returns
        // structured JSON with safety assessment (same as patient chat).
        $llmMessages = $this->messageService->injectResponseSchema($llmMessages);

        // Call LLM using the model configured on THIS style (therapistDashboard)
        $model = $this->getLlmModel();
        $temperature = $this->getLlmTemperature();
        $maxTokens = $this->getLlmMaxTokens();

        $response = $this->messageService->callLlmApi($llmMessages, $model, $temperature, $maxTokens);

        if (!$response || empty($response['content'])) {
            return array('error' => 'AI did not generate a summary. Please try again.');
        }

        // Extract human-readable text from structured JSON response
        $rawContent = $response['content'];
        $displayContent = $this->messageService->extractDisplayContent($rawContent);

        // Create a new LLM conversation for the summary (for audit trail)
        $summaryConvId = $this->messageService->createSummaryConversation(
            $conversationId, $therapistId, $this->getSectionId(),
            $displayContent, $llmMessages, $response
        );

        return array(
            'success' => true,
            'summary' => $displayContent,
            'summary_conversation_id' => $summaryConvId,
            'tokens_used' => $response['tokens_used'] ?? null
        );
    }

    /* =========================================================================
     * CONVERSATION FULL LOAD (business logic)
     * ========================================================================= */

    /**
     * Load full conversation data: conversation, messages, notes, alerts.
     * Also marks messages as seen.
     *
     * @param int $conversationId
     * @param int $therapistId
     * @return array|null
     */
    public function loadFullConversation($conversationId, $therapistId)
    {
        $conversation = $this->messageService->getTherapyConversation($conversationId);
        if (!$conversation) {
            return null;
        }

        $messages = $this->messageService->getTherapyMessages($conversationId);
        $notes = $this->messageService->getNotesForConversation($conversationId);
        $alerts = $this->messageService->getAlertsForTherapist($therapistId, array('unread_only' => false));

        $this->messageService->updateLastSeen($conversationId, 'therapist');
        $this->messageService->markMessagesAsSeen($conversationId, $therapistId);

        return array(
            'conversation' => $conversation,
            'messages' => $messages,
            'notes' => $notes,
            'alerts' => $alerts
        );
    }

    /**
     * Lightweight polling: returns counts/flags so frontend can decide
     * whether a full fetch is needed.
     *
     * @param int $therapistId
     * @return array
     */
    public function checkUpdates($therapistId)
    {
        $unreadMessages = $this->messageService->getUnreadCountForUser($therapistId);
        $unreadAlerts = $this->messageService->getUnreadAlertCount($therapistId);
        $latestMsgId = $this->messageService->getLatestMessageIdForTherapist($therapistId);

        return array(
            'unread_messages' => (int)$unreadMessages,
            'unread_alerts' => (int)$unreadAlerts,
            'latest_message_id' => $latestMsgId
        );
    }

    /**
     * Get unread counts broken down by subject and group.
     *
     * @param int $therapistId
     * @return array
     */
    public function getUnreadCounts($therapistId)
    {
        return array(
            // Exclude AI messages — therapists only need to see patient messages as unread
            'total' => $this->messageService->getUnreadCountForUser($therapistId, true),
            'totalAlerts' => $this->messageService->getUnreadAlertCount($therapistId),
            'bySubject' => $this->messageService->getUnreadBySubjectForTherapist($therapistId),
            'byGroup' => $this->messageService->getUnreadByGroupForTherapist($therapistId)
        );
    }

    /* =========================================================================
     * EMAIL NOTIFICATIONS (business logic)
     * ========================================================================= */

    /**
     * Send email notification to patient when therapist sends a message.
     * Uses SelfHelp's JobScheduler to queue an email.
     *
     * @param int $conversationId
     * @param int $therapistId
     * @param string $messageContent
     */
    public function notifyPatientNewMessage($conversationId, $therapistId, $messageContent)
    {
        $enabled = (bool)$this->get_db_field('enable_patient_email_notification', '1');
        if (!$enabled) return;

        $conversation = $this->messageService->getTherapyConversation($conversationId);
        if (!$conversation) return;

        $patientId = $conversation['id_users'];
        $services = $this->get_services();
        $db = $services->get_db();
        $jobScheduler = $services->get_job_scheduler();

        // Get patient info
        $patient = $db->select_by_uid('users', $patientId);
        if (!$patient || empty($patient['email'])) return;

        // Get therapist info
        $therapist = $db->select_by_uid('users', $therapistId);
        $therapistName = $therapist ? $therapist['name'] : 'Your Therapist';

        // Get email configuration from style fields
        $subject = $this->get_db_field('patient_notification_email_subject', '[Therapy Chat] New message from your therapist');
        $body = $this->get_db_field('patient_notification_email_body',
            '<p>Hello @user_name,</p>' .
            '<p>You have received a new message from <strong>' . htmlspecialchars($therapistName) . '</strong> in your therapy chat.</p>' .
            '<p>Please log in to read and respond to the message.</p>' .
            '<p>Best regards,<br>Therapy Chat</p>'
        );

        // Replace placeholders
        $body = str_replace('@user_name', htmlspecialchars($patient['name'] ?? ''), $body);
        $body = str_replace('@therapist_name', htmlspecialchars($therapistName), $body);
        $subject = str_replace('@therapist_name', htmlspecialchars($therapistName), $subject);

        $fromEmail = $this->get_db_field('notification_from_email', 'noreply@selfhelp.local');
        $fromName = $this->get_db_field('notification_from_name', 'Therapy Chat');

        $mail = array(
            "id_jobTypes" => $db->get_lookup_id_by_value(jobTypes, jobTypes_email),
            "id_jobStatus" => $db->get_lookup_id_by_value(scheduledJobsStatus, scheduledJobsStatus_queued),
            "date_to_be_executed" => date('Y-m-d H:i:s'),
            "from_email" => $fromEmail,
            "from_name" => $fromName,
            "reply_to" => $fromEmail,
            "recipient_emails" => $patient['email'],
            "subject" => $subject,
            "body" => $body,
            "description" => "Therapy Chat: therapist message notification to patient #" . $patientId,
            "is_html" => 1,
            "id_users" => array($patientId),
            "attachments" => array()
        );

        try {
            $jobScheduler->schedule_job($mail, transactionBy_by_system);
        } catch (Exception $e) {
            error_log("TherapyChat: Failed to schedule patient notification email: " . $e->getMessage());
        }
    }

    /* =========================================================================
     * SPEECH-TO-TEXT
     * ========================================================================= */

    /**
     * Transcribe audio to text using the LLM plugin's speech service.
     *
     * @param string $tempPath Path to uploaded audio file
     * @return array {success, text} or {error}
     */
    public function transcribeSpeech($tempPath)
    {
        $llmSpeechServicePath = __DIR__ . "/../../../../../sh-shp-llm/server/service/LlmSpeechToTextService.php";

        if (!file_exists($llmSpeechServicePath)) {
            return array('error' => 'Speech-to-text service not available');
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
     * GETTERS
     * ========================================================================= */

    public function getUserId()
    {
        return $this->userId;
    }

    public function getSelectedGroupId()
    {
        return $this->selectedGroupId;
    }

    public function getSelectedSubjectId()
    {
        return $this->selectedSubjectId;
    }

    public function getSectionId()
    {
        return $this->section_id;
    }

    public function getTherapyService()
    {
        return $this->messageService;
    }

    public function getLlmModel()
    {
        return $this->get_db_field('llm_model', '') ?: 'gpt-4o-mini';
    }

    public function getLlmTemperature()
    {
        return (float)$this->get_db_field('llm_temperature', '0.7');
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
     * REACT CONFIG
     * ========================================================================= */

    public function getReactConfig()
    {
        $stats = $this->getStats();
        $assignedGroups = $this->getAssignedGroups();

        $getField = function ($name, $default = '') {
            $value = $this->get_db_field($name);
            return $value !== null && $value !== '' ? $value : $default;
        };

        $getBoolField = function ($name, $default = true) use ($getField) {
            $value = $getField($name, $default ? '1' : '0');
            return $value === '1' || $value === 1 || $value === true;
        };

        $getNumField = function ($name, $default = 0) use ($getField) {
            return intval($getField($name, $default));
        };

        return [
            // Core identifiers
            'userId' => $this->getUserId(),
            'sectionId' => $this->getSectionId(),
            'selectedGroupId' => $this->getSelectedGroupId(),
            'selectedSubjectId' => $this->getSelectedSubjectId(),

            // Stats
            'stats' => $stats,

            // Assigned groups (for group filter UI)
            'assignedGroups' => $assignedGroups,

            // Configuration settings
            'pollingInterval' => $getNumField('dashboard_polling_interval', 5) * 1000,
            'messagesPerPage' => $getNumField('dashboard_messages_per_page', 50),
            'conversationsPerPage' => $getNumField('dashboard_conversations_per_page', 20),

            // Feature toggles
            'features' => [
                'showRiskColumn' => $getBoolField('dashboard_show_risk_column', true),
                'showStatusColumn' => $getBoolField('dashboard_show_status_column', true),
                'showAlertsPanel' => $getBoolField('dashboard_show_alerts_panel', true),
                'showNotesPanel' => $getBoolField('dashboard_show_notes_panel', true),
                'showStatsHeader' => $getBoolField('dashboard_show_stats_header', true),
                'enableAiToggle' => $getBoolField('dashboard_enable_ai_toggle', true),
                'enableRiskControl' => $getBoolField('dashboard_enable_risk_control', true),
                'enableStatusControl' => $getBoolField('dashboard_enable_status_control', true),
                'enableNotes' => $getBoolField('dashboard_enable_notes', true),
                'enableInvisibleMode' => $getBoolField('dashboard_enable_invisible_mode', true),
            ],

            // Notification settings
            'notifications' => [
                'notifyOnTag' => $getBoolField('dashboard_notify_on_tag', true),
                'notifyOnDanger' => $getBoolField('dashboard_notify_on_danger', true),
                'notifyOnCritical' => $getBoolField('dashboard_notify_on_critical', true),
            ],

            // UI Labels
            'labels' => [
                'title' => $getField('title', 'Therapist Dashboard'),
                'conversationsHeading' => $getField('dashboard_conversations_heading', 'Patient Conversations'),
                'alertsHeading' => $getField('dashboard_alerts_heading', 'Alerts'),
                'notesHeading' => $getField('dashboard_notes_heading', 'Clinical Notes'),
                'statsHeading' => $getField('dashboard_stats_heading', 'Overview'),
                'riskHeading' => $getField('dashboard_risk_heading', 'Risk Level'),

                'noConversations' => $getField('dashboard_no_conversations', 'No patient conversations found.'),
                'noAlerts' => $getField('dashboard_no_alerts', 'No alerts at this time.'),
                'selectConversation' => $getField('dashboard_select_conversation', 'Select a patient conversation to view messages and respond.'),

                'sendPlaceholder' => $getField('dashboard_send_placeholder', 'Type your response to the patient...'),
                'sendButton' => $getField('dashboard_send_button', 'Send Response'),
                'addNotePlaceholder' => $getField('dashboard_add_note_placeholder', 'Add a clinical note (not visible to patient)...'),
                'addNoteButton' => $getField('dashboard_add_note_button', 'Add Note'),
                'loading' => $getField('dashboard_loading_text', 'Loading...'),

                'aiLabel' => $getField('dashboard_ai_label', 'AI Assistant'),
                'therapistLabel' => $getField('dashboard_therapist_label', 'Therapist'),
                'subjectLabel' => $getField('dashboard_subject_label', 'Patient'),

                'riskLow' => $getField('dashboard_risk_low', 'Low'),
                'riskMedium' => $getField('dashboard_risk_medium', 'Medium'),
                'riskHigh' => $getField('dashboard_risk_high', 'High'),
                'riskCritical' => $getField('dashboard_risk_critical', 'Critical'),

                'statusActive' => $getField('dashboard_status_active', 'Active'),
                'statusPaused' => $getField('dashboard_status_paused', 'Paused'),
                'statusClosed' => $getField('dashboard_status_closed', 'Closed'),

                'disableAI' => $getField('dashboard_disable_ai', 'Pause AI'),
                'enableAI' => $getField('dashboard_enable_ai', 'Resume AI'),
                'aiModeIndicator' => $getField('dashboard_ai_mode_indicator', 'AI-assisted mode'),
                'humanModeIndicator' => $getField('dashboard_human_mode_indicator', 'Therapist-only mode'),

                'acknowledge' => $getField('dashboard_acknowledge_button', 'Acknowledge'),
                'dismiss' => $getField('dashboard_dismiss_button', 'Dismiss'),
                'viewInLlm' => $getField('dashboard_view_llm_button', 'View in LLM Console'),
                'joinConversation' => $getField('dashboard_join_conversation', 'Join Conversation'),
                'leaveConversation' => $getField('dashboard_leave_conversation', 'Leave Conversation'),

                'statPatients' => $getField('dashboard_stat_patients', 'Patients'),
                'statActive' => $getField('dashboard_stat_active', 'Active'),
                'statCritical' => $getField('dashboard_stat_critical', 'Critical'),
                'statAlerts' => $getField('dashboard_stat_alerts', 'Alerts'),
                'statTags' => $getField('dashboard_stat_tags', 'Tags'),

                'filterAll' => $getField('dashboard_filter_all', 'All'),
                'filterActive' => $getField('dashboard_filter_active', 'Active'),
                'filterCritical' => $getField('dashboard_filter_critical', 'Critical'),
                'filterUnread' => $getField('dashboard_filter_unread', 'Unread'),
                'filterTagged' => $getField('dashboard_filter_tagged', 'Tagged'),

                'allGroupsTab' => $getField('dashboard_all_groups_tab', 'All Groups'),
                'emptyMessage' => $getField('dashboard_empty_message', 'No messages yet.'),

                'interventionMessage' => $getField('dashboard_intervention_message', 'Your therapist has joined the conversation.'),
                'aiPausedNotice' => $getField('dashboard_ai_paused_notice', 'AI responses have been paused. Your therapist will respond directly.'),
                'aiResumedNotice' => $getField('dashboard_ai_resumed_notice', 'AI-assisted support has been resumed.'),
            ],

            // Speech-to-Text
            'speechToTextEnabled' => $this->isSpeechToTextEnabled(),
            'speechToTextModel' => $this->getSpeechToTextModel(),
            'speechToTextLanguage' => $this->getSpeechToTextLanguage(),
        ];
    }
}
?>
