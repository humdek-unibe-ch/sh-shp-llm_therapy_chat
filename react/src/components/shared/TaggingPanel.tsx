/**
 * Tagging Panel Component
 * ========================
 * 
 * Allows subjects to tag their therapist with predefined reasons.
 */

import React, { useState, useCallback } from 'react';
import { Button, Collapse } from 'react-bootstrap';
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
  const [isOpen, setIsOpen] = useState(false);
  const [isTagging, setIsTagging] = useState(false);

  const handleTag = useCallback(async (reason?: string, urgency?: TagUrgency) => {
    if (isTagging) return;
    
    setIsTagging(true);
    try {
      await onTag(reason, urgency);
      setIsOpen(false);
    } catch (err) {
      console.error('Tag error:', err);
    } finally {
      setIsTagging(false);
    }
  }, [onTag, isTagging]);

  if (!enabled) return null;

  return (
    <div className="therapy-tagging-panel mb-2">
      <Button
        variant="outline-warning"
        size="sm"
        onClick={() => setIsOpen(!isOpen)}
        aria-expanded={isOpen}
        className="therapy-tag-toggle"
      >
        <i className="fas fa-at mr-1"></i>
        {buttonLabel}
      </Button>

      <Collapse in={isOpen}>
        <div className="mt-2">
          <div className="d-flex flex-wrap gap-2">
            {reasons.map((reason) => (
              <Button
                key={reason.code}
                variant={
                  reason.urgency === 'emergency' 
                    ? 'danger' 
                    : reason.urgency === 'urgent' 
                      ? 'warning' 
                      : 'outline-secondary'
                }
                size="sm"
                onClick={() => handleTag(reason.code, reason.urgency)}
                disabled={isTagging}
              >
                {reason.label}
              </Button>
            ))}
            <Button
              variant="outline-secondary"
              size="sm"
              onClick={() => setIsOpen(false)}
            >
              <i className="fas fa-times"></i>
            </Button>
          </div>
        </div>
      </Collapse>
    </div>
  );
};

export default TaggingPanel;
