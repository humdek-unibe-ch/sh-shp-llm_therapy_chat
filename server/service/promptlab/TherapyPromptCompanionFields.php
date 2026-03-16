<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

class TherapyPromptCompanionFields
{
    public function getCompanionFieldNames($profile)
    {
        if ($profile === 'therapy_chat_runtime') {
            return array(
                'llm_model',
                'llm_temperature',
                'llm_max_tokens',
                'therapy_enable_ai',
                'therapy_chat_default_mode',
                'enable_danger_detection',
                'danger_keywords',
                'danger_notification_emails',
                'danger_blocked_message',
                'enable_speech_to_text',
                'speech_to_text_model',
                'speech_to_text_language'
            );
        }

        if ($profile === 'therapy_draft_runtime') {
            return array(
                'llm_model',
                'llm_temperature',
                'llm_max_tokens',
                'conversation_context',
                'therapy_draft_context'
            );
        }

        if ($profile === 'therapy_summary_runtime') {
            return array(
                'llm_model',
                'llm_temperature',
                'llm_max_tokens',
                'therapy_summary_context'
            );
        }

        return array();
    }

    public function getConfigSnapshotFields($profile, $runtime_values)
    {
        if ($profile !== 'therapy_chat_runtime') {
            return array();
        }

        return array(
            'strict_conversation_mode' => $this->toBoolString($runtime_values['strict_conversation_mode'] ?? null),
            'enable_form_mode' => $this->toBoolString($runtime_values['enable_form_mode'] ?? null),
            'enable_progress_tracking' => $this->toBoolString($runtime_values['enable_progress_tracking'] ?? null),
            'enable_danger_detection' => $this->toBoolString($runtime_values['enable_danger_detection'] ?? null),
            'danger_keywords' => $runtime_values['danger_keywords'] ?? '',
            'enable_floating_button' => $this->toBoolString($runtime_values['enable_floating_button'] ?? null),
            'enable_media_rendering' => $this->toBoolString($runtime_values['enable_media_rendering'] ?? null)
        );
    }

    private function toBoolString($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ((string)$value === '1' || $value === true) ? '1' : '0';
    }
}
?>
