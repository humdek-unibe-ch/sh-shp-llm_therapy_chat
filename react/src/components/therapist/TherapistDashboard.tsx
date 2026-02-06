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
import { LoadingIndicator } from '../shared/LoadingIndicator';
import { useChatState } from '../../hooks/useChatState';
import { usePolling } from '../../hooks/usePolling';
import { createTherapistApi } from '../../utils/api';
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

type FilterType = 'all' | 'active' | 'critical' | 'unread';

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
  const [activeGroupId, setActiveGroupId] = useState<number | null>(urlState.gid);
  const [activeFilter, setActiveFilter] = useState<FilterType>('all');
  const [listLoading, setListLoading] = useState(true);
  const [listError, setListError] = useState<string | null>(null);
  const [activeDraft, setActiveDraft] = useState<Draft | null>(null);
  const [draftText, setDraftText] = useState('');
  const [draftModalOpen, setDraftModalOpen] = useState(false);
  const [draftGenerating, setDraftGenerating] = useState(false);

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
      setUnreadCounts({
        total: uc?.total ?? 0,
        totalAlerts: uc?.totalAlerts ?? 0,
        bySubject: uc?.bySubject ?? {},
        byGroup: uc?.byGroup ?? {},
      });
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

  // ---- Polling (stable) ----

  const pollingCb = useCallback(async () => {
    await Promise.all([
      loadConversations(activeGroupIdRef.current, activeFilterRef.current, true),
      loadAlerts(),
      loadUnreadCounts(),
    ]);
    if (selectedIdRef.current) await chat.pollMessages();
  }, [loadConversations, loadAlerts, loadUnreadCounts, chat.pollMessages]);

  usePolling({
    callback: pollingCb,
    interval: config.pollingInterval,
    enabled: true,
  });

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
      // Mark messages as read
      try {
        await api.markMessagesRead(convId);
        loadUnreadCounts();
      } catch { /* ignore */ }
    },
    [api, chat.loadConversation, loadNotes, loadUnreadCounts],
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
    try {
      const res = await api.createDraft(chat.conversation.id);
      if (res.draft) {
        setActiveDraft(res.draft);
        setDraftText(res.draft.edited_content || res.draft.ai_content);
      }
    } catch (err) {
      console.error('Create draft error:', err);
      setDraftModalOpen(false);
    } finally {
      setDraftGenerating(false);
    }
  }, [api, chat.conversation?.id]);

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

  // ---- Badge helpers ----

  const riskBadge = (r?: RiskLevel) => {
    if (!r) return null;
    const v: Record<RiskLevel, string> = { low: 'badge-success', medium: 'badge-warning', high: 'badge-danger', critical: 'badge-danger' };
    return (
      <span className={`badge ${v[r]}`}>
        {r === 'critical' && <i className="fas fa-exclamation-triangle mr-1" />}
        {labels[`risk${r.charAt(0).toUpperCase() + r.slice(1)}` as keyof typeof labels] || r}
      </span>
    );
  };

  const statusBadge = (s?: ConversationStatus) => {
    if (!s) return null;
    const v: Record<ConversationStatus, string> = { active: 'badge-success', paused: 'badge-warning', closed: 'badge-secondary' };
    return <span className={`badge ${v[s]}`}>{labels[`status${s.charAt(0).toUpperCase() + s.slice(1)}` as keyof typeof labels] || s}</span>;
  };

  // ---- Filtered critical alerts ----
  const criticalAlerts = alerts.filter((a) => a.severity === 'critical' || a.severity === 'emergency');

  // ---- Render ----

  return (
    <div className="tc-dashboard container-fluid py-3">
      {/* ============ Stats Header ============ */}
      {features.showStatsHeader && (
        <div className="card border-0 shadow-sm mb-3">
          <div className="card-body d-flex justify-content-between align-items-center flex-wrap py-2">
            <h5 className="mb-0">
              <i className="fas fa-stethoscope text-primary mr-2" />
              {labels.title}
            </h5>
            <div className="d-flex flex-wrap" style={{ gap: '1.5rem' }}>
              <StatItem value={config.stats.total} label="Patients" />
              <StatItem value={config.stats.active} label={labels.statusActive} className="text-success" />
              <StatItem value={unreadCounts.total} label="Unread" className={unreadCounts.total > 0 ? 'text-primary font-weight-bold' : ''} />
              <StatItem value={config.stats.risk_critical} label={labels.riskCritical} className="text-danger" />
              <StatItem value={alerts.length} label="Alerts" className="text-warning" />
            </div>
          </div>
        </div>
      )}

      {/* ============ Alert Banner ============ */}
      {features.showAlertsPanel && criticalAlerts.length > 0 && (
        <div className="mb-3">
          {criticalAlerts.map((a) => (
            <div key={a.id} className="alert alert-danger d-flex justify-content-between align-items-center mb-2">
              <div>
                <i className="fas fa-exclamation-triangle mr-2" />
                <strong>{a.subject_name}:</strong> {a.message}
              </div>
              <button className="btn btn-outline-light btn-sm" onClick={() => handleMarkAlertRead(a.id)}>
                <i className="fas fa-check mr-1" />
                {labels.dismiss}
              </button>
            </div>
          ))}
        </div>
      )}

      {/* ============ Group Tabs ============ */}
      {groups.length > 0 && (
        <ul className="nav nav-tabs mb-3">
          <li className="nav-item">
            <button
              className={`nav-link ${activeGroupId === null ? 'active' : ''}`}
              onClick={() => switchGroup(null)}
            >
              {labels.allGroupsTab}
              {unreadCounts.total > 0 && (
                <span className="badge badge-primary ml-1">{unreadCounts.total}</span>
              )}
            </button>
          </li>
          {groups.map((g) => {
            const groupUnread = unreadCounts?.byGroup?.[g.id_groups] ?? unreadCounts?.byGroup?.[String(g.id_groups)] ?? 0;
            return (
              <li key={g.id_groups} className="nav-item">
                <button
                  className={`nav-link ${activeGroupId === g.id_groups ? 'active' : ''}`}
                  onClick={() => switchGroup(g.id_groups)}
                >
                  {g.group_name}
                  {g.patient_count != null && (
                    <span className="badge badge-light ml-1">{g.patient_count}</span>
                  )}
                  {groupUnread > 0 && (
                    <span className="badge badge-primary ml-1">{groupUnread}</span>
                  )}
                </button>
              </li>
            );
          })}
        </ul>
      )}

      <div className="row" style={{ minHeight: 500 }}>
        {/* ============ Patient List Sidebar ============ */}
        <div className="col-md-4 col-lg-3 mb-3 mb-md-0">
          <div className="card border-0 shadow-sm h-100">
            <div className="card-header bg-light py-2">
              <div className="d-flex justify-content-between align-items-center mb-2">
                <h6 className="mb-0">
                  <i className="fas fa-users mr-2" />
                  {labels.conversationsHeading}
                </h6>
              </div>
              {/* Filter buttons */}
              <div className="btn-group btn-group-sm w-100">
                {(['all', 'active', 'critical', 'unread'] as FilterType[]).map((f) => (
                  <button
                    key={f}
                    className={`btn ${activeFilter === f ? (f === 'critical' ? 'btn-danger' : 'btn-primary') : 'btn-outline-secondary'}`}
                    onClick={() => switchFilter(f)}
                  >
                    {labels[`filter${f.charAt(0).toUpperCase() + f.slice(1)}` as keyof typeof labels] || f}
                  </button>
                ))}
              </div>
            </div>

            <div className="list-group list-group-flush tc-patient-list">
              {listLoading ? (
                <div className="p-3 text-center text-muted">
                  <div className="spinner-border spinner-border-sm" role="status" />
                </div>
              ) : listError ? (
                <div className="p-3 text-center text-danger">{listError}</div>
              ) : conversations.length === 0 ? (
                <div className="p-3 text-center text-muted">{labels.noConversations}</div>
              ) : (
                conversations.map((conv) => {
                  // Safely access bySubject – guard against undefined/null and
                  // handle both numeric and zero-padded string user IDs
                  const bySubject = unreadCounts?.bySubject ?? {};
                  const uid = conv.id_users ?? 0;
                  const uc = bySubject[uid] ?? bySubject[String(uid)] ?? null;
                  const unread = uc?.unreadCount ?? 0;
                  const isActive = selectedId != null && String(selectedId) === String(conv.id);

                  return (
                    <button
                      key={conv.id}
                      type="button"
                      className={`list-group-item list-group-item-action ${isActive ? 'active' : ''} ${unread > 0 && !isActive ? 'tc-patient-list__unread' : ''}`}
                      onClick={() => selectConversation(conv.id)}
                    >
                      <div className="d-flex justify-content-between align-items-center mb-1">
                        <div className="d-flex align-items-center" style={{ minWidth: 0 }}>
                          <strong className={`text-truncate ${unread > 0 ? 'font-weight-bold' : ''}`}>
                            {conv.subject_name || 'Unknown'}
                          </strong>
                        </div>
                        <div className="d-flex flex-shrink-0 ml-2" style={{ gap: '0.25rem' }}>
                          {unread > 0 && (
                            <span className="badge badge-primary">{unread} new</span>
                          )}
                          {features.showRiskColumn && riskBadge(conv.risk_level)}
                          {features.showStatusColumn && statusBadge(conv.status)}
                          {(conv.unread_alerts ?? 0) > 0 && (
                            <span className="badge badge-danger">
                              <i className="fas fa-bell" /> {conv.unread_alerts}
                            </span>
                          )}
                        </div>
                      </div>
                      <div className="d-flex justify-content-between align-items-center">
                        <small className={isActive ? '' : unread > 0 ? 'text-dark' : 'text-muted'}>
                          {conv.subject_code}
                          {!conv.ai_enabled && <span className="ml-1">&middot; Human only</span>}
                        </small>
                        <small className={isActive ? '' : 'text-muted'}>
                          <i className="fas fa-comment-dots mr-1" style={{ fontSize: '0.65rem' }} />
                          {conv.message_count ?? 0}
                        </small>
                      </div>
                    </button>
                  );
                })
              )}
            </div>
          </div>
        </div>

        {/* ============ Conversation Area ============ */}
        <div className="col-md-8 col-lg-6 mb-3 mb-md-0">
          <div className="card border-0 shadow-sm h-100 d-flex flex-column">
            {selectedId && chat.conversation ? (
              <>
                {/* Header */}
                <div className="card-header bg-white d-flex justify-content-between align-items-center py-2">
                  <div>
                    <h5 className="mb-0">{chat.conversation.subject_name || labels.subjectLabel}</h5>
                    <small className="text-muted">
                      {chat.conversation.subject_code}
                      {chat.conversation.ai_enabled ? (
                        <span className="ml-2 text-success">
                          <i className="fas fa-robot mr-1" />
                          {labels.aiModeIndicator}
                        </span>
                      ) : (
                        <span className="ml-2 text-warning">
                          <i className="fas fa-user-md mr-1" />
                          {labels.humanModeIndicator}
                        </span>
                      )}
                    </small>
                  </div>
                  <div className="d-flex align-items-center" style={{ gap: '0.5rem' }}>
                    {features.showRiskColumn && riskBadge(chat.conversation.risk_level)}
                    {features.showStatusColumn && statusBadge(chat.conversation.status)}
                    {features.enableAiToggle && (
                      <button
                        className={`btn btn-sm ${chat.conversation.ai_enabled ? 'btn-outline-warning' : 'btn-outline-success'}`}
                        onClick={handleToggleAI}
                      >
                        <i className="fas fa-robot mr-1" />
                        {chat.conversation.ai_enabled ? labels.disableAI : labels.enableAI}
                      </button>
                    )}
                    {features.enableStatusControl && (
                      <div className="dropdown">
                        <button className="btn btn-outline-secondary btn-sm dropdown-toggle" data-toggle="dropdown">
                          <i className="fas fa-flag" />
                        </button>
                        <div className="dropdown-menu dropdown-menu-right">
                          <button className="dropdown-item" onClick={() => handleSetStatus('active')}>
                            <span className="badge badge-success mr-2">&bull;</span> {labels.statusActive}
                          </button>
                          <button className="dropdown-item" onClick={() => handleSetStatus('paused')}>
                            <span className="badge badge-warning mr-2">&bull;</span> {labels.statusPaused}
                          </button>
                          <button className="dropdown-item" onClick={() => handleSetStatus('closed')}>
                            <span className="badge badge-secondary mr-2">&bull;</span> {labels.statusClosed}
                          </button>
                        </div>
                      </div>
                    )}
                  </div>
                </div>

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
                  <div className="d-flex mb-2" style={{ gap: '0.5rem' }}>
                    <button className="btn btn-outline-info btn-sm" onClick={handleCreateDraft} disabled={draftModalOpen}>
                      <i className="fas fa-magic mr-1" />
                      Generate AI Draft
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
                  <i className="fas fa-hand-pointer fa-3x mb-3" style={{ opacity: 0.3 }} />
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
                  <div className="card-body p-2 d-flex flex-wrap" style={{ gap: '0.25rem' }}>
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
                <div className="card border-0 shadow-sm">
                  <div className="card-header bg-light py-2">
                    <h6 className="mb-0">
                      <i className="fas fa-sticky-note mr-2" />
                      {labels.notesHeading}
                    </h6>
                  </div>
                  <div className="card-body p-2 tc-notes-list">
                    {notes.length === 0 ? (
                      <p className="text-muted text-center mb-0 small">No notes yet.</p>
                    ) : (
                      notes.map((n) => (
                        <div key={n.id} className="tc-note-item mb-2 p-2 rounded">
                          <div className="d-flex justify-content-between text-muted mb-1">
                            <small className="font-weight-bold">{n.author_name}</small>
                            <div className="d-flex align-items-center" style={{ gap: '0.5rem' }}>
                              <small>{new Date(n.created_at).toLocaleDateString()}</small>
                              <button
                                className="btn btn-link btn-sm p-0 text-muted"
                                title="Edit note"
                                onClick={() => { setEditingNoteId(n.id); setEditingNoteText(n.content); }}
                              >
                                <i className="fas fa-pencil-alt" style={{ fontSize: '0.7rem' }} />
                              </button>
                              <button
                                className="btn btn-link btn-sm p-0 text-danger"
                                title="Delete note"
                                onClick={() => handleDeleteNote(n.id)}
                              >
                                <i className="fas fa-trash-alt" style={{ fontSize: '0.7rem' }} />
                              </button>
                            </div>
                          </div>
                          {editingNoteId === n.id ? (
                            <div>
                              <textarea
                                className="form-control form-control-sm mb-1"
                                rows={2}
                                value={editingNoteText}
                                onChange={(e) => setEditingNoteText(e.target.value)}
                              />
                              <div className="d-flex" style={{ gap: '0.25rem' }}>
                                <button className="btn btn-primary btn-sm py-0 px-2" onClick={handleEditNote} disabled={!editingNoteText.trim()}>
                                  Save
                                </button>
                                <button className="btn btn-outline-secondary btn-sm py-0 px-2" onClick={() => setEditingNoteId(null)}>
                                  Cancel
                                </button>
                              </div>
                            </div>
                          ) : (
                            <>
                              <p className="mb-0 small">{n.content}</p>
                              {n.last_edited_by_name && (
                                <small className="text-muted" style={{ fontSize: '0.65rem' }}>
                                  <i className="fas fa-edit mr-1" />
                                  Last edited by {n.last_edited_by_name}
                                </small>
                              )}
                            </>
                          )}
                        </div>
                      ))
                    )}
                  </div>
                  <div className="card-footer bg-white p-2">
                    <textarea
                      className="form-control form-control-sm mb-2"
                      rows={2}
                      value={newNote}
                      onChange={(e) => setNewNote(e.target.value)}
                      placeholder={labels.addNotePlaceholder}
                    />
                    <button
                      className="btn btn-outline-primary btn-sm"
                      onClick={handleAddNote}
                      disabled={!newNote.trim()}
                    >
                      <i className="fas fa-plus mr-1" />
                      {labels.addNoteButton}
                    </button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      </div>

      {/* ============ AI Draft Modal ============ */}
      {draftModalOpen && (
        <div className="modal d-block" tabIndex={-1} style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="modal-dialog modal-lg modal-dialog-centered">
            <div className="modal-content">
              <div className="modal-header bg-info text-white py-2">
                <h5 className="modal-title">
                  <i className="fas fa-robot mr-2" />
                  AI Draft Response
                  {chat.conversation?.subject_name && (
                    <small className="ml-2 font-weight-normal">
                      for {chat.conversation.subject_name}
                    </small>
                  )}
                </h5>
                <button
                  type="button"
                  className="close text-white"
                  onClick={() => {
                    if (activeDraft) handleDiscardDraft();
                    else setDraftModalOpen(false);
                  }}
                >
                  <span>&times;</span>
                </button>
              </div>
              <div className="modal-body">
                {draftGenerating ? (
                  <div className="text-center py-4">
                    <div className="spinner-border text-info mb-3" role="status" />
                    <p className="text-muted">Generating AI draft response...</p>
                  </div>
                ) : (
                  <>
                    <p className="text-muted small mb-2">
                      <i className="fas fa-info-circle mr-1" />
                      Review and edit the AI-generated response before sending it to the patient.
                    </p>
                    <textarea
                      className="form-control"
                      rows={8}
                      value={draftText}
                      onChange={(e) => setDraftText(e.target.value)}
                      placeholder="AI draft content..."
                    />
                    <div className="d-flex justify-content-between mt-2">
                      <small className="text-muted">{draftText.length} characters</small>
                    </div>
                  </>
                )}
              </div>
              <div className="modal-footer py-2">
                <button
                  className="btn btn-outline-secondary"
                  onClick={() => {
                    if (activeDraft) handleDiscardDraft();
                    else setDraftModalOpen(false);
                  }}
                  disabled={draftGenerating}
                >
                  <i className="fas fa-times mr-1" />
                  Discard
                </button>
                <button
                  className="btn btn-primary"
                  onClick={handleSendDraft}
                  disabled={draftGenerating || !draftText.trim()}
                >
                  <i className="fas fa-paper-plane mr-1" />
                  Send to Patient
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

// ---------------------------------------------------------------------------
// Small stat display helper
// ---------------------------------------------------------------------------

const StatItem: React.FC<{ value: number; label: string; className?: string }> = ({ value, label, className = '' }) => (
  <div className="text-center">
    <div className={`h5 mb-0 ${className}`}>{value}</div>
    <small className="text-muted">{label}</small>
  </div>
);

export default TherapistDashboard;
