<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Therapist Group Assignments Template
 *
 * Injected into the admin user edit page via hook.
 * Allows admins to assign groups that a user (therapist) can monitor.
 *
 * Variables:
 * - $targetUserId: The user being edited
 * - $allGroups: All available groups [{id, name}]
 * - $assignedGroupIds: Currently assigned group IDs
 */
?>
<!-- Therapy Chat: Therapist Group Assignments -->
<div class="card mb-3" id="therapy-therapist-assignments">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0">
            <i class="fas fa-user-md mr-2"></i>
            Therapy Chat - Patient Group Monitoring
        </h5>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            Select which patient groups this user can monitor as a therapist in the Therapy Chat system.
            The user will be able to see and respond to conversations from patients in the selected groups.
        </p>
        <div class="form-group">
            <label><strong>Assigned Patient Groups:</strong></label>
            <?php $multiSelect->output_content(); ?>
            <?php if (empty($allGroups)): ?>
                <div class="alert alert-warning">
                    No groups found in the system.
                </div>
            <?php endif; ?>
        </div>
        <button type="button" id="save-therapy-assignments" class="btn btn-primary" data-target-user-id="<?php echo htmlspecialchars($targetUserId); ?>" data-base-path="<?php echo BASE_PATH; ?>">Save Assignments</button>
        <input type="hidden" name="therapy_assignments_present" value="1">
    </div>
</div>
