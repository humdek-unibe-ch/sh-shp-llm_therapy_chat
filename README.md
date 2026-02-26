# sh-shp-llm_therapy_chat

Therapy chat plugin for [SelfHelp CMS](https://github.com/humdek-unibe-ch/sh-selfhelp). Extends the [`sh-shp-llm`](../sh-shp-llm/) base plugin with therapy-specific features: a patient chat with AI and therapist messaging, a therapist monitoring dashboard, group-based access control, AI draft generation, clinical notes, risk management, and real-time notifications.

## Table of Contents

- [Overview](#overview)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Configuration](#configuration)
- [How It Works](#how-it-works)
- [Architecture](#architecture)
- [Development](#development)
- [Troubleshooting](#troubleshooting)
- [License](#license)

## Overview

This plugin adds two SelfHelp page styles:

1. **`therapyChat`** — Patient-facing chat. Patients converse with an AI assistant. Therapists can read all messages, intervene, and send messages directly. Patients can tag therapists with `@therapist` to bypass AI and reach a human.

2. **`therapistDashboard`** — Therapist-facing dashboard. Shows all assigned patients organized by group, with unread counts, alert banners, clinical notes, AI draft generation, conversation summarization, risk levels, and CSV export.

The two roles interact through the same conversation stored in the base plugin's `llmConversations` table, extended with therapy-specific metadata.

### Dependency

This plugin **requires** `sh-shp-llm` to be installed first. It extends the base plugin's `LlmService` and uses its conversation, message, and speech-to-text infrastructure.

## Prerequisites

| Requirement | Version | Notes |
|-------------|---------|-------|
| SelfHelp | v7.8.0+ | Core CMS framework |
| `sh-shp-llm` plugin | v1.0.0+ | Base LLM plugin (must be installed first) |
| PHP | 8.2+ | With cURL extension |
| MySQL | 8.0+ | InnoDB, utf8mb4 |
| Node.js | 16+ | For building frontend assets |
| LLM API | Any | Same as base plugin (GPUStack, BFH, etc.) |

## Installation

### 1. Install the Base Plugin

Follow the [`sh-shp-llm` installation instructions](../sh-shp-llm/README.md) first.

### 2. Place the Therapy Plugin

Copy the plugin folder into the SelfHelp plugins directory:

```
server/plugins/sh-shp-llm_therapy_chat/
```

### 3. Run Database Migration

```bash
mysql -u <user> -p <database> < server/plugins/sh-shp-llm_therapy_chat/server/db/v1.0.0.sql
```

This creates:
- 6 plugin tables (`therapyTherapistAssignments`, `therapyConversationMeta`, `therapyMessageRecipients`, `therapyAlerts`, `therapyNotes`, `therapyDraftMessages`)
- 3 database views
- Lookup values for modes, statuses, risk levels, alert types, and more
- Page types, styles, fields, hooks, and ACL entries

### 4. Build Frontend Assets

```bash
cd server/plugins/sh-shp-llm_therapy_chat/react
npm install
npm run build
```

Output: `js/ext/therapy-chat.umd.js` and `css/ext/therapy-chat.css`

### 5. Configure Pages

#### Module Configuration Page

1. Go to **Admin > Pages** and find the auto-created module page (`sh_module_llm_therapy_chat`)
2. Set the required fields:
   - `therapy_chat_subject_page` — Select the patient chat page
   - `therapy_chat_therapist_page` — Select the therapist dashboard page
   - `therapy_chat_enable_floating_button` — Enable/disable the floating chat button
   - Configure floating button icon, position, and label if enabled

#### Patient Chat Page

1. Create a new page or use the auto-created `therapyChatSubject` page
2. Add a section with style **`therapyChat`**
3. Configure the section fields (see [Configuration](#section-level-fields-therapychat))
4. Set ACL permissions (patients need access)

#### Therapist Dashboard Page

1. Create a new page or use the auto-created `therapyChatTherapist` page
2. Add a section with style **`therapistDashboard`**
3. Configure the section fields (see [Configuration](#section-level-fields-therapistdashboard))
4. Set ACL permissions (therapists need access)

#### Assign Therapists to Patient Groups

1. Go to **Admin > Users** and select a therapist user
2. In the user management page, find the "Therapist Group Assignments" section
3. Assign the therapist to one or more patient groups
4. The therapist will see patients from those groups in their dashboard

## Configuration

### Module-Level Fields

Configured on the `sh_module_llm_therapy_chat` page.

| Field | Type | Description |
|-------|------|-------------|
| `therapy_chat_subject_page` | select-page | Patient chat page |
| `therapy_chat_therapist_page` | select-page | Therapist dashboard page |
| `therapy_chat_enable_floating_button` | checkbox | Show floating chat button on all pages |
| `therapy_chat_floating_icon` | text | Font Awesome icon class (e.g., `fa-comments`) |
| `therapy_chat_floating_position` | select | Button position (bottom-right, bottom-left, etc.) |
| `therapy_chat_floating_label` | text | Tooltip text for the floating button |

### Section-Level Fields (therapyChat)

| Field | Type | Description |
|-------|------|-------------|
| `conversation_context` | markdown | System instructions for the AI |
| `therapy_enable_ai` | checkbox | Enable/disable AI responses for this section |
| `therapy_chat_mode` | select | Default mode: `ai_hybrid` or `human_only` |
| `therapy_auto_start` | checkbox | Insert welcome message on first visit |
| `therapy_auto_start_context` | markdown | Welcome message text |
| `therapy_chat_help_text` | text | Help text explaining @mention and #topic usage |
| `therapy_tag_reasons` | textarea | Predefined #topic suggestions (one per line) |
| `enable_speech_to_text` | checkbox | Enable voice input |
| `speech_to_text_model` | select | Whisper model |
| `enable_danger_detection` | checkbox | Enable LLM safety assessment |
| `danger_keywords` | text | Safety topic hints for LLM context |
| `danger_notification_emails` | text | Additional notification emails for safety events |
| `danger_blocked_message` | markdown | Message shown when conversation is blocked |
| `enable_therapist_email_notification` | checkbox | Email therapists on tag/non-AI messages |
| `therapist_notification_email_subject` | text | Email subject template |
| `therapist_notification_email_body` | textarea | Email body template |
| `therapist_tag_email_subject` | text | Tag-specific email subject |
| `therapist_tag_email_body` | textarea | Tag-specific email body |
| `notification_from_email` | text | Sender email address |
| `notification_from_name` | text | Sender display name |

### Section-Level Fields (therapistDashboard)

| Field | Type | Description |
|-------|------|-------------|
| `llm_model` | select | Model for AI drafts and summaries |
| `llm_temperature` | number | Temperature for AI drafts |
| `llm_max_tokens` | number | Max tokens for AI drafts |
| `conversation_context` | markdown | Context for AI drafts |
| `therapy_draft_context` | markdown | Additional instructions for AI draft generation |
| `therapy_summary_context` | markdown | Instructions for AI summarization |
| `dashboard_all_groups_tab` | checkbox | Show "All" tab combining all groups |
| `dashboard_stat_ai_enabled` | checkbox | Show AI-enabled count in stats |
| `dashboard_stat_ai_blocked` | checkbox | Show AI-blocked count in stats |
| `enable_patient_email_notification` | checkbox | Email patients when therapist sends message |
| `patient_notification_email_subject` | text | Email subject template |
| `patient_notification_email_body` | textarea | Email body template |
| `notification_from_email` | text | Sender email address |
| `notification_from_name` | text | Sender display name |

## How It Works

### Patient Flow

1. Patient visits the `therapyChat` page
2. A conversation is created (with optional auto-start welcome message)
3. Patient sends messages; AI responds with structured JSON (safety assessment included)
4. If the patient types `@therapist`, the message goes directly to all assigned therapists (no AI response); if `@Dr. Smith`, only that therapist is notified
5. If the LLM detects critical safety concerns, the conversation switches to human-only mode and therapists are urgently notified
6. Patient can use `#topic` tags to categorize messages

### Therapist Flow

1. Therapist opens the `therapistDashboard` page
2. Sees all assigned patients organized by group, with unread badges
3. Selects a patient to view the full conversation history
4. Can:
   - **Send a message** directly to the patient
   - **Generate an AI draft** — AI suggests a response; therapist edits and sends
   - **Summarize** the conversation — AI generates a clinical summary that can be saved as a note
   - **Add clinical notes** — Manual or AI-generated notes per conversation
   - **Change risk level** — Low, medium, high, critical
   - **Change status** — Active, paused, closed
   - **Toggle AI** — Enable or disable AI responses for the conversation
   - **Export CSV** — Download conversation history

### Safety Flow

1. Every AI response includes a `safety` field in the structured JSON schema
2. The `danger_keywords` CMS field provides context hints to the LLM (not matched server-side)
3. For critical/emergency danger levels:
   - Conversation is blocked (AI disabled)
   - Risk level escalated to critical
   - Alert created for all assigned therapists
   - Urgent email sent to therapists and configured notification addresses
4. Patient can still send messages (delivered to therapists), but AI no longer responds
5. Therapist can re-enable AI (which also unblocks the conversation)

## Architecture

```
sh-shp-llm_therapy_chat/
├── server/
│   ├── component/
│   │   ├── TherapyChatHooks.php           # Hook implementations
│   │   ├── style/
│   │   │   ├── therapyChat/               # Patient chat MVC
│   │   │   ├── therapistDashboard/        # Therapist dashboard MVC
│   │   │   ├── TherapyBaseController.php  # Shared controller base
│   │   │   ├── TherapyModelConfigTrait.php # Shared config access
│   │   │   └── TherapyViewHelper.php      # Asset versioning
│   │   └── TherapyChatHooks/tpl/          # Hook templates (floating icon, nav, assignments)
│   ├── service/
│   │   ├── TherapyChatService.php         # Core: conversations, access control
│   │   ├── TherapyAlertService.php        # Alerts and danger handling
│   │   ├── TherapyMessageService.php      # Messaging, AI responses, schema injection
│   │   ├── TherapyNotificationService.php # Centralized notifications
│   │   ├── TherapyEmailHelper.php         # Email scheduling via JobScheduler
│   │   └── TherapyPushHelper.php          # Push notification scheduling
│   ├── constants/TherapyLookups.php       # All lookup constants
│   ├── ajax/AjaxTherapyChat.php           # AJAX endpoint for assignments
│   └── db/
│       ├── v1.0.0.sql                     # Full schema
│       └── FUN_PRO_VIEWS/                 # Database views
├── react/src/
│   ├── TherapyChat.tsx                    # Entry point (mounts SubjectChat or TherapistDashboard)
│   ├── components/subject/SubjectChat.tsx # Patient chat component
│   ├── components/therapist/             # 15 therapist dashboard subcomponents
│   ├── components/shared/                # Shared UI components
│   ├── hooks/                            # 6 custom React hooks
│   ├── utils/                            # API client, helpers
│   └── types/index.ts                    # TypeScript type definitions
├── gulp/gulpfile.js                       # Build tasks
├── css/ext/therapy-chat.css               # Built CSS (tc- prefixed)
├── js/ext/
│   ├── therapy-chat.umd.js               # React bundle
│   ├── therapy_chat_floating.js           # Floating badge polling (vanilla JS)
│   └── therapy_assignments.js             # Admin assignment UI
└── doc/                                   # Documentation
```

### Service Hierarchy

```
LlmService (sh-shp-llm)
  └── TherapyChatService — Conversations, access control, therapist assignments
        └── TherapyAlertService — Danger alerts, tag alerts, mark-as-read
              └── TherapyMessageService — Send/receive messages, AI responses, schema injection
```

`TherapyNotificationService` is a standalone service that routes both patient-to-therapist and therapist-to-patient notifications through `TherapyEmailHelper` and `TherapyPushHelper`.

### Database Tables

| Table | Purpose |
|-------|---------|
| `therapyTherapistAssignments` | Maps therapists to patient groups |
| `therapyConversationMeta` | 1:1 extension of `llmConversations` — mode, status, risk, ai_enabled |
| `therapyMessageRecipients` | Per-user message delivery and read tracking |
| `therapyAlerts` | Danger and tag alerts with severity and metadata JSON |
| `therapyNotes` | Clinical notes with type (manual/ai_summary) and soft-delete |
| `therapyDraftMessages` | AI draft lifecycle (draft -> sent/discarded) |

### Database Views

| View | Purpose |
|------|---------|
| `view_therapyConversations` | Conversations with patient info and lookup values |
| `view_therapyAlerts` | Alerts with conversation and patient details |
| `view_therapyTherapistAssignments` | Therapist-group assignments with names |

## Development

### Building Frontend

```bash
cd server/plugins/sh-shp-llm_therapy_chat/react

npm install       # Install dependencies (first time)
npm run build     # Production build
npm run watch     # Development mode with file watching
```

Or via Gulp:

```bash
cd server/plugins/sh-shp-llm_therapy_chat/gulp

npx gulp build          # Full build
npx gulp react-install  # Install npm dependencies
npx gulp watch-react    # Watch mode
npx gulp clean          # Remove built files
```

### React Component Structure

The React app has a single entry point (`TherapyChat.tsx`) that checks the root element:
- `.therapy-chat-root` -> mounts `SubjectChat`
- `.therapist-dashboard-root` -> mounts `TherapistDashboard`

Key therapist subcomponents: `StatsHeader`, `AlertBanner`, `GroupTabs`, `PatientList`, `ConversationViewer`, `ConversationHeader`, `ConversationArea`, `NotesPanel`, `DraftEditor`, `SummaryModal`, `RiskStatusControls`.

Custom hooks: `useChatState`, `usePolling`, `useDraftState`, `useNoteEditor`, `useSummaryState`, `useConversationActions`.

### Adding New Features

See [doc/DEVELOPER_GUIDE.md](doc/DEVELOPER_GUIDE.md) for guidance on extending the plugin.

## Troubleshooting

| Symptom | Possible Cause | Solution |
|---------|---------------|----------|
| Chat not loading | Missing JS bundle | Run `npm run build` in `react/` |
| No patients in dashboard | No group assignments | Assign therapist to patient groups via user admin |
| AI not responding | AI disabled or conversation blocked | Check `therapy_enable_ai` field and conversation status in dashboard |
| Floating button not showing | Module config missing | Set `therapy_chat_enable_floating_button` and page fields in module config |
| Emails not sending | Missing config | Configure email fields on both `therapyChat` and `therapistDashboard` styles |
| Unread badges stuck | Polling issue | Verify the floating JS script is loaded; check browser console |
| "Page not found" on API calls | URL resolution issue | Ensure page routes are correctly configured in SelfHelp admin |

Check SelfHelp logs and `data/clockwork` for detailed error traces.

## Documentation

| Document | Description |
|----------|-------------|
| [Architecture](doc/architecture.md) | System design, database schema, service layers |
| [API Reference](doc/api-reference.md) | All frontend API endpoints and actions |
| [Configuration](doc/configuration.md) | Complete field reference for all settings |
| [Admin Setup](doc/ADMIN_SETUP.md) | Step-by-step installation and setup guide |
| [Developer Guide](doc/DEVELOPER_GUIDE.md) | How to extend the plugin |
| [User Guide](doc/USER_GUIDE.md) | Patient and therapist user guides |
| [Feature Roadmap](doc/FEATURE_ROADMAP.md) | Implemented features and future plans |

## License

Mozilla Public License, v. 2.0 — see [LICENSE](https://mozilla.org/MPL/2.0/).
