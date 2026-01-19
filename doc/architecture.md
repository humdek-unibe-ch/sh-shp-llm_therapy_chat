# Architecture Documentation

## Plugin Design Philosophy

The LLM Therapy Chat plugin follows a **extension-based architecture** that maximizes code reuse from the sh-shp-llm plugin while adding therapy-specific functionality.

### Core Principles

1. **No Code Duplication** - All LLM functionality is inherited, not reimplemented
2. **Single Data Source** - All chat data lives in the LLM plugin's tables
3. **Metadata Extension** - Therapy features add metadata, not duplicate data
4. **Service Inheritance** - PHP classes extend LlmService for full functionality

## Data Flow

```
[Subject] ─────> [TherapyChatController]
                        │
                        ▼
              [TherapyMessageService]
                        │
                        ├──> [LlmService.addMessage()] ──> llmMessages table
                        │
                        ├──> [Danger Detection] (from sh-shp-llm)
                        │
                        └──> [TherapyTaggingService] ──> therapyTags table
                                    │
                                    └──> [TherapyAlertService] ──> therapyAlerts table
```

## Table Relationships

```
llmConversations (from sh-shp-llm)
       │
       ├──── llmMessages (from sh-shp-llm)
       │           │
       │           └──── therapyTags
       │
       ├──── therapyConversationMeta (1:1)
       │
       ├──── therapyAlerts (1:many)
       │
       └──── therapyNotes (1:many)
```

## Service Hierarchy

```
LlmService (sh-shp-llm)
    │
    └── TherapyChatService
            │
            ├── TherapyMessageService
            │       │
            │       └── (uses parent addMessage, getMessages)
            │
            └── TherapyAlertService
                    │
                    └── TherapyTaggingService
```

## Component Structure

### Subject Chat (therapyChat style)

```
TherapyChatComponent
    │
    ├── TherapyChatModel
    │       │
    │       ├── CMS field access
    │       ├── Conversation state
    │       └── TherapyTaggingService instance
    │
    ├── TherapyChatView
    │       │
    │       └── Renders HTML + vanilla JavaScript
    │
    └── TherapyChatController
            │
            └── API endpoints: send, messages, tag
```

### Therapist Dashboard (therapistDashboard style)

```
TherapistDashboardComponent
    │
    ├── TherapistDashboardModel
    │       │
    │       ├── Conversation list access
    │       ├── Alert/tag retrieval
    │       └── Notes access
    │
    ├── TherapistDashboardView
    │       │
    │       └── Dashboard layout + controls
    │
    └── TherapistDashboardController
            │
            └── API endpoints: send, toggle-ai, set-risk, add-note
```

## Access Control

### Subject Access
- Can only access their own conversations
- Can send messages, tag therapist
- Cannot see therapist notes

### Therapist Access
- Can access conversations in their assigned groups
- Can send messages, toggle AI, set risk levels
- Can add private notes
- Full visibility in LLM Admin Console

### Admin Access
- Full access via LLM Admin Console
- Can block/unblock conversations
- Can view all debug data

## Message Attribution

All messages are stored in `llmMessages` with therapy metadata in the `sent_context` JSON field:

```json
{
    "therapy_sender_type": "subject|therapist|ai",
    "therapy_sender_id": 123,
    "therapy_mode": "ai_hybrid|human_only"
}
```

This allows:
- Proper sender attribution in the UI
- Filtering by sender type
- Full compatibility with LLM Admin Console

## Real-time Updates

The plugin uses **polling** for message updates:

1. Client polls `/request/{section}/therapy-chat/messages?after={lastId}` every N seconds
2. Server returns only messages newer than `lastId`
3. Client appends new messages to the UI

Polling interval is configurable via CMS field (default: 3 seconds).

### Future: WebSocket Support

The architecture is designed to allow WebSocket upgrade in the future:
- Same API endpoints can be used
- Message format is already compatible
- Only transport layer would change
