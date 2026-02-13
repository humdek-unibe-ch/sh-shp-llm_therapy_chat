/**
 * PatientList – Patient list sidebar with filters and conversation items
 */

import React from 'react';
import type { Conversation, UnreadCounts, RiskLevel, ConversationStatus } from '../../types';
import type { TherapistDashboardLabels } from '../../types';
import type { TherapistFeatures } from '../../types';

export type FilterType = 'all' | 'active' | 'critical' | 'unread';

export interface PatientListProps {
  patients: Conversation[];
  selectedPatientId: number | string | null;
  onSelectPatient: (convId: number | string) => void;
  onInitializeConversation: (patientId: number, patientName?: string) => void | Promise<void>;
  unreadCounts: UnreadCounts;
  filter: FilterType;
  onFilterChange: (f: FilterType) => void;
  listLoading: boolean;
  listError: string | null;
  initializingPatientId: number | null;
  labels: TherapistDashboardLabels;
  features: TherapistFeatures;
}

function riskBadge(
  r: RiskLevel | undefined,
  labels: TherapistDashboardLabels
): React.ReactNode {
  if (!r) return null;
  const v: Record<RiskLevel, string> = {
    low: 'badge-success',
    medium: 'badge-warning',
    high: 'badge-danger',
    critical: 'badge-danger',
  };
  const labelKey = `risk${r.charAt(0).toUpperCase() + r.slice(1)}` as keyof TherapistDashboardLabels;
  const label = labels[labelKey];
  return (
    <span className={`badge ${v[r]}`}>
      {r === 'critical' && <i className="fas fa-exclamation-triangle mr-1" />}
      {typeof label === 'string' ? label : r}
    </span>
  );
}

function statusBadge(
  s: ConversationStatus | undefined,
  labels: TherapistDashboardLabels
): React.ReactNode {
  if (!s) return null;
  const v: Record<ConversationStatus, string> = {
    active: 'badge-success',
    paused: 'badge-warning',
    closed: 'badge-secondary',
  };
  const labelKey = `status${s.charAt(0).toUpperCase() + s.slice(1)}` as keyof TherapistDashboardLabels;
  const label = labels[labelKey];
  return <span className={`badge ${v[s]}`}>{typeof label === 'string' ? label : s}</span>;
}

export const PatientList: React.FC<PatientListProps> = ({
  patients,
  selectedPatientId,
  onSelectPatient,
  onInitializeConversation,
  unreadCounts,
  filter,
  onFilterChange,
  listLoading,
  listError,
  initializingPatientId,
  labels,
  features,
}) => (
  <div className="card border-0 shadow-sm h-100">
    <div className="card-header bg-light py-2">
      <div className="d-flex justify-content-between align-items-center mb-2">
        <h6 className="mb-0">
          <i className="fas fa-users mr-2" />
          {labels.conversationsHeading}
        </h6>
      </div>
      <div className="btn-group btn-group-sm w-100">
        {(['all', 'active', 'critical', 'unread'] as FilterType[]).map((f) => (
          <button
            key={f}
            className={`btn ${
              filter === f ? (f === 'critical' ? 'btn-danger' : 'btn-primary') : 'btn-outline-secondary'
            }`}
            onClick={() => onFilterChange(f)}
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
      ) : patients.length === 0 ? (
        <div className="p-3 text-center text-muted">{labels.noConversations}</div>
      ) : (
        patients.map((conv) => {
          const hasConversation = !conv.no_conversation || Number(conv.no_conversation) === 0;
          const bySubject = unreadCounts?.bySubject ?? {};
          const uid = conv.id_users ?? 0;
          const uc = bySubject[uid] ?? bySubject[String(uid)] ?? null;
          const unread = hasConversation ? (uc?.unreadCount ?? 0) : 0;
          const isActive =
            hasConversation && selectedPatientId != null && String(selectedPatientId) === String(conv.id);
          const isInitializing = initializingPatientId === uid;

          if (!hasConversation) {
            return (
              <div key={`patient-${uid}`} className="list-group-item tc-patient-list__no-conv">
                <div className="d-flex justify-content-between align-items-center mb-1">
                  <div className="d-flex align-items-center tc-min-width-0">
                    <strong className="text-truncate text-muted">
                      {conv.subject_name || 'Unknown'}
                    </strong>
                  </div>
                  <span className="badge badge-light text-muted tc-font-xs">
                    <i className="fas fa-comment-slash mr-1" />
                    {labels.noConversationYet ?? 'No conversation yet'}
                  </span>
                </div>
                <div className="d-flex justify-content-between align-items-center">
                  <small className="text-muted">{conv.subject_code}</small>
                  <button
                    className="btn btn-outline-primary btn-sm py-0 px-2 tc-font-smm"
                    disabled={isInitializing}
                    onClick={(e) => {
                      e.stopPropagation();
                      onInitializeConversation(uid, conv.subject_name);
                    }}
                  >
                    {isInitializing ? (
                      <>
                        <span
                          className="spinner-border spinner-border-sm mr-1 tc-spinner-xs"
                          role="status"
                        />
                        {labels.initializingConversation ?? 'Initializing...'}
                      </>
                    ) : (
                      <>
                        <i className="fas fa-plus mr-1" />
                        {labels.startConversation ?? 'Start Conversation'}
                      </>
                    )}
                  </button>
                </div>
              </div>
            );
          }

          return (
            <button
              key={conv.id}
              type="button"
              className={`list-group-item list-group-item-action ${isActive ? 'active' : ''} ${
                unread > 0 && !isActive ? 'tc-patient-list__unread' : ''
              }`}
              onClick={() => onSelectPatient(conv.id)}
            >
              <div className="d-flex justify-content-between align-items-center mb-1">
                <div className="d-flex align-items-center tc-min-width-0">
                  <strong className={`text-truncate ${unread > 0 ? 'font-weight-bold' : ''}`}>
                    {conv.subject_name || 'Unknown'}
                  </strong>
                </div>
                <div className="d-flex flex-shrink-0 ml-2 tc-flex-gap-xs">
                  {unread > 0 && <span className="badge badge-primary">{unread} new</span>}
                  {features.showRiskColumn && riskBadge(conv.risk_level, labels)}
                  {features.showStatusColumn && statusBadge(conv.status, labels)}
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
                  <i className="fas fa-comment-dots mr-1 tc-icon-xs" />
                  {conv.message_count ?? 0}
                </small>
              </div>
            </button>
          );
        })
      )}
    </div>
  </div>
);

export default PatientList;
