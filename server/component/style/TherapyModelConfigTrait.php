<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

/**
 * Shared config helpers for therapy style models.
 *
 * Keeps repeated label and speech-to-text access logic in one place.
 */
trait TherapyModelConfigTrait
{
    /**
     * Build message sender labels from style fields.
     */
    protected function buildMessageLabelOverrides(
        $aiField,
        $therapistField,
        $subjectField = null,
        $subjectDefault = 'Patient'
    ) {
        $subjectLabel = $subjectField
            ? $this->get_db_field($subjectField, $subjectDefault)
            : $subjectDefault;

        return array(
            'ai' => $this->get_db_field($aiField, 'AI Assistant'),
            'therapist' => $this->get_db_field($therapistField, 'Therapist'),
            'subject' => $subjectLabel,
            'system' => 'System',
        );
    }

    /**
     * Speech-to-text is only active when enabled and a model is configured.
     */
    protected function isSpeechToTextConfigured()
    {
        $enabled = (bool)$this->get_db_field('enable_speech_to_text', '0');
        $model = trim((string)$this->get_db_field('speech_to_text_model', ''));
        return $enabled && $model !== '';
    }

    protected function getSpeechToTextConfiguredModel()
    {
        return (string)$this->get_db_field('speech_to_text_model', '');
    }

    protected function getSpeechToTextConfiguredLanguage()
    {
        $language = (string)$this->get_db_field('speech_to_text_language', 'auto');
        return $language !== '' ? $language : 'auto';
    }
}
?>
