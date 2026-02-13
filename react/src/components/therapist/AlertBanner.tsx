/**
 * AlertBanner – Critical alerts display with acknowledge/dismiss
 */

import React from 'react';
import type { Alert } from '../../types';

export interface AlertBannerProps {
  alerts: Alert[];
  onAcknowledge: (alertId: number) => void | Promise<void>;
  onDismissAll?: () => void | Promise<void>;
  labels: {
    dismiss: string;
  };
}

/** Build a clean display message from alert data */
function getAlertDisplayMessage(a: Alert): string {
  const meta = (a.metadata ?? {}) as Record<string, unknown>;
  const concerns = Array.isArray(meta.detected_concerns)
    ? (meta.detected_concerns as string[]).join(', ')
    : null;
  const alertType = a.alert_type ?? '';
  if (alertType === 'danger_detected' && concerns) {
    return `Safety concerns detected: ${concerns}`;
  }
  if (alertType === 'tag_received') {
    return meta.reason ? `Tagged: ${meta.reason}` : 'Patient tagged therapist';
  }
  const raw = a.message ?? '';
  const jsonIdx = raw.indexOf('{');
  return jsonIdx > 0 ? raw.substring(0, jsonIdx).trim().replace(/["\n]+$/, '') : raw;
}

export const AlertBanner: React.FC<AlertBannerProps> = ({
  alerts,
  onAcknowledge,
  onDismissAll,
  labels,
}) => {
  if (alerts.length === 0) return null;

  return (
    <div className="mb-3">
      {alerts.map((a) => (
        <div
          key={a.id}
          className="alert alert-danger d-flex justify-content-between align-items-center mb-2"
        >
          <div className="text-truncate mr-2">
            <i className="fas fa-exclamation-triangle mr-2" />
            <strong>{a.subject_name}:</strong> {getAlertDisplayMessage(a)}
          </div>
          <button
            className="btn btn-outline-light btn-sm flex-shrink-0"
            onClick={() => onAcknowledge(a.id)}
          >
            <i className="fas fa-check mr-1" />
            {labels.dismiss}
          </button>
        </div>
      ))}
      {alerts.length > 1 && onDismissAll && (
        <button className="btn btn-sm btn-outline-danger" onClick={onDismissAll}>
          <i className="fas fa-check-double mr-1" />
          Dismiss all alerts
        </button>
      )}
    </div>
  );
};

export default AlertBanner;
