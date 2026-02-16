/**
 * Badge Helper Components
 * ========================
 *
 * Shared risk-level and status badge rendering used by
 * PatientList, ConversationHeader, and other therapist components.
 */

import React from 'react';
import type { RiskLevel, ConversationStatus, TherapistDashboardLabels } from '../types';

const RISK_CLASSES: Record<RiskLevel, string> = {
  low: 'badge-success',
  medium: 'badge-warning',
  high: 'badge-danger',
  critical: 'badge-danger',
};

const STATUS_CLASSES: Record<ConversationStatus, string> = {
  active: 'badge-success',
  paused: 'badge-warning',
  closed: 'badge-secondary',
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

export function StatusBadge({ status, labels }: { status: ConversationStatus | undefined; labels: TherapistDashboardLabels }): React.ReactElement | null {
  if (!status) return null;
  return (
    <span className={`badge ${STATUS_CLASSES[status]}`}>
      {labelFor(labels, 'status', status)}
    </span>
  );
}
