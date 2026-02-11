/**
 * TaggingPanel Component
 * =======================
 *
 * Displays a small help label explaining @mention and #hashtag usage.
 * The help text may come from the DB with HTML tags (e.g. `<p>...</p>`),
 * so we strip HTML and render as plain text.
 *
 * Bootstrap 4.6 styled, no custom CSS needed.
 */

import React from 'react';

interface TaggingPanelProps {
  enabled: boolean;
  /** Customizable help text from DB field (therapy_chat_help_text) */
  helpText?: string;
}

/**
 * Strip HTML tags from a string and return plain text.
 * Handles common cases like `<p>text</p>` from DB field values.
 */
function stripHtml(html: string): string {
  const doc = new DOMParser().parseFromString(html, 'text/html');
  return doc.body.textContent?.trim() || '';
}

export const TaggingPanel: React.FC<TaggingPanelProps> = ({
  enabled,
  helpText,
}) => {
  if (!enabled || !helpText) return null;

  const cleanText = stripHtml(helpText);
  if (!cleanText) return null;

  return (
    <div className="mb-2">
      <small className="text-muted">
        <i className="fas fa-info-circle mr-1" />
        {cleanText}
      </small>
    </div>
  );
};

export default TaggingPanel;
