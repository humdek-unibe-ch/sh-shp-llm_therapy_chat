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
<div class="therapy-chat-root <?php echo $this->css; ?>"
     data-user-id="<?php echo htmlspecialchars($user_id, ENT_QUOTES, 'UTF-8'); ?>"
     data-section-id="<?php echo htmlspecialchars($section_id, ENT_QUOTES, 'UTF-8'); ?>"
     data-conversation-id="<?php echo htmlspecialchars($conversation_id ?? '', ENT_QUOTES, 'UTF-8'); ?>"
     data-config="<?php echo htmlspecialchars($this->getReactConfig()); ?>">
     <!-- React app will be mounted here -->
</div>
