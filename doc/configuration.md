# Configuration Guide

## Module Page Configuration

The plugin registers a page type `sh_module_llm_therapy_chat` with these fields:

| Field | Type | Description |
|-------|------|-------------|
| `therapy_chat_subject_group` | select-group | Group containing patients (subjects) |
| `therapy_chat_therapist_group` | select-group | Group containing therapists (for floating button visibility) |
| `therapy_chat_subject_page` | select-page | Page ID for the patient chat interface |
| `therapy_chat_therapist_page` | select-page | Page ID for the therapist dashboard |
| `therapy_chat_danger_words` | textarea | Comma-separated danger keywords |
| `therapy_chat_floating_position` | select | Position of the floating chat button |

## Style Fields (therapyChat)

Each `therapyChat` style instance can be configured with:

| Field | Default | Description |
|-------|---------|-------------|
| `therapy_enable_ai` | `1` (enabled) | When disabled, AI is off — pure human-to-human chat |
| `therapy_chat_default_mode` | `ai_hybrid` | Default mode: `ai_hybrid` or `human_only` |
| `therapy_chat_default_model` | `gpt-4o-mini` | LLM model for AI responses |
| `therapy_chat_max_tokens` | `2048` | Max tokens for AI response |
| `therapy_chat_temperature` | `0.7` | AI temperature (creativity) |
| `therapy_chat_system_prompt` | (built-in) | Custom system prompt for the AI |
| `therapy_chat_polling_interval` | `10000` | Polling interval in ms |
| `therapy_chat_tagging_enabled` | `1` | Allow patients to tag therapists |
| `therapy_chat_speech_to_text_enabled` | `0` | Enable speech input |
| `therapy_chat_help_text` | `Use @therapist to request your therapist, or #topic to tag a predefined topic.` | Help text shown below chat input explaining @mention and #hashtag usage. Supports multilingual content via field translations. |
| `css` | (empty) | Additional CSS class |

## Style Fields (therapistDashboard) - LLM & Summary

| Field | Default | Description |
|-------|---------|-------------|
| `llm_model` | (empty) | AI model for draft generation and summarization |
| `llm_temperature` | `0.7` | Temperature for AI draft/summary generation |
| `llm_max_tokens` | `2048` | Max tokens for AI draft/summary responses |
| `conversation_context` | (empty) | System context for AI responses in draft generation |
| `therapy_summary_context` | (default therapeutic guidance) | Additional context/instructions for AI summarization. This text is prepended to the summarization prompt to guide the AI output. Supports multilingual content via field translations. |
| `debug` | `0` | Debug mode |

## Access Control

### Patient Access
Patients must be in the configured `therapy_chat_subject_group`.
They see a floating chat button on all pages and can access the chat page.

### Therapist Access
Therapists must be in the configured `therapy_chat_therapist_group`.
They see a floating dashboard button.

### Patient Monitoring Scope
**Separate from SelfHelp group membership.** Controlled by `therapyTherapistAssignments`:
- Admin assigns therapist → patient groups via the user admin page hook
- Therapist only sees conversations from patients in their assigned groups
- Multiple therapists can be assigned to the same group

## Therapist Group Assignment

Assignments are managed via the admin user edit page (`/admin/user/{id}`).
The plugin injects a "Therapy Chat - Patient Group Monitoring" card with checkboxes
for each available group. This is done via the `outputTherapistGroupAssignments` hook.
