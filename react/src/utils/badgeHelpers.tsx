/**
 * Badge Helper Components
 * ========================
 *
 * Shared risk-level and AI-mode badge rendering used by
 * PatientList, ConversationHeader, and other therapist components.
 *
 * NOTE: StatusBadge was removed — the "status" concept (active/paused)
 * is now unified under the `ai_enabled` flag. Use AiModeBadge instead.
 */

import React from 'react';
import type { RiskLevel, TherapistDashboardLabels } from '../types';

const RISK_CLASSES: Record<RiskLevel, string> = {
  low: 'badge-success',
  medium: 'badge-warning',
  high: 'badge-danger',
  critical: 'badge-danger',
};

function labelFor(labels: TherapistDashboardLabels, prefix: string, key: string): string {
  const prop = `${prefix}${key.charAt(0).toUpperCase()}${key.slice(1)}` as keyof TherapistDashboardLabels;
  const val = labels[prop];
  return typeof val === 'string' ? val : key;
}

export function RiskBadge({ risk, labels }: { risk: RiskLevel | undefined; labels: TherapistDashboardLabels }): React.ReactElement | null {
  if (!risk) return null;
  return (
    <span className={`badge ${RISK_CLASSES[risk]}`}>
      {risk === 'critical' && <i className="fas fa-exclamation-triangle mr-1" />}
      {labelFor(labels, 'risk', risk)}
    </span>
  );
}

export function AiModeBadge({ aiEnabled, labels }: { aiEnabled: boolean; labels: TherapistDashboardLabels }): React.ReactElement {
  return aiEnabled ? (
    <span className="badge badge-light">
      <i className="fas fa-robot mr-1" />
      {labels.aiModeIndicator || 'AI'}
    </span>
  ) : (
    <span className="badge badge-warning">
      <i className="fas fa-user-md mr-1" />
      {labels.humanModeIndicator || 'Human'}
    </span>
  );
}
