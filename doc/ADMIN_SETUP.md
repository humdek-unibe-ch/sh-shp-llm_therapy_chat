# Administrator Setup Guide - LLM Therapy Chat Plugin

## Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [Installation Steps](#2-installation-steps)
3. [Initial Configuration](#3-initial-configuration)
4. [User & Group Setup](#4-user--group-setup)
5. [AI Configuration](#5-ai-configuration)
6. [Safety Configuration](#6-safety-configuration)
7. [UI Customization](#7-ui-customization)
8. [Email Notifications](#8-email-notifications)
9. [Access Control](#9-access-control)
10. [Testing the Installation](#10-testing-the-installation)
11. [Maintenance](#11-maintenance)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. Prerequisites

### System Requirements

| Component | Requirement |
|-----------|-------------|
| PHP | 8.2+ (8.3 recommended) |
| MySQL | 8.0+ or MariaDB 10.2+ |
| Node.js | 18+ (for building frontend) |
| Character Set | utf8mb4 |

### Required PHP Extensions

- PDO MySQL
- JSON
- mbstring
- APCu (recommended for caching)

### Required SelfHelp Plugins

| Plugin | Version | Purpose |
|--------|---------|---------|
| **sh-shp-llm** | >= 1.0.0 | **REQUIRED** - Base LLM functionality |

> **IMPORTANT**: The sh-shp-llm plugin MUST be installed and configured before installing this plugin. All conversations and messages are stored in the LLM plugin's tables.

---

## 2. Installation Steps

### Step 1: Verify LLM Plugin Installation

Ensure the sh-shp-llm plugin is installed:

```sql
-- Check that llmConversations table exists
SELECT COUNT(*) FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llmConversations';
-- Should return 1
```

### Step 2: Run Database Migration

```bash
cd server/plugins/sh-shp-llm_therapy_chat
mysql -u username -p database < server/db/v1.0.0.sql
```

**What this creates:**
- `therapyConversationMeta` table
- `therapyTags` table
- `therapyAlerts` table
- `therapyNotes` table
- Database views
- Lookup entries
- Pages and sections
- Hooks
- Style definitions

### Step 3: Build React Frontend

```bash
cd gulp

# Install gulp dependencies
npm install

# Install React dependencies
gulp react-install

# Build the React components
gulp build
```

**Output files:**
- `js/ext/therapy-chat.umd.js` - React bundle
- `css/ext/therapy-chat.css` - Styles

### Step 4: Verify Installation

1. Navigate to `/admin/module_llm_therapy_chat`
2. You should see the configuration page
3. Check that the style fields are available in CMS

---

## 3. Initial Configuration

### Module Configuration Page

Navigate to: `/admin/module_llm_therapy_chat`

### Core Settings

| Field | Description | Default |
|-------|-------------|---------|
| **therapy_chat_subject_group** | Group containing patients | (select) |
| **therapy_chat_therapist_group** | Group containing therapists | (select) |
| **therapy_chat_subject_page** | Page ID for patient chat | therapyChatSubject |
| **therapy_chat_therapist_page** | Page ID for therapist dashboard | therapyChatTherapist |

### Floating Button Settings

| Field | Description | Default |
|-------|-------------|---------|
| **therapy_chat_floating_icon** | Font Awesome icon class | `fa-comments` |
| **therapy_chat_floating_label** | Optional text label | (empty) |
| **therapy_chat_floating_position** | Screen position | `bottom-right` |

### Behavior Settings

| Field | Description | Default |
|-------|-------------|---------|
| **therapy_chat_default_mode** | Default chat mode | `ai_hybrid` |
| **therapy_chat_polling_interval** | Real-time update interval (seconds) | `3` |
| **therapy_chat_enable_tagging** | Allow @mention tagging | `1` (enabled) |

### Tag Reasons Configuration

Configure predefined tag reasons in JSON format:

```json
[
    {"key": "overwhelmed", "label": "I am feeling overwhelmed", "urgency": "normal"},
    {"key": "need_talk", "label": "I need to talk soon", "urgency": "urgent"},
    {"key": "urgent", "label": "This feels urgent", "urgency": "urgent"},
    {"key": "emergency", "label": "Emergency - please respond immediately", "urgency": "emergency"}
]
```

**Each tag reason has:**
- `key` - Unique identifier (no spaces)
- `label` - Displayed text to patients
- `urgency` - `normal`, `urgent`, or `emergency`

---

## 4. User & Group Setup

### Create Required Groups

If not already existing, create these groups in SelfHelp:

1. **Patient/Subject Group**
   - Navigate to `/admin/groups`
   - Create group (e.g., "patients" or "therapy_subjects")
   - Note the group ID

2. **Therapist Group**
   - Create group (e.g., "therapists")
   - Note the group ID

### Assign Users to Groups

**For Patients:**
1. Navigate to user management
2. Assign patient users to the patient group

**For Therapists:**
1. Assign therapist users to the therapist group
2. Therapists can be in multiple groups for different patient sets

### Configure ACL Permissions

The database migration creates default ACL entries, but verify:

**Patient Chat Page (`therapyChatSubject`):**
```sql
-- Verify patient group has SELECT access
SELECT * FROM acl_groups 
WHERE id_pages = (SELECT id FROM pages WHERE keyword = 'therapyChatSubject')
AND id_groups = {patient_group_id};
```

**Therapist Dashboard (`therapyChatTherapist`):**
```sql
-- Verify therapist group has SELECT and INSERT access
SELECT * FROM acl_groups 
WHERE id_pages = (SELECT id FROM pages WHERE keyword = 'therapyChatTherapist')
AND id_groups = {therapist_group_id};
```

---

## 5. AI Configuration

### LLM Model Selection

In the section configuration (CMS), configure:

| Field | Description | Example |
|-------|-------------|---------|
| **llm_model** | AI model to use | `gpt-4`, `claude-3-opus` |
| **llm_temperature** | Creativity (0-2) | `1` |
| **llm_max_tokens** | Max response length | `2048` |

### System Prompt Configuration

Set the `conversation_context` field to customize AI behavior:

```markdown
You are a supportive AI assistant in a mental health therapy context.

Your role:
- Provide empathetic, non-judgmental support
- Use evidence-based techniques (validation, reflection, grounding)
- Encourage the user while respecting boundaries
- Suggest professional support when appropriate

Important boundaries:
- You are NOT a therapist
- You cannot diagnose conditions
- You cannot prescribe treatments
- Always encourage speaking with the assigned therapist for clinical concerns

Communication style:
- Use warm, conversational language
- Ask open-ended questions
- Reflect back what you hear
- Validate emotions before problem-solving

If the user seems in distress:
- Express genuine concern
- Encourage them to tag their therapist
- Remind them of crisis resources if appropriate
```

### Chat Modes

| Mode | Description | Use Case |
|------|-------------|----------|
| **ai_hybrid** | AI responds, therapist can intervene | Default - 24/7 support |
| **human_only** | Only therapist responds | Sensitive situations |

---

## 6. Safety Configuration

### Danger Detection

**Enable in section configuration:**

| Field | Value |
|-------|-------|
| **enable_danger_detection** | `1` (enabled) |

### Danger Keywords

Configure trigger keywords in `danger_keywords` field:

```
suicide,selbstmord,kill myself,mich umbringen,self-harm,selbstverletzung,harm myself,mir schaden,end my life,mein leben beenden,overdose,überdosis
```

**Guidelines for keyword selection:**
- Include multiple languages your patients use
- Include common phrases, not just single words
- Test for false positives with common expressions
- Review and update periodically

### Danger Response Message

Customize the `danger_blocked_message`:

```
I noticed some concerning content in your message. While I want to help, please consider reaching out to a trusted person or crisis hotline. Your well-being is important.

Emergency resources:
- National Suicide Prevention Lifeline: 988 (US)
- Crisis Text Line: Text HOME to 741741
```

### Crisis Escalation Flow

```
1. Message contains danger keyword
         ↓
2. Alert created (EMERGENCY severity)
         ↓
3. Conversation risk level → CRITICAL
         ↓
4. Email sent to configured addresses
         ↓
5. AI responds with supportive message
         ↓
6. Therapist reviews and intervenes
```

---

## 7. UI Customization

### Patient Chat Labels

Configure in `therapyChat` style fields:

| Field | Default | Description |
|-------|---------|-------------|
| `therapy_ai_label` | "AI Assistant" | Label for AI messages |
| `therapy_therapist_label` | "Therapist" | Label for therapist messages |
| `therapy_tag_button_label` | "Tag Therapist" | Tag button text |
| `therapy_empty_message` | "No messages yet..." | Empty state message |
| `therapy_ai_thinking_text` | "AI is thinking..." | Loading indicator |
| `therapy_mode_indicator_ai` | "AI-assisted chat" | Mode badge for AI hybrid |
| `therapy_mode_indicator_human` | "Therapist-only mode" | Mode badge for human only |
| `submit_button_label` | "Send" | Send button text |
| `message_placeholder` | "Type your message..." | Input placeholder |

### Therapist Dashboard Labels

Configure in `therapistDashboard` style fields:

| Field | Default |
|-------|---------|
| `title` | "Therapist Dashboard" |
| `dashboard_conversations_heading` | "Patient Conversations" |
| `dashboard_alerts_heading` | "Alerts" |
| `dashboard_notes_heading` | "Clinical Notes" |
| `dashboard_no_conversations` | "No patient conversations found." |
| `dashboard_send_placeholder` | "Type your response..." |
| `dashboard_send_button` | "Send Response" |
| `dashboard_risk_low` | "Low" |
| `dashboard_risk_medium` | "Medium" |
| `dashboard_risk_high` | "High" |
| `dashboard_risk_critical` | "Critical" |
| `dashboard_disable_ai` | "Pause AI" |
| `dashboard_enable_ai` | "Resume AI" |

### Multi-Language Support

All label fields support SelfHelp's translation system:
1. Configure base labels in default language
2. Add translations for each supported language
3. System automatically selects based on user preference

---

## 8. Email Notifications

### Configure Email Recipients

In `danger_notification_emails` field, enter addresses separated by newlines or semicolons:

```
therapist1@clinic.com
therapist2@clinic.com
admin@clinic.com
```

### Email Requirements

1. **SelfHelp job scheduler must be configured** - Emails sent via queue
2. **Valid email configuration** - SMTP settings in SelfHelp config
3. **Valid email addresses** - Verified recipients

### Email Template

The system sends emails with:
- **Subject**: `[URGENT] Therapy Chat Alert: {alert_type}`
- **Body**: Alert details, patient info, conversation link

### Testing Email Notifications

1. Create a test patient and therapist
2. Send a message with a danger keyword
3. Verify:
   - Alert created in database
   - Email delivered to configured addresses
   - Email content is correct

---

## 9. Access Control

### Permission Matrix

| Role | Patient Chat | Therapist Dashboard | Configuration |
|------|--------------|---------------------|---------------|
| Patient | ✅ View/Send | ❌ No access | ❌ No access |
| Therapist | ❌ No access | ✅ Full access | ❌ No access |
| Admin | ✅ Full access | ✅ Full access | ✅ Full access |

### How Access Control Works

1. **Group membership** - Users must be in configured groups
2. **ACL permissions** - Page-level access via `acl_groups` table
3. **Conversation-level** - Therapists see only their patients' conversations

### Therapist Multi-Group Support

Therapists can be assigned to multiple patient groups:
- Each group represents a set of patients
- Therapist sees all conversations from their groups
- Useful for team coverage or specialty areas

### Adding a Therapist to Multiple Groups

```sql
-- Add therapist to additional patient group
INSERT INTO users_groups (id_users, id_groups)
VALUES ({therapist_id}, {patient_group_id});
```

---

## 10. Testing the Installation

### Test Checklist

- [ ] Module configuration page accessible
- [ ] Patient chat page loads
- [ ] Therapist dashboard loads
- [ ] Patient can send message
- [ ] AI responds to patient
- [ ] Therapist sees conversation
- [ ] Therapist can send response
- [ ] Patient can tag therapist
- [ ] Alert created on tag
- [ ] Danger detection triggers alert
- [ ] Email sent on danger detection
- [ ] Floating icon appears for patients
- [ ] Risk level can be changed
- [ ] AI can be toggled on/off
- [ ] Clinical notes can be added

### Test Users

Create test users:

1. **Test Patient**
   - Assign to patient group
   - Login and access `/therapy-chat/subject`

2. **Test Therapist**
   - Assign to therapist group
   - Login and access `/therapy-chat/therapist`

### Test Scenarios

**Scenario 1: Basic Chat Flow**
1. Patient sends: "Hello, I'm feeling anxious today"
2. Verify AI responds
3. Therapist sees conversation in dashboard
4. Therapist sends response
5. Patient sees therapist message

**Scenario 2: Tag Flow**
1. Patient clicks "Tag Therapist"
2. Selects "I need to talk soon"
3. Verify alert created
4. Therapist acknowledges tag
5. Verify acknowledgment recorded

**Scenario 3: Danger Detection**
1. Patient sends message with danger keyword
2. Verify alert created with EMERGENCY severity
3. Verify conversation risk level = CRITICAL
4. Verify email sent
5. Verify AI supportive response

---

## 11. Maintenance

### Regular Tasks

| Task | Frequency | Description |
|------|-----------|-------------|
| Review danger keywords | Monthly | Update based on new patterns |
| Check alert volume | Weekly | Monitor for false positives |
| Review email delivery | Weekly | Ensure notifications working |
| Update system prompt | As needed | Improve AI responses |

### Database Maintenance

```sql
-- Check conversation counts
SELECT COUNT(*) as total,
       SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
       SUM(CASE WHEN risk_level = 'critical' THEN 1 ELSE 0 END) as critical
FROM view_therapyConversations;

-- Check alert backlog
SELECT COUNT(*) as unread_alerts
FROM therapyAlerts
WHERE is_read = 0;

-- Check pending tags
SELECT COUNT(*) as pending_tags
FROM therapyTags
WHERE acknowledged = 0;
```

### Log Monitoring

Check SelfHelp logs for:
- API errors
- Failed email deliveries
- Database errors
- Authentication failures

### Backup Considerations

Ensure backups include:
- `therapyConversationMeta`
- `therapyTags`
- `therapyAlerts`
- `therapyNotes`
- Related `llmConversations` and `llmMessages` data

---

## 12. Troubleshooting

### Common Issues

#### "sh-shp-llm plugin must be installed first"

**Cause**: LLM plugin not installed
**Solution**: Run LLM plugin migration first

```bash
cd server/plugins/sh-shp-llm
mysql -u username -p database < server/db/v1.0.0.sql
```

#### Patient Chat Shows "Configuration not available"

**Cause**: Section not configured or React config error
**Solutions**:
1. Check section has `therapyChat` style
2. Verify style fields are configured
3. Check browser console for JavaScript errors
4. Rebuild React: `cd gulp && gulp build`

#### Therapist Can't See Conversations

**Causes**:
1. Therapist not in correct group
2. ACL permissions not set
3. Patients not in group therapist has access to

**Solutions**:
```sql
-- Check therapist group membership
SELECT * FROM users_groups WHERE id_users = {therapist_id};

-- Check ACL permissions
SELECT * FROM acl_groups 
WHERE id_pages = (SELECT id FROM pages WHERE keyword = 'therapyChatTherapist')
AND id_groups IN (SELECT id_groups FROM users_groups WHERE id_users = {therapist_id});
```

#### AI Not Responding

**Causes**:
1. `ai_enabled` = 0 for conversation
2. Mode is `human_only`
3. LLM API not configured
4. LLM API error

**Solutions**:
```sql
-- Check conversation settings
SELECT ai_enabled, mode FROM view_therapyConversations 
WHERE id_llmConversations = {conversation_id};
```

Check LLM plugin configuration and API credentials.

#### Danger Detection Not Working

**Causes**:
1. `enable_danger_detection` = 0
2. Keywords not configured
3. Keyword not in message

**Solutions**:
1. Verify `enable_danger_detection` = 1 in style fields
2. Check `danger_keywords` field has keywords
3. Test with exact keyword match

#### Emails Not Sending

**Causes**:
1. Job scheduler not running
2. Invalid email configuration
3. No recipients configured

**Solutions**:
1. Check SelfHelp job scheduler status
2. Verify SMTP configuration
3. Check `danger_notification_emails` has valid addresses

#### Floating Icon Not Appearing

**Causes**:
1. User not in configured group
2. Page not configured in module settings
3. Hook not firing

**Solutions**:
1. Verify user is in patient or therapist group
2. Check `therapy_chat_subject_page` and `therapy_chat_therapist_page` settings
3. Check hook registration in database

### Debug Mode

Enable debug in section configuration:
```
debug: 1
```

Check:
- Browser console for JavaScript errors
- Network tab for API responses
- PHP error logs

### Getting Support

For technical issues:
1. Check this documentation
2. Review error logs
3. Check GitHub issues for similar problems
4. Contact SelfHelp support

---

## Appendix A: Database Tables Reference

### therapyConversationMeta

```sql
CREATE TABLE therapyConversationMeta (
    id INT PRIMARY KEY,
    id_llmConversations INT NOT NULL,  -- FK to llmConversations
    id_groups INT NOT NULL,            -- Access group
    id_therapist INT,                  -- Assigned therapist
    id_chatModes INT,                  -- FK to lookups
    ai_enabled TINYINT DEFAULT 1,
    id_conversationStatus INT,         -- FK to lookups
    id_riskLevels INT,                 -- FK to lookups
    therapist_last_seen TIMESTAMP,
    subject_last_seen TIMESTAMP
);
```

### therapyTags

```sql
CREATE TABLE therapyTags (
    id INT PRIMARY KEY,
    id_llmMessages INT NOT NULL,  -- FK to llmMessages
    id_users INT NOT NULL,        -- Tagged therapist
    tag_reason VARCHAR(255),
    id_tagUrgency INT,           -- FK to lookups
    acknowledged TINYINT DEFAULT 0,
    acknowledged_at TIMESTAMP
);
```

### therapyAlerts

```sql
CREATE TABLE therapyAlerts (
    id INT PRIMARY KEY,
    id_llmConversations INT NOT NULL,
    id_users INT,                -- Target therapist (NULL = all)
    id_alertTypes INT NOT NULL,  -- FK to lookups
    id_alertSeverity INT,        -- FK to lookups
    message TEXT,
    metadata JSON,
    is_read TINYINT DEFAULT 0,
    read_at TIMESTAMP
);
```

### therapyNotes

```sql
CREATE TABLE therapyNotes (
    id INT PRIMARY KEY,
    id_llmConversations INT NOT NULL,
    id_users INT NOT NULL,  -- Author
    content TEXT NOT NULL
);
```

---

## Appendix B: Lookup Values

### Chat Modes (therapyChatModes)

| Code | Value | Description |
|------|-------|-------------|
| `ai_hybrid` | AI Hybrid | AI responds with therapist oversight |
| `human_only` | Human Only | Only therapist responds |

### Conversation Status (therapyConversationStatus)

| Code | Value |
|------|-------|
| `active` | Active |
| `paused` | Paused |
| `closed` | Closed |

### Risk Levels (therapyRiskLevels)

| Code | Value |
|------|-------|
| `low` | Low |
| `medium` | Medium |
| `high` | High |
| `critical` | Critical |

### Tag Urgency (therapyTagUrgency)

| Code | Value |
|------|-------|
| `normal` | Normal |
| `urgent` | Urgent |
| `emergency` | Emergency |

### Alert Types (therapyAlertTypes)

| Code | Value |
|------|-------|
| `danger_detected` | Danger Detected |
| `tag_received` | Tag Received |
| `high_activity` | High Activity |
| `inactivity` | Inactivity |
| `new_message` | New Message |

### Alert Severity (therapyAlertSeverity)

| Code | Value |
|------|-------|
| `info` | Info |
| `warning` | Warning |
| `critical` | Critical |
| `emergency` | Emergency |
