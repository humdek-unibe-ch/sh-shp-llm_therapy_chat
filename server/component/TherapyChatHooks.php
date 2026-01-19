<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . "/../../../../component/BaseHooks.php";
require_once __DIR__ . "/../../../../component/style/BaseStyleComponent.php";
require_once __DIR__ . "/../service/TherapyTaggingService.php";
require_once __DIR__ . "/../service/TherapyMessageService.php";
require_once __DIR__ . "/../constants/TherapyLookups.php";

/**
 * Therapy Chat Hooks
 *
 * Provides hook implementations for the LLM Therapy Chat plugin.
 *
 * @package LLM Therapy Chat Plugin
 * @requires sh-shp-llm plugin
 */
class TherapyChatHooks extends BaseHooks
{
    /** @var TherapyTaggingService */
    private $taggingService;

    /** @var TherapyMessageService */
    private $messageService;

    /**
     * Constructor
     *
     * @param object $services
     */
    public function __construct($services, $params = array())
    {
        parent::__construct($services, $params);
        $this->taggingService = new TherapyTaggingService($services);
        $this->messageService = new TherapyMessageService($services);
    }

    /**
     * Get available pages for therapy chat page selection
     *
     * Hook: getTherapyChatPages
     * Provides dropdown options for page selection fields
     *
     * @return array Array of page options for select fields
     */
    public function getTherapyChatPages()
    {
        try {
            // Get all accessible pages
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
            // Return empty array on error
            return [];
        }
    }

    /**
     * Output select-page field
     * @param string $value Value of the field
     * @param string $name The name of the field
     * @param int $disabled 0 or 1 - If the field is in edit mode or view mode (disabled)
     * @return object Return instance of BaseStyleComponent -> select style
     */
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

    /**
     * Return a BaseStyleComponent object for select-page field
     * @param object $args Params passed to the method
     * @param int $disabled 0 or 1 - If the field is in edit mode or view mode (disabled)
     * @return object Return a BaseStyleComponent object
     */
    private function returnSelectPageField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        if(!$field['content']) {
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

    /**
     * Output select-page field in edit mode
     *
     * Hook: field-select-page-edit
     * Triggered: CmsView::create_field_form_item
     *
     * @param array $args Hook arguments containing field data
     * @return object BaseStyleComponent object
     */
    public function outputFieldSelectPageEdit($args)
    {
        return $this->returnSelectPageField($args, 0);
    }

    /**
     * Output select-page field in view mode
     *
     * Hook: field-select-page-view
     * Triggered: CmsView::create_field_item
     *
     * @param array $args Hook arguments containing field data
     * @return object BaseStyleComponent object
     */
    public function outputFieldSelectPageView($args)
    {
        return $this->returnSelectPageField($args, 1);
    }

    /**
     * Get floating button position options from lookups
     *
     * @return array Array of position options for select fields
     */
    public function getFloatingButtonPositionOptions()
    {
        try {
            // Get floating button position lookups
            $lookups = $this->db->query_db(
                "SELECT id, lookup_code, lookup_value FROM lookups WHERE type_code = :type_code ORDER BY lookup_value",
                array(
                    ':type_code' => THERAPY_LOOKUP_FLOATING_BUTTON_POSITIONS
                )
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
            // Return default options on error
            return [
                ['value' => 'bottom-right', 'text' => 'Bottom Right'],
                ['value' => 'bottom-left', 'text' => 'Bottom Left'],
                ['value' => 'top-right', 'text' => 'Top Right'],
                ['value' => 'top-left', 'text' => 'Top Left']
            ];
        }
    }

    /**
     * Output select field for floating button position
     * @param string $value Value of the field
     * @param string $name The name of the field
     * @param int $disabled 0 or 1 - If the field is in edit mode or view mode (disabled)
     * @return object Return instance of BaseStyleComponent -> select style
     */
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

    /**
     * Return a BaseStyleComponent object for select-floating-position field
     * @param object $args Params passed to the method
     * @param int $disabled 0 or 1 - If the field is in edit mode or view mode (disabled)
     * @return object Return a BaseStyleComponent object
     */
    private function returnSelectFloatingPositionField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        if(!$field['content']) {
            $field['content'] = 'bottom-right'; // Default value
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

    /**
     * Output floating button position field in edit mode
     *
     * Hook: field-select-floating-position-edit
     * Triggered: CmsView::create_field_form_item
     *
     * @param array $args Hook arguments containing field data
     * @return object BaseStyleComponent object
     */
    public function outputFieldSelectFloatingPositionEdit($args)
    {
        return $this->returnSelectFloatingPositionField($args, 0);
    }

    /**
     * Output floating button position field in view mode
     *
     * Hook: field-select-floating-position-view
     * Triggered: CmsView::create_field_item
     *
     * @param array $args Hook arguments containing field data
     * @return object BaseStyleComponent object
     */
    public function outputFieldSelectFloatingPositionView($args)
    {
        return $this->returnSelectFloatingPositionField($args, 1);
    }


    /**
     * Output floating therapy chat icon next to user profile
     *
     * Hook: outputTherapyChatIcon
     * Triggers: NavView::output_profile (hook_on_function_execute)
     *
     * @param array $args Hook arguments (optional for hook_on_function_execute)
     * @return void
     */
    public function outputTherapyChatIcon($args = null)
    {
        $userId = $_SESSION['id_user'] ?? null;
        
        if (!$userId) {
            return;
        }

        // Check if user has access to therapy chat (as subject or therapist)
        $isSubject = $this->taggingService->isSubject($userId);
        $isTherapist = $this->taggingService->isTherapist($userId);

        if (!$isSubject && !$isTherapist) {
            return;
        }

        // Get configuration values for page IDs
        $subjectPageId = $this->getConfigValue('therapy_chat_subject_page');
        $therapistPageId = $this->getConfigValue('therapy_chat_therapist_page');

        // Resolve page IDs to URLs with fallbacks
        $subjectPageUrl = $this->getPageUrl($subjectPageId, 'therapyChatSubject');
        $therapistPageUrl = $this->getPageUrl($therapistPageId, 'therapyChatTherapist');

        // Get unread count and determine URL
        if ($isTherapist && (!$isSubject || $isTherapist)) {
            // If user is therapist (or both), prioritize therapist dashboard
            $unreadCount = $this->taggingService->getUnreadAlertCount($userId)
                         + $this->taggingService->getPendingTagCount($userId);
            $chatUrl = $therapistPageUrl;
            $iconTitle = 'Therapist Dashboard';
        } else {
            // User is subject only
            $unreadCount = $this->messageService->getUnreadCountForUser($userId);
            $chatUrl = $subjectPageUrl;
            $iconTitle = 'Therapy Chat';
        }

        // Get icon configuration from module settings
        $icon = $this->getConfigValue('therapy_chat_floating_icon', 'fa-comments');
        $position = $this->getConfigValue('therapy_chat_floating_position', 'bottom-right');

        // Prepare template variables
        $badgeClass = $unreadCount > 0 ? 'badge-danger' : 'badge-secondary';
        $badgeHtml = $unreadCount > 0 ? "<span class=\"badge $badgeClass therapy-chat-badge\">$unreadCount</span>" : '';
        $positionCss = $this->getPositionCss($position);

        // Include the template
        include __DIR__ . '/TherapyChatHooks/tpl/floating_chat_icon.php';
    }

    /**
     * Get configuration value from therapy chat module settings
     *
     * @param string $fieldName Field name
     * @param string $defaultValue Default value if not found
     * @return string Configuration value
     */
    private function getConfigValue($fieldName, $defaultValue = '')
    {
        try {
            // Get the therapy chat configuration page info
            $configPage = $this->db->fetch_page_info('sh_module_llm_therapy_chat');

            if ($configPage && isset($configPage[$fieldName])) {
                return $configPage[$fieldName] ?: $defaultValue;
            }
        } catch (Exception $e) {
            // Fall back to default if there's any error
        }

        return $defaultValue;
    }

    /**
     * Get page URL from page ID with fallback to keyword
     *
     * @param int|string|null $pageId Page ID
     * @param string $fallbackKeyword Fallback page keyword
     * @return string Page URL
     */
    private function getPageUrl($pageId, $fallbackKeyword)
    {
        if (!empty($pageId) && is_numeric($pageId)) {
            try {
                $pageInfo = $this->db->fetch_page_info_by_id($pageId);
                if ($pageInfo && isset($pageInfo['url'])) {
                    return '/' . $pageInfo['url'];
                }
            } catch (Exception $e) {
                // Fall back to keyword if page ID lookup fails
            }
        }

        // Fallback to keyword-based URL
        return '/' . $fallbackKeyword;
    }

    /**
     * Get CSS for positioning the floating button
     *
     * @param string $position Position identifier
     * @return string CSS properties
     */
    private function getPositionCss($position)
    {
        switch ($position) {
            case 'bottom-left':
                return 'bottom: 20px; left: 20px;';
            case 'top-right':
                return 'top: 80px; right: 20px;';
            case 'top-left':
                return 'top: 80px; left: 20px;';
            case 'bottom-right':
            default:
                return 'bottom: 20px; right: 20px;';
        }
    }
}
?>
