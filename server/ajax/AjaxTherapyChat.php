<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>

<?php

require_once __DIR__ . '/../../../../ajax/BaseAjax.php';
require_once __DIR__ . '/../service/TherapyMessageService.php';

/**
 * AJAX controller for therapy chat assignments.
 */
class AjaxTherapyChat extends BaseAjax
{
    protected $messageService;
    protected $services;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->services = $services;
        $this->messageService = new TherapyMessageService($services);
    }

    /**
     * Save therapist group assignments.
     *
     * @param array $post POST data containing targetUserId and selectedGroupIds
     * @return bool Success status
     */
    public function saveTherapistAssignments($post)
    {
        $targetUserId = (int)($post['targetUserId'] ?? 0);
        $selectedGroupIds = $post['selectedGroupIds'] ?? [];

        if (!$targetUserId || !is_array($selectedGroupIds)) {
            return false;
        }

        $groupIds = array_map('intval', $selectedGroupIds);

        // ACL check is done in has_access

        $success = $this->messageService->setTherapistAssignments($targetUserId, $groupIds);

        if ($success) {
            // Log transaction
            $adminId = $_SESSION['id_user'] ?? 0;
            $this->services->get_transaction()->add_transaction(
                'therapist_assignment_save',
                'by_user',
                $adminId,
                'users',
                $targetUserId,
                false,
                'Therapist assignments saved for user ' . $targetUserId . ' with groups: ' . implode(', ', $groupIds)
            );
        }

        return $success;
    }
}
?>
