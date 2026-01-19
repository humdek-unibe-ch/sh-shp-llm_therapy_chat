<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Therapy Chat Main Template
 * 
 * Renders the container for the React therapy chat component.
 * Configuration is passed via data-config attribute.
 */
?>
<!-- Therapy Chat React App Container -->
<div class="therapy-chat-root"
     data-user-id="<?php echo $user_id; ?>"
     data-section-id="<?php echo $section_id; ?>"
     data-conversation-id="<?php echo $conversation_id ?? ''; ?>"
     data-config="<?php echo htmlspecialchars($this->getReactConfig()); ?>">
     <!-- React app will be mounted here -->
</div>
