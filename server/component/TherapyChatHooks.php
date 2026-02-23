<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . "/../../../../component/BaseHooks.php";
require_once __DIR__ . "/../../../../component/style/BaseStyleComponent.php";
require_once __DIR__ . "/../service/TherapyMessageService.php";
require_once __DIR__ . "/../constants/TherapyLookups.php";

/**
 * Therapy Chat Hooks
 *
 * Hook implementations for the LLM Therapy Chat plugin:
 *
 * 1. Floating chat icon (NavView::output_profile)
 * 2. select-page field type rendering (CmsView)
 * 3. select-floating-position field type rendering (CmsView)
 * 4. Therapist group assignment UI on admin user page (UserSelectView::output_user_manipulation)
 * 5. Save therapist group assignments on admin user update (UserSelectController::execute_update)
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapyChatHooks extends BaseHooks
{
    /** @var TherapyMessageService */
    private $messageService;

    public function __construct($services, $params = array())
    {
        parent::__construct($services, $params);
        $this->messageService = new TherapyMessageService($services);
    }

    /* =========================================================================
     * HOOK: Floating Chat Icon (NavView::output_profile)
     * Type: hook_on_function_execute
     * ========================================================================= */

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
        // The JS will poll check_updates (subject) or get_unread_counts (therapist)
        // to keep the badge count fresh without React.
        // For therapists, use the therapistDashboard section ID (polls that controller).
        // For subjects, use the therapyChat section ID.
        $pollSectionId = $isTherapist
            ? $this->getSectionIdForStyle('therapistDashboard')
            : $this->getTherapyChatSectionId();
        $pollConfig = json_encode(array(
            'role' => $isTherapist ? 'therapist' : 'subject',
            'baseUrl' => $chatUrl,
            'sectionId' => $pollSectionId,
            'interval' => 3000 // 3 seconds
        ));

        include __DIR__ . '/TherapyChatHooks/tpl/floating_chat_icon.php';
    }

    /**
     * Check if the floating chat modal mode is enabled.
     * Looks up the actual configured value of `enable_floating_chat` from the
     * sections_fields_translation table (runtime value), falling back to the
     * styles_fields default_value.
     */
    private function isFloatingChatModalEnabled()
    {
        try {
            // First try to get the actual runtime value from section field translations
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

            // Fallback: check the style field default value
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
     * Includes sectionId and baseUrl so the React app can fetch
     * the full configuration from the correct controller endpoint.
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
     * Looks up the sections table for a section using the therapyChat style.
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

    /* =========================================================================
     * HOOK: Mobile page response (BasePage::output_base_content_mobile)
     * Type: hook_overwrite_return
     * Adds therapy_chat field to every mobile page response.
     * ========================================================================= */

    /**
     * Add therapy_chat data to the mobile page response so the app can show
     * the chat tab/FAB without loading the chat page first.
     *
     * Also intercepts API requests (action + section_id) targeting therapy
     * sections and routes them directly to the controller, bypassing the
     * full page rendering pipeline.
     */
    public function addTherapyChatToMobileResponse($args = null)
    {
        if ($this->handleTherapyApiIfNeeded()) {
            return [];
        }

        $res = $this->execute_private_method($args);
        if (!is_array($res)) {
            return $res;
        }

        $userId = $_SESSION['id_user'] ?? null;
        if (!$userId || !($_SESSION['logged_in'] ?? false)) {
            return $res;
        }

        try {
            $isSubject = $this->messageService->isSubject($userId);
            $isTherapist = $this->messageService->isTherapist($userId);

            // Group membership check
            $subjectGroupId = $this->getConfigValue('therapy_chat_subject_group');
            $therapistGroupId = $this->getConfigValue('therapy_chat_therapist_group');
            if ($subjectGroupId && !$this->isUserInGroup($userId, $subjectGroupId)) $isSubject = false;
            if ($therapistGroupId && !$this->isUserInGroup($userId, $therapistGroupId)) $isTherapist = false;

            if (!$isSubject && !$isTherapist) {
                return $res;
            }

            $styleName = $isTherapist ? 'therapistDashboard' : 'therapyChat';
            $sectionId = $this->getSectionIdForStyle($styleName);

            $unreadCount = 0;
            try {
                if ($isTherapist) {
                    $unreadCount = $this->messageService->getUnreadCountForUser($userId, true)
                        + (int)$this->messageService->getUnreadAlertCount($userId);
                } else {
                    $unreadCount = $this->messageService->getUnreadCountForUser($userId);
                }
            } catch (Exception $e) {}

            $icon = $this->getConfigValue('therapy_chat_floating_icon', 'fa-comments');
            $label = $this->getConfigValue('therapy_chat_floating_label', 'Chat');
            $position = $this->getConfigValue('therapy_chat_floating_position', 'bottom-right');
            $enableFloating = $isSubject ? $this->isFloatingChatModalEnabled() : false;

            $faToIonic = array(
                'fa-comments' => 'chatbubbles',
                'fa-comment' => 'chatbubble',
                'fa-comment-dots' => 'chatbubble-ellipses',
                'fa-comment-medical' => 'medkit',
                'fa-envelope' => 'mail',
                'fa-bell' => 'notifications',
                'fa-user-md' => 'person',
                'fa-heart' => 'heart',
                'fa-shield' => 'shield',
                'fa-stethoscope' => 'fitness',
                'fa-brain' => 'bulb',
                'fa-hands-helping' => 'people',
            );
            $mobileIcon = isset($faToIonic[$icon]) ? $faToIonic[$icon] : 'chatbubbles';

            $pageIdField = $isTherapist ? 'therapy_chat_therapist_page' : 'therapy_chat_subject_page';
            $chatUrl = $this->getPageUrl(
                $this->getConfigValue($pageIdField),
                'home'
            );
            $basePath = defined('BASE_PATH') ? BASE_PATH : '';
            if ($basePath && strpos($chatUrl, $basePath) === 0) {
                $chatUrl = substr($chatUrl, strlen($basePath));
            }

            $res['therapy_chat'] = array(
                'available' => true,
                'section_id' => $sectionId,
                'url' => $chatUrl,
                'unread_count' => (int)$unreadCount,
                'icon' => $icon,
                'mobile_icon' => $mobileIcon,
                'label' => $label,
                'role' => $isTherapist ? 'therapist' : 'subject',
                'enable_floating' => (bool)$enableFloating,
                'position' => $position,
            );
        } catch (Exception $e) {
            // Plugin error should not break the page response
        }

        return $res;
    }

    /**
     * If the mobile request carries an action + section_id that matches a
     * therapy-chat or therapist-dashboard section, instantiate the component
     * directly so its controller handles the API call and exits.
     *
     * Uses output buffering to capture the controller's JSON response,
     * avoiding reliance on exit() which can be intercepted by uopz inside
     * hook_overwrite_return callbacks.
     *
     * @return bool true if the request was handled
     */
    private function handleTherapyApiIfNeeded()
    {
        $action = $_GET['action'] ?? $_POST['action'] ?? null;
        $sectionId = $_GET['section_id'] ?? $_POST['section_id'] ?? null;

        if (!$action || !$sectionId) {
            return false;
        }

        $therapySectionId = $this->getSectionIdForStyle('therapyChat');
        $dashboardSectionId = $this->getSectionIdForStyle('therapistDashboard');

        $sectionIdInt = (int)$sectionId;
        if ($sectionIdInt !== $therapySectionId && $sectionIdInt !== $dashboardSectionId) {
            return false;
        }

        ob_start();
        try {
            new \StyleComponent($this->services, $sectionIdInt, [], -1);
        } catch (\Throwable $e) {
            // Controller calls exit which may throw in uopz
        }
        $captured = ob_get_clean();

        if ($captured && json_decode($captured) !== null) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo $captured;
            if (function_exists('uopz_allow_exit')) {
                uopz_allow_exit(true);
            }
            exit;
        }

        return false;
    }

    /* =========================================================================
     * HOOK: Therapist Group Assignments on Admin User Page
     * Type: hook_on_function_execute
     * Target: UserSelectView::output_user_manipulation
     * ========================================================================= */

    /**
     * Output therapist group assignment checkboxes on admin user edit page.
     * This injects a card with group checkboxes into the user admin page.
     */
    public function outputTherapistGroupAssignments($args = null)
    {
        // Only show on admin user pages
        $router = $this->services->get_router();
        if (!$router->is_active('userSelect') && !$router->is_active('userUpdate')) {
            return;
        }

        // Get target user ID from URL params
        $targetUserId = $this->getTargetUserId($args);
        if (!$targetUserId) return;

        // Get all groups and current assignments
        $allGroups = $this->messageService->getAllGroups();
        $assignedGroups = $this->messageService->getTherapistAssignedGroups($targetUserId);
        $assignedGroupIds = array_map(function ($g) {
            return (int)$g['id_groups'];
        }, $assignedGroups);

        // Prepare multi-select component
        $items = array_map(function($g) {
            return ['value' => $g['id'], 'text' => $g['name']];
        }, $allGroups);
        $selectOptions = array(
            "is_multiple" => true,
            "allow_clear" => 1,
            "name" => "therapy_assigned_groups[]",
            "id" => "therapy_assigned_groups_select",
            "items" => $items,
            "live_search" => true,
            "max" => 10
        );
        if (count($assignedGroupIds) > 0) {
            $selectOptions['value'] = $assignedGroupIds;
        }
        $multiSelect = new BaseStyleComponent('select', $selectOptions);

        include __DIR__ . '/TherapyChatHooks/tpl/therapist_group_assignments.php';
    }

    /* =========================================================================
     * HOOK: Load JS scripts for therapy chat LLM
     * Type: hook_overwrite_return
     * Target: BasePage::get_js_includes
     * ========================================================================= */

    /**
     * Load JS scripts for therapy chat LLM.
     */
    public function loadTherapyChatLLMJs($args = null)
    {
        $includes = $this->execute_private_method($args);
        if (!is_array($includes)) {
            $includes = array();
        }
        $router = $this->services->get_router();
        if ($router->is_active('userSelect') || $router->is_active('userUpdate')) {
            $includes[] = '/server/plugins/sh-shp-llm_therapy_chat/js/ext/therapy_assignments.js';
        }
        $includes[] = '/server/plugins/sh-shp-llm_therapy_chat/js/ext/therapy-chat.umd.js';
        $includes[] = '/server/plugins/sh-shp-llm_therapy_chat/js/ext/therapy_chat_floating.js';
        return $includes;
    }

    /* =========================================================================
     * HOOK: select-page field rendering (CmsView::create_field_form_item)
     * ========================================================================= */

    public function outputFieldSelectPageEdit($args)
    {
        return $this->returnSelectPageField($args, 0);
    }

    public function outputFieldSelectPageView($args)
    {
        return $this->returnSelectPageField($args, 1);
    }

    /* =========================================================================
     * HOOK: select-floating-position field rendering (CmsView::create_field_form_item)
     * ========================================================================= */

    public function outputFieldSelectFloatingPositionEdit($args)
    {
        return $this->returnSelectFloatingPositionField($args, 0);
    }

    public function outputFieldSelectFloatingPositionView($args)
    {
        return $this->returnSelectFloatingPositionField($args, 1);
    }

    /* =========================================================================
     * PRIVATE: select-page helpers
     * ========================================================================= */

    private function getTherapyChatPages()
    {
        try {
            $pages = $this->db->fetch_accessible_pages();
            $options = [];
            foreach ($pages as $page) {
                $options[] = [
                    'value' => $page['id'],
                    'text' => $page['keyword'] . ' (' . ($page['action'] ?? 'unknown') . ')'
                ];
            }
            return $options;
        } catch (Exception $e) {
            return [];
        }
    }

    private function outputSelectPageField($value, $name, $disabled)
    {
        return new BaseStyleComponent("select", array(
            "value" => $value,
            "name" => $name,
            "max" => 10,
            "live_search" => 1,
            "is_required" => 0,
            "disabled" => $disabled,
            "items" => $this->getTherapyChatPages()
        ));
    }

    private function returnSelectPageField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        if (!$field['content']) {
            $field['content'] = '';
        }
        $res = $this->execute_private_method($args);

        if ($field['name'] == 'therapy_chat_subject_page' || $field['name'] == 'therapy_chat_therapist_page') {
            $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
            $selectField = $this->outputSelectPageField($field['content'], $field_name_prefix . "[content]", $disabled);

            if ($selectField && $res) {
                $children = $res->get_view()->get_children();
                $children[] = $selectField;
                $res->get_view()->set_children($children);
            }
        }

        return $res;
    }

    /* =========================================================================
     * PRIVATE: select-floating-position helpers
     * ========================================================================= */

    private function getFloatingButtonPositionOptions()
    {
        try {
            $lookups = $this->db->query_db(
                "SELECT id, lookup_code, lookup_value FROM lookups WHERE type_code = :type_code ORDER BY lookup_value",
                array(':type_code' => THERAPY_LOOKUP_FLOATING_BUTTON_POSITIONS)
            );

            $options = [];
            foreach ($lookups as $lookup) {
                $options[] = [
                    'value' => $lookup['lookup_code'],
                    'text' => $lookup['lookup_value']
                ];
            }
            return $options;
        } catch (Exception $e) {
            return [
                ['value' => 'bottom-right', 'text' => 'Bottom Right'],
                ['value' => 'bottom-left', 'text' => 'Bottom Left'],
                ['value' => 'top-right', 'text' => 'Top Right'],
                ['value' => 'top-left', 'text' => 'Top Left']
            ];
        }
    }

    private function outputSelectFloatingPositionField($value, $name, $disabled)
    {
        return new BaseStyleComponent("select", array(
            "value" => $value,
            "name" => $name,
            "live_search" => false,
            "is_required" => 0,
            "disabled" => $disabled,
            "items" => $this->getFloatingButtonPositionOptions()
        ));
    }

    private function returnSelectFloatingPositionField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        if (!$field['content']) {
            $field['content'] = 'bottom-right';
        }
        $res = $this->execute_private_method($args);

        if ($field['name'] == 'therapy_chat_floating_position') {
            $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
            $selectField = $this->outputSelectFloatingPositionField($field['content'], $field_name_prefix . "[content]", $disabled);

            if ($selectField && $res) {
                $children = $res->get_view()->get_children();
                $children[] = $selectField;
                $res->get_view()->set_children($children);
            }
        }

        return $res;
    }

    /* =========================================================================
     * PRIVATE: Utility methods
     * ========================================================================= */

    /**
     * Extract target user ID from hook args or URL
     */
    private function getTargetUserId($args)
    {
        // Try from URL params (e.g., /admin/user/0000000005)
        $router = $this->services->get_router();
        $routeParams = $router->route['params'];

        if (!empty($routeParams['uid'])) {
            return (int)$routeParams['uid'];
        }

        // Try from POST data
        if (!empty($_POST['id_users'])) {
            return (int)$_POST['id_users'];
        }

        // Try from GET data
        if (!empty($_GET['uid'])) {
            return (int)$_GET['uid'];
        }

        // Try from hook args
        if ($args && isset($args['hookedClassInstance'])) {
            try {
                $reflector = new ReflectionObject($args['hookedClassInstance']);
                // Try to find a model or user id property
                foreach (['model', 'selected_user'] as $prop) {
                    if ($reflector->hasProperty($prop)) {
                        $property = $reflector->getProperty($prop);
                        $property->setAccessible(true);
                        $obj = $property->getValue($args['hookedClassInstance']);
                        if (is_object($obj) && method_exists($obj, 'getSelectedUserId')) {
                            return $obj->getSelectedUserId();
                        }
                        if (is_array($obj) && isset($obj['id'])) {
                            return (int)$obj['id'];
                        }
                    }
                }
            } catch (Exception $e) {
                // Silently fail
            }
        }

        return null;
    }

    private function getConfigValue($fieldName, $defaultValue = '')
    {
        try {
            $configPage = $this->db->fetch_page_info('sh_module_llm_therapy_chat');
            if ($configPage && isset($configPage[$fieldName])) {
                return $configPage[$fieldName] ?: $defaultValue;
            }
        } catch (Exception $e) {
            // Fall through
        }
        return $defaultValue;
    }

    private function getPageUrl($pageId, $fallbackKeyword)
    {
        if (!empty($pageId) && is_numeric($pageId)) {
            try {
                $pageKeyword = $this->db->fetch_page_keyword_by_id($pageId);
                if ($pageKeyword) {
                    $url = $this->services->get_router()->get_link_url($pageKeyword);
                    if (!empty($url)) return $url;
                }
            } catch (Exception $e) {
                // Fall through
            }
        }

        $url = $this->services->get_router()->get_link_url($fallbackKeyword);
        return !empty($url) ? $url : BASE_PATH . '/' . $fallbackKeyword;
    }

    private function getPositionCss($position)
    {
        switch ($position) {
            case 'bottom-left': return 'bottom: 20px; left: 20px;';
            case 'top-right': return 'top: 80px; right: 20px;';
            case 'top-left': return 'top: 80px; left: 20px;';
            case 'bottom-right':
            default: return 'bottom: 20px; right: 20px;';
        }
    }

    private function isCmsPage()
    {
        try {
            $router = $this->services->get_router();
            return ($router->is_active("cms")
                || $router->is_active("cmsSelect")
                || $router->is_active("cmsUpdate")
                || $router->is_active("cmsInsert")
                || $router->is_active("cmsDelete"));
        } catch (Exception $e) {
            return false;
        }
    }

    private function isUserInGroup($userId, $groupId)
    {
        try {
            $groups = array_filter(explode(',', $groupId));
            if (empty($groups)) return false;
            $placeholders = str_repeat('?,', count($groups) - 1) . '?';
            $sql = "SELECT COUNT(*) as count FROM users_groups WHERE id_users = ? AND id_groups IN ($placeholders)";
            $params = array_merge([$userId], $groups);
            $result = $this->db->query_db_first($sql, $params);
            return $result['count'] > 0;
        } catch (Exception $e) {
            return false;
        }
    }

        /**
     * Get the plugin version
     */
    public function get_plugin_db_version($plugin_name = 'llm_therapy_chat')
    {
        return parent::get_plugin_db_version($plugin_name);
    }
}
?>
