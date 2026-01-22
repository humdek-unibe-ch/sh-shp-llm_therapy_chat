# SelfHelp Plugin: LLM Therapy Chat

**Version:** 1.0.0  
**Author:** SelfHelp Team  
**License:** MPL-2.0

## Overview

The LLM Therapy Chat plugin extends the [sh-shp-llm](../sh-shp-llm/) plugin to provide AI-assisted therapeutic conversations with therapist monitoring and intervention capabilities. This system is designed to extend therapeutic support between sessions, **not replace professional care**.

## ⚠️ Dependency: sh-shp-llm Plugin Required

**IMPORTANT:** This plugin requires the `sh-shp-llm` plugin to be installed first!

All conversations and messages are stored in the LLM plugin's tables (`llmConversations` and `llmMessages`). This ensures:
- Single source of truth for all chat data
- Full compatibility with LLM Admin Console
- No code duplication for message/conversation management
- Access to all LLM features (danger detection, context management, etc.)

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    sh-shp-llm Plugin                        │
│  ┌──────────────────┐  ┌──────────────────┐                │
│  │ llmConversations │  │   llmMessages    │                │
│  │    (Table)       │  │     (Table)      │                │
│  └────────┬─────────┘  └────────┬─────────┘                │
│           │                     │                          │
│  ┌────────┴─────────────────────┴─────────────────────┐    │
│  │              LlmService (Base)                     │    │
│  │  - Conversation CRUD                               │    │
│  │  - Message management                              │    │
│  │  - LLM API calls                                   │    │
│  │  - Danger detection                                │    │
│  └────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
                              │
                              │ extends
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                sh-shp-llm_therapy_chat Plugin               │
│                                                             │
│  ┌─────────────────────────┐  ┌─────────────────────────┐  │
│  │ therapyConversationMeta │  │     therapyAlerts       │  │
│  │  - id_therapist         │  │  - alert_type           │  │
│  │  - mode (ai/human)      │  │  - severity             │  │
│  │  - risk_level           │  │  - is_read              │  │
│  │  - ai_enabled           │  └─────────────────────────┘  │
│  └─────────────────────────┘                               │
│                                                             │
│  ┌─────────────────────────┐  ┌─────────────────────────┐  │
│  │      therapyTags        │  │     therapyNotes        │  │
│  │  - @mention tags        │  │  - therapist notes      │  │
│  │  - urgency level        │  │  - conversation notes   │  │
│  └─────────────────────────┘  └─────────────────────────┘  │
│                                                             │
│  ┌────────────────────────────────────────────────────┐    │
│  │           TherapyChatService (extends LlmService)  │    │
│  │  - Therapy conversation management                 │    │
│  │  - Access control (subject/therapist)              │    │
│  │  - Mode management (AI/human)                      │    │
│  └────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
```

## Features

### For Patients (Subjects)

- **AI Chat Buddy (24/7)** - Supportive AI trained in empathy, validation, and grounding
- **Tag Therapist** - @mention to request human intervention
- **Predefined Tag Reasons:**
  - "I'm feeling overwhelmed"
  - "I need to talk soon"
  - "This feels urgent"
- **Safe & Private** - Encrypted, secure conversations with automatic safety monitoring

### For Therapists

- **Real-time Dashboard** - Monitor all patient conversations
- **Risk Level Indicators** - Visual alerts for concerning conversations
- **AI Control** - Enable/disable AI responses per conversation
- **Smart Alerts** - Notifications for:
  - Danger keyword detection
  - Patient tags
  - High activity
- **Notes System** - Add private notes to conversations
- **Full Conversation History** - Access via LLM Admin Console

### Clinical Boundaries

Clear disclaimers are shown to patients:
- The AI is **not** a therapist
- The AI does **not** diagnose
- The AI does **not** replace therapy
- The therapist remains the primary clinical decision-maker

## Installation

1. **Install sh-shp-llm plugin first!**
   ```bash
   # The llm plugin must be installed before this plugin
   # See sh-shp-llm/README.md for installation
   ```

2. **Run the database migration:**
   ```sql
   SOURCE server/db/v1.0.0.sql;
   ```

3. **Build the React frontend:**
   ```bash
   cd gulp
   npm install
   gulp react-install  # Install React dependencies
   gulp build         # Build React components
   ```

4. **Configure the plugin:**
   - Go to `/admin/module_llm_therapy_chat`
   - Set subject group (patients)
   - Set therapist group
   - Configure AI settings

## Build System

This plugin uses Gulp for building the React frontend components. The build process compiles TypeScript and bundles the React application into UMD modules.

### Available Gulp Tasks

| Task | Description |
|------|-------------|
| `gulp` or `gulp build` | Build React component (default) |
| `gulp react-install` | Install React dependencies |
| `gulp react-build` | Build React component only |
| `gulp react-watch` | Watch React files for changes (development) |
| `gulp clean` | Remove built files |
| `gulp help` | Show available tasks |

### First-Time Setup

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

### Output Files

After building, the following files are generated:

- `js/ext/therapy-chat.umd.js` - React component bundle
- `css/ext/therapy-chat.css` - React component styles

### Development Workflow

For development with hot reloading:

```bash
cd gulp
gulp react-watch
```

This will start Vite's development server and watch for changes to React files.

## Configuration

### Module Configuration (`/admin/module_llm_therapy_chat`)

| Field | Description |
|-------|-------------|
| `therapy_chat_subject_group` | Group containing patients |
| `therapy_chat_therapist_group` | Group containing therapists |
| `therapy_chat_default_mode` | `ai_hybrid` (AI + therapist) or `human_only` |
| `therapy_chat_polling_interval` | Real-time update interval (seconds) |
| `therapy_chat_enable_tagging` | Allow @mention tagging |

### Per-Section Configuration (Style Fields)

The `therapyChat` style inherits many fields from `llmChat`:

| Field | Description |
|-------|-------------|
| `llm_model` | AI model to use (from sh-shp-llm) |
| `conversation_context` | System prompt for AI |
| `enable_danger_detection` | Enable safety monitoring |
| `danger_keywords` | Keywords that trigger alerts |
| `danger_notification_emails` | Email addresses for alerts |

## URLs

| URL | Description |
|-----|-------------|
| `/therapy-chat/subject/[gid]` | Patient chat interface |
| `/therapy-chat/therapist/[gid]/[uid]` | Therapist dashboard |
| `/admin/module_llm_therapy_chat` | Plugin configuration |
| `/admin/module_llm/conversations` | LLM Admin Console (view all data) |

## Database Tables

### therapyConversationMeta
Links to `llmConversations` and adds therapy-specific metadata.

```sql
- id_llmConversations  -- Foreign key to llmConversations
- id_groups            -- Access group for therapist assignment
- id_therapist         -- Assigned therapist (optional)
- mode                 -- 'ai_hybrid' or 'human_only'
- ai_enabled           -- Can AI respond?
- status               -- 'active', 'paused', 'closed'
- risk_level           -- 'low', 'medium', 'high', 'critical'
```

### therapyTags
@mention tags created by patients.

```sql
- id_llmMessages       -- The message containing the tag
- id_users             -- Tagged therapist
- tag_reason           -- Predefined reason or custom text
- urgency              -- 'normal', 'urgent', 'emergency'
- acknowledged         -- Has therapist acknowledged?
```

### therapyAlerts
Notifications for therapists.

```sql
- id_llmConversations  -- Related conversation
- alert_type           -- 'danger_detected', 'tag_received', etc.
- severity             -- 'info', 'warning', 'critical', 'emergency'
- is_read              -- Has therapist read?
```

### therapyNotes
Therapist notes on conversations (not visible to patients).

```sql
- id_llmConversations  -- Related conversation
- id_users             -- Therapist who wrote note
- content              -- Note content
```

## Services

All services extend `LlmService` from sh-shp-llm:

| Service | Purpose |
|---------|---------|
| `TherapyChatService` | Core conversation management |
| `TherapyMessageService` | Message handling with sender attribution |
| `TherapyAlertService` | Alert creation and management |
| `TherapyTaggingService` | @mention tag processing |

## Integration with LLM Admin Console

All conversations can be viewed and managed in the LLM Admin Console at `/admin/module_llm/conversations`:

- View/block conversations
- Debug context and payload
- Access full message history
- See danger detections

The therapy plugin adds metadata but does not duplicate core functionality.

## Security Considerations

1. **Access Control** - Strict separation between patient and therapist access
2. **Danger Detection** - Leverages sh-shp-llm's danger keyword system
3. **Audit Trail** - All actions logged to transactions table
4. **Group-based Permissions** - Therapists only see patients in their groups

## Documentation

Comprehensive documentation is available in the `/doc` folder:

| Document | Description |
|----------|-------------|
| [Developer Guide](./doc/DEVELOPER_GUIDE.md) | Technical documentation for developers |
| [User Guide](./doc/USER_GUIDE.md) | Guide for therapists and clinic users |
| [Admin Setup Guide](./doc/ADMIN_SETUP.md) | Administrator installation and configuration |
| [Feature Roadmap](./doc/FEATURE_ROADMAP.md) | Implementation status and future TODOs |
| [API Reference](./doc/api-reference.md) | API endpoint documentation |
| [Architecture](./doc/architecture.md) | System architecture overview |
| [Configuration](./doc/configuration.md) | Configuration options reference |

## Changelog

See [CHANGELOG.md](./CHANGELOG.md) for version history.

## License

Mozilla Public License 2.0 - see LICENSE file.
