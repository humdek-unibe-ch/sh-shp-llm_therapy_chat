<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

/**
 * Therapist Dashboard Component
 * 
 * Dashboard for therapists to monitor and communicate with patients.
 * Features:
 * - Conversation list with filtering
 * - Real-time message viewing
 * - Alert management
 * - Tag acknowledgment
 * - Notes on conversations
 * - Control over AI/human-only modes
 * 
 * @package LLM Therapy Chat Plugin
 * @requires sh-shp-llm plugin
 */

require_once __DIR__ . "/TherapistDashboardModel.php";
require_once __DIR__ . "/TherapistDashboardView.php";
require_once __DIR__ . "/TherapistDashboardController.php";

class TherapistDashboardComponent extends BaseComponent
{
    /** @var TherapistDashboardModel */
    protected $model;

    /** @var TherapistDashboardView */
    protected $view;

    /** @var TherapistDashboardController */
    protected $controller;

    /**
     * Constructor
     *
     * @param object $services
     * @param int $id_section
     * @param array $params
     */
    public function __construct($services, $id_section, $params = array())
    {
        parent::__construct($services, $id_section, $params);
        
        $this->model = new TherapistDashboardModel($services, $id_section, $params);
        $this->controller = new TherapistDashboardController($services, $this->model);
        $this->view = new TherapistDashboardView($services, $this->model);
    }

    /**
     * Output the component
     *
     * @return string
     */
    public function output_content()
    {
        // Handle API requests
        if ($this->controller->isApiRequest()) {
            return $this->controller->handleApiRequest();
        }

        return $this->view->render();
    }

    /**
     * Get CSS classes
     *
     * @return string
     */
    public function get_css_class()
    {
        return 'therapist-dashboard-component';
    }
}
?>
