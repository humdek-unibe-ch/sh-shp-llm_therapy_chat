<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . "/../../../../component/BaseHooks.php";
require_once __DIR__ . "/../service/promptlab/TherapyPromptProfileMapper.php";
require_once __DIR__ . "/../service/promptlab/TherapyPromptCompanionFields.php";
require_once __DIR__ . "/../service/promptlab/TherapyPromptPlaygroundPolicy.php";
require_once __DIR__ . "/../service/promptlab/TherapyDatasetProfileMapper.php";

class TherapyPromptLabHooks extends BaseHooks
{
    /** @var TherapyPromptProfileMapper */
    private $profile_mapper;

    /** @var TherapyPromptCompanionFields */
    private $companion_fields;

    /** @var TherapyPromptPlaygroundPolicy */
    private $playground_policy;

    /** @var TherapyDatasetProfileMapper */
    private $dataset_mapper;

    public function __construct($services, $params = array())
    {
        parent::__construct($services, $params);
        $this->profile_mapper = new TherapyPromptProfileMapper();
        $this->companion_fields = new TherapyPromptCompanionFields();
        $this->playground_policy = new TherapyPromptPlaygroundPolicy();
        $this->dataset_mapper = new TherapyDatasetProfileMapper();
    }

    public function resolveExecutionProfileByPromptSlot($args)
    {
        $res = $this->execute_private_method($args);
        $mapped = $this->profile_mapper->resolveExecutionProfileByPromptSlot($args['descriptor'] ?? array());
        return $mapped !== '' ? $mapped : $res;
    }

    public function resolveConversationContextExecutionProfile($args)
    {
        $res = $this->execute_private_method($args);
        $mapped = $this->profile_mapper->resolveConversationContextExecutionProfile(
            $args['descriptor'] ?? array(),
            strtolower((string)($args['style_name'] ?? ''))
        );
        return $mapped !== '' ? $mapped : $res;
    }

    public function getExtendedCompanionFieldNames($args)
    {
        $res = $this->execute_private_method($args);
        $mapped = $this->companion_fields->getCompanionFieldNames((string)($args['profile'] ?? ''));
        return !empty($mapped) ? $mapped : $res;
    }

    public function getExtendedPlaygroundRuntimeType($args)
    {
        $res = $this->execute_private_method($args);
        $mapped = $this->playground_policy->getRuntimeType((string)($args['profile'] ?? ''));
        return $mapped !== 'none' ? $mapped : $res;
    }

    public function isExtendedChatLikeExecutionProfile($args)
    {
        $res = $this->execute_private_method($args);
        if ($this->playground_policy->isChatLikeProfile((string)($args['profile'] ?? ''))) {
            return true;
        }
        return (bool)$res;
    }

    public function resolveExtendedDefaultChatPromptForProfile($args)
    {
        $res = $this->execute_private_method($args);
        $mapped = $this->playground_policy->getDefaultChatPrompt((string)($args['profile'] ?? ''));
        return $mapped !== '' ? $mapped : $res;
    }

    public function getExtendedConfigSnapshotFields($args)
    {
        $res = $this->execute_private_method($args);
        $mapped = $this->companion_fields->getConfigSnapshotFields(
            (string)($args['profile'] ?? ''),
            is_array($args['runtime_values'] ?? null) ? $args['runtime_values'] : array()
        );
        return !empty($mapped) ? $mapped : $res;
    }

    public function mapExecutionProfileToCaseTypeExtension($args)
    {
        $res = $this->execute_private_method($args);
        $mapped = $this->dataset_mapper->mapExecutionProfileToCaseType((string)($args['execution_profile'] ?? ''));
        return $mapped !== '' ? $mapped : $res;
    }

    public function resolveConversationImportRuntimeProfileExtension($args)
    {
        $res = $this->execute_private_method($args);
        $mapped = $this->dataset_mapper->resolveConversationImportRuntimeProfile((string)($args['execution_profile'] ?? ''));
        return $mapped !== '' ? $mapped : $res;
    }
}
?>
