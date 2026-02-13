<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . '/TherapyChatService.php';
require_once __DIR__ . '/TherapyEmailHelper.php';

/**
 * Therapy Alert Service
 *
 * Manages alerts and notifications for therapists.
 * Tags (patient @mentions) are now alerts with type='tag_received' and metadata JSON.
 *
 * Extends TherapyChatService for conversation/access control.
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapyAlertService extends TherapyChatService
{
    public function __construct($services)
    {
        parent::__construct($services);
    }

    /* =========================================================================
     * ALERT CREATION
     * ========================================================================= */

    /**
     * Create an alert.
     *
     * @param int $llmConversationId llmConversations.id (NOT therapyConversationMeta.id)
     * @param string $alertType THERAPY_ALERT_* constant
     * @param string $message Human-readable description
     * @param string $severity THERAPY_SEVERITY_* constant
     * @param int|null $targetUserId Specific therapist (NULL = all assigned)
     * @param array|null $metadata Extra data
     * @return int|bool Alert ID or false
     */
    public function createAlert($llmConversationId, $alertType, $message, $severity = THERAPY_SEVERITY_INFO, $targetUserId = null, $metadata = null, $extraNotificationEmails = '')
    {
        if (!in_array($alertType, THERAPY_VALID_ALERT_TYPES)) {
            return false;
        }
        if (!in_array($severity, THERAPY_VALID_SEVERITIES)) {
            $severity = THERAPY_SEVERITY_INFO;
        }

        $alertTypeId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_ALERT_TYPES, $alertType);
        $severityId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_ALERT_SEVERITY, $severity);

        if (!$alertTypeId) {
            return false;
        }

        $data = array(
            'id_llmConversations' => $llmConversationId,
            'id_users' => $targetUserId,
            'id_alertTypes' => $alertTypeId,
            'id_alertSeverity' => $severityId,
            'message' => $message,
            'metadata' => $metadata ? json_encode($metadata) : null
        );

        $alertId = $this->db->insert('therapyAlerts', $data);

        if ($alertId && ($severity === THERAPY_SEVERITY_CRITICAL || $severity === THERAPY_SEVERITY_EMERGENCY)) {
            $this->sendUrgentNotification($alertId, $llmConversationId, $alertType, $message, $extraNotificationEmails);
        }

        return $alertId;
    }

    /**
     * Create a danger detection alert.
     *
     * @param int $conversationId therapyConversationMeta.id
     * @param array $detectedKeywords
     * @param string $userMessage
     * @param string $extraNotificationEmails Comma-separated emails (from danger_notification_emails CMS field)
     * @return int|bool
     */
    public function createDangerAlert($conversationId, $detectedKeywords, $userMessage, $extraNotificationEmails = '')
    {
        $conversation = $this->getTherapyConversation($conversationId);
        if (!$conversation) {
            return false;
        }

        $keywords = implode(', ', $detectedKeywords);
        $excerpt = mb_substr($userMessage, 0, 100) . (mb_strlen($userMessage) > 100 ? '...' : '');

        $message = "Danger keywords detected: $keywords\nFull message: \"$userMessage\"";

        $metadata = array(
            'detected_keywords' => $detectedKeywords,
            'message_excerpt' => $excerpt
        );

        // Elevate risk to critical
        $this->updateRiskLevel($conversationId, THERAPY_RISK_CRITICAL);

        return $this->createAlert(
            $conversation['id_llmConversations'],
            THERAPY_ALERT_DANGER,
            $message,
            THERAPY_SEVERITY_EMERGENCY,
            null,
            $metadata,
            $extraNotificationEmails
        );
    }

    /**
     * Create a tag alert when patient @mentions a therapist.
     *
     * @param int $llmConversationId
     * @param int|null $therapistId Specific therapist (NULL = all assigned)
     * @param string|null $reason Tag reason key
     * @param string $urgency normal/urgent/emergency
     * @param int|null $messageId The message that contained the tag
     * @return int|bool
     */
    public function createTagAlert($llmConversationId, $therapistId = null, $reason = null, $urgency = THERAPY_URGENCY_NORMAL, $messageId = null)
    {
        // Map urgency to severity
        $severity = THERAPY_SEVERITY_WARNING;
        if ($urgency === THERAPY_URGENCY_EMERGENCY) {
            $severity = THERAPY_SEVERITY_EMERGENCY;
        } elseif ($urgency === THERAPY_URGENCY_URGENT) {
            $severity = THERAPY_SEVERITY_CRITICAL;
        }

        $message = "Patient tagged therapist";
        if ($reason) {
            $message .= ": \"$reason\"";
        }

        $metadata = array(
            'urgency' => $urgency,
            'reason' => $reason,
            'message_id' => $messageId
        );

        return $this->createAlert(
            $llmConversationId,
            THERAPY_ALERT_TAG,
            $message,
            $severity,
            $therapistId,
            $metadata
        );
    }

    /* =========================================================================
     * ALERT RETRIEVAL
     * ========================================================================= */

    /**
     * Get alerts for a therapist (across all their conversations).
     *
     * @param int $therapistId
     * @param array $filters (unread_only, alert_type, severity)
     * @param int $limit
     * @return array
     */
    public function getAlertsForTherapist($therapistId, $filters = array(), $limit = 50)
    {
        $conversations = $this->getTherapyConversationsByTherapist($therapistId, array(), THERAPY_STATS_LIMIT, 0);
        if (empty($conversations)) {
            return array();
        }

        $llmIds = array_column($conversations, 'id_llmConversations');
        $placeholders = implode(',', array_fill(0, count($llmIds), '?'));

        $sql = "SELECT * FROM view_therapyAlerts
                WHERE id_llmConversations IN ($placeholders)
                AND (id_users IS NULL OR id_users = ?)";

        $params = array_merge($llmIds, array($therapistId));

        if (!empty($filters['unread_only'])) {
            $sql .= " AND is_read = 0";
        }
        if (!empty($filters['alert_type'])) {
            $sql .= " AND alert_type = ?";
            $params[] = $filters['alert_type'];
        }

        $sql .= " ORDER BY
                    FIELD(severity, '" . THERAPY_SEVERITY_EMERGENCY . "', '" . THERAPY_SEVERITY_CRITICAL . "', '" . THERAPY_SEVERITY_WARNING . "', '" . THERAPY_SEVERITY_INFO . "'),
                    is_read ASC, created_at DESC
                  LIMIT " . (int)$limit;

        $result = $this->db->query_db($sql, $params);
        return $result !== false ? $result : array();
    }

    /**
     * Get unread alert count for a therapist.
     *
     * @param int $therapistId
     * @return int
     */
    public function getUnreadAlertCount($therapistId)
    {
        $conversations = $this->getTherapyConversationsByTherapist($therapistId, array(), THERAPY_STATS_LIMIT, 0);
        if (empty($conversations)) {
            return 0;
        }

        $llmIds = array_column($conversations, 'id_llmConversations');
        $placeholders = implode(',', array_fill(0, count($llmIds), '?'));

        $sql = "SELECT COUNT(*) as cnt FROM therapyAlerts
                WHERE id_llmConversations IN ($placeholders)
                AND (id_users IS NULL OR id_users = ?)
                AND is_read = 0";

        $params = array_merge($llmIds, array($therapistId));
        $result = $this->db->query_db_first($sql, $params);

        return intval($result['cnt'] ?? 0);
    }

    /* =========================================================================
     * ALERT MANAGEMENT
     * ========================================================================= */

    /**
     * Mark an alert as read.
     *
     * @param int $alertId
     * @return bool
     */
    public function markAlertRead($alertId)
    {
        return $this->db->update_by_ids(
            'therapyAlerts',
            array('is_read' => 1, 'read_at' => date('Y-m-d H:i:s')),
            array('id' => $alertId)
        );
    }

    /**
     * Mark all alerts as read for a therapist in a conversation.
     *
     * Accepts either therapyConversationMeta.id or no conversation filter.
     * Resolves to llmConversations.id internally since therapyAlerts stores
     * id_llmConversations.
     *
     * @param int $therapistId
     * @param int|null $conversationId therapyConversationMeta.id (or null for all)
     * @return bool
     */
    public function markAllAlertsRead($therapistId, $conversationId = null)
    {
        if ($conversationId) {
            // Resolve therapyConversationMeta.id → llmConversations.id
            $conversation = $this->getTherapyConversation($conversationId);
            if (!$conversation || empty($conversation['id_llmConversations'])) {
                return false;
            }
            $sql = "UPDATE therapyAlerts SET is_read = 1, read_at = NOW()
                    WHERE id_llmConversations = ? AND (id_users IS NULL OR id_users = ?) AND is_read = 0";
            $this->db->query_db($sql, array($conversation['id_llmConversations'], $therapistId));
        } else {
            // Mark ALL unread alerts for this therapist across all conversations
            $conversations = $this->getTherapyConversationsByTherapist($therapistId, array(), THERAPY_STATS_LIMIT, 0);
            if (empty($conversations)) {
                return true;
            }
            $llmIds = array_column($conversations, 'id_llmConversations');
            $placeholders = implode(',', array_fill(0, count($llmIds), '?'));
            $sql = "UPDATE therapyAlerts SET is_read = 1, read_at = NOW()
                    WHERE id_llmConversations IN ($placeholders)
                    AND (id_users IS NULL OR id_users = ?) AND is_read = 0";
            $params = array_merge($llmIds, array($therapistId));
            $this->db->query_db($sql, $params);
        }
        return true;
    }

    /* =========================================================================
     * PRIVATE HELPERS
     * ========================================================================= */

    /**
     * Send urgent email notification for critical/emergency alerts.
     * Emails are sent to:
     *   1. All assigned therapists for the patient
     *   2. Extra notification emails from the CMS danger_notification_emails field
     *
     * @param int $alertId
     * @param int $llmConversationId
     * @param string $alertType
     * @param string $message
     * @param string $extraNotificationEmails Comma-separated extra email addresses
     */
    private function sendUrgentNotification($alertId, $llmConversationId, $alertType, $message, $extraNotificationEmails = '')
    {
        try {
            // Get patient info
            $sql = "SELECT lc.id_users, u.name, u.email as patient_email FROM llmConversations lc
                    INNER JOIN users u ON u.id = lc.id_users
                    WHERE lc.id = ?";
            $patient = $this->db->query_db_first($sql, array($llmConversationId));
            if (!$patient) {
                error_log("TherapyAlertService: No patient found for llmConversation #$llmConversationId");
                return;
            }

            $patientName = htmlspecialchars($patient['name']);
            $subject = "[URGENT] Therapy Chat Alert – $patientName: " . ucfirst(str_replace('_', ' ', $alertType));
            $body = "<h2>Urgent Therapy Alert</h2>"
                . "<table style='border-collapse:collapse; width:100%; margin-bottom:16px;'>"
                . "<tr><td style='padding:8px; border:1px solid #ddd; font-weight:bold; width:140px;'>Patient</td>"
                . "<td style='padding:8px; border:1px solid #ddd;'>$patientName</td></tr>"
                . "<tr><td style='padding:8px; border:1px solid #ddd; font-weight:bold;'>Alert Type</td>"
                . "<td style='padding:8px; border:1px solid #ddd;'>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $alertType))) . "</td></tr>"
                . "<tr><td style='padding:8px; border:1px solid #ddd; font-weight:bold;'>Details</td>"
                . "<td style='padding:8px; border:1px solid #ddd;'>" . nl2br(htmlspecialchars($message)) . "</td></tr>"
                . "</table>"
                . "<p><strong>Please review this conversation immediately.</strong></p>"
                . "<p style='color:#666; font-size:0.9em;'>This alert was generated by the automated safety monitoring system. "
                . "Keyword detection uses word-boundary matching to reduce false positives.</p>";

            // Collect all recipient emails (therapists + extra configured addresses)
            $recipientEmails = array();

            // 1. Get therapists for this patient
            $therapists = $this->getTherapistsForPatient($patient['id_users']);
            foreach ($therapists as $therapist) {
                if (!empty($therapist['email']) && filter_var($therapist['email'], FILTER_VALIDATE_EMAIL)) {
                    $recipientEmails[$therapist['email']] = array(
                        'email' => $therapist['email'],
                        'id_users' => array($therapist['id']),
                        'description' => "Urgent therapy alert #$alertId for patient: " . $patient['name'] . " (therapist)"
                    );
                }
            }

            // 2. Add extra notification emails from danger_notification_emails CMS field
            if (!empty($extraNotificationEmails)) {
                $extraEmails = array_filter(array_map('trim', preg_split('/[,;\n]+/', $extraNotificationEmails)));
                foreach ($extraEmails as $extraEmail) {
                    if (filter_var($extraEmail, FILTER_VALIDATE_EMAIL) && !isset($recipientEmails[$extraEmail])) {
                        $recipientEmails[$extraEmail] = array(
                            'email' => $extraEmail,
                            'id_users' => array(),
                            'description' => "Urgent therapy alert #$alertId for patient: " . $patient['name'] . " (configured notification)"
                        );
                    }
                }
            }

            if (empty($recipientEmails)) {
                error_log("TherapyAlertService: No recipients found for urgent notification (patient #" . $patient['id_users'] . ")");
                return;
            }

            // Send to all recipients
            foreach ($recipientEmails as $recipient) {
                TherapyEmailHelper::scheduleEmail(
                    $this->db,
                    $this->job_scheduler,
                    $recipient['email'],
                    $subject,
                    $body,
                    'noreply@selfhelp.local',
                    'Therapy Chat',
                    $recipient['description'],
                    $recipient['id_users']
                );
            }
        } catch (Exception $e) {
            error_log("TherapyAlertService: Failed to send urgent notification - " . $e->getMessage());
        }
    }
}
?>
