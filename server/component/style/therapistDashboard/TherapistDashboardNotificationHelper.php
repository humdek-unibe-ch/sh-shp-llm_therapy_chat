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
 * Contains email notification logic for patient message notifications.
 *
 * @package LLM Therapy Chat Plugin
 */
trait TherapistDashboardNotificationTrait
{
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
        $enabled = (bool)$this->get_db_field('enable_patient_email_notification', '1');
        if (!$enabled) return;

        $conversation = $this->messageService->getTherapyConversation($conversationId);
        if (!$conversation) return;

        $patientId = $conversation['id_users'];
        $services = $this->get_services();
        $db = $services->get_db();
        $jobScheduler = $services->get_job_scheduler();

        // Get patient info
        $patient = $db->select_by_uid('users', $patientId);
        if (!$patient || empty($patient['email'])) return;

        // Get therapist info
        $therapist = $db->select_by_uid('users', $therapistId);
        $therapistName = $therapist ? $therapist['name'] : 'Your Therapist';

        // Get email configuration from style fields
        $subject = $this->get_db_field('patient_notification_email_subject', '[Therapy Chat] New message from your therapist');
        $body = $this->get_db_field('patient_notification_email_body',
            '<p>Hello @user_name,</p>' .
            '<p>You have received a new message from <strong>' . htmlspecialchars($therapistName) . '</strong> in your therapy chat.</p>' .
            '<p>Please log in to read and respond to the message.</p>' .
            '<p>Best regards,<br>Therapy Chat</p>'
        );

        // Replace placeholders
        $body = str_replace('@user_name', htmlspecialchars($patient['name'] ?? ''), $body);
        $body = str_replace('@therapist_name', htmlspecialchars($therapistName), $body);
        $subject = str_replace('@therapist_name', htmlspecialchars($therapistName), $subject);

        $fromEmail = $this->get_db_field('notification_from_email', 'noreply@selfhelp.local');
        $fromName = $this->get_db_field('notification_from_name', 'Therapy Chat');

        TherapyEmailHelper::scheduleEmail(
            $db,
            $jobScheduler,
            $patient['email'],
            $subject,
            $body,
            $fromEmail,
            $fromName,
            "Therapy Chat: therapist message notification to patient #" . $patientId,
            array($patientId)
        );
    }
}
