<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Floating Chat Icon Template
 *
 * Template for the therapy chat floating icon with badge.
 * Variables available:
 * - $chatUrl: URL for the chat link (used when not in modal mode)
 * - $iconTitle: Title attribute for the link
 * - $icon: FontAwesome icon class
 * - $badgeHtml: HTML for the notification badge
 * - $positionCss: CSS for positioning
 * - $label: Optional text label for the button
 * - $enableFloatingModal: boolean - whether to open inline modal instead of navigating
 * - $floatingModalConfig: JSON string with React config (only when modal enabled)
 */
?>
<?php if ($enableFloatingModal ?? false): ?>
<?php
// Inject therapy-chat.css so floating modal has proper bubble styling on any page
$tcCssPath = __DIR__ . '/../../../css/ext/therapy-chat.css';
if (file_exists($tcCssPath)):
?>
<link rel="stylesheet" href="<?php echo BASE_PATH . '/server/plugins/sh-shp-llm_therapy_chat/css/ext/therapy-chat.css?v=' . filemtime($tcCssPath); ?>" />
<?php endif; ?>
<!-- Therapy Chat Floating Icon — Modal Mode -->
<button type="button" id="therapy-chat-floating-trigger" class="position-fixed d-flex align-items-center justify-content-center bg-primary text-white <?php echo !empty($label) ? 'rounded-pill px-3' : 'rounded-circle'; ?> shadow therapy-chat-icon" style="min-width: 50px; height: 50px; font-size: 1.5rem; z-index: 1000; border: none; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; <?php echo $positionCss; ?>" title="<?php echo $iconTitle; ?>" aria-label="<?php echo $iconTitle; ?>" data-poll-config="<?php echo htmlspecialchars($pollConfig); ?>">
    <?php if (!empty($label)): ?>
        <span class="d-flex align-items-center">
            <i class="fas <?php echo $icon; ?> me-2"></i>
            <span><?php echo htmlspecialchars($label); ?></span>
        </span>
    <?php else: ?>
        <i class="fas <?php echo $icon; ?>"></i>
    <?php endif; ?>
    <?php echo $badgeHtml; ?>
</button>

<!-- Floating Chat Modal Panel -->
<div id="therapy-chat-floating-backdrop" class="therapy-chat-floating-backdrop" style="display:none;"></div>
<div id="therapy-chat-floating-panel" class="therapy-chat-floating-panel" style="display:none; left: 12px;">
    <div class="therapy-chat-floating-header">
        <h6 class="mb-0 text-white"><?php echo htmlspecialchars($iconTitle); ?></h6>
        <button type="button" id="therapy-chat-floating-close" class="therapy-chat-floating-close-btn" aria-label="Close">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="therapy-chat-floating-body">
        <div class="therapy-chat-root" data-config="<?php echo htmlspecialchars($floatingModalConfig); ?>">
            <!-- React app mounts here when panel opens -->
        </div>
    </div>
</div>

<?php else: ?>
<!-- Therapy Chat Floating Icon — Link Mode -->
<a href="<?php echo $chatUrl; ?>" id="therapy-chat-floating-link" class="position-fixed d-flex align-items-center justify-content-center bg-primary text-white <?php echo !empty($label) ? 'rounded-pill px-3' : 'rounded-circle'; ?> shadow therapy-chat-icon" style="min-width: 50px; height: 50px; font-size: 1.5rem; z-index: 1000; text-decoration: none; transition: transform 0.2s, box-shadow 0.2s; <?php echo $positionCss; ?>" title="<?php echo $iconTitle; ?>" data-poll-config="<?php echo htmlspecialchars($pollConfig); ?>">
    <?php if (!empty($label)): ?>
        <span class="d-flex align-items-center">
            <i class="fas <?php echo $icon; ?> me-2"></i>
            <span><?php echo htmlspecialchars($label); ?></span>
        </span>
    <?php else: ?>
        <i class="fas <?php echo $icon; ?>"></i>
    <?php endif; ?>
    <?php echo $badgeHtml; ?>
</a>
<?php endif; ?>

<style>
    .therapy-chat-icon:hover {
        transform: scale(1.05);
        box-shadow: 0 0.25rem 0.5rem rgba(0,0,0,0.3) !important;
        color: white !important;
        text-decoration: none !important;
    }
    .therapy-chat-badge {
        top: -5px;
        right: -5px;
        font-size: 0.7rem;
        min-width: 18px;
        height: 18px;
        line-height: 18px;
        text-align: center;
    }
    .therapy-chat-icon span {
        font-size: 0.9rem;
        font-weight: 500;
        white-space: nowrap;
    }

    /* Floating modal panel styles */
    .therapy-chat-floating-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.35);
        z-index: 10000;
    }
    .therapy-chat-floating-panel {
        position: fixed;
        width: 380px;
        height: calc(100vh - 80px);
        max-height: calc(100vh - 80px);
        top: 40px !important;
        z-index: 10001;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    .therapy-chat-floating-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.65rem 1rem;
        background: #0d6efd;
        flex-shrink: 0;
    }
    .therapy-chat-floating-close-btn {
        background: none;
        border: none;
        color: rgba(255,255,255,0.8);
        font-size: 1.1rem;
        cursor: pointer;
        padding: 0.2rem 0.35rem;
        border-radius: 4px;
    }
    .therapy-chat-floating-close-btn:hover {
        color: #fff;
        background: rgba(255,255,255,0.15);
    }
    .therapy-chat-floating-body {
        flex: 1;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }
    /* React root fills the body */
    .therapy-chat-floating-body .therapy-chat-root {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
        overflow: hidden;
    }
    /* SubjectChat wrapper fills the root */
    .therapy-chat-floating-body .tc-subject {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
        overflow: hidden;
    }
    /* Card fills the wrapper and uses flex layout */
    .therapy-chat-floating-body .card {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
        border: none !important;
        border-radius: 0 !important;
        box-shadow: none !important;
    }
    /* Hide the inline card header since the floating panel has its own */
    .therapy-chat-floating-body .card-header {
        display: none !important;
    }
    /* Card body: flex column, scrollable messages */
    .therapy-chat-floating-body .card-body {
        flex: 1;
        min-height: 0;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    /* Message list fills available space and scrolls */
    .therapy-chat-floating-body .tc-msg-list {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
    }
    /* Card footer (input area) stays at bottom */
    .therapy-chat-floating-body .card-footer {
        flex-shrink: 0;
    }
    /* Floating panel bubble overrides — reinforce bubble styling */
    .therapy-chat-floating-body .tc-msg-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        padding: 0.75rem;
    }
    .therapy-chat-floating-body .tc-msg {
        max-width: 80%;
        font-size: 0.85rem;
        padding: 0.5rem 0.75rem;
        border-radius: 0.75rem;
        margin-bottom: 0;
        margin-left: 0.5rem;
        margin-right: 0.5rem;
    }
    .therapy-chat-floating-body .tc-msg__header {
        font-size: 0.65rem;
    }
    /* Own (patient) messages — blue, right-aligned */
    .therapy-chat-floating-body .tc-msg--self {
        background-color: #007bff !important;
        color: #fff !important;
        align-self: flex-end !important;
        margin-left: auto !important;
        margin-right: 0.5rem !important;
        border-bottom-right-radius: 0.2rem;
    }
    .therapy-chat-floating-body .tc-msg--self .tc-msg__sender,
    .therapy-chat-floating-body .tc-msg--self .tc-msg__time {
        color: rgba(255,255,255,0.85) !important;
    }
    /* AI messages — white with visible border, left-aligned */
    .therapy-chat-floating-body .tc-msg--ai {
        background-color: #fff !important;
        border: 1px solid #c8cdd3 !important;
        align-self: flex-start !important;
        margin-right: auto !important;
        margin-left: 0.5rem !important;
        border-bottom-left-radius: 0.2rem;
    }
    /* Therapist messages — green accent, left-aligned */
    .therapy-chat-floating-body .tc-msg--therapist {
        background-color: #d4edda !important;
        border-left: 3px solid #28a745 !important;
        align-self: flex-start !important;
        margin-right: auto !important;
        margin-left: 0.5rem !important;
    }
    /* Subject (patient) messages in therapist view — left-aligned */
    .therapy-chat-floating-body .tc-msg--subject {
        background-color: #e3f2fd !important;
        align-self: flex-start !important;
        margin-right: auto !important;
        margin-left: 0.5rem !important;
    }
    /* System messages — centered yellow */
    .therapy-chat-floating-body .tc-msg--system {
        background-color: #fff3cd !important;
        color: #856404 !important;
        align-self: center !important;
        text-align: center;
        font-style: italic;
        margin-left: auto !important;
        margin-right: auto !important;
    }

    @media (max-width: 767px) {
        .therapy-chat-floating-panel {
            width: calc(100vw - 24px) !important;
            height: calc(100vh - 80px) !important;
            max-height: calc(100vh - 80px) !important;
            left: 12px !important;
            right: 12px !important;
            top: 40px !important;
            bottom: auto !important;
        }
    }
</style>
