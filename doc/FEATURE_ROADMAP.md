# Feature Roadmap - LLM Therapy Chat Plugin

This document tracks the implementation status of features from the original requirements and identifies items for future development.

---

## Implementation Status Summary

| Category | Implemented | Partial | Missing |
|----------|-------------|---------|---------|
| Patient Experience | 5 | 1 | 0 |
| Therapist Dashboard | 9 | 2 | 2 |
| AI Control | 4 | 1 | 1 |
| Smart Alerts | 4 | 1 | 2 |
| Security & Privacy | 6 | 0 | 2 |
| **Total** | **28** | **5** | **7** |

---

## ✅ Fully Implemented Features

### Patient Experience

| Feature | Status | Notes |
|---------|--------|-------|
| AI Chat Buddy (24/7) | ✅ | Empathy, validation, grounding, reflection |
| Tag Therapist (@mention) | ✅ | With predefined reasons |
| Predefined Tag Reasons | ✅ | Configurable via JSON |
| Continuous Conversation History | ✅ | Stored in llmMessages |
| Clear Message Labeling | ✅ | AI vs Therapist clearly distinguished |
| Gentle Reminders | ✅ | Clinical boundary disclaimers |

### Therapist Dashboard

| Feature | Status | Notes |
|---------|--------|-------|
| Real-time Dashboard | ✅ | Polling-based updates |
| Conversation List with Filtering | ✅ | Risk, status, activity filters |
| Risk Level Indicators | ✅ | Low/Medium/High/Critical |
| Tag Acknowledgment | ✅ | With timestamp |
| Private Notes System | ✅ | Not visible to patients |
| AI Enable/Disable Toggle | ✅ | Per conversation |
| Full Conversation History | ✅ | Via LLM Admin Console |
| One-Click Intervention | ✅ | Send message, join conversation |
| Statistics Overview | ✅ | Total, active, critical, alerts, tags |

### AI Control

| Feature | Status | Notes |
|---------|--------|-------|
| AI Hybrid Mode | ✅ | AI responds, therapist can intervene |
| Human Only Mode | ✅ | Only therapist responds |
| Per-Conversation AI Toggle | ✅ | Enable/disable AI |
| Configurable System Prompt | ✅ | Custom AI instructions |

### Smart Alerts

| Feature | Status | Notes |
|---------|--------|-------|
| Danger Detection Alerts | ✅ | Keyword-based, emergency severity |
| Tag Received Alerts | ✅ | With urgency levels |
| In-App Notifications | ✅ | Dashboard indicator |
| Alert Read/Acknowledge | ✅ | Tracked with timestamp |

### Security & Privacy

| Feature | Status | Notes |
|---------|--------|-------|
| Group-Based Access Control | ✅ | Therapists see only their patients |
| Conversation-Level Permissions | ✅ | Per-conversation access |
| Audit Trail | ✅ | Transaction logging |
| Input Sanitization | ✅ | All user inputs |
| SQL Injection Prevention | ✅ | Parameterized queries |
| XSS Protection | ✅ | Output escaping |

---

## 🟡 Partially Implemented Features

### Therapist Dashboard

| Feature | Status | Notes | TODO |
|---------|--------|-------|------|
| Conversation Summaries | 🟡 | Basic stats available | Add AI-generated daily/weekly summaries |
| Mood Trend Tracking | 🟡 | Risk level tracked | Add historical mood analysis |

### AI Control

| Feature | Status | Notes | TODO |
|---------|--------|-------|------|
| Topics to Avoid | 🟡 | Via system prompt | Add explicit topic blocking |

### Smart Alerts

| Feature | Status | Notes | TODO |
|---------|--------|-------|------|
| High Activity Alerts | 🟡 | Alert type exists | Implement detection logic |

### Security & Privacy

| Feature | Status | Notes | TODO |
|---------|--------|-------|------|
| End-to-End Encryption | 🟡 | In-transit (HTTPS) | Add at-rest encryption |

---

## ❌ Missing Features (Future Development)

### High Priority

| Feature | Priority | Description | Complexity |
|---------|----------|-------------|------------|
| **WebSocket Support** | High | Real-time updates without polling | High |
| **Email Notifications (Configurable)** | High | Configurable notification rules, not just danger | Medium |
| **Inactivity Alerts** | High | Alert when patient hasn't messaged in X days | Medium |

### Medium Priority

| Feature | Priority | Description | Complexity |
|---------|----------|-------------|------------|
| **Automatic Therapist Assignment** | Medium | Assign based on availability/load | Medium |
| **AI-Generated Summaries** | Medium | Daily/weekly conversation summaries | High |
| **Office Hours Configuration** | Medium | Define when therapist is available | Medium |
| **After-Hours Behavior** | Medium | What triggers alerts outside office hours | Medium |

### Lower Priority

| Feature | Priority | Description | Complexity |
|---------|----------|-------------|------------|
| **Multi-Therapist Conversations** | Low | Multiple therapists per patient | High |
| **Group Communication** | Low | Send messages to patient groups | Medium |
| **Mobile App Integration** | Low | Native mobile app support | High |
| **Export for Clinical Notes** | Low | Export summaries to EHR | Medium |
| **Topic Blocking (Explicit)** | Low | Block specific topics, not just keywords | Medium |

---

## TODO List for Development

### Phase 1: Essential Improvements (Recommended)

```
TODO-001: Implement WebSocket support for real-time updates
Priority: High
Description: Replace polling with WebSocket connections for instant message delivery
Files: react/src/hooks/usePolling.ts, server/service/TherapyMessageService.php
Estimated effort: 2-3 days

TODO-002: Add configurable email notification rules
Priority: High  
Description: Allow therapists to configure which events trigger email notifications
Files: server/service/TherapyAlertService.php, database migration
Estimated effort: 1-2 days

TODO-003: Implement inactivity alert detection
Priority: High
Description: Create scheduled job to detect patient inactivity and create alerts
Files: server/cronjobs/InactivityCheck.php (new), database migration
Estimated effort: 1 day

TODO-004: Add high activity alert detection
Priority: Medium
Description: Detect unusual message frequency and create alerts
Files: server/service/TherapyAlertService.php
Estimated effort: 1 day
```

### Phase 2: Enhanced Functionality

```
TODO-005: Automatic therapist assignment
Priority: Medium
Description: Assign therapists based on availability, specialty, or load balancing
Files: server/service/TherapyChatService.php
Estimated effort: 2-3 days

TODO-006: AI-generated conversation summaries
Priority: Medium
Description: Generate daily/weekly summaries using LLM
Files: server/service/TherapySummaryService.php (new)
Estimated effort: 3-5 days

TODO-007: Office hours configuration
Priority: Medium
Description: Define therapist office hours in configuration
Files: database migration, TherapyChatHooks.php
Estimated effort: 1-2 days

TODO-008: After-hours behavior settings
Priority: Medium
Description: Configure what triggers alerts outside office hours
Files: server/service/TherapyAlertService.php
Estimated effort: 1 day
```

### Phase 3: Advanced Features

```
TODO-009: Multi-therapist conversation support
Priority: Low
Description: Allow multiple therapists to participate in a conversation
Files: Major refactoring of therapyConversationMeta
Estimated effort: 5+ days

TODO-010: Group communication
Priority: Low
Description: Send announcements to patient groups
Files: New service and UI components
Estimated effort: 3-5 days

TODO-011: Mobile app integration
Priority: Low
Description: React Native app or enhanced mobile web experience
Files: New project
Estimated effort: Weeks

TODO-012: Clinical notes export
Priority: Low
Description: Export summaries in HL7 FHIR or other EHR formats
Files: server/service/TherapyExportService.php (new)
Estimated effort: 2-3 days
```

---

## Feature Comparison with Requirements

### Original Requirements Checklist

#### For Patients
- [x] AI Chat Buddy (24/7) with empathy, validation, grounding
- [x] Tag therapist with @mention
- [x] Predefined tag reasons (overwhelmed, need to talk, urgent)
- [x] Continuous, context-aware support
- [x] Encrypted, secure conversations
- [x] Automatic safety monitoring
- [x] Clear labeling of AI vs. therapist messages

#### For Therapists
- [x] Configure topics AI should avoid (via system prompt)
- [x] AI tone configuration (via system prompt)
- [ ] Maximum depth of AI support before suggesting therapist contact
- [ ] Crisis sensitivity thresholds (partially via danger keywords)
- [x] Pause AI responses in specific conversation
- [x] Switch to therapist-only mode
- [x] Resume AI support at any time

#### Dashboard
- [x] List of all active patient conversations
- [x] Filters by activity level, risk level, tags received
- [x] Safety alerts indicator
- [x] Invisible monitoring (observe without patient knowing)
- [x] Full conversation history
- [ ] Long-term tracking of patient patterns and progress (partial)

#### Smart Notifications
- [x] Alerts when patient tags therapist
- [x] Alerts when risky/concerning content detected
- [x] Risk level indicator (Low/Medium/High/Critical)
- [ ] Recommended next action (not implemented)
- [x] In-app alerts
- [x] Email (for critical alerts)

#### Crisis Handling
- [x] AI detects concerning language
- [x] AI responds with supportive language
- [x] Therapist alerted immediately
- [x] Emergency escalation protocol
- [x] Therapist can override and intervene
- [x] All actions logged with audit trails

#### Intervention
- [x] One-click to join conversation
- [x] Messages appear under therapist name
- [x] AI performs seamless handoff
- [ ] Group communication to multiple patients

#### Summaries & Insights
- [ ] Daily/weekly conversation summaries
- [ ] Mood trends analysis
- [ ] Repeated themes identification
- [ ] Escalation frequency tracking
- [ ] Positive progress indicators
- [ ] AI intervention limits reached tracking
- [ ] Export for clinical notes

#### Workflow
- [x] Morning check-in workflow supported
- [x] Daytime monitoring
- [x] Evening review (via dashboard)

#### Workload Protection
- [ ] Office hours definition
- [ ] After-hours behavior configuration
- [ ] Event-based alert routing (immediate vs. next-day)

#### Privacy & Security
- [x] End-to-end encryption (in-transit)
- [x] Full audit trails for AI responses, therapist interventions, crisis escalations
- [x] Invisible monitoring (patients don't know when observed)
- [ ] Enterprise-grade data protection (dependent on deployment)

---

## Implementation Notes

### For WebSocket Support (TODO-001)

Consider using:
- Ratchet PHP WebSocket server
- Socket.io compatible protocol
- Fallback to polling for environments without WebSocket

### For AI Summaries (TODO-006)

Prompt template for summaries:
```
Analyze the following conversation and provide:
1. Mood/emotional state trends
2. Main themes discussed
3. Risk indicators
4. Progress indicators
5. Recommended follow-up topics

Conversation:
{messages}
```

### For Office Hours (TODO-007)

Database schema:
```sql
CREATE TABLE therapistOfficeHours (
    id INT PRIMARY KEY,
    id_users INT NOT NULL,
    day_of_week TINYINT, -- 0-6
    start_time TIME,
    end_time TIME,
    timezone VARCHAR(50)
);
```

---

## Contributing

When implementing a TODO:
1. Create a feature branch
2. Update this document with status change
3. Add unit tests
4. Update documentation
5. Submit PR with reference to TODO number

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2026-01-22 | Initial feature roadmap |
