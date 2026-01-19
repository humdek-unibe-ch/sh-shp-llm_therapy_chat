<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

/**
 * Therapy Chat Component (Subject/Patient Interface)
 * 
 * This component provides the chat interface for subjects to communicate
 * with AI and their therapist. It leverages the sh-shp-llm plugin for
 * AI functionality while adding therapy-specific features.
 * 
 * Features:
 * - Real-time messaging with AI and therapist
 * - @mention tagging for therapist
 * - Danger word detection (via sh-shp-llm)
 * - Message history
 * - Polling for new messages
 * 
 * @package LLM Therapy Chat Plugin
 * @requires sh-shp-llm plugin
 */

require_once __DIR__ . "/TherapyChatModel.php";
require_once __DIR__ . "/TherapyChatView.php";
require_once __DIR__ . "/TherapyChatController.php";

class TherapyChatComponent extends BaseComponent
{
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
        $model = new TherapyChatModel($services, $id, $params, $id_page, $entry_record);
        $controller = new TherapyChatController($model);
        $view = new TherapyChatView($model, $controller);
        parent::__construct($model, $view, $controller);
    }

    /**
     * Output the component
     *
     * @return string HTML output
     */
    public function output_content()
    {
        // Handle API requests (AJAX)
        if ($this->controller->isApiRequest()) {
            return $this->controller->handleApiRequest();
        }

        // Render the chat interface
        return $this->view->render();
    }

    /**
     * Get custom CSS classes
     *
     * @return string
     */
    public function get_css_class()
    {
        $classes = array('therapy-chat-component');
        
        $customCss = $this->model->getFieldValue('css');
        if ($customCss) {
            $classes[] = $customCss;
        }

        return implode(' ', $classes);
    }
}
?>
