# Architecture Overview

## Plugin Structure

```
sh-shp-llm_therapy_chat/
├── server/
│   ├── service/
│   │   ├── globals.php                    # Plugin constants loader
│   │   ├── TherapyChatService.php         # Base: conversations, assignments, ACL
│   │   ├── TherapyAlertService.php        # Extends above: alerts + tags
│   │   └── TherapyMessageService.php      # Extends above: messaging, drafts, recipients
│   ├── constants/
│   │   └── TherapyLookups.php             # All PHP constants (lookup codes)
│   ├── component/
│   │   ├── style/
│   │   │   ├── therapyChat/               # Patient chat (MVC)
│   │   │   │   ├── TherapyChatComponent.php
│   │   │   │   ├── TherapyChatModel.php
│   │   │   │   ├── TherapyChatView.php
│   │   │   │   ├── TherapyChatController.php
│   │   │   │   └── tpl/therapy_chat_main.php
│   │   │   └── therapistDashboard/        # Therapist dashboard (MVC)
│   │   │       ├── TherapistDashboardComponent.php
│   │   │       ├── TherapistDashboardModel.php
│   │   │       ├── TherapistDashboardView.php
│   │   │       ├── TherapistDashboardController.php
│   │   │       └── tpl/therapist_dashboard_main.php
│   │   ├── TherapyChatHooks.php           # Hook implementations
│   │   └── TherapyChatHooks/tpl/          # Hook templates
│   └── db/
│       ├── v1.0.0.sql                     # Full schema + lookups + hooks
│       └── FUN_PRO_VIEWS/                 # Standalone view definitions
├── react/src/                             # React frontend
│   ├── TherapyChat.tsx                    # Entry point & auto-mount
│   ├── types/index.ts                     # All TypeScript interfaces
│   ├── utils/api.ts                       # API communication layer
│   ├── hooks/
│   │   ├── useChatState.ts                # Shared chat state hook
│   │   └── usePolling.ts                  # Interval polling hook
│   ├── components/
│   │   ├── subject/SubjectChat.tsx        # Patient chat UI
│   │   ├── therapist/TherapistDashboard.tsx  # Therapist dashboard UI
│   │   └── shared/                        # Reusable components
│   │       ├── MessageList.tsx
│   │       ├── MessageInput.tsx
│   │       ├── LoadingIndicator.tsx
│   │       ├── TaggingPanel.tsx
│   │       └── MarkdownRenderer.tsx
│   └── styles/therapy-chat.css            # All custom CSS (single file)
├── js/ext/therapy-chat.umd.js             # Built JS bundle
├── css/ext/therapy-chat.css               # Built CSS bundle
└── doc/                                   # Documentation
```

## Database Schema

### Core Principle: Extend, Don't Modify

The plugin extends the `sh-shp-llm` base tables (`llmConversations`, `llmMessages`)
without altering them. All therapy-specific data lives in separate tables.

### Tables

| Table | Purpose |
|-------|---------|
| `therapyTherapistAssignments` | Maps therapist users → patient groups they monitor |
| `therapyConversationMeta` | 1:1 extension of `llmConversations` with therapy metadata |
| `therapyMessageRecipients` | Per-user message delivery / read tracking |
| `therapyAlerts` | All notifications (danger, tags, activity, inactivity) |
| `therapyNotes` | Clinical notes per conversation |
| `therapyDraftMessages` | AI draft editing workflow for therapists |

### Key Design Decisions

**No `id_therapist` on conversations**: Multiple therapists can interact with one conversation.
Sender identity is tracked in `llmMessages.sent_context` JSON:
```json
{
  "therapy_sender_type": "therapist",
  "therapy_sender_id": 12345
}
```

**No `id_groups` on conversations**: Access control is via `therapyTherapistAssignments`.
A therapist sees conversations from patients who belong to groups the therapist is assigned to.

**Tags absorbed into alerts**: The `tag_received` alert type with `metadata` JSON replaces
the old separate `therapyTags` table.

### Access Control Flow

```
Therapist opens dashboard
  → therapyTherapistAssignments: therapist → [group_1, group_2]
  → users_groups: find patients in those groups
  → llmConversations: patient's conversation(s)
  → therapyConversationMeta: therapy metadata
```

## Service Architecture

```
TherapyMessageService (top-level)
  └── extends TherapyAlertService
        └── extends TherapyChatService
              └── extends LlmService (from sh-shp-llm)
```

| Service | Responsibility |
|---------|---------------|
| `TherapyChatService` | Conversations, assignments, settings, access control |
| `TherapyAlertService` | Alerts (danger, tag, activity), notifications |
| `TherapyMessageService` | Sending messages, editing, deleting, drafts, recipients |

### Danger Detection

When the LLM's safety assessment returns `danger_level: critical` or `emergency`, `TherapyAlertService::sendUrgentNotification()` sends emails to assigned therapists and to addresses in the `danger_notification_emails` CMS field (e.g., clinical supervisors). Emails are deduplicated. There is no server-side keyword matching — safety detection is purely context-based via the LLM.

## React Frontend Architecture

The frontend is built as a single UMD bundle. Two React apps mount on different
DOM containers:

- `.therapy-chat-root` → `SubjectChat` (patient view)
- `.therapist-dashboard-root` → `TherapistDashboard` (therapist view)

Configuration is passed via `data-config` JSON attribute from PHP.

When the floating chat is enabled, the hook renders a panel that loads `therapy-chat.css`
explicitly via a `<link>` tag so styles apply on any page; the React app mounts into
the panel after config is fetched.

### Component Hierarchy

```
TherapyChat.tsx (entry point)
├── SubjectChatLoader → SubjectChat
│   ├── MessageList
│   ├── MessageInput
│   ├── TaggingPanel
│   └── LoadingIndicator
└── TherapistDashboardLoader → TherapistDashboard
    ├── MessageList
    ├── MessageInput
    ├── LoadingIndicator
    └── StatItem (inline)
```

### Data Flow

All API calls go through the current page's controller via `?action=xxx`.
Security is handled by SelfHelp's session + ACL system.

```
React Component
  → utils/api.ts (fetch with action param)
  → PHP Controller (TherapyChatController / TherapistDashboardController)
  → PHP Service layer (TherapyMessageService)
  → Database
```
