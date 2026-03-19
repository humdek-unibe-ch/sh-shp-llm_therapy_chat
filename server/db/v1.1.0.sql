-- =====================================================
-- SelfHelp Plugin: LLM Therapy Chat
-- Version: 1.1.0
-- Description: Prompt-lab therapy runtime ownership and hook extensions
-- =====================================================

START TRANSACTION;

-- Keep plugin version in sync with this migration.
UPDATE plugins
SET version = 'v1.1.0'
WHERE `name` = 'llm_therapy_chat';

-- Ensure the base plugin prompt field type exists (added in sh-shp-llm v1.1.0).
DELIMITER //
CREATE PROCEDURE check_llm_prompt_dependency()
BEGIN
    DECLARE llm_prompt_field_type_count INT DEFAULT 0;

    SELECT COUNT(*) INTO llm_prompt_field_type_count
    FROM fieldType
    WHERE `name` = 'llm_prompt';

    IF llm_prompt_field_type_count = 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ERROR: sh-shp-llm v1.1.0+ is required (missing fieldType llm_prompt).';
    END IF;
END //
DELIMITER ;

CALL check_llm_prompt_dependency();
DROP PROCEDURE check_llm_prompt_dependency;

-- Own therapy execution profiles in the therapy plugin.
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES
('llm_eval_execution_profiles', 'therapy_chat_runtime', 'therapy_chat_runtime', 'Therapy chat runtime profile'),
('llm_eval_execution_profiles', 'therapy_draft_runtime', 'therapy_draft_runtime', 'Therapy draft runtime profile'),
('llm_eval_execution_profiles', 'therapy_summary_runtime', 'therapy_summary_runtime', 'Therapy summary runtime profile');

-- Move therapy prompt-like fields to the shared React prompt field type so they
-- automatically get history/diff/restore tooling from sh-shp-llm.
UPDATE `fields`
SET `id_type` = get_field_type_id('llm_prompt')
WHERE `name` IN (
    'conversation_context',
    'therapy_draft_context',
    'therapy_summary_context',
    'therapy_auto_start_context'
);

-- Prompt-lab/dataset runtime extension hooks for therapy profiles.
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES
((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'therapy-prompt-profile-slot', 'Resolve therapy prompt-slot execution profile mappings', 'LlmPromptExecutionProfileService', 'resolveExecutionProfileByPromptSlot', 'TherapyPromptLabHooks', 'resolveExecutionProfileByPromptSlot', 5),
((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'therapy-prompt-profile-conversation', 'Resolve therapy conversation_context execution profile mappings', 'LlmPromptExecutionProfileService', 'resolveConversationContextExecutionProfile', 'TherapyPromptLabHooks', 'resolveConversationContextExecutionProfile', 5),
((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'therapy-prompt-companion-fields', 'Provide therapy companion fields for extended profiles', 'LlmPromptExecutionProfileService', 'getExtendedCompanionFieldNames', 'TherapyPromptLabHooks', 'getExtendedCompanionFieldNames', 5),
((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'therapy-prompt-runtime-type', 'Classify therapy playground runtime type', 'LlmPromptExecutionProfileService', 'getExtendedPlaygroundRuntimeType', 'TherapyPromptLabHooks', 'getExtendedPlaygroundRuntimeType', 5),
((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'therapy-prompt-chatlike', 'Mark therapy execution profiles as chat-like', 'LlmPromptExecutionProfileService', 'isExtendedChatLikeExecutionProfile', 'TherapyPromptLabHooks', 'isExtendedChatLikeExecutionProfile', 5),
((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'therapy-prompt-default-message', 'Provide therapy default playground user messages', 'LlmPromptExecutionProfileService', 'resolveExtendedDefaultChatPromptForProfile', 'TherapyPromptLabHooks', 'resolveExtendedDefaultChatPromptForProfile', 5),
((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'therapy-prompt-config-snapshot', 'Add therapy config snapshot fields for extended profiles', 'LlmPromptExecutionProfileService', 'getExtendedConfigSnapshotFields', 'TherapyPromptLabHooks', 'getExtendedConfigSnapshotFields', 5),
((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'therapy-dataset-case-type', 'Map therapy execution profiles to dataset case types', 'LlmDatasetService', 'mapExecutionProfileToCaseTypeExtension', 'TherapyPromptLabHooks', 'mapExecutionProfileToCaseTypeExtension', 5),
((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'therapy-dataset-conversation-profile', 'Normalize therapy runtime profile during conversation imports', 'LlmDatasetIngestionService', 'resolveConversationImportRuntimeProfileExtension', 'TherapyPromptLabHooks', 'resolveConversationImportRuntimeProfileExtension', 5);

COMMIT;
