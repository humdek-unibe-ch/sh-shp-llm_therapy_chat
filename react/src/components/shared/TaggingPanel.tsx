/**
 * TaggingPanel Component
 * =======================
 *
 * Expandable panel that lets the patient tag their therapist with
 * a predefined reason + urgency. Creates a therapyAlert on the backend.
 *
 * Bootstrap 4.6 styled, no custom CSS needed.
 */

import React, { useState, useCallback } from 'react';
import type { TagReason, TagUrgency } from '../../types';

interface TaggingPanelProps {
  enabled: boolean;
  reasons: TagReason[];
  onTag: (reason?: string, urgency?: TagUrgency) => Promise<void>;
  buttonLabel?: string;
}

export const TaggingPanel: React.FC<TaggingPanelProps> = ({
  enabled,
  reasons,
  onTag,
  buttonLabel = 'Tag Therapist',
}) => {
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  const handleTag = useCallback(
    async (reason?: string, urgency?: TagUrgency) => {
      if (busy) return;
      setBusy(true);
      try {
        await onTag(reason, urgency);
        setOpen(false);
      } catch {
        // error handled by parent
      } finally {
        setBusy(false);
      }
    },
    [onTag, busy],
  );

  if (!enabled) return null;

  return (
    <div className="mb-2">
      <button
        type="button"
        className="btn btn-outline-warning btn-sm"
        onClick={() => setOpen(!open)}
      >
        <i className="fas fa-at mr-1" />
        {buttonLabel}
      </button>

      {open && (
        <div className="mt-2 d-flex flex-wrap" style={{ gap: '0.25rem' }}>
          {reasons.map((r) => {
            const variant =
              r.urgency === 'emergency' ? 'btn-danger' : r.urgency === 'urgent' ? 'btn-warning' : 'btn-outline-secondary';
            return (
              <button
                key={r.code}
                type="button"
                className={`btn btn-sm ${variant}`}
                onClick={() => handleTag(r.code, r.urgency)}
                disabled={busy}
              >
                {r.label}
              </button>
            );
          })}
          <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setOpen(false)}>
            <i className="fas fa-times" />
          </button>
        </div>
      )}
    </div>
  );
};

export default TaggingPanel;
