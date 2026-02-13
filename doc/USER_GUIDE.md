# LLM Therapy Chat - Practical User Guide

This guide is written for **non-technical users**.

It explains, in plain language, how to:
- set up the plugin in CMS,
- assign therapists to the right patient groups,
- and use the therapist dashboard in daily work.

---

## 1) Who this guide is for

### Admin / Coordinator
You configure pages and settings in CMS, manage groups, and assign therapists.

### Therapist
You use the dashboard to monitor patients, respond, create notes, and manage risk/status.

### Patient
You use the chat interface and can tag therapists when needed.

---

## 2) Quick setup checklist (admin)

Before going live, confirm:

- [ ] **Base dependency plugin is installed:** `sh-shp-llm`  
      https://github.com/humdek-unibe-ch/sh-shp-llm
- [ ] The plugin is installed and active.
- [ ] Both plugins are configured in CMS using their **module configuration pages**.
- [ ] A **module configuration page** exists (`sh_module_llm_therapy_chat`).
- [ ] A **patient page** exists with style `therapyChat`.
- [ ] A **therapist page** exists with style `therapistDashboard`.
- [ ] Users are added to correct groups (patients and therapists).
- [ ] Therapists are assigned to monitor patient groups.

---

## 3) CMS configuration (module page)

This therapy plugin depends on the base LLM plugin (`sh-shp-llm`).

In practice, admins configure **both plugins** in CMS:

1. **Base LLM plugin module** (`sh-shp-llm`) - main LLM/provider-level setup.
2. **Therapy Chat module** (`sh-shp-llm_therapy_chat`) - therapy-specific setup (groups, pages, floating behavior, tagging, etc.).

After both module configurations are set, create/use these two pages:

- a **patient page** with style **`therapyChat`**,
- a **therapist page** with style **`therapistDashboard`**.

Each page must use the correct style, because style settings control how the interface is shown and how it behaves.

Open the module page in CMS and configure these core fields:

1. **Subject Group**  
   Group containing patients.

2. **Therapist Group**  
   Group containing therapists.

3. **Subject Page**  
   Page where patients open chat.

4. **Therapist Page**  
   Page where therapists open dashboard.

5. **Floating Icon / Label / Position**  
   Controls how the floating button looks and where it appears.

6. **Default Mode** (`AI Hybrid` or `Human Only`)  
   Sets how new conversations start.

7. **Polling Interval**  
   How often the UI checks for new updates.

8. **Enable Tagging**  
   Allows patients to use `@therapist` and `#topic`.

9. **Tag Reasons**  
   Predefined topic list that appears when patient types `#`.

---

## 4) Configure the Patient Chat page (`therapyChat`)

On the patient chat section, review these key options:

- **Enable AI** - turns AI replies on/off.

If **Enable AI** is turned **off**, the plugin works as a normal human chat system:

- patients can chat only with therapist(s),
- patients do **not** get AI replies,
- patients do **not** have access to AI.

This is useful when you want a fully therapist-led communication flow.
- **LLM Model / Temperature / Max Tokens** - AI behavior and response length.
- **Conversation Context** - extra instruction for AI tone and scope.
- **Help Text** - message under input to explain `@` and `#`.
- **Danger Settings** - safety topic hints + safety message + optional alert emails.
- **Enable Floating Chat** - opens chat in modal from floating button.
- **Auto Start + Auto Start Context** - optional first welcome/system message.
- **Speech-to-Text** - microphone input if enabled.
- **Therapist email notification fields** - email templates for patient-to-therapist messages/tags.

---

## 5) Configure Therapist Dashboard page (`therapistDashboard`)

On the dashboard section, set:

- **Dashboard labels/texts** (titles, button labels, empty states).
- **Dashboard visibility controls** (alerts panel, notes panel, stats header, etc.).
- **LLM Model / Temperature / Max Tokens** for draft and summary tools.
- **Draft Context** - extra instruction for AI draft responses.
- **Summary Context** - extra instruction for AI conversation summaries.
- **Conversation controls** permissions (AI toggle, risk, status, notes).
- **Email notification templates** for therapist-to-patient and patient-to-therapist notifications.
- **Speech-to-Text** options.
- **Start Conversation labels** for patients without an active conversation.

---

## 6) Group and assignment model (important)

There are **two layers**:

1. **Role access layer**
   - Patient group = can access patient chat
   - Therapist group = can access therapist dashboard

2. **Monitoring scope layer**
   - Therapists are assigned to specific **patient groups**
   - This decides which patients they can actually monitor

So a therapist can have dashboard access but still see no patients until monitoring assignments are added.

---

## 7) Assign therapists to patient groups

1. Go to **Admin -> Users**.
2. Open a therapist user profile.
3. Find card: **"Therapy Chat - Patient Group Monitoring"**.
4. Select one or more patient groups.
5. Click **Save Assignments**.

Result: that therapist can now see and respond to patients in those selected groups.

---

## 8) Add patients to groups

1. Open each patient profile.
2. Add patient to the appropriate patient group(s).
3. Save user changes.

If therapist assignment and patient group overlap, that patient appears in therapist dashboard.

---

## 9) Therapist dashboard - daily workflow

### A. Start of day
1. Open dashboard.
2. Check stats (patients, active, critical, alerts).
3. Check alert banner.
4. Select a group tab (or "All Groups").

### B. Working patient by patient
1. Click a patient in left list.
2. If patient has no conversation, click **Start Conversation**.
3. Read latest messages.
4. Reply directly OR use **Generate AI Draft**.
5. If needed, use **Summarize** and save as note.

### C. Clinical controls
- **AI Toggle**: pause/resume AI
- **Status**: Active / Paused / Closed
- **Risk**: Low / Medium / High / Critical
- **Notes**: add, edit, delete (therapist-only)

### D. Inbox hygiene
- Use unread badges to prioritize.
- Mark messages as read.
- Dismiss resolved alerts.

---

## 10) What patients experience

- They see a floating chat button (if in patient group).
- They can type messages and receive AI and/or therapist responses (depending on mode/settings).
- Typing `@` lets them notify therapist(s).
- Typing `#` lets them add a predefined topic.
- If therapist pauses conversation status, patient cannot send until resumed.

---

## 11) Safety behavior (simple overview)

If content is flagged as concerning:

- therapists are alerted,
- AI can be paused/blocked for safety,
- patient still gets guidance text,
- therapist reviews and decides next action.

The system is designed so therapists can continue care and supervision quickly.

---

## 12) Email notifications

The plugin can send:

- therapist -> patient message notifications,
- patient -> therapist message notifications,
- special notifications when patient tags therapist.

Admin can customize:

- subject lines,
- message templates,
- sender name/email.

---

## 13) Common issues and simple fixes

### Therapist sees no patients
Check both:
1. Therapist is in therapist group, and
2. Therapist has monitoring assignments to patient groups.

### Patient does not see chat button
Check:
1. Patient is in configured subject group.
2. Subject page is selected in module settings.
3. Floating button settings are configured.

### AI is not responding
Check:
1. **Enable AI** is on for the style.
2. Conversation is not paused/closed.
3. LLM model is configured.

### Tagging does not work
Check:
1. Tagging is enabled.
2. Therapist assignments exist.
3. Tag reasons are valid JSON list.

---

## 14) Go-live checklist (recommended)

- [ ] Test with one demo patient and one demo therapist.
- [ ] Verify therapist can see only assigned patients.
- [ ] Verify `@therapist` creates alert/notification.
- [ ] Verify AI draft + summary works.
- [ ] Verify risk/status changes are saved.
- [ ] Verify clinical notes are therapist-only.
- [ ] Verify email templates and sender address.

---

## 15) Quick reference: who does what

### Admin
- Configures module fields and pages
- Manages groups
- Assigns therapist monitoring groups

### Therapist
- Monitors assigned patients
- Sends messages / uses AI draft
- Sets risk/status
- Adds notes and handles alerts

### Patient
- Uses chat
- Tags therapist when needed
- Receives support based on configured mode
