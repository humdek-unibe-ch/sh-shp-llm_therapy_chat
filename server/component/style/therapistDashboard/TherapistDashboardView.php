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
        $config = $this->model->getReactConfig();
        return json_encode($config);
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
