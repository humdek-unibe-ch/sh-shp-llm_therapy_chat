# Therapy Chat Database Views

This folder contains the database views for the LLM Therapy Chat plugin.

## Naming Convention

Views are numbered with a two-digit prefix (e.g., `01_`, `02_`) to control the order of execution during database setup and updates.

## Current Views

1. **`01_view_therapyConversations.sql`** - Main therapy conversations view combining therapy metadata with LLM conversations and lookup values. No `id_groups` column — access control is handled via `therapyTherapistAssignments` + `users_groups`.
2. **`02_view_therapyAlerts.sql`** - Therapy alerts view with conversation details and severity levels. Covers ALL notification types including tags (the old `therapyTags` table has been removed; tag data lives in alert `metadata` JSON).
3. **`03_view_therapyTherapistAssignments.sql`** - Therapist-to-group assignment view for admin management and dashboard access control.

## Execution Order

The numbered prefix ensures views are created in the correct dependency order:
- `01_view_therapyConversations` - Depends on `therapyConversationMeta`, `llmConversations`, `users`, `lookups`
- `02_view_therapyAlerts` - Depends on `therapyAlerts`, `llmConversations`, `users`, `lookups`
- `03_view_therapyTherapistAssignments` - Depends on `therapyTherapistAssignments`, `users`, `groups`

## Adding New Views

When adding new views:
1. Increment the number (e.g., `04_view_newFeature.sql`)
2. Ensure proper dependency ordering
3. Update this README with the new view description
