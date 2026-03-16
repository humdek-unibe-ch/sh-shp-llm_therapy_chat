<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

class TherapyPromptPlaygroundPolicy
{
    public function getRuntimeType($profile)
    {
        if ($profile === 'therapy_chat_runtime' || $profile === 'therapy_draft_runtime') {
            return 'chat';
        }
        if ($profile === 'therapy_summary_runtime') {
            return 'form';
        }

        return 'none';
    }

    public function isChatLikeProfile($profile)
    {
        return $profile === 'therapy_chat_runtime' || $profile === 'therapy_draft_runtime';
    }

    public function getDefaultChatPrompt($profile)
    {
        if ($profile === 'therapy_chat_runtime') {
            return 'Test this therapy chat prompt in playground mode.';
        }
        if ($profile === 'therapy_draft_runtime') {
            return 'Create a therapist-facing reply draft for the latest patient message.';
        }

        return '';
    }
}
?>
