<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

/**
 * Therapist Dashboard Notification Helper
 *
 * Extracted from TherapistDashboardModel to keep files focused.
 * Contains email and push notification logic for patient message notifications.
 *
 * @package LLM Therapy Chat Plugin
 */
trait TherapistDashboardNotificationTrait
{
    /**
     * Send both email and push notifications to the patient.
     */
    protected function notifyPatientMessageChannels($conversationId, $therapistId, $messageContent)
    {
        $this->getNotificationService()->notifyPatientForTherapistMessage(
            $conversationId,
            $therapistId,
            $messageContent,
            true,
            true
        );
    }

    /**
     * Send email notification to patient when therapist sends a message.
     * Uses SelfHelp's JobScheduler to queue an email.
     *
     * @param int $conversationId
     * @param int $therapistId
     * @param string $messageContent
     */
    public function notifyPatientNewMessage($conversationId, $therapistId, $messageContent)
    {
        $this->getNotificationService()->notifyPatientForTherapistMessage(
            $conversationId,
            $therapistId,
            $messageContent,
            true,
            false
        );
    }

    /**
     * Send push notification to patient when therapist sends a message.
     *
     * @param int $conversationId
     * @param int $therapistId
     * @param string $messageContent
     */
    public function notifyPatientPush($conversationId, $therapistId, $messageContent)
    {
        $this->getNotificationService()->notifyPatientForTherapistMessage(
            $conversationId,
            $therapistId,
            $messageContent,
            false,
            true
        );
    }
}
