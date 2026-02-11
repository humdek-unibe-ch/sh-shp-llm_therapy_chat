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

        // When floating chat mode is enabled, the chat is accessed via the
        // floating icon/modal panel (rendered by the TherapyChatHooks hook).
        // We do NOT render the inline chat on the page to avoid duplication.
        if ($this->model->isFloatingChatEnabled()) {
            return;
        }

        $user_id = $this->model->getUserId();
        $section_id = $this->model->getSectionId();

        // Check if user is logged in
        if (!$user_id) {
            echo '<div class="alert alert-warning">'
               . '<i class="fa fa-exclamation-triangle"></i> '
               . 'Please log in to use the therapy chat.'
               . '</div>';
            return;
        }

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
     * Delegates to the model for the actual config data
     *
     * @return string JSON encoded config
     */
    public function getReactConfig()
    {
        return json_encode($this->model->getReactConfig());
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
