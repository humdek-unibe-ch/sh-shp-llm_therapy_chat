<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . '/TherapyChatService.php';

/**
 * Therapy Alert Service
 * 
 * Manages smart alerts and notifications for therapists.
 * Integrates with sh-shp-llm's danger detection for safety alerts.
 * 
 * Uses lookups table for alert types and severities via TherapyLookups constants.
 * 
 * Alert types (therapyAlertTypes):
 * - danger_detected: Triggered by LLM danger detection
 * - tag_received: When subject tags therapist
 * - high_activity: Unusual message frequency
 * - inactivity: Extended silence from subject
 * - new_message: New message notification
 * 
 * @package LLM Therapy Chat Plugin
 * @requires sh-shp-llm plugin
 */
class TherapyAlertService extends TherapyChatService
{
    /* Alert Creation *********************************************************/

    /**
     * Create an alert for a conversation
     *
     * @param int $conversationId
     * @param string $alertType Alert type lookup_code (THERAPY_ALERT_* constants)
     * @param string $message Alert message
     * @param string $severity Severity lookup_code (THERAPY_SEVERITY_* constants)
     * @param int|null $targetUserId Specific therapist (null = all in group)
     * @param array|null $metadata Additional data
     * @return int|bool Alert ID or false
     */
    public function createAlert($conversationId, $alertType, $message, $severity = THERAPY_SEVERITY_INFO, $targetUserId = null, $metadata = null)
    {
        // Validate alert type
        if (!in_array($alertType, THERAPY_VALID_ALERT_TYPES)) {
            return false;
        }

        // Validate severity
        if (!in_array($severity, THERAPY_VALID_SEVERITIES)) {
            $severity = THERAPY_SEVERITY_INFO;
        }

        // Get lookup IDs
        $alertTypeId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_ALERT_TYPES, $alertType);
        $severityId = $this->db->get_lookup_id_by_code(THERAPY_LOOKUP_ALERT_SEVERITY, $severity);

        if (!$alertTypeId) {
            return false;
        }

        $data = array(
            'id_llmConversations' => $conversationId,
            'id_users' => $targetUserId,
            'id_alertTypes' => $alertTypeId,
            'id_alertSeverity' => $severityId,
            'message' => $message,
            'metadata' => $metadata ? json_encode($metadata) : null
        );

        $alertId = $this->db->insert('therapyAlerts', $data);

        if ($alertId) {
            // Log transaction
            $conversation = $this->getTherapyConversation($conversationId);
            $this->logTransaction(
                transactionTypes_insert,
                'therapyAlerts',
                $alertId,
                $conversation ? $conversation['id_users'] : 0,
                "Alert created: $alertType ($severity)"
            );

            // Send notifications based on severity
            if ($severity === THERAPY_SEVERITY_CRITICAL || $severity === THERAPY_SEVERITY_EMERGENCY) {
                $this->sendUrgentNotification($alertId, $conversationId, $alertType, $message);
            }
        }

        return $alertId;
    }

    /**
     * Create a danger detection alert
     * 
     * Called when LLM danger detection triggers.
     *
     * @param int $conversationId
     * @param array $detectedKeywords
     * @param string $userMessage
     * @return int|bool
     */
    public function createDangerAlert($conversationId, $detectedKeywords, $userMessage)
    {
        $keywords = implode(', ', $detectedKeywords);
        $excerpt = mb_substr($userMessage, 0, 100) . (mb_strlen($userMessage) > 100 ? '...' : '');
        
        $message = "Danger keywords detected: $keywords\n\nMessage excerpt: \"$excerpt\"";
        
        $metadata = array(
            'detected_keywords' => $detectedKeywords,
            'message_excerpt' => $excerpt,
            'timestamp' => date('Y-m-d H:i:s')
        );

        // Also update conversation risk level
        $this->updateRiskLevel($conversationId, THERAPY_RISK_CRITICAL);

        return $this->createAlert(
            $conversationId,
            THERAPY_ALERT_DANGER,
            $message,
            THERAPY_SEVERITY_EMERGENCY,
            null, // Notify all therapists
            $metadata
        );
    }

    /**
     * Create a tag alert when subject tags therapist
     *
     * @param int $conversationId
     * @param int $tagId
     * @param int $therapistId
     * @param string|null $reason
     * @param string $urgency Urgency lookup_code
     * @return int|bool
     */
    public function createTagAlert($conversationId, $tagId, $therapistId, $reason = null, $urgency = THERAPY_URGENCY_NORMAL)
    {
        // Map urgency to severity
        $severity = THERAPY_SEVERITY_WARNING;
        if ($urgency === THERAPY_URGENCY_EMERGENCY) {
            $severity = THERAPY_SEVERITY_EMERGENCY;
        } elseif ($urgency === THERAPY_URGENCY_URGENT) {
            $severity = THERAPY_SEVERITY_CRITICAL;
        }

        $message = "You have been tagged by a patient";
        if ($reason) {
            $message .= ": \"$reason\"";
        }

        $metadata = array(
            'tag_id' => $tagId,
            'urgency' => $urgency,
            'reason' => $reason
        );

        return $this->createAlert(
            $conversationId,
            THERAPY_ALERT_TAG,
            $message,
            $severity,
            $therapistId,
            $metadata
        );
    }

    /* Alert Retrieval ********************************************************/

    /**
     * Get alerts for a therapist
     * Uses view_therapyAlerts for easy access to lookup values
     *
     * @param int $therapistId
     * @param array $filters (unread_only, alert_type, severity)
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getAlertsForTherapist($therapistId, $filters = array(), $limit = 50, $offset = 0)
    {
        // Get accessible conversation IDs
        $conversations = $this->getTherapyConversationsByTherapist($therapistId);
        
        if (empty($conversations)) {
            return array();
        }

        $conversationIds = array_column($conversations, 'id_llmConversations');
        $placeholders = implode(',', array_fill(0, count($conversationIds), '?'));

        $sql = "SELECT * FROM view_therapyAlerts
                WHERE id_llmConversations IN ($placeholders)
                AND (id_users IS NULL OR id_users = ?)";

        $params = $conversationIds;
        $params[] = $therapistId;

        // Apply filters
        if (isset($filters['unread_only']) && $filters['unread_only']) {
            $sql .= " AND is_read = 0";
        }

        if (!empty($filters['alert_type'])) {
            $sql .= " AND alert_type = ?";
            $params[] = $filters['alert_type'];
        }

        if (!empty($filters['severity'])) {
            $sql .= " AND severity = ?";
            $params[] = $filters['severity'];
        }

        // Order by severity priority
        $sql .= " ORDER BY
                    FIELD(severity, '" . THERAPY_SEVERITY_EMERGENCY . "', '" . THERAPY_SEVERITY_CRITICAL . "', '" . THERAPY_SEVERITY_WARNING . "', '" . THERAPY_SEVERITY_INFO . "'),
                    is_read ASC,
                    created_at DESC
                  LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        return $this->db->query_db($sql, $params);
    }

    /**
     * Get unread alert count for a therapist
     *
     * @param int $therapistId
     * @return int
     */
    public function getUnreadAlertCount($therapistId)
    {
        $conversations = $this->getTherapyConversationsByTherapist($therapistId);
        
        if (empty($conversations)) {
            return 0;
        }

        $conversationIds = array_column($conversations, 'id_llmConversations');
        $placeholders = implode(',', array_fill(0, count($conversationIds), '?'));

        $sql = "SELECT COUNT(*) as cnt FROM therapyAlerts 
                WHERE id_llmConversations IN ($placeholders)
                AND (id_users IS NULL OR id_users = ?)
                AND is_read = 0";

        $params = $conversationIds;
        $params[] = $therapistId;

        $result = $this->db->query_db_first($sql, $params);
        
        return intval($result['cnt'] ?? 0);
    }

    /**
     * Get alerts for a specific conversation
     * Uses view_therapyAlerts for easy access to lookup values
     *
     * @param int $conversationId
     * @param int $limit
     * @return array
     */
    public function getAlertsForConversation($conversationId, $limit = 20)
    {
        $sql = "SELECT * FROM view_therapyAlerts 
                WHERE id_llmConversations = :cid
                ORDER BY created_at DESC
                LIMIT " . (int)$limit;
        
        return $this->db->query_db($sql, array(':cid' => $conversationId));
    }

    /* Alert Management *******************************************************/

    /**
     * Mark an alert as read
     *
     * @param int $alertId
     * @param int $userId Reading user
     * @return bool
     */
    public function markAlertRead($alertId, $userId)
    {
        return $this->db->update_by_ids(
            'therapyAlerts',
            array(
                'is_read' => 1,
                'read_at' => date('Y-m-d H:i:s')
            ),
            array('id' => $alertId)
        );
    }

    /**
     * Mark all alerts as read for a therapist
     *
     * @param int $therapistId
     * @param int|null $conversationId Optional: limit to specific conversation
     * @return bool
     */
    public function markAllAlertsRead($therapistId, $conversationId = null)
    {
        $conversations = $conversationId 
            ? array(array('id_llmConversations' => $conversationId))
            : $this->getTherapyConversationsByTherapist($therapistId);
        
        if (empty($conversations)) {
            return true;
        }

        $conversationIds = array_column($conversations, 'id_llmConversations');
        $placeholders = implode(',', array_fill(0, count($conversationIds), '?'));

        $sql = "UPDATE therapyAlerts 
                SET is_read = 1, read_at = NOW()
                WHERE id_llmConversations IN ($placeholders)
                AND (id_users IS NULL OR id_users = ?)
                AND is_read = 0";

        $params = $conversationIds;
        $params[] = $therapistId;

        return $this->db->execute($sql, $params);
    }

    /* Notification Helpers ***************************************************/

    /**
     * Send urgent notification (email) for critical/emergency alerts
     *
     * @param int $alertId
     * @param int $conversationId
     * @param string $alertType
     * @param string $message
     */
    private function sendUrgentNotification($alertId, $conversationId, $alertType, $message)
    {
        $conversation = $this->getTherapyConversation($conversationId);
        
        if (!$conversation) {
            return;
        }

        // Get therapists to notify
        $therapists = $this->getTherapistsForGroup($conversation['id_groups']);
        
        if (empty($therapists)) {
            return;
        }

        try {
            $jobScheduler = $this->job_scheduler;
            
            $subject = "[URGENT] Therapy Chat Alert: " . ucfirst(str_replace('_', ' ', $alertType));
            
            $body = "## Urgent Alert Notification\n\n";
            $body .= "**Alert Type:** " . ucfirst(str_replace('_', ' ', $alertType)) . "\n\n";
            $body .= "**Subject:** " . ($conversation['subject_name'] ?? 'Unknown') . " (ID: {$conversation['id_users']})\n\n";
            $body .= "**Message:**\n\n$message\n\n";
            $body .= "---\n\n*Please review this conversation immediately.*";

            foreach ($therapists as $therapist) {
                if (!filter_var($therapist['email'], FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                $mailData = array(
                    'id_jobTypes' => $this->db->get_lookup_id_by_value('jobTypes', 'email'),
                    'id_jobStatus' => $this->db->get_lookup_id_by_value('scheduledJobsStatus', 'queued'),
                    'date_to_be_executed' => date('Y-m-d H:i:s'),
                    'recipient_emails' => $therapist['email'],
                    'subject' => $subject,
                    'body' => $body,
                    'is_html' => 0,
                    'description' => "Urgent therapy chat alert #$alertId"
                );

                $jobScheduler->add_and_execute_job($mailData, 'by_therapy_chat_plugin');
            }
        } catch (Exception $e) {
            error_log("TherapyAlertService: Failed to send urgent notification - " . $e->getMessage());
        }
    }
}
?>
