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

3. **Add database changes** as a new migration file (e.g., `server/db/v1.1.0.sql`)

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
