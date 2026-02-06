# Admin Setup Guide

## Prerequisites

1. **SelfHelp** platform installed and running
2. **sh-shp-llm** plugin installed (provides base LLM functionality)
3. PHP 8.2+ with `uopz` extension (for hooks)
4. MySQL 8.0+

## Installation Steps

### 1. Deploy Plugin Files

Copy the `sh-shp-llm_therapy_chat` folder to `server/plugins/`.

### 2. Run Database Migration

```bash
mysql -u <user> -p <database> < server/plugins/sh-shp-llm_therapy_chat/server/db/v1.0.0.sql
```

This creates all required tables, views, lookups, hooks, and configuration fields. All schema changes are consolidated in this single migration file.

### 3. Build React Frontend

```bash
cd server/plugins/sh-shp-llm_therapy_chat/react
npm install
npm run build
```

This generates `js/ext/therapy-chat.umd.js` and `css/ext/therapy-chat.css`.

### 4. Configure Module Page

1. Go to SelfHelp admin → **Pages**
2. Create a new page with page type **sh_module_llm_therapy_chat**
3. Configure the module fields:
   - **Subject Group**: Select the group containing your patients
   - **Therapist Group**: Select the group containing your therapists
   - **Subject Page**: The page where the patient chat component lives
   - **Therapist Page**: The page where the therapist dashboard component lives
   - **Danger Words**: Comma-separated keywords that trigger safety alerts
   - **Floating Position**: Where the floating button appears (e.g., `bottom-right`)

### 5. Create Chat Pages

#### Patient Chat Page
1. Create a new page accessible to subjects
2. Add a section with style **therapyChat**
3. Configure style fields (AI model, polling interval, etc.)

#### Therapist Dashboard Page
1. Create a new page accessible to therapists
2. Add a section with style **therapistDashboard**
3. Configure style fields:
   - **LLM Model**: Select the AI model for draft generation and summarization
   - **LLM Temperature**: Set the temperature for AI responses (0.0-2.0, default 0.7)
   - **LLM Max Tokens**: Maximum tokens for AI responses (default 2048)
   - **Conversation Context**: System context for AI draft generation
   - **Summary Context**: Additional context/instructions for conversation summarization

### 6. Set Up Groups

1. Create a **patient/subject group** (e.g., "subjects")
2. Create a **therapist group** (e.g., "therapists")
3. Add users to appropriate groups

### 7. Assign Therapists to Patient Groups

1. Go to admin → **Users** → select a therapist user
2. Scroll to the **"Therapy Chat - Patient Group Monitoring"** card
3. Check the patient groups this therapist should monitor
4. Click **Save Assignments**

## Verification Checklist

- [ ] Plugin tables exist: `therapyConversationMeta`, `therapyTherapistAssignments`, etc.
- [ ] Module page type `sh_module_llm_therapy_chat` is registered
- [ ] Style types `therapyChat` and `therapistDashboard` are registered
- [ ] Hooks are registered in the `hooks` table
- [ ] JS/CSS build files exist in `js/ext/` and `css/ext/`
- [ ] Subject users see a floating chat button
- [ ] Therapist users see a floating dashboard button
- [ ] Therapist assignment card appears on user admin pages

## Troubleshooting

### Floating button not showing
- Check that the user is in the correct group (subject or therapist)
- Verify the module page type is configured with correct group IDs
- Check browser console for JavaScript errors

### Therapist sees no conversations
- Verify the therapist has group assignments in `therapyTherapistAssignments`
- Check that patients are in the assigned groups via `users_groups`
- Verify patients have active conversations

### AI not responding
- Check that `therapy_enable_ai` is enabled on the style
- Verify the LLM model is configured correctly in the base sh-shp-llm plugin
- Check PHP error logs for API call failures

### Hooks not firing
- Verify the `uopz` PHP extension is installed and enabled
- Check the `hooks` table for correct registrations
- Clear APCu cache: hooks are cached
