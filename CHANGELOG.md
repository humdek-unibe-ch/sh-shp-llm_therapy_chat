# Changelog

All notable changes to the **sh-shp-llm_therapy_chat** plugin are documented in this file.

## [1.1.2] - 2026-03-24 - Not released yet

### Fixed

- Fixed loading the floating-mode icon for therapy chats.
- **Fatal `Call to a member function isSubject() on null` on mobile page
  responses.** `TherapyChatHooks::addTherapyChatToMobileResponse()` and the
  surrounding notification / role-detection helpers accessed
  `$this->messageService` directly, but the property is lazily instantiated
  via `getMessageService()`. On requests where the lazy getter had not run
  yet (typical for mobile page responses) the property was `null` and the
  first `isSubject()` call crashed the whole mobile response. All eight
  call sites in `TherapyChatHooks.php` now consistently go through
  `getMessageService()` so the service is guaranteed to be initialized.

## [1.1.1] - 2026-03-20

### Fixed

- Fixed CMS plugin-version rendering when a plugin exposes multiple `*Hooks.php` classes.
- Ensured all therapy hook classes resolve the same plugin DB version (`llm_therapy_chat`) for `version` component output.

### Changed

- Introduced shared hook base class `server/component/TherapyPluginHooksBase.php`.
- Refactored `TherapyChatHooks` and `TherapyPromptLabHooks` to inherit from the shared therapy base instead of duplicating plugin-version logic.

## [1.1.0] - 2026-03-16

### Prompt asset externalization

- Moved therapy hardcoded LLM-facing prompts/instructions to `assets/prompts/therapy/`.
- Added key-based therapy prompt loader/registry:
  - `server/service/prompt/TherapyPromptAssetRegistry.php`
  - `server/service/prompt/TherapyPromptAssetLoader.php`
- Updated therapy runtime and dashboard prompt generation paths to load prompt text from assets.
- Added `doc/prompt-assets.md` with maintenance and troubleshooting guidance.

### Added

- Added migration `server/db/v1.1.0.sql` to enable therapy prompt fields on the shared `llm_prompt` field type from `sh-shp-llm`.
- Therapy prompt fields now use the shared prompt-lab UX (version history, diff, restore, save-comment metadata) via base-plugin hooks and services.
- Added runtime-aware prompt-lab compatibility for therapy prompt slots through the base plugin:
  - `conversation_context` resolves to therapy-aware execution where applicable
  - `therapy_draft_context` resolves to `therapy_draft_runtime`
  - `therapy_summary_context` resolves to `therapy_summary_runtime`
  - `therapy_auto_start_context` resolves to `therapy_chat_runtime`
- Added dataset/evaluation replay compatibility for therapy prompt-lab contexts (including conversation-based case import and replay).

- Took ownership of therapy prompt-lab runtime mapping through therapy-plugin hook extensions:
  - `conversation_context` (therapy styles) -> `therapy_chat_runtime` / `therapy_draft_runtime`
  - `therapy_draft_context` -> `therapy_draft_runtime`
  - `therapy_summary_context` -> `therapy_summary_runtime`
  - `therapy_auto_start_context` -> `therapy_chat_runtime`
- Added modular therapy prompt-lab extension services for:
  - runtime profile mapping
  - companion-field mapping
  - playground runtime policy/default prompts
  - dataset case/profile mapping
- Added `TherapyPromptLabHooks` and registered hook interceptors for base prompt-lab/dataset extension seam methods.
- Added therapy execution-profile lookup ownership in `server/db/v1.1.0.sql`:
  - `therapy_chat_runtime`
  - `therapy_draft_runtime`
  - `therapy_summary_runtime`

### Changed

- Updated plugin DB/runtime version to `v1.1.0`.
- Switched the following field definitions to `llm_prompt`:
  - `conversation_context`
  - `therapy_draft_context`
  - `therapy_summary_context`
  - `therapy_auto_start_context`
- Moved therapy runtime/dataset replay profile ownership out of `sh-shp-llm` base plugin and into this plugin.
- Updated dependency documentation to explicitly state one-way dependency:
  - `sh-shp-llm_therapy_chat` depends on `sh-shp-llm`
  - `sh-shp-llm` has no runtime dependency on `sh-shp-llm_therapy_chat`

### Dependency

- Requires `sh-shp-llm` **v1.1.0+** (provides `llm_prompt` field type, prompt registry tables, and prompt-lab hooks/endpoints).

## [1.0.2] - 2026-03-10

### Changed

- Unified model identifier handling with the base `sh-shp-llm` plugin by reusing the shared canonical model normalization path.
- Therapy conversation model persistence now uses the base plugin's scoped model format (`Server Name :: model-id`) for consistent server routing.
- Fixed therapist dashboard fallback model resolution to use `llm_default_model` from the base plugin config (instead of the wrong `llm_model` key).
- Kept all LLM execution and server/API-key resolution centralized in the base plugin service layer.

### Dependency

- Requires `sh-shp-llm` **v1.1.0+**.

## [1.0.1] - 2026-03-04

### Changed

- Migrated all therapy plugin `callLlmApi(...)` usage to the centralized strict logging flow in `sh-shp-llm` v1.1.0 (`callLlmApi(..., log_options)`).
- Removed duplicated manual assistant `addMessage(...)` writes for draft, summary, and patient AI-response paths to avoid double logging and inconsistent payload/context storage.
- Ensured user request messages are logged before LLM assistant responses in therapist tools conversations (drafts and summaries).
- Added structured `sent_context` metadata to centralized assistant logs (`therapy_sender_type`, sender IDs, and `llm_context`) so therapy sender labeling and audit traces remain complete.
- Kept therapy UI readable by updating centrally logged assistant message content to extracted display text after structured JSON responses.

### Dependency

- Requires `sh-shp-llm` **v1.1.0+**.

## [1.0.0] - 2026-02-26

Initial release. Extends the `sh-shp-llm` base plugin with therapy-specific features: a patient chat with AI and therapist messaging, a therapist dashboard for monitoring multiple patients, group-based access control, AI draft generation, clinical notes, risk management, and real-time notifications.

### Patient Chat (`therapyChat` style)

- **AI + Therapist Messaging** — Patients chat with an AI assistant; therapists can read all messages and intervene at any time
- **@Mention Tagging** — Patients type `@therapist` or `@SpecificName` to tag therapists directly; tagged messages skip AI response and notify the therapist via email
- **#Topic Suggestions** — Typing `#` opens a dropdown of predefined tag reasons/topics configured in CMS
- **Autocomplete** — Keyboard navigation (arrow keys, Enter, Tab, Escape) for @mention and #topic dropdowns
- **Paused Conversation Handling** — When a therapist pauses the conversation, the patient sees a disabled notice and cannot send messages
- **Blocked Conversation Recovery** — When danger is detected, the conversation switches to human-only mode (AI disabled); patients can still write messages that go directly to therapists
- **Help Text** — Configurable `therapy_chat_help_text` field explains @mention and #topic usage to patients
- **Speech-to-Text** — Integrated with the base `sh-shp-llm` plugin's Whisper transcription service
- **Auto-Start** — Optional welcome message inserted when a conversation is first created (configurable via `therapy_auto_start` and `therapy_auto_start_context` fields)
- **Floating Chat Button** — Configurable floating button that links to the chat page, with real-time unread badge polling

### Therapist Dashboard (`therapistDashboard` style)

- **Patient List** — Shows all assigned patients across groups, including those without conversations yet; unread badges per patient
- **Group Tabs** — Patients organized by assigned group with per-group unread counts and an optional "All" tab
- **Conversation Viewer** — Full message history with sender type indicators (patient, AI, therapist, system), markdown rendering, and 24-hour timestamps
- **Message Actions** — Therapists can edit their own messages and soft-delete any message
- **Mark as Read** — Explicit "Mark read" button and automatic read-tracking on conversation open
- **AI Draft Workflow** — Generate an AI draft, edit the rendered markdown, regenerate (with undo), then send or discard; drafts saved to `therapyDraftMessages` with full audit trail in `llmMessages`
- **Conversation Summarization** — AI-generated clinical summaries via a "Summarize" button; summaries can be saved as clinical notes
- **Clinical Notes** — Per-conversation notes panel with create, edit, soft-delete, and full audit trail; supports both manual notes and AI-generated summaries with markdown rendering
- **Risk Level Management** — Set patient risk level (low/medium/high/critical) with immediate UI update and transaction logging
- **Conversation Status** — Set conversation status (active/paused/closed) with transaction logging
- **AI Toggle** — Enable or disable AI per conversation; re-enabling AI also unblocks previously blocked conversations
- **Therapist-Initiated Conversations** — Start conversations for patients who haven't chatted yet; no auto-start gate for therapists
- **CSV Export** — Export conversations as semicolon-delimited CSV files; three scopes: single patient, group, or all accessible conversations
- **Stats Header** — Dashboard header showing total patients, active conversations, AI-enabled count, AI-blocked count, and unread alert count
- **Alert Banner** — Displays unread critical alerts with "Dismiss" and "Dismiss all" actions
- **URL State** — Selected group and patient preserved in the URL (`?gid=...&uid=...`)

### Safety Detection

- **Context-Based Only** — Safety assessment relies entirely on the LLM's structured JSON response `safety` field; no server-side keyword matching
- **Danger Keywords as Context** — The `danger_keywords` CMS field provides topic hints injected into LLM context, not matched server-side
- **Automatic Escalation** — Critical/emergency danger levels trigger: conversation blocking, AI disable, risk escalation to critical, and urgent email to all assigned therapists
- **Custom Blocked Message** — Configurable `danger_blocked_message` shown to patients when conversation is blocked
- **Audit Trail** — All safety detections logged via the transaction service

### Notifications

- **Therapist Notifications (patient to therapist)** — Email sent when patient tags `@therapist` or when AI is disabled (all messages go to therapists)
- **Patient Notifications (therapist to patient)** — Email sent when therapist sends a message or sends a draft
- **Configurable Templates** — Separate email subject/body fields for tag notifications and regular notifications, with placeholders (`@user_name`, `@therapist_name`, `{{patient_name}}`, `{{message_preview}}`)
- **Configurable Sender** — `notification_from_email` and `notification_from_name` fields on both styles
- **SelfHelp Integration** — All emails queued via SelfHelp's `JobScheduler::add_and_execute_job()`

### Access Control

- **Group-Based Assignments** — `therapyTherapistAssignments` table maps therapists to patient groups they can monitor
- **Admin User Page Integration** — Therapist group assignments managed via a hook on the user admin page
- **Per-Conversation Access** — All therapist operations verify access via `canAccessTherapyConversation()` before proceeding

### Polling and Real-Time Updates

- **Two-Phase Polling** — Lightweight `check_updates` endpoint returns only counts and latest message ID; full data fetch only when changes are detected
- **Floating Badge Polling** — Vanilla JS polling (every 3 seconds) updates the floating button unread badge on all pages without React
- **Visibility-Aware** — React polling pauses when the floating modal is hidden (via MutationObserver) to prevent incorrect read-marking

### Mobile Support

- **Mobile Page Response Hook** — `addTherapyChatToMobileResponse()` hook adds `therapy_chat` field to every mobile page response with `available`, `section_id`, `url`, `unread_count`, `icon`, `label`, `role`, and more
- **POST Polling Support** — All polling actions (`check_updates`, `get_conversation`, `get_messages`, `get_unread_counts`) work via both GET and POST for mobile compatibility
- **Mobile Config** — `output_content_mobile()` includes `polling_interval` and `chat_config` in the JSON response

### Architecture

- **Service Hierarchy** — `LlmService` (base) -> `TherapyChatService` -> `TherapyAlertService` -> `TherapyMessageService`
- **Shared Services** — `TherapyNotificationService`, `TherapyEmailHelper`, `TherapyPushHelper` for centralized notification handling
- **Shared Traits** — `TherapyModelConfigTrait` for config field access, `TherapyViewHelper` for asset versioning
- **Thin Controllers** — All business logic in models; controllers handle input validation and delegation only
- **Therapist Tools Conversation** — Shared LLM conversation per therapist+section for AI drafts and summaries, keeping audit messages separate from patient conversations
- **React Frontend** — Single entry point (`TherapyChat.tsx`) mounts `SubjectChat` or `TherapistDashboard` based on the root element; 15 therapist subcomponents, 6 custom hooks
- **Database Views** — `view_therapyConversations`, `view_therapyAlerts`, `view_therapyTherapistAssignments` for efficient queries

### Database Tables

| Table | Purpose |
|-------|---------|
| `therapyTherapistAssignments` | Maps therapists to patient groups |
| `therapyConversationMeta` | Extension of `llmConversations` with mode, status, risk, ai_enabled |
| `therapyMessageRecipients` | Per-user message delivery and read status |
| `therapyAlerts` | Danger and tag alerts with severity and metadata |
| `therapyNotes` | Clinical notes (manual and AI summary) with soft-delete |
| `therapyDraftMessages` | AI draft workflow (draft/sent/discarded lifecycle) |

### Hooks

| Hook | Target | Purpose |
|------|--------|---------|
| `outputTherapyChatIcon` | `NavView::output_profile` | Floating chat icon in navigation |
| `therapyChat-therapist-assignments` | `UserSelectView::output_user_manipulation` | Group assignment UI on user admin page |
| `therapyChatLLM - load JS scripts` | `BasePage::get_js_includes` | Load floating badge and assignment scripts |
| `therapyChatMobile - mobile page info` | `BasePage::output_base_content_mobile` | Add therapy chat data to mobile responses |
| `field-select-page-edit/view` | `CmsView` | Custom page selector field type |
| `field-select-floating-position-edit/view` | `CmsView` | Custom position selector field type |

### Configuration

Module-level fields (on the `sh_module_llm_therapy_chat` page):

| Field | Type | Description |
|-------|------|-------------|
| `therapy_chat_subject_page` | select-page | Patient chat page |
| `therapy_chat_therapist_page` | select-page | Therapist dashboard page |
| `therapy_chat_enable_floating_button` | checkbox | Show floating chat button |
| `therapy_chat_floating_icon` | text | Font Awesome icon class |
| `therapy_chat_floating_position` | select | Button position (bottom-right, etc.) |
| `therapy_chat_floating_label` | text | Tooltip label |

Style-level fields are documented in [doc/configuration.md](doc/configuration.md).
