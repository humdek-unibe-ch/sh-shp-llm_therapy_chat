# API Reference

All endpoints use the current page URL with `?action=xxx` for GET requests
and `action` field in FormData for POST requests.

## Subject Chat Endpoints (TherapyChatController)

### GET `get_config`
Returns the chat configuration for the current user.

**Response**: `{ config: SubjectChatConfig }`

### GET `get_conversation`
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | No | Specific conversation (auto-creates if missing) |

**Response**: `{ conversation, messages }`

### GET `get_therapists`
Returns therapists available for @mention (patient view).

**Response**: `{ therapists: [{ id, display, name, email }] }`

### GET `get_messages`
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | No | Conversation to poll |
| `after_id` | int | No | Only messages after this ID |

**Response**: `{ messages: Message[], conversation_id }`

### POST `mark_messages_read`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | No | Scope to conversation; if omitted, uses current conversation |

**Response**: `{ success, unread_count }`

### POST `send_message`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `message` | string | Yes | Message content |
| `conversation_id` | int | No | Target conversation |

**Response**: `{ message_id, conversation_id, ai_message?, blocked? }`

When the message tags a therapist (`@therapist` or `@SpecificName`), no AI response is generated — the response omits `ai_message`.

### POST `tag_therapist`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | Yes | Conversation |
| `reason` | string | No | Tag reason code |
| `urgency` | string | No | `normal` / `urgent` / `emergency` |

**Response**: `{ alert_id, alert_created }`

### POST `speech_transcribe`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `audio` | file | Yes | Audio blob (webm) |

**Response**: `{ text: string }`

### GET `check_updates`
Lightweight polling endpoint. Returns only the latest message ID and unread
count so the frontend can decide whether a full fetch is needed.

**Response**: `{ latest_message_id, unread_count }`

---

## Mobile App Data (`output_content_mobile`)

The `TherapyChatView::output_content_mobile()` method returns the following
additional fields for the mobile app (Ionic/Angular frontend):

| Field | Type | Description |
|-------|------|-------------|
| `conversation` | object | Current conversation (auto-created if missing) |
| `messages` | array | Messages for the current conversation |
| `user_id` | int | Current user ID |
| `section_id` | int | Section ID for API calls |
| `is_subject` | bool | Whether the user is a patient/subject |
| `tagging_enabled` | bool | Whether @therapist tagging is enabled |
| `labels` | object | UI labels (ai_label, therapist_label, etc.) |
| `polling_interval` | int | Polling interval in seconds (default 3) |
| `chat_config` | object | Floating button / tab configuration |

### `chat_config` object

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `icon` | string | `fa-comments` | Icon class for tab / FAB button |
| `label` | string | `Chat` | Label for tab / FAB button |
| `position` | string | `bottom-right` | Floating button position |
| `ai_enabled` | bool | `true` | Whether AI responses are enabled |
| `ai_label` | string | `AI Assistant` | Display label for AI messages |
| `therapist_label` | string | `Therapist` | Display label for therapist messages |

The mobile app uses `chat_config` to render:
1. A bottom tab with the configured icon and label (first position)
2. An Ionic FAB button with unread message badge
3. Background polling via `TherapyChatNotificationService` using `check_updates` endpoint

## Mobile Page Response (`therapy_chat` field)

Every mobile page response for logged-in users includes a `therapy_chat` field
injected by `TherapyChatHooks::addTherapyChatToMobileResponse()` via a
`hook_overwrite_return` on `BasePage::output_base_content_mobile`. This keeps all
therapy-chat logic in the plugin — no core Selfhelp.php changes are required.

| Field | Type | Description |
|-------|------|-------------|
| `available` | bool | Whether the user has therapy chat access |
| `section_id` | int\|null | Section ID of the therapyChat/therapistDashboard style |
| `url` | string | URL of the chat page (from `therapy_chat_subject_page` or `therapy_chat_therapist_page`) |
| `unread_count` | int | Current unread message count |
| `icon` | string | FA icon class from config (e.g. `fa-comments`) |
| `mobile_icon` | string | Ionic-compatible icon name (e.g. `chatbubbles`) — mapped server-side |
| `label` | string | Button/tab label from config |
| `role` | string | `subject` or `therapist` |
| `enable_floating` | bool | Whether floating quick-access button behavior is enabled (subjects only) |
| `position` | string | Floating button position (`bottom-right`, `bottom-left`, `top-right`, `top-left`) |

**Mobile app behavior:**
- When `enable_floating` is `true`: show FAB button at configured `position`, do NOT show in tab bar
- When `enable_floating` is `false`: show in tab bar as first tab, do NOT show FAB

**FA → Ionic icon mapping** (server-side):

| Font Awesome | Ionic |
|-------------|-------|
| `fa-comments` | `chatbubbles` |
| `fa-comment` | `chatbubble` |
| `fa-comment-dots` | `chatbubble-ellipses` |
| `fa-comment-medical` | `medkit` |
| `fa-envelope` | `mail` |
| `fa-bell` | `notifications` |
| `fa-user-md` | `person` |
| `fa-heart` | `heart` |
| `fa-shield` | `shield` |
| `fa-stethoscope` | `fitness` |
| `fa-brain` | `bulb` |
| `fa-hands-helping` | `people` |

---

## Therapist Dashboard Endpoints (TherapistDashboardController)

### GET `get_config`
Returns dashboard configuration including stats, groups, features, labels.

**Response**: `{ config: TherapistDashboardConfig }`

### GET `get_conversations`
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `group_id` | int | No | Filter by patient group |
| `filter` | string | No | `active`, `critical`, `unread` |

**Response**: `{ conversations: Conversation[] }`

### GET `get_conversation`
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | Yes | Conversation to load |

**Response**: `{ conversation, messages, notes?, alerts? }`

### GET `get_messages`
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | Yes | Conversation |
| `after_id` | int | No | Messages after this ID |

**Response**: `{ messages: Message[], conversation_id }`

### GET `export_csv`
Exports conversations as a semicolon-delimited CSV file download. Access control enforced.

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `scope` | string | Yes | `patient`, `group`, or `all` |
| `conversation_id` | int | When scope=patient | Specific conversation to export |
| `group_id` | int | When scope=group | Group whose conversations to export |

**Response**: CSV file download with headers: `conversation_id`, `patient_name`, `patient_code`, `group_name`, `mode`, `ai_enabled`, `status`, `risk_level`, `message_id`, `timestamp`, `sender_type`, `sender_name`, `role`, `content`

### GET `get_alerts`
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `unread_only` | 0/1 | No | Only unread alerts |
| `alert_type` | string | No | Filter by alert type |

**Response**: `{ alerts: Alert[] }`

### GET `get_stats`
**Response**: `{ stats: DashboardStats }`

### GET `get_notes`
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | Yes | Conversation |

**Response**: `{ notes: Note[] }`

### GET `get_unread_counts`
**Response**: `{ unread_counts: { total, totalAlerts, bySubject: {...}, byGroup: {...} } }`

Returns unread message counts per user and per group tab on the therapist dashboard.
These counts exclude AI-generated messages (`role = 'assistant'`).

### GET `get_groups`
Returns therapist's assigned groups with patient counts.

**Response**: `{ groups: TherapistGroup[] }`

### POST `send_message`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | Yes | Target conversation |
| `message` | string | Yes | Message content |

**Response**: `{ success: true, message_id }`

### POST `edit_message`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `message_id` | int | Yes | Message to edit |
| `content` | string | Yes | New content |

**Response**: `{ success: boolean }`

### POST `delete_message`
Soft-deletes a message (sets `deleted` flag).

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `message_id` | int | Yes | Message to delete |

**Response**: `{ success: boolean }`

### POST `toggle_ai`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | Yes | Conversation |
| `enabled` | 0/1 | Yes | Enable/disable AI |

**Response**: `{ success, ai_enabled }`

### POST `set_risk`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | Yes | Conversation |
| `risk_level` | string | Yes | `low`/`medium`/`high`/`critical` |

**Response**: `{ success }`

### POST `add_note`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | Yes | Conversation |
| `content` | string | Yes | Note text |
| `note_type` | string | No | Note type (default: `THERAPY_NOTE_MANUAL`; e.g. `manual`, `ai_summary`) |

**Response**: `{ success, note_id }`

### POST `edit_note`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `note_id` | int | Yes | Note to edit |
| `content` | string | Yes | Updated note text |

**Response**: `{ success: boolean }`

### POST `delete_note`
Soft-deletes a note (sets `id_noteStatus` to lookup `deleted` via `therapyNoteStatus`).

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `note_id` | int | Yes | Note to delete |

**Response**: `{ success: boolean }`

### POST `mark_alert_read`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `alert_id` | int | Yes | Alert to mark |

**Response**: `{ success }`

### POST `mark_all_read`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | No | Scope to conversation |

**Response**: `{ success }`

### POST `mark_messages_read`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | Yes | Conversation |

**Response**: `{ success, unread_count }`

### POST `initialize_conversation`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `patient_id` | int | Yes | Patient ID to initialize conversation for |

**Response**: `{ success, conversation, already_exists }`

### POST `create_draft`
Generates an AI draft for the therapist to edit. Opens a modal dialog in the UI.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | Yes | Conversation |

**Response**: `{ success, draft: Draft }`

### POST `update_draft`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `draft_id` | int | Yes | Draft to update |
| `edited_content` | string | Yes | Edited text |

**Response**: `{ success }`

### POST `send_draft`
Sends the draft as a therapist message to the patient.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `draft_id` | int | Yes | Draft to send |
| `conversation_id` | int | Yes | Conversation |

**Response**: `{ success, message_id }`

### POST `discard_draft`
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `draft_id` | int | Yes | Draft to discard |

**Response**: `{ success }`

### GET `check_updates`
Lightweight polling endpoint. Returns only counts and latest message ID so the
frontend can decide whether a full fetch is needed.

**Response**: `{ unread_messages, unread_alerts, latest_message_id }`

### POST `generate_summary`
Generate an AI clinical summary for a conversation. Creates a new LLM
conversation linked to the therapist and section for audit trail.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | Yes | Conversation to summarize |

**Response**: `{ success, summary, summary_conversation_id, tokens_used }`

### POST `speech_transcribe`
Same as subject endpoint — transcribes audio to text.

---

## Backend Implementation Notes

### Unread Counts
`TherapyMessageService::getUnreadCountForUser($userId, $excludeAI = false)` — when `$excludeAI` is `true`, messages with `role = 'assistant'` are excluded. Used for therapist dashboard and floating icon badge.

### Removed Methods
- `TherapyChatModel::getConversation()`
- `TherapistDashboardModel::notifyTherapistNewMessage()`
- `TherapyChatService::getTherapyConversationByLlmId()`, `getTherapistsForGroup()`, `setTherapyMode()`, `getLookupValues()`, `removeTherapistFromGroup()`
- `api.ts::setApiBaseUrl()`
- `types/index.ts`: `DEFAULT_SUBJECT_LABELS`, `DEFAULT_THERAPIST_LABELS`
