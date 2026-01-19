# Configuration Guide

## Module Configuration

Navigate to `/admin/module_llm_therapy_chat` to configure global settings.

### Subject & Therapist Groups

| Field | Description |
|-------|-------------|
| `therapy_chat_subject_group` | The SelfHelp group containing patients/subjects |
| `therapy_chat_therapist_group` | The SelfHelp group containing therapists |

**Important:** Users must be members of these groups to access the respective interfaces.

### Default Settings

| Field | Default | Description |
|-------|---------|-------------|
| `therapy_chat_default_mode` | `ai_hybrid` | Default chat mode for new conversations |
| `therapy_chat_polling_interval` | `3` | Seconds between message polling |
| `therapy_chat_enable_tagging` | `1` | Enable @mention tagging |

### Floating Button (Optional)

| Field | Default | Description |
|-------|---------|-------------|
| `therapy_chat_floating_icon` | `fa-comments` | Font Awesome icon class |
| `therapy_chat_floating_label` | (empty) | Optional text label |
| `therapy_chat_floating_position` | `bottom-right` | Screen position |

## Style Configuration (Per Section)

When adding a `therapyChat` style to a page, configure these fields in the CMS.

### LLM Configuration

These fields are inherited from the `llmChat` style:

| Field | Description |
|-------|-------------|
| `llm_model` | AI model to use (dropdown populated from sh-shp-llm) |
| `llm_temperature` | Creativity level (0-2, default: 1) |
| `llm_max_tokens` | Maximum response length |
| `conversation_context` | System prompt for AI behavior |

### Danger Detection

| Field | Description |
|-------|-------------|
| `enable_danger_detection` | Enable safety monitoring (recommended: ON) |
| `danger_keywords` | Comma-separated list of trigger words |
| `danger_notification_emails` | Email addresses for alerts |
| `danger_blocked_message` | Message shown when danger detected |

**Recommended Keywords:**
```
suicide,selbstmord,kill myself,mich umbringen,self-harm,selbstverletzung,harm myself,mir schaden,end my life,mein leben beenden,overdose,überdosis
```

### Labels (Translatable)

These fields support translation via the CMS:

| Field | Default | Description |
|-------|---------|-------------|
| `therapy_ai_label` | "AI Assistant" | Label for AI messages |
| `therapy_therapist_label` | "Therapist" | Label for therapist messages |
| `therapy_tag_button_label` | "Tag Therapist" | Tag button text |
| `therapy_tag_reason_overwhelmed` | "I am feeling overwhelmed" | Tag reason option |
| `therapy_tag_reason_need_talk` | "I need to talk soon" | Tag reason option |
| `therapy_tag_reason_urgent` | "This feels urgent" | Tag reason option |
| `therapy_empty_message` | "No messages yet..." | Empty state message |
| `therapy_ai_thinking_text` | "AI is thinking..." | Loading indicator |
| `therapy_mode_indicator_ai` | "AI-assisted chat" | Mode badge for AI hybrid |
| `therapy_mode_indicator_human` | "Therapist-only mode" | Mode badge for human only |

### General Labels

Inherited from `llmChat`:

| Field | Default |
|-------|---------|
| `submit_button_label` | "Send" |
| `message_placeholder` | "Type your message..." |
| `loading_text` | "Loading..." |

## Chat Modes

### AI Hybrid Mode (`ai_hybrid`)

- AI responds to all subject messages
- Therapist can observe and intervene
- Therapist can disable AI at any time
- Best for 24/7 support with human oversight

### Human Only Mode (`human_only`)

- AI does not respond automatically
- Only therapist can send messages
- Subject waits for human response
- Best for sensitive situations requiring human judgment

## Access Control Setup

### For Subjects

1. Create or use an existing group (e.g., "patients")
2. Assign users who should have subject access to this group
3. Set this group in `therapy_chat_subject_group`
4. Grant the group ACL access to `therapyChatSubject` page

### For Therapists

1. Create or use an existing group (e.g., "therapists")
2. Assign users who should have therapist access to this group
3. Set this group in `therapy_chat_therapist_group`
4. Grant the group ACL access to `therapyChatTherapist` page

## AI System Prompt Example

Here's a recommended system prompt for the `conversation_context` field:

```markdown
You are a supportive AI assistant in a mental health therapy context.

Your role:
- Provide empathetic, non-judgmental support
- Use evidence-based techniques (validation, reflection, grounding)
- Encourage the user while respecting boundaries
- Suggest professional support when appropriate

Important boundaries:
- You are NOT a therapist
- You cannot diagnose conditions
- You cannot prescribe treatments
- Always encourage speaking with the assigned therapist for clinical concerns

Communication style:
- Use warm, conversational language
- Ask open-ended questions
- Reflect back what you hear
- Validate emotions before problem-solving

If the user seems in distress:
- Express genuine concern
- Encourage them to tag their therapist
- Remind them of crisis resources if appropriate
```

## Email Notification Setup

For danger detection alerts to work:

1. Ensure SelfHelp's email/job scheduler is configured
2. Enter valid email addresses in `danger_notification_emails`
3. Separate multiple emails with semicolons or newlines

**Example:**
```
therapist1@clinic.com
therapist2@clinic.com
admin@clinic.com
```
