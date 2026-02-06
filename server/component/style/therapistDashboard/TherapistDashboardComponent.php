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
        $model = new TherapistDashboardModel($services, $id, $params, $id_page, $entry_record);
        $controller = new TherapistDashboardController($model);
        $view = new TherapistDashboardView($model, $controller);
        parent::__construct($model, $view, $controller);
    }

    /**
     * Output the component.
     * API requests are handled by the controller in its constructor.
     * For normal page rendering, delegate to BaseComponent which calls view->output_content().
     */
    public function output_content()
    {
        parent::output_content();
    }
}
?>
