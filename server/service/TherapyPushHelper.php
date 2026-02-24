<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

/**
 * Therapy Push Notification Helper
 *
 * Shared helper for scheduling push notifications via the SelfHelp
 * JobScheduler. Uses FCM via the core Notificaitoner job class.
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapyPushHelper
{
    /**
     * Schedule a push notification via the SelfHelp JobScheduler.
     *
     * @param object $db           Database service
     * @param object $jobScheduler JobScheduler service
     * @param string $title        Notification title
     * @param string $body         Notification body text
     * @param string $url          Deep-link URL (opened when notification tapped)
     * @param array  $recipientUserIds User IDs to notify
     * @param string $description  Log description
     * @return bool
     */
    public static function schedulePush($db, $jobScheduler, $title, $body, $url, $recipientUserIds = array(), $description = '')
    {
        if (empty($recipientUserIds)) return false;

        $pushData = array(
            "id_jobTypes" => $db->get_lookup_id_by_value(jobTypes, jobTypes_notification),
            "id_jobStatus" => $db->get_lookup_id_by_value(scheduledJobsStatus, scheduledJobsStatus_queued),
            "date_to_be_executed" => date('Y-m-d H:i:s'),
            "subject" => $title,
            "body" => strip_tags($body),
            "url" => $url,
            "recipients" => $recipientUserIds,
            "id_users" => $recipientUserIds,
            "description" => $description
        );

        try {
            $jobScheduler->schedule_job($pushData, transactionBy_by_system);
            return true;
        } catch (\Exception $e) {
            error_log("TherapyPushHelper: Failed to schedule push for users [" . implode(',', $recipientUserIds) . "]: " . $e->getMessage());
            return false;
        }
    }
}
