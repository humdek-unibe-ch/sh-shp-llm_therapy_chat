<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Therapist Dashboard Main Template
 * 
 * Renders the container for the React therapist dashboard component.
 * Configuration is passed via data-config attribute.
 */
?>
<!-- Therapist Dashboard React App Container -->
<div class="therapist-dashboard-root"
     data-user-id="<?php echo $user_id; ?>"
     data-section-id="<?php echo $section_id; ?>"
     data-selected-conversation-id="<?php echo $selected_conversation_id ?? ''; ?>"
     data-config="<?php echo htmlspecialchars($this->getReactConfig()); ?>">
     <!-- React app will be mounted here -->
</div>
