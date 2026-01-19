# Therapy Chat Database Views

This folder contains the database views for the LLM Therapy Chat plugin.

## Naming Convention

Views are numbered with a two-digit prefix (e.g., `01_`, `02_`) to control the order of execution during database setup and updates.

## Current Views

1. **`01_view_therapyConversations.sql`** - Main therapy conversations view combining therapy metadata with LLM conversations and lookup values
2. **`02_view_therapyTags.sql`** - Therapy tags view with message details and urgency levels
3. **`03_view_therapyAlerts.sql`** - Therapy alerts view with conversation details and severity levels

## Execution Order

The numbered prefix ensures views are created in the correct dependency order:
- `01_view_therapyConversations` - Depends on base tables only
- `02_view_therapyTags` - Depends on therapyTags table and lookups
- `03_view_therapyAlerts` - Depends on therapyAlerts table and lookups

## Adding New Views

When adding new views:
1. Increment the number (e.g., `04_view_newFeature.sql`)
2. Ensure proper dependency ordering
3. Update this README with the new view description