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
 * Data model for the therapist dashboard.
 * Uses TherapyMessageService (top-level) for all service calls.
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
     * Get conversation by ID
     */
    public function getConversationById($conversationId)
    {
        return $this->messageService->getTherapyConversation($conversationId);
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
