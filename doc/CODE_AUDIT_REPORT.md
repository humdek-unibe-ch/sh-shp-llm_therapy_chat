# Code Audit Report (Current Baseline)

**Last updated:** 2026-02-24  
**Scope:** `sh-shp-llm_therapy_chat` plugin (PHP + SQL + React/TS + docs)

This file tracks the **current** audit status.  
Older frontend-only findings from 2025 are superseded and no longer represent the current code layout.

## Completed Remediations

- Removed unregistered/dead hook methods from `TherapyChatHooks`.
- Removed stale/unused frontend utility exports in `react/src/utils`.
- Simplified floating/nav badge polling to one JS path (`therapy_chat_floating.js`) and removed stale modal-only branch.
- Removed unused therapist `set_status` endpoint and corresponding backend model method.
- Added shared backend notification service (`TherapyNotificationService`) and shared model config trait (`TherapyModelConfigTrait`) to reduce duplication.
- Reduced controller duplication in `TherapistDashboardController` with shared request/access/error helpers.
- Fixed SQL drift in `server/db/v1.0.0.sql`:
  - removed duplicate `therapy_tag_reasons` style insert
  - removed unused `transactionBy/by_therapy_chat_plugin` lookup
  - added missing dashboard label fields used by runtime config
  - made subject page ACL explicit for `subject` group
  - removed unused alert lookup types (`high_activity`, `inactivity`, `new_message`)
- Aligned docs with runtime behavior and migration schema (`configuration`, `admin setup`, `user guide`, `architecture`, `api reference`, `developer guide`).

## Remaining Ongoing Watchpoints

- Keep docs in lockstep with SQL and runtime config keys on every schema/API change.
- Preserve single-source notification logic in `TherapyNotificationService`; avoid reintroducing per-model duplication.
- Keep frontend badge polling centralized (avoid adding template-level polling scripts).
- Re-run full plugin audit after major feature additions (new hooks, new controller actions, or migration updates).
