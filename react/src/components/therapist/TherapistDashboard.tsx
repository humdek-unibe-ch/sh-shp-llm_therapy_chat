/**
 * TherapistDashboard Component
 * ============================
 *
 * Main therapist monitoring dashboard. Composes layout, data provider,
 * and feature-specific hooks. All business logic lives in dedicated hooks;
 * this file is purely composition and wiring (~200 lines).
 */

import React, { useEffect, useCallback, useMemo } from 'react';
import { useChatState } from '../../hooks/useChatState';
import { usePolling } from '../../hooks/usePolling';
import { useDraftState } from '../../hooks/useDraftState';
import { useSummaryState } from '../../hooks/useSummaryState';
import { useNoteEditor } from '../../hooks/useNoteEditor';
import { useConversationActions } from '../../hooks/useConversationActions';
import { createTherapistApi } from '../../utils/api';
import { getUnreadForSubject, getTotalUnread } from '../../utils/unreadHelpers';
import { readUrlState, writeUrlState } from '../../utils/urlState';
import { DashboardDataProvider, useDashboardData } from './DashboardDataProvider';
import { DashboardLayout } from './DashboardLayout';
import { HeaderArea } from './HeaderArea';
import { ConversationArea } from './ConversationArea';
import { ConversationViewer } from './ConversationViewer';
import { RiskStatusControls } from './RiskStatusControls';
import { SummaryModal } from './SummaryModal';
import { DraftEditorModal } from './DraftEditor';
import { StatsHeader } from './StatsHeader';
import { AlertBanner } from './AlertBanner';
import { GroupTabs } from './GroupTabs';
import { PatientList } from './PatientList';
import { NotesPanel } from './NotesPanel';
import type { FilterType } from './PatientList';
import type { TherapistDashboardConfig } from '../../types';

// ---------------------------------------------------------------------------
// Inner Dashboard (lives inside DashboardDataProvider)
// ---------------------------------------------------------------------------

const TherapistDashboardInner: React.FC<{ config: TherapistDashboardConfig }> = ({ config }) => {
  const api = useMemo(() => createTherapistApi(config.sectionId), [config.sectionId]);
  const { features, labels } = config;
  const { state, actions } = useDashboardData();
  const {
    conversations, alerts, notes, unreadCounts, groups, stats,
    selectedConversationId, activeGroupId, activeFilter, loading, errors,
  } = state;

  // ---- Chat state ----
  const chat = useChatState({
    loadFn: (convId) => api.getConversation(convId as number | string),
    sendFn: (convId, msg) => api.sendMessage(convId, msg),
    pollFn: (convId, afterId) => api.getMessages(convId, afterId),
    senderType: 'therapist',
  });

  const getConversationId = useCallback(
    () => chat.conversation?.id,
    [chat.conversation],
  );

  // ---- Conversation actions (risk, status, AI, mark read) ----
  const convActions = useConversationActions({
    api,
    getConversation: () => chat.conversation,
    updateConversation: actions.updateConversation,
    refreshUnreadCounts: actions.loadUnreadCounts,
    refreshConversations: actions.loadConversations,
    refreshStats: actions.loadStats,
    selectConversation: (id) => {
      actions.setSelectedConversation(id);
      writeUrlState({ uid: id ?? undefined, gid: activeGroupId ?? undefined });
    },
    activeGroupId,
    activeFilter,
    reloadChat: chat.loadConversation,
  });

  // ---- Draft state ----
  const draft = useDraftState({
    createDraft: (convId) => api.createDraft(convId),
    sendMessage: chat.sendMessage,
    getConversationId,
  });

  // ---- Summary state ----
  const summary = useSummaryState({
    generateSummary: (convId) => api.generateSummary(convId),
    addNote: (convId, content) => api.addNote(convId, content),
    getConversationId,
    onNoteAdded: actions.addNote,
  });

  // ---- Note editor state ----
  const noteEditor = useNoteEditor({
    api: { addNote: api.addNote, editNote: api.editNote, deleteNote: api.deleteNote },
    getConversationId,
    onNoteAdded: actions.addNote,
    onNoteUpdated: actions.updateNote,
    onNoteDeleted: actions.deleteNote,
  });

  // ---- Polling (refreshes dashboard data AND chat messages) ----
  usePolling({
    callback: async () => {
      await Promise.all([
        actions.refresh(),
        chat.pollMessages(),
      ]);
    },
    interval: config.pollingInterval || 5000,
  });

  // ---- Load conversation + notes when selection changes ----
  useEffect(() => {
    if (selectedConversationId) {
      chat.loadConversation(selectedConversationId);
      actions.loadNotes(selectedConversationId);
    }
  }, [selectedConversationId]); // eslint-disable-line react-hooks/exhaustive-deps

  // ---- Reload conversations when filter/group changes ----
  useEffect(() => {
    actions.loadConversations(activeGroupId, activeFilter);
  }, [activeGroupId, activeFilter]); // eslint-disable-line react-hooks/exhaustive-deps

  // ---- Navigation handlers ----
  const selectConversation = useCallback((convId: number | string | null) => {
    actions.setSelectedConversation(convId);
    writeUrlState({ uid: convId ?? undefined, gid: activeGroupId ?? undefined });
  }, [actions, activeGroupId]);

  const switchGroup = useCallback((groupId: number | string | null) => {
    actions.setActiveGroup(groupId);
    writeUrlState({ uid: selectedConversationId ?? undefined, gid: groupId ?? undefined });
  }, [actions, selectedConversationId]);

  const switchFilter = useCallback((filter: FilterType) => {
    actions.setActiveFilter(filter);
  }, [actions]);

  // ---- Computed values ----
  const selectedConversation = conversations.find(c => String(c.id) === String(selectedConversationId));
  const selectedUnreadCount = selectedConversationId
    ? getUnreadForSubject(unreadCounts, selectedConversationId)
    : 0;

  // ---- Render ----
  return (
    <>
      <DashboardLayout
        header={
          <HeaderArea
            statsHeader={
              stats && (
                <StatsHeader
                  title={labels.title}
                  stats={stats}
                  unreadCounts={unreadCounts}
                  labels={{ title: labels.title, riskCritical: labels.riskCritical }}
                />
              )
            }
            alertBanner={(() => {
              const unreadAlerts = alerts.filter(a => !a.is_read);
              return unreadAlerts.length > 0 && (
                <AlertBanner alerts={unreadAlerts} onAcknowledge={actions.markAlertRead} labels={labels} />
              );
            })()}
            groupTabs={
              groups.length > 1 && (
                <GroupTabs
                  groups={groups}
                  selectedGroupId={activeGroupId}
                  onSelectGroup={switchGroup}
                  unreadByGroup={unreadCounts.byGroup}
                  totalUnread={getTotalUnread(unreadCounts)}
                  labels={{ allGroupsTab: labels.allGroupsTab }}
                />
              )
            }
          />
        }
        sidebar={
          <PatientList
            patients={conversations}
            selectedPatientId={selectedConversationId}
            onSelectPatient={selectConversation}
            onInitializeConversation={convActions.initializeConversation}
            unreadCounts={unreadCounts}
            filter={activeFilter}
            onFilterChange={switchFilter}
            listLoading={loading.conversations}
            listError={errors.conversations}
            initializingPatientId={convActions.initializingPatientId}
            labels={labels}
            features={features}
          />
        }
        main={
          <ConversationArea
            hasConversation={!!selectedConversationId}
            labels={labels}
            conversationViewer={
              selectedConversationId && chat.conversation && (
                <ConversationViewer
                  conversation={chat.conversation}
                  chat={chat}
                  unreadCount={selectedUnreadCount}
                  labels={labels}
                  features={features}
                  config={config}
                  onMarkRead={convActions.markRead}
                  onCreateDraft={draft.generate}
                  onGenerateSummary={summary.generate}
                  draftModalOpen={draft.open}
                  summaryModalOpen={summary.open}
                />
              )
            }
          />
        }
        rightSidebar={
          selectedConversationId && chat.conversation && (
            <>
              {features.enableRiskControl && (
                <RiskStatusControls
                  riskLevel={chat.conversation.risk_level || 'low'}
                  aiEnabled={chat.conversation.ai_enabled || false}
                  labels={labels}
                  features={features}
                  onSetRisk={convActions.setRisk}
                  onToggleAI={convActions.toggleAI}
                />
              )}
              {/* Export CSV dropdown – grouped with right-sidebar controls */}
              <div className="card border-0 shadow-sm mb-3">
                <div className="card-body py-2 px-3">
                  <div className="dropdown">
                    <button className="btn btn-outline-secondary btn-sm btn-block dropdown-toggle" type="button" data-toggle="dropdown">
                      <i className="fas fa-download mr-1" />Export CSV
                    </button>
                    <div className="dropdown-menu dropdown-menu-right">
                      <a className="dropdown-item" href={api.getExportUrl('patient', selectedConversationId)} target="_blank" rel="noopener noreferrer">
                        <i className="fas fa-user mr-2" />Export current patient
                      </a>
                      {activeGroupId != null && (
                        <a className="dropdown-item" href={api.getExportUrl('group', null, activeGroupId)} target="_blank" rel="noopener noreferrer">
                          <i className="fas fa-users mr-2" />Export current group
                        </a>
                      )}
                      <a className="dropdown-item" href={api.getExportUrl('all')} target="_blank" rel="noopener noreferrer">
                        <i className="fas fa-globe mr-2" />Export all conversations
                      </a>
                    </div>
                  </div>
                </div>
              </div>
              {features.enableNotes && features.showNotesPanel && (
                <NotesPanel
                  notes={notes}
                  newNote={noteEditor.newNote}
                  onNewNoteChange={noteEditor.setNewNote}
                  onAddNote={noteEditor.add}
                  editingNoteId={noteEditor.editingId}
                  editingNoteText={noteEditor.editingText}
                  onEditStart={noteEditor.startEditing}
                  onEditCancel={noteEditor.cancelEditing}
                  onEditTextChange={noteEditor.setEditingText}
                  onEditSave={noteEditor.save}
                  onDeleteNote={noteEditor.remove}
                  labels={labels}
                />
              )}
            </>
          )
        }
      />

      {/* Draft Modal */}
      <DraftEditorModal
        open={draft.open}
        draftText={draft.text}
        onDraftTextChange={draft.setText}
        draftGenerating={draft.generating}
        draftError={draft.error}
        draftUndoStack={draft.undoStack}
        hasActiveDraft={!!draft.text}
        subjectName={chat.conversation?.subject_name || ''}
        onRegenerate={draft.regenerate}
        onUndo={draft.undo}
        onSend={draft.send}
        onDiscard={draft.discard}
        onClose={draft.close}
        onRetry={draft.retry}
      />

      {/* Summary Modal */}
      <SummaryModal
        open={summary.open}
        onClose={summary.close}
        generating={summary.generating}
        error={summary.error}
        summaryText={summary.text}
        subjectName={chat.conversation?.subject_name || ''}
        onRetry={summary.retry}
        onSaveAsNote={summary.saveAsNote}
      />
    </>
  );
};

// ---------------------------------------------------------------------------
// Public Export – wraps inner component with data provider
// ---------------------------------------------------------------------------

export const TherapistDashboard: React.FC<{ config: TherapistDashboardConfig }> = ({ config }) => {
  // Read URL state ONCE on mount to seed the provider
  const urlState = useMemo(() => readUrlState(), []);

  return (
    <DashboardDataProvider
      config={config}
      initialGroupId={urlState.gid ?? null}
      initialSubjectId={urlState.uid ?? null}
    >
      <TherapistDashboardInner config={config} />
    </DashboardDataProvider>
  );
};

export default TherapistDashboard;
