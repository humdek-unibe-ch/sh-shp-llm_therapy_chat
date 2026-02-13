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

3. **Add database changes** to the existing migration file (`server/db/v1.0.0.sql`) for initial release, or create a new versioned migration (e.g., `server/db/v1.2.0.sql`) for post-release updates

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

Tag reasons come from `get_config` → `config.tagReasons`, not a separate endpoint.

### Props on `MessageInput`
- `onFetchMentions?: () => Promise<MentionItem[]>` — Async callback to fetch @mention suggestions
- `topicSuggestions?: MentionItem[]` — Static list of #topic suggestions

The `MentionItem` type is exported from `MessageInput.tsx`.

## Safety Detection Flow

Safety detection is **purely context-based** via the LLM's structured response. There is no keyword matching or pre-message scanning — the LLM evaluates the full conversation context and returns a safety assessment as part of every response.

### How It Works

1. **Patient sends a message** → `TherapyChatModel::sendPatientMessage()` saves the message via `sendTherapyMessage()`. The message is always saved regardless of blocked/AI state.
2. **AI decision**: `isConversationAIActive()` checks whether AI should respond. Returns `false` when `ai_enabled = 0` (therapist toggle, or set by safety detection).
3. **Manual mode** (AI off or blocked): When AI is not active, `notifyTherapistsNewMessage()` delivers the message to assigned therapists. No AI response is generated. The patient can keep writing — messages are delivered to therapists only.
4. **AI mode**: When AI is active and the patient did not @mention a therapist, `processAIResponse()` is called.
5. **Schema injection**: `TherapyChatModel::processAIResponse()` injects the structured JSON response schema via `LlmResponseService::buildResponseContext()`. The schema includes safety categories (suicide, self-harm, harm to others, etc.) and danger level definitions. The `danger_keywords` CMS field provides **topic hints** to the LLM (not matched server-side).
6. **LLM responds** with structured JSON containing a `safety` field: `{ is_safe, danger_level, detected_concerns, requires_intervention, safety_message }`.
7. **Post-LLM evaluation**: `handlePostLlmSafetyDetection()` parses the structured response and evaluates the safety assessment via `LlmResponseService::assessSafety()`.
8. **If `danger_level` is `critical` or `emergency`**:
   - Conversation is blocked via `LlmDangerDetectionService::blockConversation()` or `TherapyChatService::blockConversation()`
   - AI is disabled: `setAIEnabled(false)` → conversation switches to manual mode
   - A danger alert is created (risk escalated to critical)
   - Email notifications sent to therapists and configured extra addresses (`danger_notification_emails`)
   - Transaction logged for audit
   - **Patient can still send messages** — they are delivered to therapists (manual mode)

### Important: Blocked ≠ Silent

When danger is detected, the conversation enters manual mode. The patient is NOT prevented from sending messages. The AI simply stops responding. All subsequent patient messages go to therapists via `notifyTherapistsNewMessage()`. This ensures the patient is never silenced during a crisis — they can always reach a human therapist.

### Important ID Distinction

`TherapyChatModel` works with `therapyConversationMeta.id` for therapy-layer operations, but `LlmDangerDetectionService::blockConversation()` operates on `llmConversations.id`. Always pass `$conversation['id_llmConversations']` to the LLM service.

### Email Notifications

`TherapyAlertService::sendUrgentNotification()` sends emails to all assigned therapists plus any additional addresses in the `danger_notification_emails` CMS field (e.g., clinical supervisors). Emails are deduplicated.

### Pause/Resume AI and Conversation Blocking

Two independent states control conversation access:
- **`therapyConversationMeta.ai_enabled`**: Controls whether AI generates responses. Set to `0` by safety detection or by therapist toggle. When `0`, patient messages are delivered to therapists only (manual mode).
- **`llmConversations.blocked`**: Hard block set by safety detection at the parent LLM plugin level. Prevents LLM API calls for this conversation. Acts as a safety net at the parent plugin level.

When a therapist resumes AI (`setAIEnabled(true)`), the system also calls `unblockConversation()` to clear the `llmConversations.blocked` flag, allowing AI responses to flow again.

### `sent_context` for AI Messages

AI response messages (`llmMessages` with `role = 'assistant'`) store the full context messages array in `sent_context`. This matches the parent `sh-shp-llm` plugin's behavior where `LlmContextService::getContextForTracking()` returns all system instructions, schema, language/safety hints, and conversation history metadata. The "Context Sent to AI" popup in the admin panel displays this data for debugging and audit.

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
| `outputTherapyChatIcon` | `NavView` | `output_profile` | Floating chat/dashboard button |
| `outputTherapistGroupAssignments` | `UserSelectView` | `output_user_manipulation` | Admin user page: group assignment UI |

Assignments are saved via AJAX endpoint `/request/AjaxTherapyChat/saveTherapistAssignments`, not via a hook on user save.

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
