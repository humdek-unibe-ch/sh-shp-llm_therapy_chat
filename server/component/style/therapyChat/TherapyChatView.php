<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/style/StyleView.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";

/**
 * Therapy Chat View
 * 
 * Renders the therapy chat interface for subjects/patients.
 * Uses React for the frontend - configuration is passed via JSON.
 * 
 * @package LLM Therapy Chat Plugin
 */
class TherapyChatView extends StyleView
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
     * Render the therapy chat component
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

        // Get conversation data
        $conversation = $this->model->getOrCreateConversation();
        $conversation_id = $conversation ? $conversation['id'] : null;

        // Include the template
        include __DIR__ . '/tpl/therapy_chat_main.php';
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
        $conversation = $this->model->getOrCreateConversation();
        
        return json_encode([
            // Core identifiers
            'userId' => $this->model->getUserId(),
            'sectionId' => $this->model->getSectionId(),
            'conversationId' => $conversation ? $conversation['id'] : null,
            'groupId' => $this->model->getGroupId(),
            
            // Conversation state
            'conversationMode' => $conversation ? $conversation['mode'] : THERAPY_MODE_AI_HYBRID,
            'aiEnabled' => $conversation ? (bool)$conversation['ai_enabled'] : true,
            'riskLevel' => $conversation ? $conversation['risk_level'] : THERAPY_RISK_LOW,
            
            // Feature flags
            'isSubject' => $this->model->isSubject(),
            'taggingEnabled' => $this->model->isTaggingEnabled(),
            'dangerDetectionEnabled' => $this->model->isDangerDetectionEnabled(),
            
            // Polling configuration
            'pollingInterval' => $this->model->getPollingInterval() * 1000, // Convert to ms
            
            // UI Labels
            'labels' => $this->model->getLabels(),
            
            // Tag reasons for quick selection
            'tagReasons' => $this->getTagReasons(),
            
            // LLM Configuration
            'configuredModel' => $this->model->getLlmModel(),
        ]);
    }

    /**
     * Get predefined tag reasons
     *
     * @return array
     */
    private function getTagReasons()
    {
        $labels = $this->model->getLabels();
        return $labels['tag_reasons'] ?? [
            ['key' => 'overwhelmed', 'label' => 'I am feeling overwhelmed', 'urgency' => THERAPY_URGENCY_NORMAL],
            ['key' => 'need_talk', 'label' => 'I need to talk soon', 'urgency' => THERAPY_URGENCY_URGENT],
            ['key' => 'urgent', 'label' => 'This feels urgent', 'urgency' => THERAPY_URGENCY_URGENT],
            ['key' => 'emergency', 'label' => 'Emergency - please respond immediately', 'urgency' => THERAPY_URGENCY_EMERGENCY]
        ];
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
        
        // Add therapy chat specific data
        $conversation = $this->model->getOrCreateConversation();
        if ($conversation) {
            $style['conversation'] = $conversation;
            $style['messages'] = $this->model->getMessages();
        }
        
        $style['user_id'] = $this->model->getUserId();
        $style['section_id'] = $this->model->getSectionId();
        $style['is_subject'] = $this->model->isSubject();
        $style['tagging_enabled'] = $this->model->isTaggingEnabled();
        $style['labels'] = $this->model->getLabels();
        
        return $style;
    }
}
?>
