/**
 * TaggingPanel Component
 * =======================
 *
 * Displays a small help label explaining @mention and #hashtag usage.
 * The actual tagging happens inline in the message text.
 *
 * Bootstrap 4.6 styled, no custom CSS needed.
 */

import React from 'react';

interface TaggingPanelProps {
  enabled: boolean;
  /** Customizable help text from DB field (therapy_chat_help_text) */
  helpText?: string;
}

export const TaggingPanel: React.FC<TaggingPanelProps> = ({
  enabled,
  helpText,
}) => {
  if (!enabled || !helpText) return null;

  return (
    <div className="mb-2">
      <small className="text-muted">
        <i className="fas fa-info-circle mr-1" />
        {helpText}
      </small>
    </div>
  );
};

export default TaggingPanel;
