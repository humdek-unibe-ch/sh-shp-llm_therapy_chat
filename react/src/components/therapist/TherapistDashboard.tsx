/**
 * TherapistDashboard Component
 * ==============================
 *
 * Full therapist monitoring dashboard with:
 *   - Group tabs (patients separated by assigned groups)
 *   - Patient list with unread counts + message totals
 *   - Full conversation viewer with AI / therapist / patient distinction
 *   - Message seen/unseen indicators
 *   - Message edit & soft-delete
 *   - AI draft generation
 *   - Clinical notes sidebar
 *   - Alert banner
 *   - Risk & status controls
 *   - URL state: ?gid=...&uid=... persisted in the address bar
 *
 * Bootstrap 4.6 classes + minimal custom CSS.
 */

import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { MessageList } from '../shared/MessageList';
import { MessageInput } from '../shared/MessageInput';
import { MarkdownRenderer } from '../shared/MarkdownRenderer';
import { LoadingIndicator } from '../shared/LoadingIndicator';
import { useChatState } from '../../hooks/useChatState';
import { usePolling } from '../../hooks/usePolling';
import { createTherapistApi } from '../../utils/api';
import { updateFloatingBadge } from '../../utils/floatingBadge';
import { StatsHeader } from './StatsHeader';
import { AlertBanner } from './AlertBanner';
import { GroupTabs } from './GroupTabs';
import { PatientList } from './PatientList';
import type { FilterType } from './PatientList';
import { ConversationHeader } from './ConversationHeader';
import { NotesPanel } from './NotesPanel';
import { DraftEditorModal } from './DraftEditor';
import type {
  TherapistDashboardConfig,
  Conversation,
  Alert as AlertT,
  Note,
  RiskLevel,
  ConversationStatus,
  UnreadCounts,
  TherapistGroup,
  Draft,
} from '../../types';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface Props {
  config: TherapistDashboardConfig;
}

// ---------------------------------------------------------------------------
// URL helpers – read/write ?gid=...&uid=... without full page navigation
// ---------------------------------------------------------------------------

function readUrlState(): { gid: number | null; uid: number | string | null } {
  const sp = new URLSearchParams(window.location.search);
  const gidStr = sp.get('gid');
  const uidStr = sp.get('uid');
  return {
    gid: gidStr != null ? Number(gidStr) : null,
    uid: uidStr != null ? (isNaN(Number(uidStr)) ? uidStr : Number(uidStr)) : null,
  };
}

function pushUrlState(gid: number | null, uid: number | string | null) {
  const sp = new URLSearchParams(window.location.search);
  if (gid != null) sp.set('gid', String(gid)); else sp.delete('gid');
  if (uid != null) sp.set('uid', String(uid)); else sp.delete('uid');
  // Keep existing params like section_id
  const newUrl = `${window.location.pathname}?${sp.toString()}${window.location.hash}`;
  window.history.replaceState(null, '', newUrl);
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export const TherapistDashboard: React.FC<Props> = ({ config }) => {
  const api = useMemo(() => createTherapistApi(config.sectionId), [config.sectionId]);
  const { features, labels } = config;
  const initRef = useRef(false);

  // ---- URL-seeded state ----
  const urlState = useMemo(() => readUrlState(), []);

  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [selectedId, setSelectedId] = useState<number | string | null>(urlState.uid);
  const [alerts, setAlerts] = useState<AlertT[]>([]);
  const [notes, setNotes] = useState<Note[]>([]);
  const [newNote, setNewNote] = useState('');
  const [unreadCounts, setUnreadCounts] = useState<UnreadCounts>({ total: 0, totalAlerts: 0, bySubject: {} });
  const [groups, setGroups] = useState<TherapistGroup[]>(config.groups || config.assignedGroups || []);
  const [activeGroupId, setActiveGroupId] = useState<number | null>(config.selectedGroupId ?? urlState.gid);
  const [activeFilter, setActiveFilter] = useState<FilterType>('all');
  const [listLoading, setListLoading] = useState(true);
  const [listError, setListError] = useState<string | null>(null);
  const [activeDraft, setActiveDraft] = useState<Draft | null>(null);
  const [draftText, setDraftText] = useState('');
  const [draftModalOpen, setDraftModalOpen] = useState(false);
  const [draftGenerating, setDraftGenerating] = useState(false);
  const [draftError, setDraftError] = useState<string | null>(null);
  /** Stores the previous draft text before a regeneration so the user can undo */
  const [draftUndoStack, setDraftUndoStack] = useState<string[]>([]);

  // ---- Summarization state ----
  const [summaryModalOpen, setSummaryModalOpen] = useState(false);
  const [summaryGenerating, setSummaryGenerating] = useState(false);
  const [summaryText, setSummaryText] = useState('');
  const [summaryError, setSummaryError] = useState<string | null>(null);

  // Note editing state
  const [editingNoteId, setEditingNoteId] = useState<number | null>(null);
  const [editingNoteText, setEditingNoteText] = useState('');

  // Refs for values used inside stable callbacks
  const activeGroupIdRef = useRef(activeGroupId);
  const activeFilterRef = useRef(activeFilter);
  const selectedIdRef = useRef(selectedId);
  useEffect(() => { activeGroupIdRef.current = activeGroupId; }, [activeGroupId]);
  useEffect(() => { activeFilterRef.current = activeFilter; }, [activeFilter]);
  useEffect(() => { selectedIdRef.current = selectedId; }, [selectedId]);

  // Chat state for selected conversation
  const chat = useChatState({
    loadFn: (convId) => api.getConversation(convId as number | string),
    sendFn: (convId, msg) => api.sendMessage(convId, msg),
    pollFn: (convId, afterId) => api.getMessages(convId, afterId),
    senderType: 'therapist',
  });

  // ---- Data loading (stable callbacks) ----

  /**
   * Load conversations. When `silent` is true (polling), the loading
   * spinner is NOT shown to avoid UI flashing on the selected user.
   */
  const loadConversations = useCallback(
    async (groupId?: number | null, filter?: FilterType, silent = false) => {
      if (!silent) {
        setListLoading(true);
        setListError(null);
      }
      try {
        const filters: Record<string, string | number> = {};
        if (groupId != null) filters.group_id = groupId;
        if (filter && filter !== 'all') filters.filter = filter;
        const res = await api.getConversations(filters);
        setConversations(res.conversations || []);
      } catch (err) {
        if (!silent) setListError(err instanceof Error ? err.message : 'Failed to load conversations');
      } finally {
        if (!silent) setListLoading(false);
      }
    },
    [api],
  );

  const loadAlerts = useCallback(async () => {
    try {
      const res = await api.getAlerts(true);
      setAlerts(res.alerts || []);
    } catch (err) {
      console.error('Load alerts error:', err);
    }
  }, [api]);

  const loadUnreadCounts = useCallback(async () => {
    try {
      const res = await api.getUnreadCounts();
      const uc = res?.unread_counts;
      const total = (uc?.total ?? 0) + (uc?.totalAlerts ?? 0);
      setUnreadCounts({
        total: uc?.total ?? 0,
        totalAlerts: uc?.totalAlerts ?? 0,
        bySubject: uc?.bySubject ?? {},
        byGroup: uc?.byGroup ?? {},
      });
      // Sync the floating icon badge (server-rendered) with live data
      updateFloatingBadge(total);
    } catch (err) {
      console.error('Unread counts error:', err);
    }
  }, [api]);

  const loadNotes = useCallback(
    async (convId: number | string) => {
      try {
        const res = await api.getNotes(convId);
        setNotes(res.notes || []);
      } catch (err) {
        console.error('Notes error:', err);
      }
    },
    [api],
  );

  const loadGroups = useCallback(async () => {
    try {
      const res = await api.getGroups();
      setGroups(res.groups || []);
    } catch (err) {
      console.error('Groups error:', err);
    }
  }, [api]);

  // ---- Initial load (once) ----

  useEffect(() => {
    if (initRef.current) return;
    initRef.current = true;

    loadConversations(activeGroupId, activeFilter);
    loadAlerts();
    loadUnreadCounts();
    loadGroups();

    // If URL has a selected conversation, load it
    if (urlState.uid) {
      chat.loadConversation(urlState.uid);
      loadNotes(urlState.uid);
    }
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  // ---- Lightweight polling ----
  // Phase 1: quick check_updates call (tiny payload)
  // Phase 2: only if something changed, do full fetches

  const lastKnownMsgIdRef = useRef<number | null>(null);
  const lastKnownUnreadRef = useRef<number>(0);
  const lastKnownAlertsRef = useRef<number>(0);

  const pollingCb = useCallback(async () => {
    try {
      const updates = await api.checkUpdates();

      const msgChanged = updates.latest_message_id !== lastKnownMsgIdRef.current;
      const unreadChanged = updates.unread_messages !== lastKnownUnreadRef.current;
      const alertsChanged = updates.unread_alerts !== lastKnownAlertsRef.current;

      lastKnownMsgIdRef.current = updates.latest_message_id;
      lastKnownUnreadRef.current = updates.unread_messages;
      lastKnownAlertsRef.current = updates.unread_alerts;

      // Only do heavy fetches when something actually changed
      if (msgChanged || unreadChanged) {
        await loadConversations(activeGroupIdRef.current, activeFilterRef.current, true);
        // If a conversation is selected, poll messages (backend marks them as read)
        if (selectedIdRef.current) await chat.pollMessages();
        // Refresh unread counts AFTER polling (which marks messages as seen)
        await loadUnreadCounts();
      }

      if (alertsChanged) {
        await loadAlerts();
      }
    } catch {
      // Polling errors are non-fatal
    }
  }, [api, loadConversations, loadAlerts, loadUnreadCounts, chat.pollMessages]);

  usePolling({
    callback: pollingCb,
    interval: config.pollingInterval,
    enabled: true,
  });

  // ---- Conversation initialization (for patients without conversations) ----

  const [initializingPatientId, setInitializingPatientId] = useState<number | null>(null);

  const handleInitializeConversation = useCallback(
    async (patientId: number, patientName?: string) => {
      setInitializingPatientId(patientId);
      try {
        const res = await api.initializeConversation(patientId);
        if (res.success && res.conversation) {
          // Refresh the conversations list
          await loadConversations(activeGroupIdRef.current, activeFilterRef.current, false);
          // Select the new conversation
          if (res.conversation.id) {
            setSelectedId(res.conversation.id);
            pushUrlState(activeGroupIdRef.current, res.conversation.id);
            chat.loadConversation(res.conversation.id);
            loadNotes(res.conversation.id);
          }
        }
      } catch (err) {
        console.error('Initialize conversation error:', err);
      } finally {
        setInitializingPatientId(null);
      }
    },
    [api, loadConversations, chat.loadConversation, loadNotes],
  );

  // ---- Conversation selection ----

  const selectConversation = useCallback(
    async (convId: number | string) => {
      setSelectedId(convId);
      pushUrlState(activeGroupIdRef.current, convId);
      chat.loadConversation(convId);
      loadNotes(convId);
      setDraftModalOpen(false);
      setActiveDraft(null);
      setDraftText('');
      setDraftError(null);
      setSummaryModalOpen(false);
      setSummaryText('');
      // Mark messages AND alerts as read for this conversation
      try {
        await api.markMessagesRead(convId);
        await api.markAllAlertsRead(convId);
        loadUnreadCounts();
        loadAlerts();
      } catch { /* ignore */ }
    },
    [api, chat.loadConversation, loadNotes, loadUnreadCounts, loadAlerts],
  );

  // ---- Group tab switch ----

  const switchGroup = useCallback(
    (groupId: number | null) => {
      setActiveGroupId(groupId);
      setSelectedId(null);
      pushUrlState(groupId, null);
      loadConversations(groupId, activeFilterRef.current);
    },
    [loadConversations],
  );

  // ---- Filter switch ----

  const switchFilter = useCallback(
    (f: FilterType) => {
      setActiveFilter(f);
      loadConversations(activeGroupIdRef.current, f);
    },
    [loadConversations],
  );

  // ---- Actions ----

  const handleToggleAI = useCallback(async () => {
    if (!chat.conversation?.id) return;
    const newVal = !chat.conversation.ai_enabled;
    await api.toggleAI(chat.conversation.id, newVal);
    chat.loadConversation(chat.conversation.id);
  }, [api, chat.conversation?.id, chat.conversation?.ai_enabled, chat.loadConversation]);

  const handleSetRisk = useCallback(
    async (risk: RiskLevel) => {
      if (!chat.conversation?.id) return;
      await api.setRiskLevel(chat.conversation.id, risk);
      // Immediately update local state so the UI reflects the change
      chat.setConversation((prev) => prev ? { ...prev, risk_level: risk } : prev);
      setConversations((prev) =>
        prev.map((c) => String(c.id) === String(chat.conversation!.id) ? { ...c, risk_level: risk } : c)
      );
    },
    [api, chat.conversation?.id, chat.setConversation],
  );

  const handleSetStatus = useCallback(
    async (status: ConversationStatus) => {
      if (!chat.conversation?.id) return;
      await api.setStatus(chat.conversation.id, status);
      chat.setConversation((prev) => prev ? { ...prev, status } : prev);
      setConversations((prev) =>
        prev.map((c) => String(c.id) === String(chat.conversation!.id) ? { ...c, status } : c)
      );
    },
    [api, chat.conversation?.id, chat.setConversation],
  );

  const handleAddNote = useCallback(async () => {
    if (!chat.conversation?.id || !newNote.trim()) return;
    await api.addNote(chat.conversation.id, newNote.trim());
    setNewNote('');
    loadNotes(chat.conversation.id);
  }, [api, chat.conversation?.id, newNote, loadNotes]);

  const handleEditNote = useCallback(async () => {
    if (!editingNoteId || !editingNoteText.trim() || !chat.conversation?.id) return;
    await api.editNote(editingNoteId, editingNoteText.trim());
    setEditingNoteId(null);
    setEditingNoteText('');
    loadNotes(chat.conversation.id);
  }, [api, editingNoteId, editingNoteText, chat.conversation?.id, loadNotes]);

  const handleDeleteNote = useCallback(async (noteId: number) => {
    if (!chat.conversation?.id) return;
    await api.deleteNote(noteId);
    loadNotes(chat.conversation.id);
  }, [api, chat.conversation?.id, loadNotes]);

  const handleMarkAlertRead = useCallback(
    async (alertId: number) => {
      await api.markAlertRead(alertId);
      loadAlerts();
    },
    [api, loadAlerts],
  );

  // ---- Draft actions ----

  const handleCreateDraft = useCallback(async () => {
    if (!chat.conversation?.id) return;
    setDraftGenerating(true);
    setDraftModalOpen(true);
    setDraftError(null);
    try {
      const res = await api.createDraft(chat.conversation.id);
      if (res.draft) {
        setActiveDraft(res.draft);
        const content = res.draft.edited_content || res.draft.ai_content || '';
        setDraftText(content);
        setDraftUndoStack([]);
      } else {
        setDraftError('AI did not generate a response. Please try again.');
      }
    } catch (err) {
      console.error('Create draft error:', err);
      const msg = err instanceof Error ? err.message : 'Failed to generate draft';
      setDraftError(msg);
      // Keep modal open so user can see the error
    } finally {
      setDraftGenerating(false);
    }
  }, [api, chat.conversation?.id]);

  /** Regenerate: saves current text to undo stack, creates a new draft */
  const handleRegenerateDraft = useCallback(async () => {
    if (!chat.conversation?.id) return;
    // Push current text onto undo stack before regenerating
    setDraftUndoStack((prev) => [...prev, draftText]);
    setDraftGenerating(true);
    setDraftError(null);
    try {
      // Discard old draft if it exists
      if (activeDraft) {
        try { await api.discardDraft(activeDraft.id); } catch { /* ignore */ }
      }
      const res = await api.createDraft(chat.conversation.id);
      if (res.draft) {
        setActiveDraft(res.draft);
        const content = res.draft.edited_content || res.draft.ai_content || '';
        setDraftText(content);
      } else {
        setDraftError('AI did not generate a response. Please try again.');
      }
    } catch (err) {
      console.error('Regenerate draft error:', err);
      const msg = err instanceof Error ? err.message : 'Failed to regenerate draft';
      setDraftError(msg);
    } finally {
      setDraftGenerating(false);
    }
  }, [api, chat.conversation?.id, activeDraft, draftText]);

  /** Undo: restore the last draft text from before regeneration */
  const handleUndoDraft = useCallback(() => {
    if (draftUndoStack.length === 0) return;
    const prev = draftUndoStack[draftUndoStack.length - 1];
    setDraftUndoStack((stack) => stack.slice(0, -1));
    setDraftText(prev);
  }, [draftUndoStack]);

  const handleSendDraft = useCallback(async () => {
    if (!activeDraft || !chat.conversation?.id) return;
    // Update draft text first if changed
    if (draftText !== (activeDraft.edited_content || activeDraft.ai_content)) {
      await api.updateDraft(activeDraft.id, draftText);
    }
    await api.sendDraft(activeDraft.id, chat.conversation.id);
    // Close modal and clean up
    setDraftModalOpen(false);
    setActiveDraft(null);
    setDraftText('');
    // Refresh conversation to show the sent message
    chat.loadConversation(chat.conversation.id);
    loadConversations(activeGroupIdRef.current, activeFilterRef.current, true);
  }, [api, activeDraft, draftText, chat.conversation?.id, chat.loadConversation, loadConversations]);

  const handleDiscardDraft = useCallback(async () => {
    if (!activeDraft) return;
    await api.discardDraft(activeDraft.id);
    setDraftModalOpen(false);
    setActiveDraft(null);
    setDraftText('');
  }, [api, activeDraft]);

  // ---- Summarization ----

  const handleGenerateSummary = useCallback(async () => {
    if (!chat.conversation?.id) return;
    setSummaryGenerating(true);
    setSummaryModalOpen(true);
    setSummaryText('');
    setSummaryError(null);
    try {
      const res = await api.generateSummary(chat.conversation.id);
      if (res.success && res.summary) {
        setSummaryText(res.summary);
      } else {
        setSummaryError('Failed to generate summary. Please try again.');
      }
    } catch (err) {
      console.error('Summary error:', err);
      const msg = err instanceof Error ? err.message : 'An error occurred while generating the summary.';
      setSummaryError(msg);
    } finally {
      setSummaryGenerating(false);
    }
  }, [api, chat.conversation?.id]);

  const handleSaveSummaryAsNote = useCallback(async () => {
    if (!chat.conversation?.id || !summaryText.trim()) return;
    try {
      await api.addNote(chat.conversation.id, summaryText, 'ai_summary');
      setSummaryModalOpen(false);
      setSummaryText('');
      loadNotes(chat.conversation.id);
    } catch (err) {
      console.error('Save summary as note error:', err);
    }
  }, [api, chat.conversation?.id, summaryText, loadNotes]);

  // ---- Filtered critical alerts ----
  const criticalAlerts = alerts.filter((a) => a.severity === 'critical' || a.severity === 'emergency');

  // ---- Unread count for selected conversation ----
  const selectedUnreadCount = (() => {
    if (!chat.conversation?.id_users) return 0;
    const bySubject = unreadCounts?.bySubject ?? {};
    const uid = chat.conversation.id_users;
    const uc = bySubject[uid] ?? bySubject[String(uid)] ?? null;
    return (uc as { unreadCount?: number } | undefined)?.unreadCount ?? 0;
  })();

  // ---- Render ----

  return (
    <div className="tc-dashboard container-fluid py-3">
      {/* ============ Stats Header ============ */}
      {features.showStatsHeader && (
        <StatsHeader
          title={labels.title}
          stats={config.stats}
          unreadCounts={unreadCounts}
          labels={{
            title: labels.title,
            statusActive: labels.statusActive,
            riskCritical: labels.riskCritical,
          }}
        />
      )}

      {/* ============ Alert Banner ============ */}
      {features.showAlertsPanel && criticalAlerts.length > 0 && (
        <AlertBanner
          alerts={criticalAlerts}
          onAcknowledge={handleMarkAlertRead}
          onDismissAll={async () => {
            try {
              await api.markAllAlertsRead();
              loadAlerts();
              loadUnreadCounts();
            } catch { /* ignore */ }
          }}
          labels={{ dismiss: labels.dismiss }}
        />
      )}

      {/* ============ Group Tabs ============ */}
      <GroupTabs
        groups={groups}
        selectedGroupId={activeGroupId}
        onSelectGroup={switchGroup}
        unreadByGroup={unreadCounts.byGroup}
        totalUnread={unreadCounts.total}
        labels={{ allGroupsTab: labels.allGroupsTab }}
      />

      <div className="row tc-row-min-height">
        {/* ============ Patient List Sidebar ============ */}
        <div className="col-md-4 col-lg-3 mb-3 mb-md-0">
          <PatientList
            patients={conversations}
            selectedPatientId={selectedId}
            onSelectPatient={selectConversation}
            onInitializeConversation={handleInitializeConversation}
            unreadCounts={unreadCounts}
            filter={activeFilter}
            onFilterChange={switchFilter}
            listLoading={listLoading}
            listError={listError}
            initializingPatientId={initializingPatientId}
            labels={labels}
            features={features}
          />
        </div>

        {/* ============ Conversation Area ============ */}
        <div className="col-md-8 col-lg-6 mb-3 mb-md-0">
          <div className="card border-0 shadow-sm h-100 d-flex flex-column">
            {selectedId && chat.conversation ? (
              <>
                {/* Header */}
                <ConversationHeader
                  conversation={chat.conversation}
                  unreadCount={selectedUnreadCount}
                  onMarkRead={async () => {
                    try {
                      await api.markMessagesRead(selectedId!);
                      loadUnreadCounts();
                    } catch { /* ignore */ }
                  }}
                  onToggleAI={handleToggleAI}
                  onSetStatus={handleSetStatus}
                  labels={labels}
                  features={features}
                />

                {/* Error */}
                {chat.error && (
                  <div className="alert alert-danger m-3 mb-0 alert-dismissible fade show" role="alert">
                    {chat.error}
                    <button type="button" className="close" onClick={chat.clearError}>
                      <span>&times;</span>
                    </button>
                  </div>
                )}

                {/* Messages */}
                <div className="card-body p-0 flex-grow-1 d-flex flex-column overflow-hidden">
                  <MessageList
                    messages={chat.messages}
                    isLoading={chat.isLoading}
                    isTherapistView={true}
                    emptyText={labels.emptyMessage}
                  />
                  {chat.isSending && (
                    <div className="px-3 pb-2">
                      <LoadingIndicator text={labels.loading} />
                    </div>
                  )}
                </div>

                {/* Input */}
                <div className="card-footer bg-white py-2">
                  <div className="d-flex mb-2 tc-flex-gap-sm">
                    <button className="btn btn-outline-info btn-sm" onClick={handleCreateDraft} disabled={draftModalOpen}>
                      <i className="fas fa-magic mr-1" />
                      Generate AI Draft
                    </button>
                    <button className="btn btn-outline-secondary btn-sm" onClick={handleGenerateSummary} disabled={summaryModalOpen}>
                      <i className="fas fa-file-alt mr-1" />
                      Summarize
                    </button>
                  </div>
                  <MessageInput
                    onSend={chat.sendMessage}
                    disabled={chat.isSending || chat.isLoading}
                    placeholder={labels.sendPlaceholder}
                    buttonLabel={labels.sendButton}
                    speechToTextEnabled={config.speechToTextEnabled}
                    sectionId={config.sectionId}
                  />
                </div>
              </>
            ) : (
              <div className="card-body d-flex align-items-center justify-content-center text-muted">
                <div className="text-center">
                  <i className="fas fa-hand-pointer fa-3x mb-3 tc-opacity-muted" />
                  <p>{labels.selectConversation}</p>
                </div>
              </div>
            )}
          </div>
        </div>

        {/* ============ Notes & Controls Sidebar ============ */}
        <div className="col-lg-3 d-none d-lg-block">
          {selectedId && chat.conversation && (
            <>
              {/* Risk Control */}
              {features.enableRiskControl && (
                <div className="card border-0 shadow-sm mb-3">
                  <div className="card-header bg-light py-2">
                    <h6 className="mb-0">
                      <i className="fas fa-shield-alt mr-2" />
                      {labels.riskHeading}
                    </h6>
                  </div>
                  <div className="card-body p-2 d-flex flex-wrap tc-flex-gap-xs">
                    {(['low', 'medium', 'high', 'critical'] as RiskLevel[]).map((r) => {
                      const active = chat.conversation!.risk_level === r;
                      const colors: Record<RiskLevel, string> = {
                        low: active ? 'btn-success' : 'btn-outline-success',
                        medium: active ? 'btn-warning' : 'btn-outline-warning',
                        high: active ? 'btn-danger' : 'btn-outline-danger',
                        critical: active ? 'btn-danger' : 'btn-outline-danger',
                      };
                      return (
                        <button key={r} className={`btn btn-sm ${colors[r]}`} onClick={() => handleSetRisk(r)}>
                          {labels[`risk${r.charAt(0).toUpperCase() + r.slice(1)}` as keyof typeof labels] || r}
                        </button>
                      );
                    })}
                  </div>
                </div>
              )}

              {/* Notes */}
              {features.enableNotes && features.showNotesPanel && (
                <NotesPanel
                  notes={notes}
                  newNote={newNote}
                  onNewNoteChange={setNewNote}
                  onAddNote={handleAddNote}
                  editingNoteId={editingNoteId}
                  editingNoteText={editingNoteText}
                  onEditStart={(noteId, text) => {
                    setEditingNoteId(noteId);
                    setEditingNoteText(text);
                  }}
                  onEditCancel={() => setEditingNoteId(null)}
                  onEditTextChange={setEditingNoteText}
                  onEditSave={handleEditNote}
                  onDeleteNote={handleDeleteNote}
                  labels={labels}
                />
              )}
            </>
          )}
        </div>
      </div>

      {/* ============ AI Draft Modal ============ */}
      <DraftEditorModal
        open={draftModalOpen}
        draftText={draftText}
        onDraftTextChange={setDraftText}
        draftGenerating={draftGenerating}
        draftError={draftError}
        draftUndoStack={draftUndoStack}
        hasActiveDraft={!!activeDraft}
        subjectName={chat.conversation?.subject_name}
        onRegenerate={handleRegenerateDraft}
        onUndo={handleUndoDraft}
        onSend={handleSendDraft}
        onDiscard={handleDiscardDraft}
        onClose={() => {
          setDraftModalOpen(false);
          setDraftError(null);
        }}
        onRetry={() => {
          setDraftError(null);
          handleCreateDraft();
        }}
      />

      {/* ============ Summary Modal ============ */}
      {summaryModalOpen && (
        <div className="tc-modal-overlay" tabIndex={-1}>
          <div className="tc-modal-box">
            <div className="tc-modal-header bg-secondary text-white">
              <h5 className="mb-0">
                <i className="fas fa-file-alt mr-2" />
                Conversation Summary
                {chat.conversation?.subject_name && (
                  <small className="ml-2 font-weight-normal">
                    for {chat.conversation.subject_name}
                  </small>
                )}
              </h5>
              <button
                type="button"
                className="close text-white"
                onClick={() => { setSummaryModalOpen(false); setSummaryText(''); setSummaryError(null); }}
              >
                <span>&times;</span>
              </button>
            </div>
            <div className="tc-modal-body">
              {summaryGenerating ? (
                <div className="text-center py-5 d-flex flex-column align-items-center justify-content-center tc-flex-1">
                  <div className="spinner-border text-secondary mb-3 tc-spinner-lg" role="status" />
                  <p className="text-muted mb-0">Generating conversation summary...</p>
                  <small className="text-muted mt-1">This may take a moment.</small>
                </div>
              ) : summaryError ? (
                <div className="d-flex flex-column align-items-center justify-content-center tc-flex-1">
                  <div className="alert alert-danger mb-3 tc-alert-max-width">
                    <i className="fas fa-exclamation-triangle mr-2" />
                    {summaryError}
                  </div>
                  <button
                    className="btn btn-secondary"
                    onClick={() => { setSummaryError(null); handleGenerateSummary(); }}
                  >
                    <i className="fas fa-redo mr-1" />
                    Retry
                  </button>
                </div>
              ) : (
                <>
                  <p className="text-muted small mb-2 flex-shrink-0">
                    <i className="fas fa-info-circle mr-1" />
                    AI-generated clinical summary. You can save it as a note.
                  </p>
                  <div className="border rounded p-3 bg-light tc-draft-editor tc-markdown tc-summary-content">
                    <MarkdownRenderer content={summaryText} />
                  </div>
                </>
              )}
            </div>
            <div className="tc-modal-footer">
              <button
                className="btn btn-outline-secondary"
                onClick={() => { setSummaryModalOpen(false); setSummaryText(''); setSummaryError(null); }}
              >
                Close
              </button>
              <button
                className="btn btn-success"
                onClick={handleSaveSummaryAsNote}
                disabled={summaryGenerating || !summaryText.trim() || !!summaryError}
              >
                <i className="fas fa-save mr-1" />
                Save as Clinical Note
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default TherapistDashboard;
