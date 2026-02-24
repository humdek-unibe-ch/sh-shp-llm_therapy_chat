<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . "/TherapyEmailHelper.php";
require_once __DIR__ . "/TherapyPushHelper.php";

/**
 * Shared notification service for therapy chat.
 *
 * Centralizes email/push composition and scheduling for:
 * - Patient -> Therapist notifications
 * - Therapist -> Patient notifications
 */
class TherapyNotificationService
{
    /** @var object */
    private $services;

    /** @var object */
    private $db;

    /** @var object */
    private $jobScheduler;

    /** @var TherapyMessageService */
    private $messageService;

    /** @var callable */
    private $fieldGetter;

    public function __construct($services, $messageService, callable $fieldGetter)
    {
        $this->services = $services;
        $this->db = $services->get_db();
        $this->jobScheduler = $services->get_job_scheduler();
        $this->messageService = $messageService;
        $this->fieldGetter = $fieldGetter;
    }

    /**
     * Notify therapists when a patient sends a message.
     */
    public function notifyTherapistsForPatientMessage($conversationId, $patientId, $messageContent, $isTag = false, $sendEmail = true, $sendPush = true)
    {
        $conversation = $this->messageService->getTherapyConversation($conversationId);
        if (!$conversation) {
            return;
        }

        $patient = $this->db->select_by_uid('users', $patientId);
        $patientName = $patient ? $patient['name'] : 'Patient';

        $therapists = $this->messageService->getTherapistsForPatient($patientId);
        if (empty($therapists)) {
            return;
        }

        if ($sendEmail && $this->isEnabled('enable_therapist_email_notification', '1')) {
            $subjectTemplate = $isTag
                ? $this->getField('therapist_tag_email_subject', '[Therapy Chat] @therapist tag from {{patient_name}}')
                : $this->getField('therapist_notification_email_subject', '[Therapy Chat] New message from {{patient_name}}');
            $bodyTemplate = $isTag
                ? $this->getField(
                    'therapist_tag_email_body',
                    '<p>Hello,</p><p><strong>{{patient_name}}</strong> has tagged you (@therapist) in their therapy chat.</p><p><em>Message preview:</em> {{message_preview}}</p><p>Please log in to the Therapist Dashboard to respond.</p>'
                )
                : $this->getField(
                    'therapist_notification_email_body',
                    '<p>Hello,</p><p>You have received a new message from <strong>{{patient_name}}</strong> in therapy chat.</p><p>Please log in to the Therapist Dashboard to review.</p>'
                );
            $fromEmail = $this->getField('notification_from_email', 'noreply@selfhelp.local');
            $fromName = $this->getField('notification_from_name', 'Therapy Chat');
            $preview = $this->buildPreview(
                $messageContent,
                defined('THERAPY_EMAIL_PREVIEW_LENGTH') ? THERAPY_EMAIL_PREVIEW_LENGTH : 200
            );

            foreach ($therapists as $therapist) {
                if (empty($therapist['email'])) {
                    continue;
                }

                $subject = $this->replaceTokens($subjectTemplate, array(
                    '{{patient_name}}' => htmlspecialchars($patientName),
                ));
                $body = $this->replaceTokens($bodyTemplate, array(
                    '{{patient_name}}' => htmlspecialchars($patientName),
                    '{{message_preview}}' => htmlspecialchars($preview),
                    '@user_name' => htmlspecialchars($therapist['name'] ?? ''),
                ));

                TherapyEmailHelper::scheduleEmail(
                    $this->db,
                    $this->jobScheduler,
                    $therapist['email'],
                    $subject,
                    $body,
                    $fromEmail,
                    $fromName,
                    ($isTag ? "Therapy Chat: tag" : "Therapy Chat: message") . " notification to therapist #" . $therapist['id'],
                    array($therapist['id'])
                );
            }
        }

        if ($sendPush && $this->isEnabled('enable_therapist_push_notification', '1')) {
            $titleTemplate = $isTag
                ? $this->getField('therapist_tag_push_notification_title', '@therapist tag from {{patient_name}}')
                : $this->getField('therapist_push_notification_title', 'New message from {{patient_name}}');
            $bodyTemplate = $isTag
                ? $this->getField('therapist_tag_push_notification_body', '{{patient_name}} has tagged you in therapy chat: {{message_preview}}')
                : $this->getField('therapist_push_notification_body', 'You have a new therapy chat message from {{patient_name}}. Tap to open.');

            $preview = $this->buildPreview($messageContent, 100);
            $title = $this->replaceTokens($titleTemplate, array(
                '{{patient_name}}' => $patientName,
            ));
            $body = $this->replaceTokens($bodyTemplate, array(
                '{{patient_name}}' => $patientName,
                '{{message_preview}}' => $preview,
            ));

            $recipientIds = array();
            foreach ($therapists as $therapist) {
                $recipientIds[] = (int)$therapist['id'];
            }

            TherapyPushHelper::schedulePush(
                $this->db,
                $this->jobScheduler,
                $title,
                $body,
                $this->resolvePageUrlByFieldName('therapy_chat_therapist_page'),
                $recipientIds,
                ($isTag ? "Therapy Chat: tag" : "Therapy Chat: message") . " push to therapists"
            );
        }
    }

    /**
     * Notify patient when therapist sends a message.
     */
    public function notifyPatientForTherapistMessage($conversationId, $therapistId, $messageContent, $sendEmail = true, $sendPush = true)
    {
        $conversation = $this->messageService->getTherapyConversation($conversationId);
        if (!$conversation) {
            return;
        }

        $patientId = $conversation['id_users'] ?? null;
        if (!$patientId) {
            return;
        }

        $patient = $this->db->select_by_uid('users', $patientId);
        if (!$patient) {
            return;
        }

        $therapist = $this->db->select_by_uid('users', $therapistId);
        $therapistName = $therapist ? $therapist['name'] : 'Your Therapist';

        if ($sendEmail && $this->isEnabled('enable_patient_email_notification', '1')) {
            if (!empty($patient['email'])) {
                $subjectTemplate = $this->getField('patient_notification_email_subject', '[Therapy Chat] New message from your therapist');
                $bodyTemplate = $this->getField(
                    'patient_notification_email_body',
                    '<p>Hello @user_name,</p><p>You have received a new message from <strong>@therapist_name</strong> in your therapy chat.</p><p>Please log in to read and respond to the message.</p><p>Best regards,<br>Therapy Chat</p>'
                );
                $subject = $this->replaceTokens($subjectTemplate, array(
                    '@therapist_name' => htmlspecialchars($therapistName),
                ));
                $body = $this->replaceTokens($bodyTemplate, array(
                    '@user_name' => htmlspecialchars($patient['name'] ?? ''),
                    '@therapist_name' => htmlspecialchars($therapistName),
                ));

                TherapyEmailHelper::scheduleEmail(
                    $this->db,
                    $this->jobScheduler,
                    $patient['email'],
                    $subject,
                    $body,
                    $this->getField('notification_from_email', 'noreply@selfhelp.local'),
                    $this->getField('notification_from_name', 'Therapy Chat'),
                    "Therapy Chat: therapist message notification to patient #" . $patientId,
                    array((int)$patientId)
                );
            }
        }

        if ($sendPush && $this->isEnabled('enable_patient_push_notification', '1')) {
            $titleTemplate = $this->getField('patient_push_notification_title', 'New message from your therapist');
            $bodyTemplate = $this->getField('patient_push_notification_body', 'Your therapist {{therapist_name}} sent you a new message. Tap to open.');
            $preview = $this->buildPreview($messageContent, 80);

            $title = $this->replaceTokens($titleTemplate, array(
                '{{therapist_name}}' => $therapistName,
            ));
            $body = $this->replaceTokens($bodyTemplate, array(
                '{{therapist_name}}' => $therapistName,
                '{{message_preview}}' => $preview,
            ));

            TherapyPushHelper::schedulePush(
                $this->db,
                $this->jobScheduler,
                $title,
                $body,
                $this->resolvePageUrlByFieldName('therapy_chat_subject_page'),
                array((int)$patientId),
                "Therapy Chat: therapist message push to patient #" . $patientId
            );
        }
    }

    private function getField($name, $defaultValue = '')
    {
        try {
            $value = call_user_func($this->fieldGetter, $name, $defaultValue);
            if ($value === null || $value === '') {
                return $defaultValue;
            }
            return $value;
        } catch (\Exception $e) {
            return $defaultValue;
        }
    }

    private function isEnabled($fieldName, $defaultValue = '1')
    {
        $value = $this->getField($fieldName, $defaultValue);
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private function buildPreview($messageContent, $limit)
    {
        $plain = strip_tags((string)$messageContent);
        $preview = mb_substr($plain, 0, $limit);
        if (mb_strlen($plain) > $limit) {
            $preview .= '...';
        }
        return $preview;
    }

    private function replaceTokens($template, $tokens)
    {
        return str_replace(array_keys($tokens), array_values($tokens), $template);
    }

    /**
     * Resolve a page URL from module field name.
     *
     * First attempts configured field getter, then falls back to direct DB lookup.
     */
    private function resolvePageUrlByFieldName($fieldName)
    {
        $pageId = $this->getField($fieldName, null);
        if (empty($pageId) || !is_numeric($pageId)) {
            try {
                $sql = "SELECT sft.content AS page_id
                        FROM sections_fields_translation sft
                        INNER JOIN fields f ON f.id = sft.id_fields
                        WHERE f.name = ?
                          AND sft.content IS NOT NULL
                          AND sft.content != ''
                          AND sft.content != '0'
                        LIMIT 1";
                $rows = $this->db->query_db($sql, array($fieldName));
                if (!empty($rows) && !empty($rows[0]['page_id'])) {
                    $pageId = $rows[0]['page_id'];
                }
            } catch (\Exception $e) {
                $pageId = null;
            }
        }

        if (empty($pageId) || !is_numeric($pageId)) {
            return '';
        }

        $pageInfo = $this->db->select_by_uid('pages', (int)$pageId);
        if (!$pageInfo || empty($pageInfo['keyword'])) {
            return '';
        }

        return '/' . $pageInfo['keyword'];
    }
}
?>
