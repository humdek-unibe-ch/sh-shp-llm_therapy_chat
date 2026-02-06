# User Guide

## For Patients (Subjects)

### Accessing the Chat

If you are enrolled in the therapy program, you will see a floating chat button
(usually in the bottom-right corner) on every page. Click it to open the chat.

### Chatting

- **Type your message** in the text box and press **Enter** or click **Send**
- **AI Assistant** may respond automatically (if enabled by your therapist)
- **Your therapist** can also send messages directly — these appear with a green highlight

### Reaching Your Therapist

Use **@therapist** or **@all** in your message to tag your therapist for attention.
Use **#topic** to tag a predefined topic or status (configured by your therapist).

A help text below the message input explains these options.

> **Note**: If your conversation is paused by your therapist, you will see a notice
> and will not be able to send messages until it is resumed.

### Voice Input

If enabled, click the **microphone button** to dictate your message.
Click again to stop recording.

---

## For Therapists

### Dashboard Overview

The therapist dashboard has three main areas:

1. **Patient List** (left sidebar): All patients you are assigned to monitor
2. **Conversation View** (center): Full message history with the selected patient
3. **Notes & Controls** (right sidebar): Clinical notes, risk controls

### Group Tabs

If you monitor patients in multiple groups, you'll see **tabs** at the top.
Click a tab to filter the patient list by group. The "All Groups" tab shows everyone.

### Unread Tracking

- **Blue badge** on patient name = unread messages from that patient
- **Red badge** = unread alerts
- **Group tab badge** = total unread messages from patients in that group
- Total unread count shown in the stats header

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

### Sending Messages

Type in the input area and press Enter or click Send. Your message goes
directly to the patient.

### AI Draft Generation

1. Click **"Generate AI Draft"** below the message area
2. A **modal dialog** opens while the AI generates a suggested response
3. **Review and edit** the draft in the modal text area
4. Click **"Send to Patient"** to send the message and close the modal, or **"Discard"** to cancel

### Conversation Summarization

1. Click **"Summarize"** next to the Generate AI Draft button
2. A **modal dialog** opens while the AI generates a clinical summary
3. Review the summary — it includes key topics, emotional state, interventions, progress, and risk flags
4. Click **"Save as Clinical Note"** to store the summary as a note, or **"Close"** to dismiss

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
