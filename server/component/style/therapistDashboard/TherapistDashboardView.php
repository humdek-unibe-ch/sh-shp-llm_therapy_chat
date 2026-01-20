<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/style/StyleView.php";

/**
 * Therapist Dashboard View
 * 
 * Renders the therapist monitoring dashboard.
 * Uses React for the frontend - configuration is passed via JSON.
 * 
 * @package LLM Therapy Chat Plugin
 */
class TherapistDashboardView extends StyleView
{
    /**
     * Constructor
     *
     * @param object $model
     * @param object $controller
     */
    public function __construct($model, $controller)
    {
        parent::__construct($model, $controller);
    }

    /**
     * Render the therapist dashboard
     */
    public function output_content()
    {
        // Skip rendering in CMS edit mode
        if (
            (method_exists($this->model, 'is_cms_page') && $this->model->is_cms_page()) &&
            (method_exists($this->model, 'is_cms_page_editing') && $this->model->is_cms_page_editing())
        ) {
            return;
        }

        $user_id = $this->model->getUserId();
        $section_id = $this->model->getSectionId();

        // Get selected conversation if any
        $selected_conversation_id = $this->model->getSelectedSubjectId();

        // Include the template
        include __DIR__ . '/tpl/therapist_dashboard_main.php';
    }

    /**
     * Get CSS includes
     */
    public function get_css_includes($local = array())
    {
        if (empty($local)) {
            $css_file = __DIR__ . "/../../../../css/ext/therapy-chat.css";
            if (file_exists($css_file)) {
                if (defined('DEBUG') && DEBUG) {
                    $version = filemtime($css_file) ?: time();
                    $local = array($css_file . "?v=" . $version);
                } else {
                    $local = array($css_file . "?v=" . rtrim(shell_exec("git describe --tags") ?? '1.0.0'));
                }
            }
        }
        return parent::get_css_includes($local);
    }

    /**
     * Get JS includes
     */
    public function get_js_includes($local = array())
    {
        if (empty($local)) {
            $js_file = __DIR__ . "/../../../../js/ext/therapy-chat.umd.js";
            if (file_exists($js_file)) {
                if (defined('DEBUG') && DEBUG) {
                    $version = filemtime($js_file) ?: time();
                    $local = array($js_file . "?v=" . $version);
                } else {
                    $local = array($js_file . "?v=" . rtrim(shell_exec("git describe --tags") ?? '1.0.0'));
                }
            }
        }
        return parent::get_js_includes($local);
    }

    /**
     * Get React configuration as JSON
     *
     * @return string JSON encoded config
     */
    public function getReactConfig()
    {
        $stats = $this->model->getStats();
        
        // Get field values from component configuration
        $getField = function($name, $default = '') {
            $value = $this->model->get_db_field($name);
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
        
        return json_encode([
            // Core identifiers
            'userId' => $this->model->getUserId(),
            'sectionId' => $this->model->getSectionId(),
            'selectedGroupId' => $this->model->getSelectedGroupId(),
            'selectedSubjectId' => $this->model->getSelectedSubjectId(),
            
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
        ]);
    }


    /**
     * Output content for mobile
     */
    public function output_content_mobile()
    {
        if (
            (method_exists($this->model, 'is_cms_page') && $this->model->is_cms_page()) &&
            (method_exists($this->model, 'is_cms_page_editing') && $this->model->is_cms_page_editing())
        ) {
            return [];
        }

        $style = parent::output_content_mobile();
        
        $style['user_id'] = $this->model->getUserId();
        $style['section_id'] = $this->model->getSectionId();
        $style['conversations'] = $this->model->getConversations();
        $style['stats'] = $this->model->getStats();
        $style['alerts'] = $this->model->getAlerts(['unread_only' => true]);
        $style['pending_tags'] = $this->model->getPendingTags();
        
        return $style;
    }
}
?>
