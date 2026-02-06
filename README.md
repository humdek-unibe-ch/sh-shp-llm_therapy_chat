# sh-shp-llm_therapy_chat

SelfHelp plugin providing AI-assisted therapy chat between patients and therapists.

## Overview

This plugin extends the `sh-shp-llm` base LLM plugin with therapy-specific features:

- **Patient Chat**: Patients converse with an AI assistant; therapists can intervene
- **Therapist Dashboard**: Monitor multiple patients, manage conversations, add notes
- **Group-Based Access**: Therapists are assigned to patient groups they can monitor
- **AI Drafts**: AI generates draft responses for therapists to edit and send
- **Risk Management**: Rate patient risk levels, track alerts and tags
- **Unread Tracking**: Per-patient unread message counts with visual indicators

## Quick Start

```bash
# 1. Install database
mysql -u user -p database < server/db/v1.0.0.sql

# 2. Build frontend
cd react && npm install && npm run build

# 3. Configure in SelfHelp admin
#    - Create module page (type: sh_module_llm_therapy_chat)
#    - Create patient chat page (style: therapyChat)
#    - Create therapist dashboard page (style: therapistDashboard)
#    - Assign therapists to patient groups via user admin
```

## Tech Stack

- **Backend**: PHP 8.2+ (vanilla, SelfHelp MVC pattern)
- **Frontend**: React 18 + TypeScript + Bootstrap 4.6
- **Database**: MySQL 8.0+ (InnoDB, utf8mb4)
- **Build**: Vite (UMD bundle)
- **Dependencies**: sh-shp-llm plugin

## Documentation

| Document | Description |
|----------|-------------|
| [Architecture](doc/architecture.md) | System design, database schema, service layers |
| [API Reference](doc/api-reference.md) | All frontend API endpoints |
| [Configuration](doc/configuration.md) | Module and style configuration fields |
| [Admin Setup](doc/ADMIN_SETUP.md) | Step-by-step installation and setup |
| [Developer Guide](doc/DEVELOPER_GUIDE.md) | How to extend the plugin |
| [User Guide](doc/USER_GUIDE.md) | Patient and therapist user guides |
| [Feature Roadmap](doc/FEATURE_ROADMAP.md) | What's implemented and planned |

## License

Mozilla Public License 2.0 (MPL-2.0)
