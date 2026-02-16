/**
 * Conversation Area Component
 * ===========================
 *
 * Container for the conversation viewer or empty state.
 * Handles the display logic for when no conversation is selected.
 */

import React from 'react';
import type { TherapistDashboardLabels } from '../../types';

export interface ConversationAreaProps {
  /** Conversation viewer component when conversation is selected */
  conversationViewer?: React.ReactNode;
  /** Whether a conversation is currently selected */
  hasConversation: boolean;
  /** Labels for empty state */
  labels: TherapistDashboardLabels;
  /** Additional CSS classes */
  className?: string;
}

/**
 * Conversation area with viewer or empty state
 */
export const ConversationArea: React.FC<ConversationAreaProps> = ({
  conversationViewer,
  hasConversation,
  labels,
  className = '',
}) => {
  if (hasConversation && conversationViewer) {
    return <div className={`tc-conversation-area ${className}`}>{conversationViewer}</div>;
  }

  // Empty state when no conversation is selected
  return (
    <div className={`tc-conversation-area-empty ${className}`}>
      <div className="card border-0 shadow-sm h-100">
        <div className="card-body d-flex align-items-center justify-content-center text-muted">
          <div className="text-center">
            <i className="fas fa-hand-pointer fa-3x mb-3 tc-opacity-muted" />
            <p>{labels.selectConversation}</p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ConversationArea;
