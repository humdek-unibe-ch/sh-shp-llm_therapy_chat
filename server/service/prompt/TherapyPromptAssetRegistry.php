<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

class TherapyPromptAssetRegistry
{
    public static function getMap()
    {
        return array(
            'therapy.chat.system' => 'therapy/chat/system.md',
            'therapy.chat.safety_fallback' => 'therapy/chat/safety-fallback.md',
            'therapy.chat.json_reinforcement' => 'therapy/chat/json-reinforcement.md',
            'therapy.dashboard.draft_instruction' => 'therapy/dashboard/draft-instruction.md',
            'therapy.dashboard.summary_instruction' => 'therapy/dashboard/summary-instruction.md',
            'therapy.dashboard.summary_user_prompt' => 'therapy/dashboard/summary-user-prompt.txt',
            'therapy.playground.default_chat_prompt' => 'therapy/playground/default-chat-prompt.txt',
            'therapy.playground.default_draft_prompt' => 'therapy/playground/default-draft-prompt.txt',
        );
    }
}
?>
