# Code Audit Report: LLM Therapy Chat React/TypeScript Frontend

**Date:** February 12, 2025  
**Scope:** All `.tsx`, `.ts`, `.jsx`, `.js` files in the plugin directory  
**Files Audited:** 15 source files (excluding generated `therapy-chat.umd.js`)

---

## Executive Summary

The codebase is generally well-structured with clear separation of concerns. However, several issues were identified: **duplicated code patterns**, **bad practices** (especially in the large TherapistDashboard component), **dead code**, **console.log/debug statements**, and **one potential broken UI element** (Bootstrap dropdown).

---

## 1. DUPLICATIONS

### 1.1 Floating Badge Update Logic
**Locations:**
- `SubjectChat.tsx` lines 35–44: `updateFloatingBadge(count)`
- `TherapistDashboard.tsx` lines 175–185: inline badge update in `loadUnreadCounts`
- `therapy_chat_floating.js` lines 44–56: `updateBadge(count)`

**Pattern:** Each implements the same logic: find `.therapy-chat-badge`, set `textContent` or hide when count ≤ 0.

**Recommendation:** Extract to a shared utility (e.g. `utils/badge.ts`) or a single DOM helper used by both React and vanilla JS.

### 1.2 API PostData Pattern
**Location:** `api.ts` lines 82–87, 125–130, 134–138, 141–145, etc.

**Pattern:** The `postData(action, sectionId)` + `fd.append(...)` pattern is repeated ~25 times. The structure is consistent but could be generalized with a helper that accepts a params object.

### 1.3 Modal Loading/Error UI
**Locations:**
- `TherapistDashboard.tsx` lines 1098–1110 (Draft Modal loading)
- `TherapistDashboard.tsx` lines 1237–1249 (Summary Modal loading)

**Pattern:** Nearly identical loading spinner + error + retry UI for both modals:
```tsx
<div className="text-center py-5 d-flex flex-column align-items-center justify-content-center" style={{ flex: 1 }}>
  <div className="spinner-border ..." style={{ width: '3rem', height: '3rem' }} role="status" />
  <p className="text-muted mb-0">...</p>
  <small className="text-muted mt-1">...</small>
</div>
```

**Recommendation:** Extract `ModalLoadingState` and `ModalErrorState` components.

### 1.4 Unread Count / bySubject Access Pattern
**Locations:**
- `TherapistDashboard.tsx` lines 539–543 (patient list)
- `TherapistDashboard.tsx` lines 634–641 (header "Mark read" button)

**Pattern:** Same logic to safely access `unreadCounts.bySubject[uid]` with numeric/string ID fallback:
```tsx
const bySubject = unreadCounts?.bySubject ?? {};
const uid = conv.id_users ?? 0;
const uc = bySubject[uid] ?? bySubject[String(uid)] ?? null;
const unread = (uc?.unreadCount ?? 0);
```

**Recommendation:** Extract `getUnreadForSubject(unreadCounts, userId): number` helper.

### 1.5 Config Fetch + Parse Pattern
**Locations:**
- `TherapyChat.tsx` lines 56–66 (SubjectChatLoader)
- `TherapyChat.tsx` lines 95–100 (TherapistDashboardLoader)
- `therapy_chat_floating.js` lines 149–167

**Pattern:** Fetch config, parse `(resp as { config?: T })?.config ?? resp`, handle errors.

**Recommendation:** Shared `fetchConfig<T>(api, fallback)` utility.

### 1.6 Polling Pattern with lastKnownMsgIdRef
**Locations:**
- `SubjectChat.tsx` lines 139–166
- `TherapistDashboard.tsx` lines 234–272

**Pattern:** Both use `lastKnownMsgIdRef` (or similar) + `checkUpdates` + conditional full fetch. Structure differs slightly but logic is duplicated.

---

## 2. BAD PRACTICES

### 2.1 Files Too Large
| File | Lines | Threshold |
|------|-------|-----------|
| `TherapistDashboard.tsx` | **1,392** | >300 |

**Recommendation:** Split into:
- `TherapistDashboard.tsx` (orchestration)
- `PatientListSidebar.tsx`
- `ConversationArea.tsx`
- `NotesSidebar.tsx`
- `DraftModal.tsx`
- `SummaryModal.tsx`
- `AlertBanner.tsx`
- `StatItem.tsx`, `riskBadge`, `statusBadge` as shared components

### 2.2 Components Doing Too Much (SRP Violations)
- **TherapistDashboard**: Handles stats, alerts, groups, filters, patient list, conversation view, notes, drafts, summaries, risk/status controls, URL state, polling, badge sync. **~15+ responsibilities.**

**Recommendation:** Extract sub-components as above.

### 2.3 Long Functions
- `TherapistDashboard` render: ~450 lines (lines 424–975+) — monolithic JSX.
- `loadConversations`, `selectConversation`, `handleCreateDraft`, etc. are reasonable length, but the overall component is too large.

### 2.4 Inline Styles / Magic Numbers
**Locations (sample):**
- `TherapistDashboard.tsx`: `style={{ gap: '1.5rem' }}`, `style={{ minHeight: 500 }}`, `style={{ fontSize: '0.65rem' }}`, `style={{ width: '0.7rem', height: '0.7rem' }}`, `style={{ opacity: 0.3 }}`, etc.
- `MessageInput.tsx`: `style={{ resize: 'none', minHeight: 44, maxHeight: 120 }}`, `style={{ position: 'relative' }}`
- `MessageList.tsx`: `style={{ opacity: 0.3 }}`, `style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}`, `style={{ fontSize: '0.6rem' }}`

**Recommendation:** Move to CSS classes in `therapy-chat.css` or use CSS variables for spacing/sizing.

### 2.5 Magic Numbers
- `MessageInput.tsx` line 41: `MAX_LENGTH = 4000`, `MAX_RECORDING_MS = 60_000` — good.
- `MessageInput.tsx` line 102: `Math.min(el.scrollHeight, 120)` — 120 is magic (should be `MAX_TEXTAREA_HEIGHT`).
- `TherapistDashboard.tsx` line 669: `minHeight: 500` — magic.
- `MessageInput.tsx` line 339: `setTimeout(..., 200)` for blur delay — magic.

### 2.6 Missing Error Handling
- `TherapistDashboard.tsx` line 266: `catch { /* Polling errors are non-fatal */ }` — silently swallows errors. Consider logging for debugging.
- `SubjectChat.tsx` line 156: `catch { /* polling errors are non-fatal */ }` — same.
- `TherapistDashboard.tsx` line 322: `catch { /* ignore */ }` in `selectConversation` — no user feedback if mark-read fails.

### 2.7 Prop Drilling
- `config` is passed deeply into TherapistDashboard. `sectionId`, `labels`, `features`, `pollingInterval`, `speechToTextEnabled` are all drilled. Consider React Context for config.

### 2.8 Bootstrap Dropdown May Not Work
**Location:** `TherapistDashboard.tsx` line 868:
```tsx
<button className="btn btn-outline-secondary btn-sm dropdown-toggle" data-toggle="dropdown">
```

Bootstrap 4 dropdowns require `data-toggle="dropdown"` **and** Bootstrap's JavaScript (or jQuery) to be loaded and initialized. If the page does not include Bootstrap's JS or uses a different version, the dropdown will not open on click.

**Recommendation:** Verify Bootstrap JS is loaded on the therapist dashboard page, or replace with a React-controlled dropdown (e.g. state + conditional render).

### 2.9 `(uc as any)` Type Unsafe
**Location:** `TherapistDashboard.tsx` line 638:
```tsx
const unread = (uc as any)?.unreadCount ?? 0;
```

**Recommendation:** Define a proper type for `uc` (e.g. `{ unreadCount?: number }`) and remove `as any`.

---

## 3. DEAD CODE

### 3.1 Unused Exports / Imports
- **`useChatState`** returns `setError` and `setMessages` — neither TherapistDashboard nor SubjectChat use them. They use `clearError` and `chat.error` but not `setError`.
- **`api.ts`** imports `Note`, `Draft`, `DashboardStats`, etc. — all appear used in type annotations.
- **`TherapyChat.tsx`** imports `createSubjectApi` and `createTherapistApi` — used in loaders. ✓

### 3.2 Unused Variables
- **`therapy_assignments.js`** line 3: `console.log('save-therapy-assignments clicked')` — debug log, should be removed.

### 3.3 Commented-Out Code
- None found.

### 3.4 Unreachable Code
- None found.

### 3.5 Exported Functions/Types Not Imported Elsewhere
- `CheckUpdatesResponse`, `SummaryResponse`, `InitializeConversationResponse` from `api.ts` — used in `api.ts` return types. ✓
- `MentionItem` from `MessageInput.tsx` — imported by `SubjectChat.tsx`. ✓

### 3.6 Event Handlers Defined But Never Attached
- None found.

---

## 4. BROKEN LOGIC / POTENTIAL BUGS

### 4.1 Race Conditions in Async Code
- **SubjectChat.tsx** lines 109–120: Initial load uses `loadedRef` to prevent double load, but the effect has empty deps `[]` and `loadConversation`/`api` are from closure. If `config` changes, the effect will not re-run. Likely intentional (load once).
- **TherapistDashboard.tsx** lines 216–229: Initial load effect has `[]` deps and uses `loadConversations`, `loadAlerts`, etc. from closure. `eslint-disable-line react-hooks/exhaustive-deps` acknowledges this. Safe if config is stable.

### 4.2 Missing Cleanup in useEffect
- **SubjectChat.tsx** lines 66–81: MutationObserver correctly returns `() => observer.disconnect()` ✓
- **MessageInput.tsx** lines 81–91: Cleanup for `audioStreamRef` and `recordingTimeoutRef` ✓
- **usePolling.ts**: `clearInterval` on unmount ✓

### 4.3 Stale Closures
- **useChatState**: Uses `loadFnRef`, `sendFnRef`, `pollFnRef`, `conversationRef` to avoid stale closures. ✓
- **TherapistDashboard**: Uses `activeGroupIdRef`, `activeFilterRef`, `selectedIdRef` for stable values in callbacks. ✓

### 4.4 Incorrect Dependency Arrays
- **TherapistDashboard.tsx** line 229: `useEffect(..., [])` with `// eslint-disable-line react-hooks/exhaustive-deps` — intentional one-time init.
- **SubjectChat.tsx** line 121: Same pattern.
- **SubjectChat.tsx** line 136: `useEffect` depends on `[panelVisible, isFloating, conversation, api]` — `api` changes when `config` changes; may cause extra effect runs. Consider if `api` is stable (from useMemo).

### 4.5 `isFloatingPanelVisible` Logic
**Location:** `SubjectChat.tsx` lines 50–54:
```ts
return panel.style.display !== 'none' && panel.style.display !== '';
```

When the panel is opened, `therapy_chat_floating.js` sets `panel.style.display = 'flex'`. When closed, it sets `'none'`. The initial HTML has `style="display:none"`. So:
- Open: `'flex' !== 'none' && 'flex' !== ''` → true ✓
- Closed: `'none' !== 'none'` → false ✓
- Unstyled: `'' !== 'none' && '' !== ''` → false ✓

Logic is correct. Note: If the panel were ever shown via CSS only (no inline style), `panel.style.display` could be `''` and we'd incorrectly say it's hidden. The current implementation always sets inline style on toggle, so this is safe.

---

## 5. CONSOLE.LOG / TODO / FIXME

### 5.1 Console Statements (Production Code)
| File | Line | Statement |
|------|------|-----------|
| `therapy_assignments.js` | 3 | `console.log('save-therapy-assignments clicked')` — **remove** |
| `therapy_assignments.js` | 33 | `console.error('Error:', error)` — acceptable for error logging |
| `TherapistDashboard.tsx` | 162, 188, 198, 209, 295, 413, 434, 461, 517, 533 | `console.error(...)` — acceptable |
| `MessageInput.tsx` | 362, 410 | `console.error(...)` — acceptable |
| `TherapyChat.tsx` | 149, 181, 197, 248, 259 | `console.error(...)` — acceptable |
| `therapy_chat_floating.js` | 165 | `console.error(...)` — acceptable |
| `useChatState.ts` | 157 | `console.error('Poll error:', err)` — acceptable |
| `usePolling.ts` | 25 | `console.error('Polling error:', err)` — acceptable |

**Action:** Remove `console.log` from `therapy_assignments.js` line 3.

### 5.2 TODO / FIXME
- None found.

---

## 6. FILE-BY-FILE SUMMARY

| File | Lines | Issues |
|------|-------|--------|
| `api.ts` | 387 | Duplicated PostData pattern; otherwise clean |
| `types/index.ts` | 351 | Clean |
| `TherapistDashboard.tsx` | 1,392 | Too large; many inline styles; console.error; dropdown; `(uc as any)` |
| `SubjectChat.tsx` | 282 | Duplicated badge logic; eslint-disable |
| `MessageList.tsx` | 178 | Inline styles; otherwise clean |
| `MessageInput.tsx` | 432 | Console.error; magic numbers; otherwise clean |
| `TherapyChat.tsx` | 282 | Console.error; duplicated config fetch |
| `MarkdownRenderer.tsx` | 32 | Clean |
| `TaggingPanel.tsx` | 49 | Clean |
| `useChatState.ts` | 177 | Returns unused `setError`; console.error |
| `LoadingIndicator.tsx` | 25 | Clean |
| `usePolling.ts` | 34 | Console.error; otherwise clean |
| `therapy_chat_floating.js` | 220 | Duplicated badge logic; console.error |
| `therapy_assignments.js` | 41 | **console.log** to remove |
| `gulpfile.js` | 185 | Build tool; console.log acceptable |
| `vite.config.ts` | 83 | Config; clean |

---

## 7. RECOMMENDED PRIORITIES

1. **High:** Remove `console.log` from `therapy_assignments.js`.
2. **High:** Verify/fix Bootstrap dropdown in TherapistDashboard (or replace with React-controlled dropdown).
3. **Medium:** Split TherapistDashboard into smaller components.
4. **Medium:** Extract shared badge update utility.
5. **Medium:** Extract shared modal loading/error components.
6. **Low:** Replace inline styles with CSS classes.
7. **Low:** Extract `getUnreadForSubject` helper.
8. **Low:** Remove `(uc as any)` with proper typing.

---

*End of audit report.*
