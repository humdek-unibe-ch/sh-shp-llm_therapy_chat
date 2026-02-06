# Feature Roadmap

## v1.0.0 (Current)

### Implemented

- [x] Patient chat with AI + therapist messaging
- [x] Therapist dashboard with patient list
- [x] Group-based access control (therapyTherapistAssignments)
- [x] Group tab filtering on dashboard
- [x] Per-patient and per-group unread message tracking
- [x] Message sender type distinction (AI / therapist / patient / system)
- [x] @mention tagging system (creates alerts)
- [x] Danger word detection
- [x] AI draft generation and editing workflow
- [x] Message editing (therapist)
- [x] Message soft-deletion (therapist)
- [x] Clinical notes per conversation (with inline edit, soft-delete, and audit trail)
- [x] Risk level management
- [x] Conversation status management (active/paused/closed)
- [x] AI toggle per conversation
- [x] Global AI enable/disable per style instance
- [x] Floating chat/dashboard buttons with unread badges
- [x] Therapist group assignment via admin user page hook
- [x] Speech-to-text input
- [x] Markdown rendering for AI messages
- [x] Polling-based real-time updates
- [x] Admin setup via SelfHelp page configuration

### Not Yet Implemented

- [ ] Real-time WebSocket updates (currently polling)
- [ ] File/image attachments in messages
- [ ] AI conversation summary generation
- [ ] Therapist-to-therapist messaging / handoff
- [ ] Scheduled message sending
- [ ] Patient self-assessment questionnaires
- [ ] Integration with external EHR systems
- [ ] Message search / full-text search
- [ ] Export conversation as PDF
- [ ] Multi-language AI system prompts
- [ ] Conversation archiving workflow
- [ ] Audit logging UI for message edits/deletes (transaction logging already implemented for notes, risk, status, AI toggle)

## Future Versions

### v1.1.0 (Planned)
- WebSocket support for real-time messaging
- Conversation summary AI generation
- Message search

### v1.2.0 (Planned)
- File attachments
- PDF export
- Audit trail UI

### v2.0.0 (Planned)
- Multi-therapist collaboration features
- Patient self-assessment integration
- External EHR integration hooks
