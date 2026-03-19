<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

/**
 * Therapy Email Helper
 *
 * Shared helper for scheduling email notifications via the SelfHelp
 * JobScheduler. Eliminates duplication across TherapyChatModel,
 * TherapistDashboardModel, and TherapyAlertService.
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapyEmailHelper
{
    /**
     * Schedule and immediately execute an email notification via the SelfHelp
     * JobScheduler. Uses add_and_execute_job() so the email is sent within
     * the current request instead of waiting for the cron queue.
     *
     * @param object $db         Database service
     * @param object $jobScheduler JobScheduler service
     * @param string $recipientEmail
     * @param string $subject
     * @param string $body       HTML body
     * @param string $fromEmail
     * @param string $fromName
     * @param string $description Log description
     * @param array  $recipientUserIds User IDs (for job tracking)
     * @return bool
     */
    public static function scheduleEmail($db, $jobScheduler, $recipientEmail, $subject, $body, $fromEmail = 'noreply@selfhelp.local', $fromName = 'Therapy Chat', $description = '', $recipientUserIds = array())
    {
        $mailData = array(
            "id_jobTypes" => $db->get_lookup_id_by_value(jobTypes, jobTypes_email),
            "id_jobStatus" => $db->get_lookup_id_by_value(scheduledJobsStatus, scheduledJobsStatus_queued),
            "date_to_be_executed" => date('Y-m-d H:i:s'),
            "from_email" => $fromEmail,
            "from_name" => $fromName,
            "reply_to" => $fromEmail,
            "recipient_emails" => $recipientEmail,
            "subject" => $subject,
            "body" => $body,
            "is_html" => 1,
            "description" => $description,
            "id_users" => $recipientUserIds,
            "attachments" => array()
        );

        try {
            $jobScheduler->add_and_execute_job($mailData, transactionBy_by_system);
            return true;
        } catch (\Exception $e) {
            error_log("TherapyEmailHelper: Failed to send email to $recipientEmail: " . $e->getMessage());
            return false;
        }
    }
}
