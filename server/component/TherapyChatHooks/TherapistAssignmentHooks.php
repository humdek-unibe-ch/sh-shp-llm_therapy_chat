<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

require_once __DIR__ . "/../../../../../component/style/BaseStyleComponent.php";

/**
 * Therapist Assignment Hooks Trait
 *
 * Hook implementations for therapist group assignment UI and select field types.
 * Used by TherapyChatHooks.
 *
 * @package LLM Therapy Chat Plugin
 */
trait TherapistAssignmentHooksTrait
{
    /**
     * Output therapist group assignment checkboxes on admin user edit page.
     */
    public function outputTherapistGroupAssignments($args = null)
    {
        // Only show on admin user pages
        $router = $this->services->get_router();
        if (!$router->is_active('userSelect') && !$router->is_active('userUpdate')) {
            return;
        }

        $targetUserId = $this->getTargetUserId($args);
        if (!$targetUserId) return;

        $allGroups = $this->messageService->getAllGroups();
        $assignedGroups = $this->messageService->getTherapistAssignedGroups($targetUserId);
        $assignedGroupIds = array_map(function ($g) {
            return (int)$g['id_groups'];
        }, $assignedGroups);

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

        include __DIR__ . '/tpl/therapist_group_assignments.php';
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

    /**
     * Extract target user ID from hook args or URL
     */
    private function getTargetUserId($args)
    {
        $router = $this->services->get_router();
        $routeParams = $router->route['params'];

        if (!empty($routeParams['uid'])) {
            return (int)$routeParams['uid'];
        }

        if (!empty($_POST['id_users'])) {
            return (int)$_POST['id_users'];
        }

        if (!empty($_GET['uid'])) {
            return (int)$_GET['uid'];
        }

        if ($args && isset($args['hookedClassInstance'])) {
            try {
                $reflector = new ReflectionObject($args['hookedClassInstance']);
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
}
