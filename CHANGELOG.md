# Changelog

All notable changes to the LLM Therapy Chat plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-01-16

### Added

#### Core Architecture
- **Dependency on sh-shp-llm plugin** - All conversations and messages stored in LLM plugin tables
- **Extension-based design** - `TherapyChatService` extends `LlmService` for zero code duplication
- **Full LLM Admin Console compatibility** - View therapy conversations in existing admin interface

#### Database Schema
- `therapyConversationMeta` - Therapy metadata linking to `llmConversations`
- `therapyTags` - @mention tagging system for patient-therapist communication
- `therapyAlerts` - Smart notification system for therapists
- `therapyNotes` - Private therapist notes on conversations
- Foreign key relationships to sh-shp-llm tables

#### Components
- `therapyChat` style - Subject/patient chat interface
  - Real-time messaging with AI and therapist
  - @mention tagging with predefined reasons
  - Polling-based message updates
  - Typing indicators
  - Danger detection integration

- `therapistDashboard` style - Therapist monitoring interface
  - Conversation list with filtering
  - Risk level indicators (low/medium/high/critical)
  - Alert management
  - Tag acknowledgment system
  - Private notes
  - AI enable/disable controls

#### Services (extending LlmService)
- `TherapyChatService` - Core conversation management
  - Create therapy conversations with metadata
  - Access control (subject/therapist permissions)
  - Mode management (ai_hybrid/human_only)
  - Risk level tracking
  - Last seen timestamps

- `TherapyMessageService` - Message handling
  - Sender type attribution (AI/therapist/subject)
  - @mention detection and processing
  - Message polling for real-time updates

- `TherapyAlertService` - Notification system
  - Alert creation for various events
  - Danger detection alerts (emergency severity)
  - Tag received alerts
  - Email notifications for critical alerts

- `TherapyTaggingService` - @mention functionality
  - Predefined tag reasons
  - Urgency levels (normal/urgent/emergency)
  - Tag acknowledgment workflow

#### Hooks
- `outputTherapyChatIcon` - Floating chat button with unread badge
- `field-therapy_chat_panel-edit/view` - Admin configuration panel

#### Configuration
- Module configuration page at `/admin/module_llm_therapy_chat`
- Style fields inherited from llmChat for AI settings
- Therapy-specific labels (customizable via CMS)

#### Security
- Group-based access control for therapists
- Danger detection integration from sh-shp-llm
- Transaction logging for audit trail
- Conversation blocking on danger detection

### Technical Notes

- **No React build required** - Uses vanilla JavaScript for frontend
- **No code duplication** - Extends existing LLM services
- **Single data source** - All chat data in llmConversations/llmMessages tables
- **Polling-based updates** - Configurable interval (default 3 seconds)

### Dependencies

| Plugin | Version | Required |
|--------|---------|----------|
| sh-shp-llm | >= 1.0.0 | Yes |

### Database Requirements

- MySQL 5.7+ / MariaDB 10.2+
- utf8mb4 character set
- JSON column support

### Known Limitations

- Initial version uses polling instead of WebSockets
- Therapist assignment is manual (no auto-assignment)
- Single therapist per conversation

### Future Considerations

- WebSocket support for true real-time updates
- Automatic therapist assignment based on availability
- Multi-therapist conversation support
- Mobile app integration
