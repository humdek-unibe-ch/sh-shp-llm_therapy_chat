/**
 * ConversationHeader – Conversation header bar with patient info and controls
 */

import React from 'react';
import { RiskBadge, StatusBadge } from '../../utils/badgeHelpers';
import type { Conversation, ConversationStatus } from '../../types';
import type { TherapistDashboardLabels, TherapistFeatures } from '../../types';

export interface ConversationHeaderProps {
  conversation: Conversation;
  unreadCount: number;
  onMarkRead?: () => void | Promise<void>;
  onToggleAI?: () => void | Promise<void>;
  onSetStatus?: (status: ConversationStatus) => void | Promise<void>;
  labels: TherapistDashboardLabels;
  features: TherapistFeatures;
}

export const ConversationHeader: React.FC<ConversationHeaderProps> = ({
  conversation,
  unreadCount,
  onMarkRead,
  onToggleAI,
  onSetStatus,
  labels,
  features,
}) => (
  <div className="card-header bg-white d-flex justify-content-between align-items-center py-2">
    <div>
      <h5 className="mb-0">{conversation.subject_name || labels.subjectLabel}</h5>
      <small className="text-muted">
        {conversation.subject_code}
        {conversation.ai_enabled ? (
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
    <div className="d-flex align-items-center tc-flex-gap-sm">
      {unreadCount > 0 && onMarkRead && (
        <button
          className="btn btn-sm btn-outline-primary"
          title="Mark all messages as read"
          onClick={onMarkRead}
        >
          <i className="fas fa-check-double mr-1" />
          Mark read
        </button>
      )}
      {features.showRiskColumn && <RiskBadge risk={conversation.risk_level} labels={labels} />}
      {features.showStatusColumn && <StatusBadge status={conversation.status} labels={labels} />}
      {features.enableAiToggle && onToggleAI && (
        <button
          className={`btn btn-sm ${
            conversation.ai_enabled ? 'btn-outline-warning' : 'btn-outline-success'
          }`}
          onClick={onToggleAI}
        >
          <i className="fas fa-robot mr-1" />
          {conversation.ai_enabled ? labels.disableAI : labels.enableAI}
        </button>
      )}
      {features.enableStatusControl && onSetStatus && (
        <div className="dropdown">
          <button className="btn btn-outline-secondary btn-sm dropdown-toggle" data-toggle="dropdown">
            <i className="fas fa-flag" />
          </button>
          <div className="dropdown-menu dropdown-menu-right">
            <button className="dropdown-item" onClick={() => onSetStatus('active')}>
              <span className="badge badge-success mr-2">&bull;</span> {labels.statusActive}
            </button>
            <button className="dropdown-item" onClick={() => onSetStatus('paused')}>
              <span className="badge badge-warning mr-2">&bull;</span> {labels.statusPaused}
            </button>
            <button className="dropdown-item" onClick={() => onSetStatus('closed')}>
              <span className="badge badge-secondary mr-2">&bull;</span> {labels.statusClosed}
            </button>
          </div>
        </div>
      )}
    </div>
  </div>
);

export default ConversationHeader;
