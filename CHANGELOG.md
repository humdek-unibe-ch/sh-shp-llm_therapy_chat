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
- **Unread tracking**: Per-patient and per-group unread message counts with badges in patient list and group tabs
- **Alert-based tagging**: Tags create alerts with `metadata` JSON for reason/urgency
- **Clinical notes edit/delete**: Therapists can edit note content and soft-delete notes with full audit trail
- **Transaction logging**: All note CRUD operations, risk level changes, AI toggle changes, status changes, summary generation, and draft creation logged via `logTransaction()`
- **`globals.php`**: Proper plugin constant loading via SelfHelp loadPluginGlobals()
- **Paused conversation blocking**: Patients see a disabled notice when conversation is paused by therapist; no AI responses generated for paused conversations
- **Immediate risk/status UI update**: Risk level and status changes reflect immediately in the UI without full page reload
- **URL state persistence**: Therapist dashboard preserves selected group tab and patient in the URL (`?gid=...&uid=...`)
- **Speech-to-text**: Integrated with `sh-shp-llm` plugin's STT service, cursor-position text insertion
- **Floating chat badge**: Auto-clears on patient chat view and after each poll
- **Floating modal chat** (`therapyChat` style): When `enable_floating_chat` is enabled, the server-rendered floating icon opens an inline modal panel instead of navigating to the chat page. Icon, position, and label are controlled by the main plugin config (`therapy_chat_floating_icon`, `therapy_chat_floating_position`, `therapy_chat_floating_label`). Includes mobile-responsive backdrop, Escape-to-close, and unread badge clearing on open
- **Email notifications — therapist→patient**: When a therapist sends a message (or sends a draft), an email is queued to the patient via SelfHelp's `JobScheduler`. Configurable via `enable_patient_email_notification`, `patient_notification_email_subject`, `patient_notification_email_body` fields on `therapistDashboard` style. Placeholders: `@user_name`, `@therapist_name`
- **Email notifications — patient→therapist**: Therapists are emailed only when: (a) patient explicitly tags `@therapist`, or (b) AI is disabled for the conversation (all messages go to therapist). Tag messages use a separate template with message preview. Configurable via `enable_therapist_email_notification`, `therapist_notification_email_subject`, `therapist_notification_email_body`, `therapist_tag_email_subject`, `therapist_tag_email_body` fields on both `therapistDashboard` and `therapyChat` styles. Placeholders: `{{patient_name}}`, `{{message_preview}}`, `@user_name`. Default: enabled
- **Email notification shared fields**: `notification_from_email` and `notification_from_name` on both styles
- Comprehensive documentation in `doc/` folder

### Changed
- Rewrote entire React frontend (types, API, hooks, all components)
- Single CSS file with `tc-` prefix instead of scattered per-component CSS
- Simplified `MessageInput` — no heavy mention library, matches `sh-shp-llm` UI patterns
- Cleaner `MessageList` with clear visual distinction per sender type
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
