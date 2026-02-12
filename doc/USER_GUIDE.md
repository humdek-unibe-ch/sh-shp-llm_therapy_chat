# User Guide

## For Patients (Subjects)

### Accessing the Chat

If you are enrolled in the therapy program, you will see a floating chat button
(usually in the bottom-right corner) on every page. Click it to open the chat.

### Chatting

- **Type your message** in the text box and press **Enter** or click **Send**
- **AI Assistant** may respond automatically (if enabled by your therapist)
- **Your therapist** can also send messages directly — these appear with a green highlight
- **Timestamps** are shown in 24-hour format (e.g., 14:30)

### Reaching Your Therapist

Type **@** in your message to see a dropdown of available therapists you can tag.
You can tag a **specific therapist** by name (e.g., `@Dr. Smith`) to notify only them,
or type **@therapist** to tag all assigned therapists at once. Select from the dropdown
or type the name to filter.

**Tagged messages skip AI**: When you tag a therapist (`@therapist` or `@SpecificName`),
your message is sent only to therapists — no AI response is generated. This keeps
therapist-directed messages focused on human attention.

Type **#** to see a dropdown of predefined topics/reasons (e.g., `#overwhelmed`,
`#need_talk`). Select one to include it in your message.

Use the **arrow keys** to navigate the dropdown, **Enter** or **Tab** to select,
and **Escape** to dismiss.

A help text below the message input explains these options.

> **Note**: If your conversation is paused by your therapist, you will see a notice
> and will not be able to send messages until it is resumed.

### Safety Detection

If you send a message that contains concerning content (e.g., references to self-harm),
the system will:
- Show you a safety message with helpful resources
- Notify your therapist immediately via email
- Block AI responses until your therapist reviews the conversation

### Voice Input

If enabled, click the **microphone button** to dictate your message.
Click again to stop recording.

---

## For Therapists

### Dashboard Overview

The therapist dashboard has three main areas:

1. **Patient List** (left sidebar): All patients you are assigned to monitor, including those who have not started a conversation yet
2. **Conversation View** (center): Full message history with the selected patient
3. **Notes & Controls** (right sidebar): Clinical notes, risk controls

### Group Tabs

If you monitor patients in multiple groups, you'll see **tabs** at the top.
Click a tab to filter the patient list by group. The "All Groups" tab shows everyone.

### Starting Conversations

If a patient does not have an active conversation yet, you will see a "Start Conversation" button next to their name.
Clicking it initializes a new conversation for that patient, allowing you to begin communication proactively.

### Unread Tracking

- **Blue badge** on patient name = unread messages from that patient
- **Red badge** = unread alerts
- **Group tab badge** = total unread messages from patients in that group
- Total unread count shown in the stats header

> **Note:** Unread counts include only **patient and therapist messages** — AI-generated
> messages are excluded, so you see how many human messages need your attention.

### Reading a Conversation

Click any patient in the list to load their conversation. Messages are color-coded:

| Color | Sender |
|-------|--------|
| **Blue (right)** | Your messages |
| **Light blue (left)** | Patient messages |
| **White with border** | AI responses |
| **Green with left border** | Other therapist messages |
| **Yellow centered** | System messages |

**Edited messages** show a small "edited" indicator.
**Deleted messages** show "This message was removed."
**Timestamps** are displayed in 24-hour format (e.g., 14:30).

### Sending Messages

Type in the input area and press Enter or click Send. Your message goes
directly to the patient.

### AI Draft Generation

1. Click **"Generate AI Draft"** below the message area
2. A **modal dialog** opens while the AI generates a suggested response
3. The AI draft is displayed as **formatted text** (headings, lists, bold, etc.) in an editable area
4. **Review and edit** the draft using the rich text toolbar (bold, italic, underline, lists)
5. Click **"Send to Patient"** to send the message and close the modal, or **"Discard"** to cancel

**Regenerate & Undo:**
- Click the **"Regenerate"** button (🔄) to generate a new AI draft. Your current text is saved automatically.
- Click the **"Undo"** button (↩) to restore the previous draft text from before the last regeneration.
- You can regenerate multiple times — each previous version is saved in the undo stack.

### Conversation Summarization

1. Click **"Summarize"** next to the Generate AI Draft button
2. A **modal dialog** opens while the AI generates a clinical summary
3. The summary is displayed with **full markdown formatting** — headings, tables, lists, bold text, etc.
4. Review the summary — it includes key topics, emotional state, therapeutic interventions, progress, and risk flags
5. Click **"Save as Clinical Note"** to store the summary as a note, or **"Close"** to dismiss
6. Saved summaries appear in the Clinical Notes panel with their markdown formatting preserved

### Managing Conversations

- **AI Toggle**: Pause or resume AI responses for a conversation
- **Status**: Set conversation to Active, Paused, or Closed (patients cannot send messages when paused)
- **Risk Level**: Rate the patient's risk (Low / Medium / High / Critical)

Risk and status changes are reflected **immediately** in the UI without a full page reload.

### Clinical Notes

Add private notes about the conversation that are only visible to therapists.
These are never shown to the patient or AI.

- **Edit** a note by clicking the pencil icon
- **Delete** a note by clicking the trash icon (soft-delete, can be recovered)
- All note changes are logged in the audit trail with the editor's name

### Alerts

Critical alerts appear as a red banner at the top of the dashboard.
Click **"Dismiss"** to mark as read.
