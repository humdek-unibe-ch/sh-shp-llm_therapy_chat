<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

class TherapyPromptProfileMapper
{
    public function resolveExecutionProfileByPromptSlot($descriptor)
    {
        $prompt_slot = (string)($descriptor['prompt_slot'] ?? '');
        if ($prompt_slot === 'therapy_draft_context') {
            return 'therapy_draft_runtime';
        }
        if ($prompt_slot === 'therapy_summary_context') {
            return 'therapy_summary_runtime';
        }
        if ($prompt_slot === 'therapy_auto_start_context') {
            return 'therapy_chat_runtime';
        }

        return '';
    }

    public function resolveConversationContextExecutionProfile($descriptor, $style_name = '')
    {
        if ($style_name === 'therapychat') {
            return 'therapy_chat_runtime';
        }
        if ($style_name === 'therapistdashboard') {
            return 'therapy_draft_runtime';
        }

        return 'chat_runtime';
    }
}
?>
