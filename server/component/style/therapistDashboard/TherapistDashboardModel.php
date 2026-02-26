<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . "/../../../service/TherapyMessageService.php";
require_once __DIR__ . "/../../../service/TherapyNotificationService.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";
require_once __DIR__ . "/TherapistDashboardDraftHelper.php";
require_once __DIR__ . "/TherapistDashboardNotificationHelper.php";
require_once __DIR__ . "/../TherapyModelConfigTrait.php";

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
 * - Risk/AI toggle management
 * - Email notifications on new messages
 * - React configuration
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapistDashboardModel extends StyleModel
{
    use TherapyModelConfigTrait;
    use TherapistDashboardDraftTrait;
    use TherapistDashboardNotificationTrait;

    /** @var TherapyMessageService */
    private $messageService;

    /** @var int|null */
    private $userId;

    /** @var int|null Group filter from URL */
    private $selectedGroupId;

    /** @var int|null Subject filter from URL */
    private $selectedSubjectId;

    /** @var TherapyNotificationService|null */
    private $notificationService;

    public function __construct($services, $id, $params = array(), $id_page = -1, $entry_record = array())
    {
        parent::__construct($services, $id, $params, $id_page, $entry_record);

        $this->messageService = new TherapyMessageService($services);
        $this->notificationService = null;
        $this->userId = $_SESSION['id_user'] ?? null;
        $this->selectedGroupId = isset($params['gid']) ? (int)$params['gid'] : null;
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
        return $this->messageService->getTherapyMessages(
            $conversationId,
            $limit,
            $afterId,
            $this->getMessageLabelOverrides()
        );
    }

    /**
     * Message label overrides sourced from therapist dashboard DB fields.
     */
    private function getMessageLabelOverrides()
    {
        return $this->buildMessageLabelOverrides(
            'dashboard_ai_label',
            'dashboard_therapist_label',
            'dashboard_subject_label',
            'Patient'
        );
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
            $this->notifyPatientMessageChannels($conversationId, $therapistId, $message);
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
     * CONVERSATION INITIALIZATION (business logic)
     * ========================================================================= */

    /**
     * Initialize a conversation for a patient who doesn't have one yet.
     * The therapist triggers this; the conversation is owned by the patient.
     *
     * @param int $patientId
     * @param int $therapistId
     * @return array {success, conversation} or {error}
     */
    public function initializeConversation($patientId, $therapistId)
    {
        // Verify the therapist can access this patient
        if (!$this->messageService->canTherapistAccessPatient($therapistId, $patientId)) {
            return array('error' => 'Access denied - patient is not in your assigned groups', 'status' => 403);
        }

        // Check if patient already has an active conversation
        $existing = $this->messageService->getTherapyConversationBySubject($patientId);
        if ($existing) {
            return array(
                'success' => true,
                'conversation' => $existing,
                'already_exists' => true
            );
        }

        // Get config from the linked therapyChat style fields
        $mode = $this->get_db_field('therapy_chat_default_mode', THERAPY_MODE_AI_HYBRID);
        $model = $this->get_db_field('llm_model', '');
        $aiEnabled = (bool)$this->get_db_field('therapy_enable_ai', '1');
        $autoStartContext = $this->get_db_field('therapy_auto_start_context', '');

        $conversation = $this->messageService->initializeConversationForPatient(
            $patientId,
            $therapistId,
            $this->getSectionId(),
            $mode,
            $model,
            $aiEnabled,
            $autoStartContext
        );

        if (!$conversation) {
            return array('error' => 'Failed to initialize conversation');
        }

        return array(
            'success' => true,
            'conversation' => $conversation,
            'already_exists' => false
        );
    }

    /* =========================================================================
     * EXPORT (CSV download)
     * ========================================================================= */

    /**
     * Export conversations to CSV format.
     *
     * Supports three scopes:
     *  - 'patient'  : single patient's conversation
     *  - 'group'    : all conversations in a group
     *  - 'all'      : all conversations the therapist has access to
     *
     * Access control is enforced: only conversations the requesting
     * therapist is assigned to are included.
     *
     * @param string $scope 'patient'|'group'|'all'
     * @param int|null $conversationId Required when scope='patient'
     * @param int|null $groupId Required when scope='group'
     * @return array {filename, headers, rows} or {error}
     */
    public function exportConversations($scope, $conversationId = null, $groupId = null)
    {
        $therapistId = $this->userId;
        if (!$therapistId) {
            return array('error' => 'Not authenticated');
        }

        $conversations = array();

        switch ($scope) {
            case 'patient':
                if (!$conversationId) {
                    return array('error' => 'Conversation ID is required for patient export');
                }
                if (!$this->canAccessConversation($therapistId, $conversationId)) {
                    return array('error' => 'Access denied');
                }
                $conv = $this->messageService->getTherapyConversation($conversationId);
                if ($conv) {
                    $conversations = array($conv);
                }
                break;

            case 'group':
                if (!$groupId) {
                    return array('error' => 'Group ID is required for group export');
                }
                $filters = array('group_id' => $groupId);
                $conversations = $this->messageService->getTherapyConversationsByTherapist(
                    $therapistId, $filters, THERAPY_STATS_LIMIT, 0
                );
                break;

            case 'all':
                $conversations = $this->messageService->getTherapyConversationsByTherapist(
                    $therapistId, array(), THERAPY_STATS_LIMIT, 0
                );
                break;

            default:
                return array('error' => 'Invalid export scope. Use: patient, group, or all');
        }

        if (empty($conversations)) {
            return array('error' => 'No conversations found for export');
        }

        // Build a patient-ID → group-names lookup from the therapist's assigned groups
        $groupLookup = $this->buildPatientGroupLookup($therapistId);

        $headers = array(
            'conversation_id',
            'patient_name',
            'patient_code',
            'group_name',
            'mode',
            'ai_enabled',
            'status',
            'risk_level',
            'message_id',
            'timestamp',
            'sender_type',
            'sender_name',
            'role',
            'content'
        );

        $rows = array();
        foreach ($conversations as $conv) {
            if (!empty($conv['no_conversation'])) {
                continue;
            }

            $convId = $conv['id'];
            $patientId = $conv['id_users'] ?? 0;
            $messages = $this->messageService->getTherapyMessages($convId, THERAPY_STATS_LIMIT);

            if (empty($messages)) {
                continue;
            }

            $groupName = $groupLookup[$patientId] ?? '';

            foreach ($messages as $msg) {
                if (!empty($msg['is_deleted'])) {
                    continue;
                }

                $rows[] = array(
                    $convId,
                    $conv['subject_name'] ?? $conv['name'] ?? '',
                    $conv['subject_code'] ?? $conv['code'] ?? '',
                    $groupName,
                    $conv['mode'] ?? '',
                    $conv['ai_enabled'] ? 'yes' : 'no',
                    $conv['status'] ?? '',
                    $conv['risk_level'] ?? '',
                    $msg['id'],
                    $msg['timestamp'] ?? '',
                    $msg['sender_type'] ?? $msg['role'] ?? '',
                    $msg['label'] ?? $msg['sender_name'] ?? '',
                    $msg['role'] ?? '',
                    $msg['content']
                );
            }
        }

        if (empty($rows)) {
            return array('error' => 'No messages found for export');
        }

        // Build filename
        $datePart = date('Y-m-d_His');
        switch ($scope) {
            case 'patient':
                $patientName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $conversations[0]['subject_name'] ?? 'patient');
                $filename = "therapy_export_{$patientName}_{$datePart}.csv";
                break;
            case 'group':
                $groups = $this->messageService->getTherapistAssignedGroups($therapistId);
                $gLabel = 'group';
                foreach ($groups as $g) {
                    if ((string)$g['id_groups'] === (string)$groupId) {
                        $gLabel = $g['group_name'];
                        break;
                    }
                }
                $gLabel = preg_replace('/[^a-zA-Z0-9_-]/', '_', $gLabel);
                $filename = "therapy_export_group_{$gLabel}_{$datePart}.csv";
                break;
            default:
                $filename = "therapy_export_all_{$datePart}.csv";
                break;
        }

        return array(
            'filename' => $filename,
            'headers' => $headers,
            'rows' => $rows
        );
    }

    /**
     * Build a patient-ID → group-names map for CSV export.
     *
     * Queries the therapist's assigned groups and maps each patient
     * to a comma-separated list of group names they belong to.
     *
     * @param int $therapistId
     * @return array patientId => "group1, group2"
     */
    private function buildPatientGroupLookup($therapistId)
    {
        $groups = $this->messageService->getTherapistAssignedGroups($therapistId);
        if (empty($groups)) {
            return array();
        }

        $groupIds = array_column($groups, 'id_groups');
        $groupNames = array_column($groups, 'group_name', 'id_groups');

        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $sql = "SELECT ug.id_users, ug.id_groups
                FROM users_groups ug
                WHERE ug.id_groups IN ($placeholders)";

        $rows = $this->db->query_db($sql, $groupIds);
        if (!$rows) {
            return array();
        }

        $lookup = array();
        foreach ($rows as $row) {
            $uid = $row['id_users'];
            $gid = $row['id_groups'];
            $gname = $groupNames[$gid] ?? '';
            if (!isset($lookup[$uid])) {
                $lookup[$uid] = $gname;
            } else {
                $lookup[$uid] .= ', ' . $gname;
            }
        }

        return $lookup;
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

        $messages = $this->messageService->getTherapyMessages(
            $conversationId,
            THERAPY_DEFAULT_MESSAGE_LIMIT,
            null,
            $this->getMessageLabelOverrides()
        );
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
     * SPEECH-TO-TEXT
     * ========================================================================= */

    /**
     * Transcribe audio to text.
     * Delegates to TherapyMessageService to avoid code duplication.
     *
     * @param string $tempPath Path to uploaded audio file
     * @return array {success, text} or {error}
     */
    public function transcribeSpeech($tempPath)
    {
        return $this->messageService->transcribeSpeech(
            $tempPath,
            $this->getSpeechToTextModel(),
            $this->getSpeechToTextLanguage()
        );
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
        $model = $this->get_db_field('llm_model', '');
        if (!empty($model)) {
            return $model;
        }
        try {
            $config = $this->messageService->getPublicLlmConfig();
            if (!empty($config['llm_model'])) {
                return $config['llm_model'];
            }
        } catch (\Exception $e) {}
        return 'gpt-4o-mini';
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
        return $this->isSpeechToTextConfigured();
    }

    public function getSpeechToTextModel()
    {
        return $this->getSpeechToTextConfiguredModel();
    }

    public function getSpeechToTextLanguage()
    {
        return $this->getSpeechToTextConfiguredLanguage();
    }

    public function getNotificationService()
    {
        if ($this->notificationService === null) {
            $this->notificationService = new TherapyNotificationService(
                $this->get_services(),
                $this->messageService,
                array($this, 'get_db_field')
            );
        }
        return $this->notificationService;
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
                'showAlertsPanel' => $getBoolField('dashboard_show_alerts_panel', true),
                'showNotesPanel' => $getBoolField('dashboard_show_notes_panel', true),
                'showStatsHeader' => $getBoolField('dashboard_show_stats_header', true),
                'enableAiToggle' => $getBoolField('dashboard_enable_ai_toggle', true),
                'enableRiskControl' => $getBoolField('dashboard_enable_risk_control', true),
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
                'statAiEnabled' => $getField('dashboard_stat_ai_enabled', 'AI Enabled'),
                'statAiBlocked' => $getField('dashboard_stat_ai_blocked', 'AI Blocked'),
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
                'startConversation' => $getField('dashboard_start_conversation', 'Start Conversation'),
                'noConversationYet' => $getField('dashboard_no_conversation_yet', 'No conversation yet'),
                'initializingConversation' => $getField('dashboard_initializing_conversation', 'Initializing conversation...'),

                'interventionMessage' => $getField('dashboard_intervention_message', 'Your therapist has joined the conversation.'),
                'aiPausedNotice' => $getField('dashboard_ai_paused_notice', 'AI responses have been paused. Your therapist will respond directly.'),
                'aiResumedNotice' => $getField('dashboard_ai_resumed_notice', 'AI-assisted support has been resumed.'),
            ],

            // Speech-to-Text
            'speechToTextEnabled' => $this->isSpeechToTextEnabled(),
            'speechToTextModel' => $this->getSpeechToTextModel(),
            'speechToTextLanguage' => $this->getSpeechToTextLanguage(),

            // Chat colors
            'chatColors' => $this->getChatColors(),
        ];
    }

    /**
     * Get chat color palette from the database field.
     */
    public function getChatColors()
    {
        $default = '{}';
        $raw = $this->get_db_field('therapy_chat_colors', $default);
        if (empty($raw)) return array();
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : array();
        }
        return is_array($raw) ? $raw : array();
    }
}
?>
