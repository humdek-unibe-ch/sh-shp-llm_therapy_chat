<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . "/../../../service/TherapyPushHelper.php";

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

    /**
     * Send push notification to patient when therapist sends a message.
     *
     * @param int $conversationId
     * @param int $therapistId
     * @param string $messageContent
     */
    public function notifyPatientPush($conversationId, $therapistId, $messageContent)
    {
        $enabled = (bool)$this->get_db_field('enable_patient_push_notification', '1');
        if (!$enabled) return;

        $conversation = $this->messageService->getTherapyConversation($conversationId);
        if (!$conversation) return;

        $patientId = $conversation['id_users'];
        if (!$patientId) return;

        $services = $this->get_services();
        $db = $services->get_db();
        $jobScheduler = $services->get_job_scheduler();

        // Get therapist info for placeholders
        $therapist = $db->select_by_uid('users', $therapistId);
        $therapistName = $therapist ? $therapist['name'] : 'Your Therapist';

        // Get configurable title and body
        $title = $this->get_db_field('patient_push_notification_title', 'New message from your therapist');
        $body = $this->get_db_field('patient_push_notification_body',
            'Your therapist {{therapist_name}} sent you a new message. Tap to open.');

        // Message preview (first 80 chars, strip tags)
        $preview = mb_substr(strip_tags($messageContent), 0, 80);
        if (mb_strlen(strip_tags($messageContent)) > 80) {
            $preview .= '...';
        }

        // Replace placeholders
        $title = str_replace('{{therapist_name}}', $therapistName, $title);
        $body = str_replace('{{therapist_name}}', $therapistName, $body);
        $body = str_replace('{{message_preview}}', $preview, $body);

        $chatUrl = $this->getChatPageUrl($db, 'therapy_chat_subject_page');

        TherapyPushHelper::schedulePush(
            $db,
            $jobScheduler,
            $title,
            $body,
            $chatUrl,
            array($patientId),
            "Therapy Chat: therapist message push to patient #" . $patientId
        );
    }

    /**
     * Look up a chat page URL from the module config fields.
     *
     * Fields like therapy_chat_subject_page / therapy_chat_therapist_page
     * store a page ID in sections_fields_translation. We resolve the
     * page keyword from there to build a URL the mobile app can open.
     *
     * @param object $db
     * @param string $fieldName  e.g. 'therapy_chat_subject_page'
     * @return string  e.g. '/therapyChatSubject' or ''
     */
    private function getChatPageUrl($db, $fieldName)
    {
        try {
            $sql = "SELECT sft.content AS page_id
                    FROM sections_fields_translation sft
                    INNER JOIN fields f ON f.id = sft.id_fields
                    WHERE f.name = ?
                    AND sft.content IS NOT NULL AND sft.content != '' AND sft.content != '0'
                    LIMIT 1";
            $result = $db->query_db($sql, array($fieldName));
            if (!empty($result) && !empty($result[0]['page_id'])) {
                $pageInfo = $db->select_by_uid('pages', $result[0]['page_id']);
                if ($pageInfo && isset($pageInfo['keyword'])) {
                    return '/' . $pageInfo['keyword'];
                }
            }
        } catch (\Exception $e) {}

        return '';
    }
}
