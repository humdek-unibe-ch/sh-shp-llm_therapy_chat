<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . "/../../../service/TherapyTaggingService.php";
require_once __DIR__ . "/../../../service/TherapyAlertService.php";
require_once __DIR__ . "/../../../service/TherapyMessageService.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";

/**
 * Therapist Dashboard Model
 *
 * Data model for the therapist dashboard.
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapistDashboardModel extends StyleModel
{
    /** @var TherapyTaggingService */
    private $taggingService;

    /** @var TherapyAlertService */
    private $alertService;

    /** @var TherapyMessageService */
    private $messageService;

    /** @var int|null */
    private $userId;

    /** @var int|null */
    private $selectedGroupId;

    /** @var int|null */
    private $selectedSubjectId;

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

        $this->taggingService = new TherapyTaggingService($services);
        $this->alertService = new TherapyAlertService($services);
        $this->messageService = new TherapyMessageService($services);
        $this->userId = $_SESSION['id_user'] ?? null;
        $this->selectedGroupId = $params['gid'] ?? null;
        $this->selectedSubjectId = $params['uid'] ?? null;
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

        return $this->alertService->isTherapist($this->userId);
    }

    /* Data Access ************************************************************/

    /**
     * Get all conversations for this therapist
     *
     * @param array $filters
     * @return array
     */
    public function getConversations($filters = array())
    {
        if ($this->selectedGroupId) {
            $filters['group_id'] = $this->selectedGroupId;
        }

        return $this->alertService->getTherapyConversationsByTherapist(
            $this->userId,
            $filters,
            100,
            0
        );
    }

    /**
     * Get selected conversation
     *
     * @return array|null
     */
    public function getSelectedConversation()
    {
        if (!$this->selectedSubjectId) {
            return null;
        }

        // Find conversation by subject ID
        $conversations = $this->getConversations();
        
        foreach ($conversations as $conv) {
            if ($conv['id_users'] == $this->selectedSubjectId) {
                return $conv;
            }
        }

        return null;
    }

    /**
     * Get conversation by ID
     *
     * @param int $conversationId
     * @return array|null
     */
    public function getConversationById($conversationId)
    {
        return $this->alertService->getTherapyConversation($conversationId);
    }

    /**
     * Get messages for a conversation
     *
     * @param int $conversationId
     * @param int $limit
     * @param int|null $afterId
     * @return array
     */
    public function getMessages($conversationId, $limit = 100, $afterId = null)
    {
        return $this->messageService->getTherapyMessages($conversationId, $limit, $afterId);
    }

    /**
     * Get alerts for this therapist
     *
     * @param array $filters
     * @return array
     */
    public function getAlerts($filters = array())
    {
        return $this->alertService->getAlertsForTherapist($this->userId, $filters);
    }

    /**
     * Get unread alert count
     *
     * @return int
     */
    public function getUnreadAlertCount()
    {
        return $this->alertService->getUnreadAlertCount($this->userId);
    }

    /**
     * Get pending tags
     *
     * @return array
     */
    public function getPendingTags()
    {
        return $this->taggingService->getPendingTagsForTherapist($this->userId);
    }

    /**
     * Get therapist statistics
     *
     * @return array
     */
    public function getStats()
    {
        return $this->alertService->getTherapistStats($this->userId);
    }

    /**
     * Get notes for a conversation
     *
     * @param int $conversationId
     * @return array
     */
    public function getNotes($conversationId)
    {
        $sql = "SELECT tn.*, u.name as author_name
                FROM therapyNotes tn
                INNER JOIN users u ON u.id = tn.id_users
                WHERE tn.id_llmConversations = :cid
                ORDER BY tn.created_at DESC";
        
        return $this->db->query_db($sql, array(':cid' => $conversationId));
    }

    /* Service Access *********************************************************/

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
     * Get selected group ID
     *
     * @return int|null
     */
    public function getSelectedGroupId()
    {
        return $this->selectedGroupId;
    }

    /**
     * Get selected subject ID
     *
     * @return int|null
     */
    public function getSelectedSubjectId()
    {
        return $this->selectedSubjectId;
    }

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
     * Get React configuration as array
     *
     * @return array
     */
    public function getReactConfig()
    {
        $stats = $this->getStats();

        // Get field values from component configuration
        $getField = function($name, $default = '') {
            $value = $this->get_db_field($name);
            return $value !== null && $value !== '' ? $value : $default;
        };

        // Get boolean field
        $getBoolField = function($name, $default = true) use ($getField) {
            $value = $getField($name, $default ? '1' : '0');
            return $value === '1' || $value === 1 || $value === true;
        };

        // Get number field
        $getNumField = function($name, $default = 0) use ($getField) {
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

            // Configuration settings
            'pollingInterval' => $getNumField('dashboard_polling_interval', 5) * 1000, // Convert to milliseconds
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
                // Headings
                'title' => $getField('title', 'Therapist Dashboard'),
                'conversationsHeading' => $getField('dashboard_conversations_heading', 'Patient Conversations'),
                'alertsHeading' => $getField('dashboard_alerts_heading', 'Alerts'),
                'notesHeading' => $getField('dashboard_notes_heading', 'Clinical Notes'),
                'statsHeading' => $getField('dashboard_stats_heading', 'Overview'),
                'riskHeading' => $getField('dashboard_risk_heading', 'Risk Level'),

                // Empty states
                'noConversations' => $getField('dashboard_no_conversations', 'No patient conversations found.'),
                'noAlerts' => $getField('dashboard_no_alerts', 'No alerts at this time.'),
                'selectConversation' => $getField('dashboard_select_conversation', 'Select a patient conversation to view messages and respond.'),

                // Input labels
                'sendPlaceholder' => $getField('dashboard_send_placeholder', 'Type your response to the patient...'),
                'sendButton' => $getField('dashboard_send_button', 'Send Response'),
                'addNotePlaceholder' => $getField('dashboard_add_note_placeholder', 'Add a clinical note (not visible to patient)...'),
                'addNoteButton' => $getField('dashboard_add_note_button', 'Add Note'),
                'loading' => $getField('dashboard_loading_text', 'Loading...'),

                // Message labels
                'aiLabel' => $getField('dashboard_ai_label', 'AI Assistant'),
                'therapistLabel' => $getField('dashboard_therapist_label', 'Therapist'),
                'subjectLabel' => $getField('dashboard_subject_label', 'Patient'),

                // Risk labels
                'riskLow' => $getField('dashboard_risk_low', 'Low'),
                'riskMedium' => $getField('dashboard_risk_medium', 'Medium'),
                'riskHigh' => $getField('dashboard_risk_high', 'High'),
                'riskCritical' => $getField('dashboard_risk_critical', 'Critical'),

                // Status labels
                'statusActive' => $getField('dashboard_status_active', 'Active'),
                'statusPaused' => $getField('dashboard_status_paused', 'Paused'),
                'statusClosed' => $getField('dashboard_status_closed', 'Closed'),

                // AI control labels
                'disableAI' => $getField('dashboard_disable_ai', 'Pause AI'),
                'enableAI' => $getField('dashboard_enable_ai', 'Resume AI'),
                'aiModeIndicator' => $getField('dashboard_ai_mode_indicator', 'AI-assisted mode'),
                'humanModeIndicator' => $getField('dashboard_human_mode_indicator', 'Therapist-only mode'),

                // Action buttons
                'acknowledge' => $getField('dashboard_acknowledge_button', 'Acknowledge'),
                'dismiss' => $getField('dashboard_dismiss_button', 'Dismiss'),
                'viewInLlm' => $getField('dashboard_view_llm_button', 'View in LLM Console'),
                'joinConversation' => $getField('dashboard_join_conversation', 'Join Conversation'),
                'leaveConversation' => $getField('dashboard_leave_conversation', 'Leave Conversation'),

                // Statistics labels
                'statPatients' => $getField('dashboard_stat_patients', 'Patients'),
                'statActive' => $getField('dashboard_stat_active', 'Active'),
                'statCritical' => $getField('dashboard_stat_critical', 'Critical'),
                'statAlerts' => $getField('dashboard_stat_alerts', 'Alerts'),
                'statTags' => $getField('dashboard_stat_tags', 'Tags'),

                // Filter labels
                'filterAll' => $getField('dashboard_filter_all', 'All'),
                'filterActive' => $getField('dashboard_filter_active', 'Active'),
                'filterCritical' => $getField('dashboard_filter_critical', 'Critical'),
                'filterUnread' => $getField('dashboard_filter_unread', 'Unread'),
                'filterTagged' => $getField('dashboard_filter_tagged', 'Tagged'),

                // Intervention messages
                'interventionMessage' => $getField('dashboard_intervention_message', 'Your therapist has joined the conversation.'),
                'aiPausedNotice' => $getField('dashboard_ai_paused_notice', 'AI responses have been paused. Your therapist will respond directly.'),
                'aiResumedNotice' => $getField('dashboard_ai_resumed_notice', 'AI-assisted support has been resumed.'),
            ]
        ];
    }
}
?>
