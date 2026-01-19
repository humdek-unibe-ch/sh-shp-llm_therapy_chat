<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . "/../../../service/TherapyTaggingService.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";

/**
 * Therapist Dashboard Model
 *
 * Data model for the therapist dashboard.
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapistDashboardModel extends StyleModel
{
    /** @var TherapyTaggingService */
    private $therapyService;

    /** @var int|null */
    private $userId;

    /** @var int|null */
    private $selectedGroupId;

    /** @var int|null */
    private $selectedSubjectId;

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
        parent::__construct($services, $id, $params, $id_page, $entry_record);

        $this->therapyService = new TherapyTaggingService($services);
        $this->userId = $_SESSION['id_user'] ?? null;
        $this->selectedGroupId = $params['gid'] ?? null;
        $this->selectedSubjectId = $params['uid'] ?? null;
    }

    /* Access Control *********************************************************/

    /**
     * Check if current user has access
     *
     * @return bool
     */
    public function hasAccess()
    {
        if (!$this->userId) {
            return false;
        }

        return $this->therapyService->isTherapist($this->userId);
    }

    /* Data Access ************************************************************/

    /**
     * Get all conversations for this therapist
     *
     * @param array $filters
     * @return array
     */
    public function getConversations($filters = array())
    {
        if ($this->selectedGroupId) {
            $filters['group_id'] = $this->selectedGroupId;
        }

        return $this->therapyService->getTherapyConversationsByTherapist(
            $this->userId,
            $filters,
            100,
            0
        );
    }

    /**
     * Get selected conversation
     *
     * @return array|null
     */
    public function getSelectedConversation()
    {
        if (!$this->selectedSubjectId) {
            return null;
        }

        // Find conversation by subject ID
        $conversations = $this->getConversations();
        
        foreach ($conversations as $conv) {
            if ($conv['id_users'] == $this->selectedSubjectId) {
                return $conv;
            }
        }

        return null;
    }

    /**
     * Get conversation by ID
     *
     * @param int $conversationId
     * @return array|null
     */
    public function getConversationById($conversationId)
    {
        return $this->therapyService->getTherapyConversation($conversationId);
    }

    /**
     * Get messages for a conversation
     *
     * @param int $conversationId
     * @param int $limit
     * @param int|null $afterId
     * @return array
     */
    public function getMessages($conversationId, $limit = 100, $afterId = null)
    {
        return $this->therapyService->getTherapyMessages($conversationId, $limit, $afterId);
    }

    /**
     * Get alerts for this therapist
     *
     * @param array $filters
     * @return array
     */
    public function getAlerts($filters = array())
    {
        return $this->therapyService->getAlertsForTherapist($this->userId, $filters);
    }

    /**
     * Get unread alert count
     *
     * @return int
     */
    public function getUnreadAlertCount()
    {
        return $this->therapyService->getUnreadAlertCount($this->userId);
    }

    /**
     * Get pending tags
     *
     * @return array
     */
    public function getPendingTags()
    {
        return $this->therapyService->getPendingTagsForTherapist($this->userId);
    }

    /**
     * Get therapist statistics
     *
     * @return array
     */
    public function getStats()
    {
        return $this->therapyService->getTherapistStats($this->userId);
    }

    /**
     * Get notes for a conversation
     *
     * @param int $conversationId
     * @return array
     */
    public function getNotes($conversationId)
    {
        $sql = "SELECT tn.*, u.name as author_name
                FROM therapyNotes tn
                INNER JOIN users u ON u.id = tn.id_users
                WHERE tn.id_llmConversations = :cid
                ORDER BY tn.created_at DESC";
        
        return $this->db->query_db($sql, array(':cid' => $conversationId));
    }

    /* Service Access *********************************************************/

    /**
     * Get therapy service
     *
     * @return TherapyTaggingService
     */
    public function getTherapyService()
    {
        return $this->therapyService;
    }

    /**
     * Get user ID
     *
     * @return int|null
     */
    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * Get selected group ID
     *
     * @return int|null
     */
    public function getSelectedGroupId()
    {
        return $this->selectedGroupId;
    }

    /**
     * Get selected subject ID
     *
     * @return int|null
     */
    public function getSelectedSubjectId()
    {
        return $this->selectedSubjectId;
    }

    /**
     * Get section ID
     *
     * @return int
     */
    public function getSectionId()
    {
        return $this->section_id;
    }
}
?>
