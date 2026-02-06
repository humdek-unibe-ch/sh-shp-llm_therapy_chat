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

## Hook System

The plugin uses these hooks:

| Hook | Class | Method | Purpose |
|------|-------|--------|---------|
| `outputTherapyChatIcon` | `PageView` | `output_content` | Floating chat/dashboard button |
| `outputTherapistGroupAssignments` | `UserUpdate` | `output_content` | Admin user page: group assignment UI |
| `saveTherapistGroupAssignments` | `UserUpdate` | `save_data` | Save assignments on user save |

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
