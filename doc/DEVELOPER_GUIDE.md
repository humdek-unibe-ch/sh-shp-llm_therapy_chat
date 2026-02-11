# Developer Guide

## Service Hierarchy

```
TherapyMessageService   ← Use this in controllers
  └── TherapyAlertService
        └── TherapyChatService
              └── LlmService (from sh-shp-llm)
```

Controllers only instantiate `TherapyMessageService` — it inherits all methods from the chain.

## Adding a New Feature

### Backend

1. **Add methods** to the appropriate service level:
   - Message/draft related → `TherapyMessageService`
   - Alert/notification related → `TherapyAlertService`
   - Conversation/config related → `TherapyChatService`

2. **Add controller action** in the appropriate controller:
   ```php
   case 'my_new_action':
       $result = $this->therapy_service->myNewMethod($params);
       echo json_encode($result);
       exit;
   ```

3. **Add database changes** to the existing migration file (`server/db/v1.0.0.sql`) for initial release, or create a new versioned migration (e.g., `server/db/v1.1.0.sql`) for post-release updates

### Frontend

1. **Add API method** in `utils/api.ts`:
   ```typescript
   async myNewAction(param: string): Promise<MyResponse> {
     return apiGet('my_new_action', withSection({ param }, sectionId));
   }
   ```

2. **Add types** in `types/index.ts`

3. **Use in component** via the API factory

### Build

```bash
cd react
npm run build
```

Output: `js/ext/therapy-chat.umd.js` + `css/ext/therapy-chat.css` (via `move-css.cjs`)

## Message Sender Tracking

All messages use `llmMessages.sent_context` JSON:
```json
{
  "therapy_sender_type": "subject|therapist|ai|system",
  "therapy_sender_id": 12345,
  "edited_at": "2025-01-01 12:00:00",
  "edited_by": 67890,
  "original_content": "..."
}
```

The `role` field in `llmMessages`:
- `user` = subject OR therapist (distinguished by `sent_context`)
- `assistant` = AI
- `system` = system messages

## Note Management

Clinical notes (`therapyNotes` table) use lookup-based status instead of ENUM:

- **`id_noteStatus`**: FK to `lookups` table (type_code: `therapyNoteStatus`, values: `active`, `deleted`)
- **`id_lastEditedBy`**: FK to `users` table — tracks who last edited a note
- **Constants**: `THERAPY_LOOKUP_NOTE_STATUS`, `THERAPY_NOTE_STATUS_ACTIVE`, `THERAPY_NOTE_STATUS_DELETED`

Key methods in `TherapyChatService`:
- `addNote()` — creates a note with `active` status, logs transaction
- `updateNote()` — updates content and `id_lastEditedBy`, logs transaction
- `softDeleteNote()` — sets `id_noteStatus` to `deleted`, logs transaction
- `getNotesForConversation()` — filters by `active` status via lookup join

## @Mention and #Topic Autocomplete

The `MessageInput` component supports inline autocomplete for @mentions and #topics:

### Frontend Architecture
- **Trigger detection**: As the user types, `detectTrigger()` scans backwards from the cursor for `@` or `#` preceded by whitespace/start-of-string
- **@mentions**: Calls `onFetchMentions()` callback which fetches therapists from `GET ?action=get_therapists`. Results are cached in a ref after first load
- **#topics**: Uses static `topicSuggestions` prop built from `config.tagReasons` (passed from PHP)
- **Filtering**: All items are filtered by the query string typed after the trigger character
- **Insertion**: Selected item's `insertText` (e.g. `@Dr. Smith` or `#anxiety`) replaces the trigger + query in the textarea
- **Keyboard navigation**: Arrow keys, Enter/Tab to select, Escape to dismiss

### Backend: Tag Processing
- `TherapyMessageService::processTagsInMessage()` matches both `@therapist` (all therapists) and specific `@TherapistName` (case-insensitive). Creates per-therapist tag alerts when a therapist is mentioned by name.
- `TherapyChatModel::sendPatientMessage()` detects both `@therapist` and `@TherapistName` patterns for email notifications.
- **AI skipping**: The condition `if ($aiActive && !$isTag)` in `sendPatientMessage()` prevents AI processing when a tag is detected. Tagged messages are sent only to therapists — no AI response is generated.

### Backend Endpoints
- `GET ?action=get_therapists&section_id=X` → Returns `{ therapists: [{ id, display, name, email }] }`
- `GET ?action=get_tag_reasons&section_id=X` → Returns `{ tag_reasons: [{ code, label, urgency }] }`

### Props on `MessageInput`
- `onFetchMentions?: () => Promise<MentionItem[]>` — Async callback to fetch @mention suggestions
- `topicSuggestions?: MentionItem[]` — Static list of #topic suggestions

The `MentionItem` type is exported from `MessageInput.tsx`.

## Danger Detection Flow

When a patient sends a message, danger detection runs in `TherapyChatModel::sendPatientMessage()` using a two-layer approach:

**Important ID distinction**: `TherapyChatModel` works with `therapyConversationMeta.id` (`$conversationId`) for therapy-layer operations, but `LlmDangerDetectionService::checkMessage()` operates on `llmConversations.id` (`$llmConversationId`). Always pass `$conversation['id_llmConversations']` to the LLM service.

1. **Conversation created first**: The conversation is always created/fetched before danger detection so alerts have a valid conversation ID.
2. **Layer 1 — LLM-based detection** (if `LlmDangerDetectionService` is available and enabled): Calls `checkMessage($message, $userId, $llmConversationId)`. If danger is detected, `LlmDangerDetectionService` internally blocks the `llmConversations` record and sends email notifications to `getDangerNotificationEmails()`. Then `handleDangerDetected()` is called with an empty `$extraEmails` parameter to avoid duplicate emails.
3. **Layer 2 — Keyword-based fallback** (if LLM detection is unavailable or didn't trigger): `scanKeywords()` splits `danger_keywords` by commas/semicolons/newlines and checks for case-insensitive substring matches. If danger keywords are found, `TherapyChatService::blockConversation()` is called explicitly to block the `llmConversations` record, then `handleDangerDetected()` is called with the extra danger emails.
4. **`handleDangerDetected()` (shared for both layers)**:
   - Saves the message (so therapists can see what was said)
   - `createDangerAlert()` creates an alert, escalates risk to critical, and sends urgent notifications to assigned therapists plus any `$extraEmails`
   - AI is disabled on the conversation (`setAIEnabled(false)`)
   - Does NOT call `notifyTherapistsNewMessage()` — `createDangerAlert` already sends urgent emails to therapists, so calling both would duplicate notifications
   - Frontend receives `{ blocked: true, type: 'danger_detected', message: '...' }`
5. **Email deduplication**: `sendUrgentNotification()` in `TherapyAlertService` merges assigned therapist emails with `$extraEmails`, deduplicates the list, and sends one email per unique address.

**`danger_notification_emails` flow**: `TherapyChatModel::getDangerNotificationEmails()` reads the CMS field and returns an array of email addresses (supports comma, semicolon, and newline separators). For Layer 1, these emails are passed directly to `LlmDangerDetectionService::checkMessage()`. For Layer 2, they are passed to `TherapyAlertService::createDangerAlert()` via `handleDangerDetected()`.

### Post-LLM Safety Detection (Layer 3)

After the AI responds, `handlePostLlmSafetyDetection()` evaluates the LLM's own safety assessment:

1. If `LlmResponseService` is available (parent plugin present), the therapy plugin injects the full structured response schema (JSON with `safety`, `content.text_blocks`, `metadata`) plus safety instructions with danger keywords via `LlmResponseService::buildResponseContext()`.
2. The LLM returns structured JSON. `TherapyMessageService::extractDisplayContent()` extracts human-readable text from `content.text_blocks[]` for storage; the raw JSON is preserved in `raw_content`.
3. `TherapyChatModel::parseStructuredResponse()` extracts the `safety` field from the JSON.
4. `LlmResponseService::assessSafety()` evaluates the safety assessment.
5. If `danger_level` is `critical` or `emergency`:
   - Conversation is blocked via `LlmDangerDetectionService::blockConversation()` or `TherapyChatService::blockConversation()`
   - A danger alert is created (risk escalated to critical)
   - AI is disabled on the conversation
   - Email notifications sent to therapists and configured extra emails
   - Transaction logged for audit

This matches the parent `sh-shp-llm` plugin's `LlmChatController::handleSafetyDetection()` behavior. If `LlmResponseService` is not available, the plugin falls back to the simple `getCriticalSafetyContext()` text injection (no structured response parsing).

### CSS Architecture

The React source CSS lives in `react/src/styles/therapy-chat.css` with `tc-` prefixed custom rules only. The build process (Vite) outputs the compiled CSS to `css/ext/therapy-chat.css`. Bootstrap 4.6 is **not** bundled — it's loaded globally by SelfHelp. The `css/ext/therapy-chat.css` file is ~6KB of custom styles.

## Floating Chat Modal

When `enable_floating_chat` is enabled on the `therapyChat` style:

**Inline chat suppression**: `TherapyChatView::output_content()` returns early when `isFloatingChatEnabled()` is true. This prevents the inline chat from rendering on the page — the floating modal panel is the sole chat interface in this mode.

1. **Hook renders a `<button>`** instead of an `<a>` link
2. **On click**: Panel opens, AJAX request fetches full config from chat page endpoint (`?action=get_config&section_id=X`)
3. **React mount**: After config is loaded, the `.therapy-chat-root` element's `data-config` is updated and React is mounted via `window.__TherapyChatMount()` or `window.TherapyChat.mount()`
4. **Config check**: `isFloatingChatModalEnabled()` checks `sections_fields_translation.content` first (actual runtime value), then falls back to `styles_fields.default_value`
5. **CSS loading**: The floating panel loads `therapy-chat.css` explicitly via a `<link>` tag in `floating_chat_icon.php` so styles work on any page (not just the chat page). Flex layout rules ensure proper scrolling and height in the floating panel; bubble background colors use `!important` for the floating modal context.

**Floating panel layout** (in `floating_chat_icon.php`):
- Panel height: `calc(100vh - 80px)` with `top: 40px` for equal top/bottom spacing
- Panel left-positioned at `12px` (hardcoded in inline style)
- Message bubbles: explicit `margin-left`/`margin-right` and `align-self` for proper alignment
  - Own (patient) messages: `align-self: flex-end` + `margin-left: auto` → right side
  - Other messages (AI, therapist, subject in therapist view): `align-self: flex-start` + `margin-right: auto` → left side

## Unread Count for Therapists

`TherapyMessageService::getUnreadCountForUser($userId, $excludeAI = false)` accepts an `$excludeAI` parameter.
When `$excludeAI` is `true`, messages with `role = 'assistant'` (AI-generated) are excluded from the count.
This is used for therapist dashboards and floating icon badges so unread counts reflect only patient and therapist messages.
`getUnreadBySubjectForTherapist()` and `getUnreadByGroupForTherapist()` filter out AI messages when computing counts.

**Read-marking flow**: Messages are marked as read (`is_new = 0`, `seen_at = NOW()`) in `therapyMessageRecipients` via `TherapyMessageService::markMessagesAsSeen()`. This is called:
- When the therapist selects a conversation (`loadFullConversation`)
- When polling fetches new messages (`handleGetMessages` → `markMessagesRead`)
- When the therapist clicks the "Mark read" button in the conversation header
- When the patient opens the chat (`SubjectChat` mount and poll)

The "Mark read" button appears in the therapist dashboard conversation header only when unread messages exist for the selected conversation.

## Lightweight Polling

Both patient and therapist UIs use a two-phase polling strategy:

1. **Phase 1** (`check_updates`): Tiny request returning only `latest_message_id` and unread counts
2. **Phase 2**: Only if values changed compared to last poll, trigger the full data fetch

This dramatically reduces server load during idle periods.

## AI Draft Generation

The "Generate AI Draft" feature allows therapists to get AI-suggested responses:

- `handleCreateDraft` in `TherapistDashboardController` builds AI context from conversation history, adds a draft-specific instruction, and calls the LLM API
- The `conversation_context` field provides the base system prompt for the AI
- The `therapy_draft_context` field provides additional customizable instructions specific to draft generation (e.g., "Generate a response based on the conversation and the patient's last message")
- `llm_model`, `llm_temperature`, `llm_max_tokens` fields on the `therapistDashboard` style control LLM parameters
- Drafts are saved to `therapyDraftMessages` table with transaction logging
- The frontend renders AI markdown as formatted HTML in a contentEditable editor
- **Regenerate**: Discards the old draft, creates a new one, saves the previous text to an undo stack
- **Undo**: Restores the last draft text from before the most recent regeneration
- Draft text is tracked as plain text; markdown is rendered as HTML only in the editor view

## Summarization

The "Summarize" feature creates a new `llmConversations` record linked to the therapist and section for full audit trail:

- `createSummaryConversation()` in `TherapyMessageService` logs the request and AI response
- The `therapy_summary_context` field on the `therapistDashboard` style provides customizable instructions
- Summaries are rendered with full markdown support (headings, tables, lists, bold/italic) via `MarkdownRenderer`
- Summaries can be saved as clinical notes of type `ai_summary`

## Markdown Rendering

The plugin uses `react-markdown` with `remark-gfm` (GitHub Flavored Markdown) for rendering markdown content:

- **AI messages** in the chat use `MarkdownRenderer` (via `MessageList`)
- **Conversation summaries** in the summary modal use `MarkdownRenderer`
- **Clinical notes** in the sidebar use `MarkdownRenderer` (notes saved from AI summaries retain their markdown formatting)
- **AI draft editor** uses a simple `markdownToHtml()` converter for the initial render in the contentEditable div; the therapist edits the rich text directly
- CSS classes with `tc-markdown` prefix provide consistent styling for tables, headings, lists, code blocks, blockquotes, and horizontal rules

## Hook System

The plugin uses these hooks:

| Hook | Class | Method | Purpose |
|------|-------|--------|---------|
| `outputTherapyChatIcon` | `PageView` | `output_content` | Floating chat/dashboard button |
| `outputTherapistGroupAssignments` | `UserUpdate` | `output_content` | Admin user page: group assignment UI |
| `saveTherapistGroupAssignments` | `UserUpdate` | `save_data` | Save assignments on user save |

## Removed Code

The following unused methods were removed:

| Location | Method |
|----------|--------|
| `TherapyChatModel` | `getConversation()` |
| `TherapistDashboardModel` | `notifyTherapistNewMessage()` |
| `TherapyChatService` | `getTherapyConversationByLlmId()`, `getTherapistsForGroup()`, `setTherapyMode()`, `getLookupValues()`, `removeTherapistFromGroup()` |
| `api.ts` | `setApiBaseUrl()`, `getErrorMessage()` |
| `types/index.ts` | `DEFAULT_SUBJECT_LABELS`, `DEFAULT_THERAPIST_LABELS` |
| `TherapyChatModel` | `hasAccess()`, `getDangerDetection()` |
| `TherapistDashboardModel` | `getConversationById()` |
| `globals.php` | `LLM_THERAPY_CHAT_PLUGIN_NAME` constant |

Use `getOrCreateConversation()` instead of `getConversation()`. `getDangerNotificationEmails()` is active — it reads the `danger_notification_emails` CMS field and returns an array of email addresses for danger alerts.

## Testing Locally

1. Set up SelfHelp with the LLM plugin
2. Run the database migration
3. For React development: `cd react && npm run dev` (proxy to SelfHelp)
4. For production: `npm run build`

## Code Style

- **PHP**: PSR-4, PHPDoc on all public methods
- **TypeScript**: Strict mode, explicit types, no `any`
- **CSS**: Bootstrap 4.6 classes first, `tc-` prefix for custom rules
- **Components**: Small, focused, single responsibility
