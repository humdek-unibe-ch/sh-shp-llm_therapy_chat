/**
 * ConversationHeader – Conversation header bar with patient info and risk/AI indicators
 *
 * Action buttons (AI toggle + risk controls) are intentionally
 * NOT here – they live exclusively in the right-sidebar RiskStatusControls
 * to avoid confusing duplicate controls.
 */

import React from 'react';
import { RiskBadge } from '../../utils/badgeHelpers';
import type { Conversation } from '../../types';
import type { TherapistDashboardLabels, TherapistFeatures } from '../../types';

export interface ConversationHeaderProps {
  conversation: Conversation;
  unreadCount: number;
  onMarkRead?: () => void | Promise<void>;
  labels: TherapistDashboardLabels;
  features: TherapistFeatures;
}

export const ConversationHeader: React.FC<ConversationHeaderProps> = ({
  conversation,
  unreadCount,
  onMarkRead,
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
    </div>
  </div>
);

export default ConversationHeader;
