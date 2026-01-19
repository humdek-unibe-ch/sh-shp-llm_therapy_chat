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
    /** @var TherapyChatModel */
    protected $model;

    /** @var TherapyChatView */
    protected $view;

    /** @var TherapyChatController */
    protected $controller;

    /**
     * Constructor
     *
     * @param object $services SelfHelp services container
     * @param int $id_section Section ID
     * @param array $params URL parameters
     */
    public function __construct($services, $id_section, $params = array())
    {
        parent::__construct($services, $id_section, $params);
        
        $this->model = new TherapyChatModel($services, $id_section, $params);
        $this->controller = new TherapyChatController($services, $this->model);
        $this->view = new TherapyChatView($services, $this->model);
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
