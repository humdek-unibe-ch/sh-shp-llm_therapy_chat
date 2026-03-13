-- =====================================================
-- SelfHelp Plugin: LLM Therapy Chat
-- Version: 1.1.0
-- Description: Prompt versioning enablement for therapy prompt fields
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

COMMIT;
