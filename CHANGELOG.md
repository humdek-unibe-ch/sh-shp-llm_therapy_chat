# Changelog

## v1.0.0 (2026-02-06) - Architecture Overhaul

### Breaking Changes
- Removed `therapyTags` table — tag functionality absorbed into `therapyAlerts` with `tag_received` alert type
- Removed `id_therapist` from `therapyConversationMeta` — multiple therapists per conversation supported via `therapyTherapistAssignments`
- Removed `id_groups` from `therapyConversationMeta` — access control via therapist-to-group assignments
- Removed `react-mentions` dependency — @mention is now simple text-based
- All React types renamed (e.g., `TherapyChatConfig` -> `SubjectChatConfig`)
- Removed tag reason buttons from patient chat — replaced with customizable help text label

### Added
- **`therapyTherapistAssignments` table**: Maps therapists to patient groups they can monitor
- **`therapyDraftMessages` table**: AI draft generation + editing workflow
- **`therapy_enable_ai` field**: Per-style toggle to disable AI completely
- **`therapy_chat_help_text` field**: Customizable help text explaining @mention and #hashtag usage (supports multilingual via field translations)
- **`therapy_summary_context` field**: Customizable summarization context for the therapist dashboard, guides AI summary output
- **`therapyNoteStatus` lookup**: Lookup-based soft-delete status for clinical notes (`active`, `deleted`)
- **`id_noteStatus` column** on `therapyNotes`: FK to lookups for note lifecycle status
- **`id_lastEditedBy` column** on `therapyNotes`: Tracks last therapist who edited a note
- **LLM config fields** on `therapistDashboard` style: `llm_model`, `llm_temperature`, `llm_max_tokens`, `conversation_context` for draft generation and summarization
- **Group tabs** on therapist dashboard: Patients separated by assigned groups with per-group unread counts
- **AI draft modal**: Full modal dialog where backend calls LLM to generate draft, therapist edits, then sends or discards
- **AI draft workflow**: Generate, edit, send or discard AI-suggested responses
- **Conversation summarization**: New "Summarize" button opens a modal showing an AI-generated clinical summary; can be saved as a clinical note. Summary requests create a separate LLM conversation for full audit trail
- **Lightweight polling**: `check_updates` endpoint returns only counts/latest message ID; full data fetch only when something changed (both patient and therapist sides)
- **Message editing**: Therapists can edit their own messages
- **Message soft-deletion**: Messages marked as deleted (not removed)
- **Unread tracking**: Per-patient and per-group unread message counts with badges in patient list and group tabs
- **Alert-based tagging**: Tags create alerts with `metadata` JSON for reason/urgency
- **Clinical notes edit/delete**: Therapists can edit note content and soft-delete notes with full audit trail
- **Transaction logging**: All note CRUD operations, risk level changes, AI toggle changes, status changes, and summary generation logged via `logTransaction()`
- **`globals.php`**: Proper plugin constant loading via SelfHelp loadPluginGlobals()
- **Paused conversation blocking**: Patients see a disabled notice when conversation is paused by therapist; no AI responses generated for paused conversations
- **Immediate risk/status UI update**: Risk level and status changes reflect immediately in the UI without full page reload
- **URL state persistence**: Therapist dashboard preserves selected group tab and patient in the URL (`?gid=...&uid=...`)
- **Speech-to-text**: Integrated with `sh-shp-llm` plugin's STT service, cursor-position text insertion
- **Floating chat badge**: Auto-clears on patient chat view and after each poll
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
- `handleCreateDraft` backend now calls LLM API directly to generate draft content (no longer requires frontend to pass `ai_content`)
- Updated all documentation to match new architecture

### Removed
- `TherapyTaggingService.php` — functionality in `TherapyAlertService`
- `react-mentions` dependency
- Individual component CSS files (consolidated into single file)
- Tag reason buttons from patient chat UI (replaced by help label)
- `v1.0.1.sql` migration — all schema changes consolidated into `v1.0.0.sql`
- Dead code and unused types
