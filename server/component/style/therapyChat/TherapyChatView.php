<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/style/StyleView.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";
require_once __DIR__ . "/../TherapyViewHelper.php";

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
            $local = TherapyViewHelper::getCssPath(__DIR__);
        }
        return parent::get_css_includes($local);
    }

    /**
     * Get JS includes
     */
    public function get_js_includes($local = array())
    {
        if (empty($local)) {
            $local = TherapyViewHelper::getJsPath(__DIR__);
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
        $style['polling_interval'] = $this->model->getPollingInterval();

        // Floating button / tab configuration for mobile app.
        // Icon, label, position may be on the module config page rather than
        // the style fields. Try get_db_field first (style-level), then fall
        // back to the module config page via the services DB.
        $icon = $this->model->get_db_field('therapy_chat_floating_icon', '');
        $label = $this->model->get_db_field('therapy_chat_floating_label', '');
        $position = $this->model->get_db_field('therapy_chat_floating_position', '');

        if (empty($icon) || empty($label)) {
            try {
                $db = $this->model->get_services()->get_db();
                $configPage = $db->fetch_page_info('sh_module_llm_therapy_chat');
                if ($configPage) {
                    if (empty($icon) && !empty($configPage['therapy_chat_floating_icon'])) {
                        $icon = $configPage['therapy_chat_floating_icon'];
                    }
                    if (empty($label) && !empty($configPage['therapy_chat_floating_label'])) {
                        $label = $configPage['therapy_chat_floating_label'];
                    }
                    if (empty($position) && !empty($configPage['therapy_chat_floating_position'])) {
                        $position = $configPage['therapy_chat_floating_position'];
                    }
                }
            } catch (Exception $e) {
                // Module config not available; use defaults
            }
        }

        $style['chat_config'] = array(
            'icon' => $icon ?: 'fa-comments',
            'label' => $label ?: 'Chat',
            'position' => $position ?: 'bottom-right',
            'ai_enabled' => $this->model->isAIEnabled(),
            'ai_label' => $this->model->get_db_field('therapy_ai_label', 'AI Assistant'),
            'therapist_label' => $this->model->get_db_field('therapy_therapist_label', 'Therapist'),
        );
        
        return $style;
    }
}
?>
