# Changelog

## v2.0.0 (2026-02-06) - Architecture Overhaul

### Breaking Changes
- Removed `therapyTags` table — tag functionality absorbed into `therapyAlerts` with `tag_received` alert type
- Removed `id_therapist` from `therapyConversationMeta` — multiple therapists per conversation supported via `therapyTherapistAssignments`
- Removed `id_groups` from `therapyConversationMeta` — access control via therapist-to-group assignments
- Removed `react-mentions` dependency — @mention is now simple text-based
- All React types renamed (e.g., `TherapyChatConfig` → `SubjectChatConfig`)

### Added
- **`therapyTherapistAssignments` table**: Maps therapists to patient groups they can monitor
- **`therapyDraftMessages` table**: AI draft generation + editing workflow
- **`therapy_enable_ai` field**: Per-style toggle to disable AI completely
- **Group tabs** on therapist dashboard: Patients separated by assigned groups
- **AI draft workflow**: Generate, edit, send or discard AI-suggested responses
- **Message editing**: Therapists can edit their own messages
- **Message soft-deletion**: Messages marked as deleted (not removed)
- **Unread tracking**: Per-patient unread message counts with badges
- **Alert-based tagging**: Tags create alerts with `metadata` JSON for reason/urgency
- **`globals.php`**: Proper plugin constant loading via SelfHelp loadPluginGlobals()
- Comprehensive documentation in `doc/` folder

### Changed
- Rewrote entire React frontend (types, API, hooks, all components)
- Single CSS file with `tc-` prefix instead of scattered per-component CSS
- Simplified `MessageInput` — no heavy mention library
- Cleaner `MessageList` with clear visual distinction per sender type
- Simplified `TherapistDashboard` with group tabs, draft panel, stat header
- Simplified `SubjectChat` — focused patient experience
- Updated `api.ts` to factory pattern (`createSubjectApi`, `createTherapistApi`)
- Updated `useChatState` hook to dependency-injection pattern
- Updated `usePolling` hook — simpler interface
- Updated all documentation to match new architecture

### Removed
- `TherapyTaggingService.php` — functionality in `TherapyAlertService`
- `react-mentions` dependency
- Individual component CSS files (consolidated into single file)
- `TaggingPanel` from `react-mentions` integration
- Dead code and unused types

## v1.0.0 (Initial Release)

- Patient chat with AI + therapist messaging
- Therapist dashboard with conversation list
- Alert and tag system
- Clinical notes
- Risk and status management
- Speech-to-text support
- Markdown rendering for AI messages
- Floating chat buttons
