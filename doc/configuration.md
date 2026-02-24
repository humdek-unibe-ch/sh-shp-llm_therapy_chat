# Configuration Guide

## Module Page (`sh_module_llm_therapy_chat`)

The plugin registers page type `sh_module_llm_therapy_chat` with these fields:

| Field | Type | Purpose |
|-------|------|---------|
| `therapy_chat_subject_group` | `select-group` | Group containing patient users |
| `therapy_chat_therapist_group` | `select-group` | Group containing therapist users |
| `therapy_chat_subject_page` | `select-page` | Page hosting `therapyChat` style |
| `therapy_chat_therapist_page` | `select-page` | Page hosting `therapistDashboard` style |
| `therapy_chat_floating_icon` | `text` | Floating/nav icon class (FA) |
| `therapy_chat_floating_label` | `text` | Optional label text |
| `therapy_chat_floating_position` | `select-floating-position` | `bottom-right`, `bottom-left`, `top-right`, `top-left` |
| `therapy_chat_enable_floating_button` | `checkbox` | Enable floating icon/link rendering |
| `therapy_chat_default_mode` | `select` | Default mode for new conversations (`ai_hybrid` / `human_only`) |
| `therapy_chat_polling_interval` | `number` | Polling interval in seconds |
| `therapy_chat_enable_tagging` | `checkbox` | Enables `@therapist` and `#topic` flows |

## `therapyChat` Style Fields

Most therapy behavior is configured on the `therapyChat` section (not the module page):

- AI and prompt fields: `therapy_enable_ai`, `llm_model`, `llm_temperature`, `llm_max_tokens`, `conversation_context`
- Safety fields: `enable_danger_detection`, `danger_keywords`, `danger_notification_emails`, `danger_blocked_message`
- Tagging fields: `therapy_chat_enable_tagging`, `therapy_tag_reasons`, `therapy_chat_help_text`
- Polling and UX fields: `therapy_chat_polling_interval`, message labels/placeholders
- Speech input fields: `enable_speech_to_text`, `speech_to_text_model`, `speech_to_text_language`
- Auto-start fields: `therapy_auto_start`, `therapy_auto_start_context`
- Notification templates (patient -> therapist): email and push template fields

## `therapistDashboard` Style Fields

The dashboard style owns therapist-facing UI/config:

- Dashboard labels and headings (including stats + group tab labels)
- Feature toggles (`dashboard_show_*`, `dashboard_enable_*`)
- Draft/summary LLM fields (`llm_*`, `conversation_context`, `therapy_draft_context`, `therapy_summary_context`)
- Speech input fields: `enable_speech_to_text`, `speech_to_text_model`, `speech_to_text_language`
- Auto-start fields: `therapy_auto_start`, `therapy_auto_start_context`
- Notification templates (therapist -> patient and therapist alert channels)

## Access Model

- Role visibility is controlled by configured subject/therapist groups.
- Monitoring scope is controlled separately by `therapyTherapistAssignments`.
- Subject page ACL is explicitly granted to the `subject` group by migration.
- Therapist monitoring assignments are managed in admin user edit via injected hook UI.
