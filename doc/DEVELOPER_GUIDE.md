# Developer Guide - LLM Therapy Chat Plugin

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Prerequisites & Dependencies](#prerequisites--dependencies)
4. [Installation](#installation)
5. [Directory Structure](#directory-structure)
6. [Backend Architecture](#backend-architecture)
7. [Frontend Architecture](#frontend-architecture)
8. [Database Schema](#database-schema)
9. [API Reference](#api-reference)
10. [Services](#services)
11. [Configuration](#configuration)
12. [Security Considerations](#security-considerations)
13. [Testing](#testing)
14. [Extending the Plugin](#extending-the-plugin)

---

## Overview

The LLM Therapy Chat plugin extends the SelfHelp platform to provide AI-assisted therapeutic conversations with full therapist monitoring and intervention capabilities. It is designed as an **extension** of the `sh-shp-llm` plugin, leveraging its conversation and message management infrastructure while adding therapy-specific features.

### Key Design Principles

1. **Extension-Based Architecture** - All LLM functionality is inherited from `sh-shp-llm`, not reimplemented
2. **Single Data Source** - All messages stored in `llmMessages`, all conversations in `llmConversations`
3. **Metadata Extension** - Therapy features add metadata tables, not duplicate data
4. **Service Inheritance** - PHP services extend `LlmService` for full functionality
5. **React Frontend** - Modern React 18 UI compiled to UMD for SelfHelp integration

### Clinical Boundaries (Built-in Safeguards)

- AI is clearly labeled as "AI Assistant" - **not** a therapist
- Danger detection with automatic alerts
- Therapist has full override control
- Audit trails for all actions
- Clear disclaimers to patients

---

## Architecture

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                      SelfHelp Platform                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                    sh-shp-llm Plugin                     │   │
│  │  ┌──────────────────┐  ┌──────────────────┐            │   │
│  │  │ llmConversations │  │   llmMessages    │            │   │
│  │  │    (Table)       │  │     (Table)      │            │   │
│  │  └────────┬─────────┘  └────────┬─────────┘            │   │
│  │           │                     │                       │   │
│  │  ┌────────┴─────────────────────┴────────────────┐     │   │
│  │  │              LlmService (Base)                 │     │   │
│  │  │  - Conversation CRUD                          │     │   │
│  │  │  - Message management                         │     │   │
│  │  │  - LLM API calls                              │     │   │
│  │  │  - Danger detection                           │     │   │
│  │  └────────────────────────────────────────────────┘     │   │
│  └─────────────────────────────────────────────────────────┘   │
│                              │                                  │
│                              │ extends                          │
│                              ▼                                  │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │             sh-shp-llm_therapy_chat Plugin              │   │
│  │                                                         │   │
│  │  ┌─────────────────────────┐  ┌──────────────────────┐ │   │
│  │  │ therapyConversationMeta │  │    therapyAlerts     │ │   │
│  │  │  - id_therapist         │  │  - alert_type        │ │   │
│  │  │  - mode (ai/human)      │  │  - severity          │ │   │
│  │  │  - risk_level           │  │  - is_read           │ │   │
│  │  │  - ai_enabled           │  └──────────────────────┘ │   │
│  │  └─────────────────────────┘                           │   │
│  │                                                         │   │
│  │  ┌─────────────────────────┐  ┌──────────────────────┐ │   │
│  │  │      therapyTags        │  │    therapyNotes      │ │   │
│  │  │  - @mention tags        │  │  - therapist notes   │ │   │
│  │  │  - urgency level        │  │  - conversation notes│ │   │
│  │  └─────────────────────────┘  └──────────────────────┘ │   │
│  │                                                         │   │
│  │  ┌────────────────────────────────────────────────┐    │   │
│  │  │    TherapyChatService (extends LlmService)     │    │   │
│  │  │  - Therapy conversation management             │    │   │
│  │  │  - Access control (subject/therapist)          │    │   │
│  │  │  - Mode management (AI/human)                  │    │   │
│  │  └────────────────────────────────────────────────┘    │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

### Data Flow

```
┌──────────┐         ┌────────────────────┐         ┌──────────────────┐
│  Patient │ ──────▶ │ TherapyChatController │ ──────▶ │ TherapyMessageService │
└──────────┘         └────────────────────┘         └──────────────────┘
                                                              │
                     ┌────────────────────────────────────────┼────────────────┐
                     │                                        │                │
                     ▼                                        ▼                ▼
          ┌──────────────────┐              ┌──────────────────┐    ┌──────────────────┐
          │ LlmService.addMessage() │        │ TherapyTaggingService │    │ TherapyAlertService │
          │ → llmMessages table │           │ → therapyTags table │    │ → therapyAlerts table │
          └──────────────────┘              └──────────────────┘    └──────────────────┘
                     │
                     ▼
          ┌──────────────────┐
          │  Danger Detection │
          │  (from sh-shp-llm) │
          └──────────────────┘
```

---

## Prerequisites & Dependencies

### Required

| Dependency | Version | Purpose |
|------------|---------|---------|
| PHP | 8.2+ (8.3 recommended) | Backend runtime |
| MySQL | 8.0+ | Database |
| Node.js | 18+ | Build tools |
| sh-shp-llm plugin | >= 1.0.0 | **REQUIRED** - Base LLM functionality |

### PHP Extensions

- PDO MySQL
- JSON
- mbstring
- APCu (for caching)

### Frontend Dependencies (managed via npm)

- React 18
- TypeScript 5
- Vite (build tool)
- Bootstrap 5

---

## Installation

### Step 1: Install sh-shp-llm Plugin First

```bash
# The llm plugin MUST be installed before this plugin
cd server/plugins/sh-shp-llm
mysql -u username -p database < server/db/v1.0.0.sql
```

### Step 2: Run Database Migration

```bash
cd server/plugins/sh-shp-llm_therapy_chat
mysql -u username -p database < server/db/v1.0.0.sql
```

### Step 3: Build React Frontend

```bash
# Navigate to gulp directory
cd gulp

# Install gulp dependencies
npm install

# Install React dependencies
gulp react-install

# Build the React components
gulp build
```

### Step 4: Configure the Plugin

1. Navigate to `/admin/module_llm_therapy_chat`
2. Set subject group (patients)
3. Set therapist group
4. Configure AI settings and danger keywords

---

## Directory Structure

```
sh-shp-llm_therapy_chat/
├── CHANGELOG.md                    # Version history
├── README.md                       # Quick start guide
├── css/
│   └── ext/
│       └── therapy-chat.css        # Compiled CSS output
├── doc/
│   ├── api-reference.md            # API documentation
│   ├── architecture.md             # Architecture overview
│   ├── configuration.md            # Configuration guide
│   ├── DEVELOPER_GUIDE.md          # This file
│   ├── USER_GUIDE.md               # User/therapist guide
│   └── ADMIN_SETUP.md              # Administrator setup guide
├── gulp/
│   ├── gulpfile.js                 # Build tasks
│   └── package.json                # npm dependencies
├── js/
│   └── ext/
│       └── therapy-chat.umd.js     # Compiled React bundle
├── react/
│   ├── src/
│   │   ├── components/
│   │   │   ├── shared/             # Shared React components
│   │   │   │   ├── LoadingIndicator.tsx
│   │   │   │   ├── MessageInput.tsx
│   │   │   │   ├── MessageList.tsx
│   │   │   │   └── TaggingPanel.tsx
│   │   │   ├── subject/            # Patient chat component
│   │   │   │   └── SubjectChat.tsx
│   │   │   └── therapist/          # Therapist dashboard
│   │   │       └── TherapistDashboard.tsx
│   │   ├── hooks/
│   │   │   ├── useChatState.ts     # Chat state management
│   │   │   └── usePolling.ts       # Message polling hook
│   │   ├── types/
│   │   │   └── index.ts            # TypeScript type definitions
│   │   ├── utils/
│   │   │   └── api.ts              # API client utilities
│   │   └── TherapyChat.tsx         # Main entry point
│   ├── tsconfig.json
│   └── vite.config.ts              # Vite build configuration
└── server/
    ├── component/
    │   ├── style/
    │   │   ├── therapyChat/        # Subject chat component
    │   │   │   ├── TherapyChatComponent.php
    │   │   │   ├── TherapyChatController.php
    │   │   │   ├── TherapyChatModel.php
    │   │   │   ├── TherapyChatView.php
    │   │   │   └── tpl/
    │   │   │       └── therapy_chat_main.php
    │   │   └── therapistDashboard/ # Therapist dashboard component
    │   │       ├── TherapistDashboardComponent.php
    │   │       ├── TherapistDashboardController.php
    │   │       ├── TherapistDashboardModel.php
    │   │       ├── TherapistDashboardView.php
    │   │       └── tpl/
    │   │           └── therapist_dashboard_main.php
    │   ├── TherapyChatHooks/
    │   │   └── tpl/
    │   │       └── floating_chat_icon.php
    │   └── TherapyChatHooks.php    # Hook implementations
    ├── constants/
    │   └── TherapyLookups.php      # Lookup constants
    ├── db/
    │   ├── FUN_PRO_VIEWS/
    │   │   ├── 01_view_therapyConversations.sql
    │   │   ├── 02_view_therapyTags.sql
    │   │   └── 03_view_therapyAlerts.sql
    │   └── v1.0.0.sql              # Database schema
    └── service/
        ├── TherapyAlertService.php
        ├── TherapyChatService.php
        ├── TherapyMessageService.php
```

---

## Backend Architecture

### Service Hierarchy

All services extend `LlmService` from the sh-shp-llm plugin:

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

### TherapyChatService

Core service for therapy conversation management:

```php
class TherapyChatService extends LlmService
{
    // Creates therapy conversation with metadata
    public function getOrCreateTherapyConversation($userId, $groupId, $sectionId, $mode, $model);
    
    // Get conversation with therapy metadata
    public function getTherapyConversation($conversationId);
    
    // Get conversations for therapist dashboard
    public function getTherapyConversationsByTherapist($therapistId, $filters, $limit, $offset);
    
    // Access control
    public function canAccessTherapyConversation($userId, $conversationId);
    public function isTherapistForGroup($userId, $groupId);
    public function isSubject($userId);
    public function isTherapist($userId);
    
    // Mode management
    public function setTherapyMode($conversationId, $mode);
    public function setAIEnabled($conversationId, $enabled);
    public function updateRiskLevel($conversationId, $riskLevel);
}
```

### TherapyMessageService

Extends message handling with sender attribution:

```php
class TherapyMessageService extends TherapyChatService
{
    const SENDER_AI = 'ai';
    const SENDER_THERAPIST = 'therapist';
    const SENDER_SUBJECT = 'subject';
    const SENDER_SYSTEM = 'system';
    
    // Send message with therapy metadata
    public function sendTherapyMessage($conversationId, $senderId, $content, $senderType, $attachments, $metadata);
    
    // Process AI response
    public function processAIResponse($conversationId, $contextMessages, $model, $temperature, $maxTokens);
    
    // Get messages with sender info
    public function getTherapyMessages($conversationId, $limit, $afterId);
}
```

### TherapyAlertService

Smart notification system:

```php
class TherapyAlertService extends TherapyChatService
{
    // Create alerts
    public function createAlert($conversationId, $alertType, $message, $severity, $targetUserId, $metadata);
    public function createDangerAlert($conversationId, $detectedKeywords, $userMessage);
    public function createTagAlert($conversationId, $tagId, $therapistId, $reason, $urgency);
    
    // Retrieve alerts
    public function getAlertsForTherapist($therapistId, $filters, $limit, $offset);
    public function getUnreadAlertCount($therapistId);
    
    // Manage alerts
    public function markAlertRead($alertId, $userId);
    public function markAllAlertsRead($therapistId, $conversationId);
}
```

### TherapyTaggingService

@mention functionality:

```php
class TherapyTaggingService extends TherapyAlertService
{
    // Tag reasons configuration
    public function getDefaultTagReasons();
    
    // Create tags
    public function createTagWithAlert($messageId, $conversationId, $therapistId, $reasonKey, $urgency);
    public function tagConversationTherapist($conversationId, $messageId, $reasonKey, $urgency);
    
    // Retrieve/manage tags
    public function getPendingTagsForTherapist($therapistId, $limit);
    public function acknowledgeTag($tagId, $therapistId);
}
```

### Component Structure (MVC Pattern)

Each component follows SelfHelp's MVC pattern:

```
TherapyChatComponent
    │
    ├── TherapyChatModel
    │       │
    │       ├── CMS field access (get_db_field)
    │       ├── Conversation state management
    │       └── TherapyTaggingService instance
    │
    ├── TherapyChatView
    │       │
    │       ├── Renders HTML container
    │       ├── Provides React configuration JSON
    │       └── CSS/JS asset includes
    │
    └── TherapyChatController
            │
            └── API endpoints: send_message, get_messages, tag_therapist, etc.
```

---

## Frontend Architecture

### React Component Structure

```
TherapyChat.tsx (Entry Point)
    │
    ├── SubjectChatLoader
    │       └── SubjectChat
    │               ├── MessageList
    │               ├── MessageInput
    │               ├── TaggingPanel
    │               └── LoadingIndicator
    │
    └── TherapistDashboardLoader
            └── TherapistDashboard
                    ├── ConversationList
                    ├── MessageList
                    ├── AlertsPanel
                    ├── NotesPanel
                    └── StatsHeader
```

### Key React Hooks

**useChatState.ts** - Manages chat state and message handling:

```typescript
interface ChatState {
  messages: Message[];
  isLoading: boolean;
  error: string | null;
  conversationId: number | null;
}

function useChatState(config: TherapyChatConfig): ChatState & {
  sendMessage: (content: string) => Promise<void>;
  refreshMessages: () => Promise<void>;
}
```

**usePolling.ts** - Handles real-time message polling:

```typescript
function usePolling(
  callback: () => Promise<void>,
  interval: number,
  enabled: boolean
): void
```

### TypeScript Types

All types are defined in `react/src/types/index.ts`:

```typescript
export type SenderType = 'subject' | 'therapist' | 'ai' | 'system';
export type RiskLevel = 'low' | 'medium' | 'high' | 'critical';
export type ConversationMode = 'ai_hybrid' | 'human_only';
export type ConversationStatus = 'active' | 'paused' | 'closed';
export type TagUrgency = 'normal' | 'urgent' | 'emergency';
export type AlertType = 'danger_detected' | 'tag_received' | 'high_activity' | 'inactivity' | 'new_message';
export type AlertSeverity = 'info' | 'warning' | 'critical' | 'emergency';

export interface Message { ... }
export interface Conversation { ... }
export interface Tag { ... }
export interface Alert { ... }
export interface TherapyChatConfig { ... }
export interface TherapistDashboardConfig { ... }
```

### Build Process

```bash
# Development with hot reload
cd gulp
gulp react-watch

# Production build
gulp build
```

Output files:
- `js/ext/therapy-chat.umd.js` - React bundle
- `css/ext/therapy-chat.css` - Styles

---

## Database Schema

### Table Relationships

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

### therapyConversationMeta

Links to `llmConversations` with therapy-specific metadata:

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| id_llmConversations | INT | FK to llmConversations |
| id_groups | INT | Access group for therapist assignment |
| id_therapist | INT | Assigned therapist user ID |
| id_chatModes | INT | FK to lookups (ai_hybrid/human_only) |
| ai_enabled | TINYINT | Can AI respond? |
| id_conversationStatus | INT | FK to lookups (active/paused/closed) |
| id_riskLevels | INT | FK to lookups (low/medium/high/critical) |
| therapist_last_seen | TIMESTAMP | Last therapist view |
| subject_last_seen | TIMESTAMP | Last subject view |

### therapyTags

@mention tags from subjects:

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| id_llmMessages | INT | FK to llmMessages |
| id_users | INT | Tagged therapist |
| tag_reason | VARCHAR | Tag reason key |
| id_tagUrgency | INT | FK to lookups |
| acknowledged | TINYINT | Has been acknowledged |
| acknowledged_at | TIMESTAMP | When acknowledged |

### therapyAlerts

Notifications for therapists:

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| id_llmConversations | INT | FK to llmConversations |
| id_users | INT | Target therapist (NULL = all) |
| id_alertTypes | INT | FK to lookups |
| id_alertSeverity | INT | FK to lookups |
| message | TEXT | Alert message |
| metadata | JSON | Additional data |
| is_read | TINYINT | Has been read |

### therapyNotes

Private therapist notes:

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| id_llmConversations | INT | FK to llmConversations |
| id_users | INT | Author (therapist) |
| content | TEXT | Note content |

### Views

The plugin provides views for easy querying with lookup values resolved:

- `view_therapyConversations` - Conversations with mode, status, risk labels
- `view_therapyTags` - Tags with urgency labels
- `view_therapyAlerts` - Alerts with type and severity labels

---

## API Reference

### Subject Chat Endpoints

Base URL: `/request/{sectionId}/therapy-chat/{action}`

#### Send Message

**POST** `send_message`

```json
// Request
{
    "conversation_id": 123,
    "message": "Hello..."
}

// Response
{
    "success": true,
    "message_id": 456,
    "conversation_id": 123,
    "ai_message": {
        "id": 457,
        "role": "assistant",
        "content": "I understand...",
        "sender_type": "ai",
        "timestamp": "2026-01-16T14:30:00+00:00"
    }
}
```

#### Get Messages (Polling)

**GET** `get_messages?conversation_id={id}&after_id={lastId}`

```json
{
    "messages": [...],
    "conversation_id": 123
}
```

#### Tag Therapist

**POST** `tag_therapist`

```json
// Request
{
    "conversation_id": 123,
    "reason": "overwhelmed",
    "urgency": "urgent"
}

// Response
{
    "success": true,
    "tag_id": 45,
    "alert_created": true
}
```

### Therapist Dashboard Endpoints

Base URL: `/request/{sectionId}/therapist-dashboard/{action}`

#### Get Conversations

**GET** `get_conversations?status={status}&risk_level={level}`

#### Send Therapist Message

**POST** `send_message`

```json
{
    "conversation_id": 123,
    "message": "I see you're having a difficult time..."
}
```

#### Toggle AI

**POST** `toggle_ai`

```json
{
    "conversation_id": 123,
    "enabled": false
}
```

#### Set Risk Level

**POST** `set_risk`

```json
{
    "conversation_id": 123,
    "risk_level": "high"
}
```

#### Add Note

**POST** `add_note`

```json
{
    "conversation_id": 123,
    "content": "Patient showing improvement..."
}
```

#### Acknowledge Tag

**POST** `acknowledge_tag`

```json
{
    "tag_id": 45
}
```

---

## Configuration

### Module Configuration Page

Navigate to `/admin/module_llm_therapy_chat`:

| Field | Description |
|-------|-------------|
| `therapy_chat_subject_group` | Group containing patients |
| `therapy_chat_therapist_group` | Group containing therapists |
| `therapy_chat_subject_page` | Page ID for patient chat |
| `therapy_chat_therapist_page` | Page ID for therapist dashboard |
| `therapy_chat_floating_icon` | Font Awesome icon class |
| `therapy_chat_floating_position` | Floating button position |
| `therapy_chat_default_mode` | Default: ai_hybrid |
| `therapy_chat_polling_interval` | Polling seconds (default: 3) |
| `therapy_chat_enable_tagging` | Enable @mention tagging |
| `therapy_tag_reasons` | JSON array of tag reasons |

### Per-Section Style Fields

**therapyChat style** (inherits from llmChat):

| Field | Description |
|-------|-------------|
| `llm_model` | AI model selection |
| `llm_temperature` | AI creativity (0-2) |
| `llm_max_tokens` | Max response length |
| `conversation_context` | System prompt |
| `enable_danger_detection` | Safety monitoring |
| `danger_keywords` | Trigger keywords |
| `danger_notification_emails` | Alert recipients |

### Lookup Constants

All enum-like values use the `lookups` table. Constants in `TherapyLookups.php`:

```php
// Chat Modes
define('THERAPY_MODE_AI_HYBRID', 'ai_hybrid');
define('THERAPY_MODE_HUMAN_ONLY', 'human_only');

// Conversation Status
define('THERAPY_STATUS_ACTIVE', 'active');
define('THERAPY_STATUS_PAUSED', 'paused');
define('THERAPY_STATUS_CLOSED', 'closed');

// Risk Levels
define('THERAPY_RISK_LOW', 'low');
define('THERAPY_RISK_MEDIUM', 'medium');
define('THERAPY_RISK_HIGH', 'high');
define('THERAPY_RISK_CRITICAL', 'critical');

// Tag Urgency
define('THERAPY_URGENCY_NORMAL', 'normal');
define('THERAPY_URGENCY_URGENT', 'urgent');
define('THERAPY_URGENCY_EMERGENCY', 'emergency');

// Alert Types
define('THERAPY_ALERT_DANGER', 'danger_detected');
define('THERAPY_ALERT_TAG', 'tag_received');
define('THERAPY_ALERT_HIGH_ACTIVITY', 'high_activity');
define('THERAPY_ALERT_INACTIVITY', 'inactivity');
define('THERAPY_ALERT_NEW_MESSAGE', 'new_message');

// Alert Severity
define('THERAPY_SEVERITY_INFO', 'info');
define('THERAPY_SEVERITY_WARNING', 'warning');
define('THERAPY_SEVERITY_CRITICAL', 'critical');
define('THERAPY_SEVERITY_EMERGENCY', 'emergency');
```

---

## Security Considerations

### Access Control

1. **Group-Based Permissions** - Therapists only see patients in their assigned groups
2. **ACL Integration** - Uses SelfHelp's ACL system for page access
3. **Conversation-Level Access** - `canAccessTherapyConversation()` validates all requests

### Data Protection

1. **Input Sanitization** - All user input sanitized
2. **SQL Injection Prevention** - Parameterized queries
3. **XSS Protection** - Output escaping
4. **CSRF Protection** - Token validation

### Danger Detection

1. **Keyword Monitoring** - Configurable danger keywords
2. **Automatic Alerts** - Emergency alerts for critical content
3. **Email Notifications** - Configurable recipients
4. **Conversation Blocking** - Optional on danger detection

### Audit Trail

All actions logged via `transactionTypes`:
- Conversation creation
- Status changes
- Risk level changes
- Tag acknowledgments

---

## Testing

### Manual Testing

```bash
# Test Subject Chat
curl -X POST "http://localhost/request/{sectionId}/therapy-chat/send_message" \
     -H "Content-Type: application/x-www-form-urlencoded" \
     -d "message=Test message&conversation_id=123"

# Test Therapist Dashboard
curl -X GET "http://localhost/request/{sectionId}/therapist-dashboard/get_conversations" \
     -H "Cookie: selfhelp_session=..."
```

### Test Scenarios

1. **Patient Flow**
   - Login as patient
   - Send message
   - Verify AI response
   - Tag therapist
   - Verify alert created

2. **Therapist Flow**
   - Login as therapist
   - View conversation list
   - Select conversation
   - Send response
   - Toggle AI off
   - Add clinical note

3. **Danger Detection**
   - Send message with danger keyword
   - Verify alert created
   - Verify email sent
   - Verify conversation blocked (if enabled)

---

## Extending the Plugin

### Adding a New Alert Type

1. Add lookup entry in SQL:

```sql
INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES ('therapyAlertTypes', 'new_type', 'New Type', 'Description...');
```

2. Add constant in `TherapyLookups.php`:

```php
define('THERAPY_ALERT_NEW_TYPE', 'new_type');
```

3. Update `THERAPY_VALID_ALERT_TYPES` array

4. Use in service:

```php
$this->createAlert($conversationId, THERAPY_ALERT_NEW_TYPE, $message, $severity);
```

### Adding Custom Tag Reasons

Configure via JSON in `therapy_tag_reasons` field:

```json
[
    {"key": "custom_reason", "label": "Custom Reason Label", "urgency": "normal"},
    {"key": "urgent_custom", "label": "Urgent Custom", "urgency": "urgent"}
]
```

### Customizing AI Behavior

Edit the `conversation_context` field with custom system prompt:

```markdown
You are a supportive AI assistant specializing in [area].

Your role:
- [Custom instruction 1]
- [Custom instruction 2]

Important boundaries:
- [Boundary 1]
- [Boundary 2]
```

### Adding New React Components

1. Create component in `react/src/components/`
2. Export in `TherapyChat.tsx`
3. Rebuild: `gulp build`

---

## Troubleshooting

### Common Issues

**"sh-shp-llm plugin must be installed first"**
- Run the LLM plugin migration before this plugin

**Messages not appearing**
- Check polling interval configuration
- Verify conversation ID is correct
- Check browser console for errors

**Therapist can't see conversations**
- Verify therapist is in correct group
- Check ACL permissions for `therapyChatTherapist` page

**AI not responding**
- Verify `ai_enabled` is true
- Check `mode` is `ai_hybrid`
- Verify LLM API credentials

### Debug Mode

Enable debug mode in section configuration:
```
debug: 1
```

Check browser console and network tab for API responses.

---

## Version History

See [CHANGELOG.md](../CHANGELOG.md) for detailed version history.

---

## License

Mozilla Public License 2.0 - see LICENSE file.
