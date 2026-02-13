<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

/**
 * Floating Chat Hooks Trait
 *
 * Hook implementations for the floating therapy chat icon and modal.
 * Used by TherapyChatHooks.
 *
 * @package LLM Therapy Chat Plugin
 */
trait FloatingChatHooksTrait
{
    /**
     * Output floating therapy chat icon next to user profile.
     *
     * For subjects: when enable_floating_chat is enabled on the therapyChat
     * style, the button opens an inline modal containing the React chat
     * instead of navigating to the page. Otherwise it navigates normally.
     *
     * For therapists: always navigates to the therapist dashboard page.
     */
    public function outputTherapyChatIcon($args = null)
    {
        $userId = $_SESSION['id_user'] ?? null;
        if (!$userId) return;

        // Don't show in CMS admin
        if ($this->isCmsPage()) return;

        $isSubject = $this->messageService->isSubject($userId);
        $isTherapist = $this->messageService->isTherapist($userId);

        // Additional group check from config
        $subjectGroupId = $this->getConfigValue('therapy_chat_subject_group');
        $therapistGroupId = $this->getConfigValue('therapy_chat_therapist_group');

        $isInSubjectGroup = $subjectGroupId && $this->isUserInGroup($userId, $subjectGroupId);
        $isInTherapistGroup = $therapistGroupId && $this->isUserInGroup($userId, $therapistGroupId);

        if (!$isSubject || !$isInSubjectGroup) $isSubject = false;
        if (!$isTherapist || !$isInTherapistGroup) $isTherapist = false;

        if (!$isSubject && !$isTherapist) return;

        // Resolve URLs
        $subjectPageId = $this->getConfigValue('therapy_chat_subject_page');
        $therapistPageId = $this->getConfigValue('therapy_chat_therapist_page');

        $subjectPageUrl = $this->getPageUrl($subjectPageId, 'home');
        $therapistPageUrl = $this->getPageUrl($therapistPageId, 'home');

        // Check if floating modal mode is enabled for subjects
        $enableFloatingModal = false;
        $floatingModalConfig = '';
        if ($isSubject) {
            $enableFloatingModal = $this->isFloatingChatModalEnabled();
            if ($enableFloatingModal) {
                $floatingModalConfig = $this->buildFloatingModalConfig($userId, $subjectPageUrl);
            }
        }

        if ($isTherapist) {
            // For therapists: count alerts + patient messages only (exclude AI)
            $unreadCount = $this->messageService->getUnreadAlertCount($userId)
                + $this->messageService->getUnreadCountForUser($userId, true);
            $chatUrl = $therapistPageUrl;
            $iconTitle = 'Therapist Dashboard';
        } else {
            $unreadCount = $this->messageService->getUnreadCountForUser($userId);
            $chatUrl = $subjectPageUrl;
            $iconTitle = 'Therapy Chat';
        }

        // Config
        $icon = $this->getConfigValue('therapy_chat_floating_icon', 'fa-comments');
        $position = $this->getConfigValue('therapy_chat_floating_position', 'bottom-right');
        $label = $this->getConfigValue('therapy_chat_floating_label', '');

        $badgeClass = $unreadCount > 0 ? 'badge-danger' : 'badge-secondary';
        $badgeHtml = $unreadCount > 0 ? "<span class=\"badge $badgeClass badge-pill position-absolute therapy-chat-badge\">$unreadCount</span>" : "<span class=\"badge badge-secondary badge-pill position-absolute therapy-chat-badge\" style=\"display:none\"></span>";
        $positionCss = $this->getPositionCss($position);

        // Build polling config for the floating icon JS (works for both modes).
        $pollSectionId = $isTherapist
            ? $this->getSectionIdForStyle('therapistDashboard')
            : $this->getTherapyChatSectionId();
        $pollConfig = json_encode(array(
            'role' => $isTherapist ? 'therapist' : 'subject',
            'baseUrl' => $chatUrl,
            'sectionId' => $pollSectionId,
            'interval' => 3000 // 3 seconds
        ));

        include __DIR__ . '/tpl/floating_chat_icon.php';
    }

    /**
     * Check if the floating chat modal mode is enabled.
     */
    private function isFloatingChatModalEnabled()
    {
        try {
            $sql = "SELECT sft.content
                    FROM sections_fields_translation sft
                    INNER JOIN sections s ON sft.id_sections = s.id
                    INNER JOIN styles st ON s.id_styles = st.id
                    INNER JOIN fields f ON sft.id_fields = f.id
                    WHERE st.name = 'therapyChat' AND f.name = 'enable_floating_chat'
                    LIMIT 1";
            $result = $this->db->query_db_first($sql);
            if ($result && isset($result['content'])) {
                return ($result['content'] === '1' || $result['content'] === 1);
            }

            $sql = "SELECT sf.default_value
                    FROM styles_fields sf
                    INNER JOIN styles s ON sf.id_styles = s.id
                    INNER JOIN fields f ON sf.id_fields = f.id
                    WHERE s.name = 'therapyChat' AND f.name = 'enable_floating_chat'
                    LIMIT 1";
            $result = $this->db->query_db_first($sql);
            return $result && ($result['default_value'] === '1' || $result['default_value'] === 1);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Build the React config JSON for the floating modal chat.
     */
    private function buildFloatingModalConfig($userId, $chatUrl)
    {
        try {
            $sectionId = $this->getTherapyChatSectionId();
            return json_encode(array(
                'userId' => (int)$userId,
                'sectionId' => $sectionId ? (int)$sectionId : null,
                'baseUrl' => $chatUrl,
                'isFloatingMode' => true,
            ));
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Get the section ID for the therapyChat style component.
     */
    private function getTherapyChatSectionId()
    {
        return $this->getSectionIdForStyle('therapyChat');
    }

    /**
     * Get the first section ID that uses a given style name.
     *
     * @param string $styleName e.g. 'therapyChat' or 'therapistDashboard'
     * @return int|null
     */
    private function getSectionIdForStyle($styleName)
    {
        try {
            $sql = "SELECT s.id
                    FROM sections s
                    INNER JOIN styles st ON s.id_styles = st.id
                    WHERE st.name = ?
                    LIMIT 1";
            $result = $this->db->query_db_first($sql, array($styleName));
            return $result ? (int)$result['id'] : null;
        } catch (Exception $e) {
            return null;
        }
    }
}
