<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

class TherapyDatasetProfileMapper
{
    public function mapExecutionProfileToCaseType($execution_profile)
    {
        if (in_array($execution_profile, array('therapy_chat_runtime', 'therapy_draft_runtime', 'therapy_summary_runtime'), true)) {
            return 'chat_case';
        }

        return '';
    }

    public function resolveConversationImportRuntimeProfile($execution_profile)
    {
        if ($execution_profile === 'therapy_chat_runtime') {
            return 'therapy_chat_runtime';
        }

        return '';
    }
}
?>
