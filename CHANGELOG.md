# Changelog

## v1.0.0 (2026-02-11) - Initial Release

### Breaking Changes
- Removed `therapyTags` table — tag functionality absorbed into `therapyAlerts` with `tag_received` alert type
- Removed `id_therapist` from `therapyConversationMeta` — multiple therapists per conversation supported via `therapyTherapistAssignments`
- Removed `id_groups` from `therapyConversationMeta` — access control via therapist-to-group assignments
- Removed `react-mentions` dependency — @mention is now simple text-based
- All React types renamed (e.g., `TherapyChatConfig` -> `SubjectChatConfig`)
- Removed tag reason buttons from patient chat — replaced with customizable help text label
- **Controller refactoring**: All business logic moved from controllers to models; controllers are now thin input-validation + delegation layers

### Added
- **`therapyTherapistAssignments` table**: Maps therapists to patient groups they can monitor
- **`therapyDraftMessages` table**: AI draft generation + editing workflow
- **`therapy_enable_ai` field**: Per-style toggle to disable AI completely
- **`therapy_chat_help_text` field**: Customizable help text explaining @mention and #hashtag usage (supports multilingual via field translations)
- **`therapy_summary_context` field**: Customizable summarization context for the therapist dashboard, guides AI summary output
- **`therapy_draft_context` field**: Customizable context/instructions for AI draft generation. Appended to the draft system prompt to guide the AI output (e.g., "Generate a response based on the conversation and the patient's last message"). Supports multilingual content via field translations
- **`therapyNoteStatus` lookup**: Lookup-based soft-delete status for clinical notes (`active`, `deleted`)
- **`id_noteStatus` column** on `therapyNotes`: FK to lookups for note lifecycle status
- **`id_lastEditedBy` column** on `therapyNotes`: Tracks last therapist who edited a note
- **LLM config fields** on `therapistDashboard` style: `llm_model`, `llm_temperature`, `llm_max_tokens`, `conversation_context`, `therapy_draft_context` for draft generation and summarization
- **Group tabs** on therapist dashboard: Patients separated by assigned groups with per-group unread counts
- **AI draft modal**: Full modal dialog where backend calls LLM to generate draft, therapist edits, then sends or discards
- **AI draft workflow**: Generate, edit, regenerate, undo, send or discard AI-suggested responses
- **AI draft Regenerate button**: Generates a new AI draft while saving the current text to an undo stack
- **AI draft Undo button**: Restores the previous draft text from before the last regeneration
- **Markdown rendering in AI draft editor**: AI-generated markdown is rendered as formatted HTML in the contentEditable editor for a rich editing experience
- **Markdown rendering in summary modal**: Conversation summaries display with proper markdown formatting (headings, tables, lists, bold/italic)
- **Markdown rendering in clinical notes**: Notes (including AI summaries saved as notes) render markdown content properly in the sidebar; `<br>` tags properly rendered using `rehype-raw`
- **Conversation summarization**: New "Summarize" button opens a modal showing an AI-generated clinical summary; can be saved as a clinical note. Summary messages are appended to the shared therapist tools conversation (all summaries for the same therapist + section in one conversation). Uses the LLM model configured on the `therapistDashboard` style
- **AI draft audit trail**: Generated drafts are saved to `llmMessages` table via parent LLM plugin's `addMessage` call for full audit, in addition to the `therapyDraftMessages` workflow table. Audit messages go to a dedicated therapist tools conversation (per therapist + section) so drafts never appear in the patient's conversation
- **Therapist tools conversation**: Shared LLM conversation per therapist + section for AI drafts and summaries. Created via `getOrCreateTherapistToolsConversation()`. Prevents drafts and summary audit messages from leaking into patient conversations
- **Lightweight polling**: `check_updates` endpoint returns only counts/latest message ID; full data fetch only when something changed (both patient and therapist sides)
- **Message editing**: Therapists can edit their own messages
- **Message soft-deletion**: Messages marked as deleted (not removed)
- **Unread tracking**: Per-patient and per-group unread message counts with badges in patient list and group tabs. Therapist unread counts exclude AI messages (`role = 'assistant'`)
- **Alert-based tagging**: Tags create alerts with `metadata` JSON for reason/urgency. Supports both `@therapist` (all therapists) and `@SpecificName` (individual therapist, case-insensitive)
- **Clinical notes edit/delete**: Therapists can edit note content and soft-delete notes with full audit trail
- **Transaction logging**: All note CRUD operations, risk level changes, AI toggle changes, status changes, summary generation, and draft creation logged via `logTransaction()`
- **`globals.php`**: Proper plugin constant loading via SelfHelp loadPluginGlobals()
- **Paused conversation blocking**: Patients see a disabled notice when conversation is paused by therapist; no AI responses generated for paused conversations
- **Immediate risk/status UI update**: Risk level and status changes reflect immediately in the UI without full page reload
- **URL state persistence**: Therapist dashboard preserves selected group tab and patient in the URL (`?gid=...&uid=...`)
- **Speech-to-text**: Integrated with `sh-shp-llm` plugin's STT service, cursor-position text insertion
- **Floating chat badge with polling**: The floating icon polls the server every 3s for unread counts (via `check_updates` for subjects, `get_unread_counts` for therapists) and updates the badge in real time. Works on all pages without React — pure vanilla JS in `therapy_chat_floating.js`. Both modal mode (button) and link mode (`<a>`) get `data-poll-config` with role, baseUrl, sectionId. Badge auto-clears on patient chat view and when opening the floating modal
- **Subject unread badge fix in floating mode**: When the floating modal is closed (hidden), the React `SubjectChat` no longer polls or marks messages as read — the standalone `therapy_chat_floating.js` handles badge updates. Previously the React component kept polling and marking messages as read in the background even when the panel was hidden, causing the badge to always show 0. Uses `MutationObserver` to track panel visibility and only resumes React polling when the panel is visible
- **Floating modal chat** (`therapyChat` style): When `enable_floating_chat` is enabled, the server-rendered floating icon opens an inline modal panel instead of navigating to the chat page. Icon, position, and label are controlled by the main plugin config (`therapy_chat_floating_icon`, `therapy_chat_floating_position`, `therapy_chat_floating_label`). Includes mobile-responsive backdrop, Escape-to-close, and unread badge clearing on open. The floating panel loads `therapy-chat.css` via `<link>` tag so message bubble styles work on any page
- **Email notifications — therapist->patient**: When a therapist sends a message (or sends a draft), an email is queued to the patient via SelfHelp's `JobScheduler`. Configurable via `enable_patient_email_notification`, `patient_notification_email_subject`, `patient_notification_email_body` fields on `therapistDashboard` style. Placeholders: `@user_name`, `@therapist_name`
- **Email notifications — patient->therapist**: Therapists are emailed when: (a) patient tags `@therapist` or `@SpecificName`, or (b) AI is disabled for the conversation. Tag messages use a separate template with message preview. Configurable via `enable_therapist_email_notification`, `therapist_notification_email_subject`, `therapist_notification_email_body`, `therapist_tag_email_subject`, `therapist_tag_email_body` fields on both `therapistDashboard` and `therapyChat` styles. Placeholders: `{{patient_name}}`, `{{message_preview}}`, `@user_name`. Default: enabled
- **Email notification shared fields**: `notification_from_email` and `notification_from_name` on both styles
- **@mention autocomplete**: Typing `@` in the message input opens a dropdown of assigned therapists; typing `#` shows predefined tag reasons/topics. Keyboard navigation (Arrow keys, Enter, Tab, Escape) supported
- **@tagged messages skip AI**: When a patient tags a therapist (`@therapist` or `@SpecificName`), the message is sent only to therapists — no AI response is generated. This ensures the therapist sees the message directly without AI interference
- **Danger detection** (multi-layer):
  1. **LLM-based detection**: If the parent `sh-shp-llm` plugin's `LlmDangerDetectionService` is available, the LLM evaluates every message and returns a structured SafetyAssessment with danger_level (null, warning, critical, emergency). For critical/emergency, conversation is blocked, AI disabled, risk escalated, and urgent email sent to all assigned therapists
  2. **Keyword-based fallback**: When LLM detection is unavailable, configurable `danger_keywords` (comma/semicolon separated) are checked with simple substring matching. Same blocking and notification behavior applies
  3. **`danger_notification_emails`** field: Configurable list of additional email addresses to receive urgent danger notifications (e.g., clinical supervisors). Used by `TherapyAlertService::sendUrgentNotification()`
  4. **Danger blocked message**: Customizable message shown to the patient when danger is detected (`danger_blocked_message` field)
  5. **Audit logging**: All danger detections are logged via transaction service
- Comprehensive documentation in `doc/` folder

### Fixed
- **Critical: Danger detection conversation ID mismatch** — `LlmDangerDetectionService::checkMessage()` was receiving `therapyConversationMeta.id` but operates on `llmConversations.id`. Now correctly passes `id_llmConversations` so conversation blocking works against the right table
- **Critical: `getDangerNotificationEmails()` return type** — Method returned a raw string but `LlmDangerDetectionService::sendNotifications()` expected an array. Now returns a parsed array (supports comma, semicolon, newline separators), matching the parent `LlmChatModel` interface
- **Critical: Post-LLM safety detection** — Added structured response schema injection (via `LlmResponseService::buildResponseContext()`) into AI context messages, matching the parent `sh-shp-llm` plugin. The LLM now returns JSON with a `safety` assessment (danger_level: null/warning/critical/emergency). After receiving the response, `handlePostLlmSafetyDetection()` parses the safety object and triggers conversation blocking + notifications for critical/emergency levels
- **Critical: Structured JSON response text extraction** — `TherapyMessageService::processAIResponse()` now extracts human-readable text from `content.text_blocks[]` in structured JSON responses. Prevents raw JSON from being saved as the chat message content
- **Layer 2 (fallback) danger detection now blocks conversation** — Added `TherapyChatService::blockConversation()` method and called it from the keyword fallback path so `llmConversations.blocked` is properly set
- **Duplicate danger notifications** — Layer 1 (LLM service) already sends to `getDangerNotificationEmails()`; no longer passes extra emails to `createDangerAlert` for Layer 1 to avoid duplicate emails to configured addresses
- **Duplicate floating UI** — When `enable_floating_chat` is enabled, `TherapyChatView::output_content()` now returns early to avoid rendering the inline chat on the page (the floating modal panel handles it)
- **CSS duplication** — Removed `bootstrap/dist/css/bootstrap.min.css` import from React bundle. Bootstrap 4.6 is already loaded globally by SelfHelp; bundling it duplicated 167KB of CSS. `css/ext/therapy-chat.css` now contains only custom `tc-` prefixed styles (6KB)
- **Therapist unread/seen message tracking** — `handleGetMessages()` (polling endpoint) now calls `markMessagesRead()` so messages polled while a conversation is open are properly marked as seen. Previously only `updateLastSeen` was called, leaving `therapyMessageRecipients.is_new = 1` for polled messages
- **"Mark as Read" button** — Added explicit "Mark read" button in therapist dashboard conversation header. Shows only when the selected conversation has unread messages
- **Plugin version constant** — Fixed `LLM_THERAPY_CHAT_PLUGIN_VERSION` from `v1.0.1` to `v1.0.0`
- **PHP Fatal: `array_merge()` null in `BasePage::output_js_includes()`** — `loadTherapyChatLLMJs` hook now guards against `execute_private_method()` returning null by defaulting to an empty array. Prevents crash when the base method returns null
- **Protected `logTransaction()` call** — `TherapyChatModel::handlePostLlmSafetyDetection()` was calling `$this->therapyService->logTransaction()` which is protected in `LlmLoggingTrait`. Now uses `$this->get_services()->get_transaction()->add_transaction()` directly
- **Duplicate danger email in post-LLM safety detection** — Removed call to `$this->dangerDetection->sendNotifications()` from `handlePostLlmSafetyDetection()`. `createDangerAlert()` already sends to all assigned therapists. The parent LLM plugin's `checkMessage()` sends its own email via `LlmDangerDetectionService::sendNotifications()` — that email is expected (from the dependency plugin) and is not a duplication
- **Therapist floating badge not updating** — `loadUnreadCounts()` in `TherapistDashboard.tsx` now syncs the server-rendered floating icon badge (`.therapy-chat-badge`) with live unread data after each poll/mark-read
- **`handleMarkMessagesRead` response** — Now returns `unread_count` in the JSON response so the frontend can update badges immediately
- **Critical: Alerts never marked as read** — `markAllAlertsRead()` in `TherapyAlertService` received `therapyConversationMeta.id` but queried `therapyAlerts.id_llmConversations` (which stores `llmConversations.id`). IDs never matched, so no alerts were ever marked as read. Now resolves `therapyConversationMeta.id` → `llmConversations.id` internally. Also supports marking ALL alerts (no conversation filter) for the "Dismiss all" use case
- **Auto-mark alerts read on conversation open** — `selectConversation` in `TherapistDashboard.tsx` now calls both `markMessagesRead()` and `markAllAlertsRead()` when a therapist opens a conversation
- **"Dismiss all alerts" button** — Added a "Dismiss all alerts" button in the alert banner when multiple critical alerts are present. Calls `markAllAlertsRead()` without a conversation ID to clear all unread alerts
- **Alert text display: raw JSON cleaned** — Alert banner now extracts clean display text from alert metadata (`detected_keywords`, `reason`) instead of showing the raw `message` field which could contain JSON. For danger alerts, shows "Danger keywords detected: X"; for tag alerts, shows "Tagged: reason"
- **Danger alert message content** — `handlePostLlmSafetyDetection()` now passes the LLM's human-readable `safety_message` to `createDangerAlert()` instead of the raw structured JSON response. Alert text is now meaningful for therapists
- **Alerts stat shows unread count** — Dashboard header "Alerts" stat now shows `totalAlerts` (unread count) instead of `alerts.length` (total loaded), bold when > 0
- **Therapist unread for AI conversations** — When AI is enabled, patient messages and AI responses no longer create `therapyMessageRecipients` entries for therapists. Only explicitly tagged therapists (via `@therapist` or `@Dr. Name`) get unread entries. When AI is disabled, all patient messages are marked unread for therapists as before (since messages are intended for them). Tag processing now runs before recipient creation so tagged IDs are available
- **Double schema injection in LLM context** — `TherapyMessageService::processAIResponse()` was independently rebuilding context and injecting the JSON schema a second time (with wrong `buildResponseContext()` arguments: model/temp/maxTokens instead of progress/danger config). Also attempted to use `callLlmWithSchemaValidation()` with a wrongly-constructed `LlmResponseService` instance. The service now simply calls `callLlmApi()` directly with the already-prepared context messages from `TherapyChatModel`, which handles all schema/safety injection via `LlmResponseService::buildResponseContext()`. This eliminates the double schema, fixes the `undefined $response` error, and fixes the `Too few arguments to callLlmWithSchemaValidation()` error
- **Critical: Wrong `require_once` path for `LlmResponseService`** — `TherapyMessageService.php` had `../../sh-shp-llm/...` (2 levels up) but the correct path is `../../../sh-shp-llm/...` (3 levels up to reach `plugins/`). Because `file_exists()` returned false silently, `class_exists('LlmResponseService')` was always false, so the unified JSON response schema was **never injected** into any LLM call. All calls went through the simple fallback path. Now correctly loads `LlmResponseService` and always injects the structured response schema
- **Schema not injected for drafts and summaries** — `TherapistDashboardModel::generateDraft()` and `generateSummary()` called `callLlmApi()` directly without injecting the response schema. Now both use `TherapyMessageService::injectResponseSchema()` to prepend the structured JSON schema with safety instructions, matching the patient chat flow. Display content is extracted from structured JSON via `extractDisplayContent()`
- **`sent_context` only saved sender type** — AI response messages in `llmMessages` had `sent_context = {"therapy_sender_type":"ai"}` with no useful debugging info. Now includes `context_message_count` alongside sender type. The full API request payload (including all messages sent to the LLM) is saved in the `request_payload` column as before
- **Removed dead code**: `hasAccess()`, `getDangerDetection()` (TherapyChatModel), `getConversationById()` (TherapistDashboardModel), `removeTherapistFromGroup()` (TherapyChatService), `getErrorMessage()` (api.ts), `LLM_THERAPY_CHAT_PLUGIN_NAME` constant

### Changed
- Rewrote entire React frontend (types, API, hooks, all components)
- Single CSS file with `tc-` prefix instead of scattered per-component CSS
- Simplified `MessageInput` — no heavy mention library, matches `sh-shp-llm` UI patterns. Accepts `onFetchMentions` callback and `topicSuggestions` prop for autocomplete
- Cleaner `MessageList` with clear visual distinction per sender type. 24-hour time format
- Simplified `TherapistDashboard` with group tabs, draft modal, summarization modal, stat header
- Simplified `SubjectChat` — focused patient experience with paused-state awareness
- Updated `api.ts` to factory pattern (`createSubjectApi`, `createTherapistApi`) with `editNote`, `deleteNote`, `checkUpdates`, `generateSummary` endpoints
- Updated `useChatState` hook with stable refs, busy guard to prevent poll/load overlap, and string-safe ID dedup
- Updated `usePolling` hook — simpler interface
- Updated `TaggingPanel` — replaced tag reason buttons with a simple configurable help text label
- Polling now uses two-phase strategy: lightweight check first, full fetch only on changes
- `handleCreateDraft` backend now calls LLM API directly to generate draft content (no longer requires frontend to pass `ai_content`); uses configurable `therapy_draft_context` for AI instructions
- Draft editor now renders AI markdown as formatted HTML using a simple markdown-to-HTML converter
- **DraftEditor fix**: `internalRef` initialized to `null` (was `value`) so the first `useEffect` always fires and populates the contentEditable div with the AI-generated content
- Summary modal renders markdown via `MarkdownRenderer` (react-markdown with GFM support)
- Clinical notes render markdown content via `MarkdownRenderer` instead of plain text
- **MarkdownRenderer**: Added `rehype-raw` plugin to properly render `<br>` and other HTML tags in markdown content
- Draft creation includes access control check (`canAccessTherapyConversation`)
- Draft creation logs transaction for audit trail
- Improved CSS for markdown tables, headings, blockquotes, horizontal rules across all modals and note panels
- **TherapistDashboardController**: Refactored to thin controller — all business logic (message send/edit/delete, AI draft generation, summarization, conversation controls, notes, alerts, email notifications, speech-to-text) moved to `TherapistDashboardModel`
- **TherapyChatController**: Refactored to thin controller — message sending with danger detection, AI response processing, therapist tagging, speech transcription, and email notifications delegated to `TherapyChatModel`
- `TherapyChat.tsx` entry point exposes global mount functions (`window.TherapyChat.mount`, `window.__TherapyChatMount`) and listens for `therapy-chat-mount` custom events for dynamic mounting from server-rendered floating panels
- Updated all documentation to match new architecture

### Removed
- `TherapyTaggingService.php` — functionality in `TherapyAlertService`
- `react-mentions` dependency
- Individual component CSS files (consolidated into single file)
- Tag reason buttons from patient chat UI (replaced by help label)
- `v1.0.1.sql` migration — all schema changes consolidated into `v1.0.0.sql`
- Dead code and unused types
- React-side `FloatingChat.tsx` component — floating modal is now rendered server-side by the hook template
- Redundant `floating_chat_position`, `floating_chat_icon`, `floating_chat_label`, `floating_chat_title` style fields — these are already available in the main plugin config
