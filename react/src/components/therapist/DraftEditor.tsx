/**
 * DraftEditor – AI draft editing modal with contentEditable and rich text toolbar
 *
 * Contains ContentEditableDraft subcomponent that avoids React re-render loops
 * by using a ref so React never re-writes the DOM while the user is typing.
 */

import React, { useRef, useEffect } from 'react';

export interface DraftEditorModalProps {
  open: boolean;
  draftText: string;
  onDraftTextChange: (value: string) => void;
  draftGenerating: boolean;
  draftError: string | null;
  draftUndoStack: string[];
  hasActiveDraft: boolean;
  subjectName?: string;
  onRegenerate: () => void | Promise<void>;
  onUndo: () => void;
  onSend: () => void | Promise<void>;
  onDiscard: () => void | Promise<void>;
  onClose: () => void;
  onRetry: () => void | Promise<void>;
}

/** Simple markdown → HTML for the draft editor initial render */
function markdownToHtml(md: string): string {
  if (!md) return '';
  let html = md
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/^### (.+)$/gm, '<h5>$1</h5>')
    .replace(/^## (.+)$/gm, '<h4>$1</h4>')
    .replace(/^# (.+)$/gm, '<h3>$1</h3>')
    .replace(/^---+$/gm, '<hr/>')
    .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\n\n/g, '</p><p>')
    .replace(/\n/g, '<br/>');
  html = '<p>' + html + '</p>';
  html = html
    .replace(/<p><\/p>/g, '')
    .replace(/<p>(<h[3-5]>)/g, '$1')
    .replace(/(<\/h[3-5]>)<\/p>/g, '$1');
  return html;
}

/** ContentEditable wrapper that avoids React re-render loops */
const ContentEditableDraft: React.FC<{
  value: string;
  onChange: (v: string) => void;
}> = ({ value, onChange }) => {
  const elRef = useRef<HTMLDivElement>(null);
  const internalRef = useRef<string | null>(null);

  useEffect(() => {
    if (elRef.current && value !== internalRef.current) {
      internalRef.current = value;
      elRef.current.innerHTML = markdownToHtml(value);
    }
  }, [value]);

  return (
    <div
      ref={elRef}
      className="form-control tc-draft-editor tc-markdown tc-draft-editor-scroll"
      contentEditable
      suppressContentEditableWarning
      onInput={() => {
        if (elRef.current) {
          const text = elRef.current.innerText;
          internalRef.current = text;
          onChange(text);
        }
      }}
    />
  );
};

export const DraftEditorModal: React.FC<DraftEditorModalProps> = ({
  open,
  draftText,
  onDraftTextChange,
  draftGenerating,
  draftError,
  draftUndoStack,
  hasActiveDraft,
  subjectName,
  onRegenerate,
  onUndo,
  onSend,
  onDiscard,
  onClose,
  onRetry,
}) => {
  if (!open) return null;

  const handleClose = () => {
    if (hasActiveDraft) onDiscard();
    else onClose();
  };

  return (
    <div className="tc-modal-overlay" tabIndex={-1}>
      <div className="tc-modal-box">
        <div className="tc-modal-header bg-info text-white">
          <h5 className="mb-0">
            <i className="fas fa-robot mr-2" />
            AI Draft Response
            {subjectName && (
              <small className="ml-2 font-weight-normal">for {subjectName}</small>
            )}
          </h5>
          <button type="button" className="close text-white" onClick={handleClose}>
            <span>&times;</span>
          </button>
        </div>
        <div className="tc-modal-body">
          {draftGenerating ? (
            <div className="text-center py-5 d-flex flex-column align-items-center justify-content-center tc-flex-1">
              <div className="spinner-border text-info mb-3 tc-spinner-lg" role="status" />
              <p className="text-muted mb-0">Generating AI draft response...</p>
              <small className="text-muted mt-1">This may take a moment.</small>
            </div>
          ) : draftError ? (
            <div className="d-flex flex-column align-items-center justify-content-center tc-flex-1">
              <div className="alert alert-danger mb-3 tc-alert-max-width">
                <i className="fas fa-exclamation-triangle mr-2" />
                {draftError}
              </div>
              <button className="btn btn-info" onClick={onRetry}>
                <i className="fas fa-redo mr-1" />
                Retry
              </button>
            </div>
          ) : (
            <>
              <p className="text-muted small mb-2 flex-shrink-0">
                <i className="fas fa-info-circle mr-1" />
                Review and edit the AI-generated response before sending it to the patient.
              </p>
              <div className="d-flex justify-content-between mb-2 flex-shrink-0 flex-wrap tc-flex-gap-sm">
                <div className="btn-toolbar" role="toolbar">
                  <div className="btn-group btn-group-sm mr-2">
                    <button
                      type="button"
                      className="btn btn-outline-secondary"
                      title="Bold"
                      onMouseDown={(e) => {
                        e.preventDefault();
                        document.execCommand('bold');
                      }}
                    >
                      <i className="fas fa-bold" />
                    </button>
                    <button
                      type="button"
                      className="btn btn-outline-secondary"
                      title="Italic"
                      onMouseDown={(e) => {
                        e.preventDefault();
                        document.execCommand('italic');
                      }}
                    >
                      <i className="fas fa-italic" />
                    </button>
                    <button
                      type="button"
                      className="btn btn-outline-secondary"
                      title="Underline"
                      onMouseDown={(e) => {
                        e.preventDefault();
                        document.execCommand('underline');
                      }}
                    >
                      <i className="fas fa-underline" />
                    </button>
                  </div>
                  <div className="btn-group btn-group-sm mr-2">
                    <button
                      type="button"
                      className="btn btn-outline-secondary"
                      title="Bulleted list"
                      onMouseDown={(e) => {
                        e.preventDefault();
                        document.execCommand('insertUnorderedList');
                      }}
                    >
                      <i className="fas fa-list-ul" />
                    </button>
                    <button
                      type="button"
                      className="btn btn-outline-secondary"
                      title="Numbered list"
                      onMouseDown={(e) => {
                        e.preventDefault();
                        document.execCommand('insertOrderedList');
                      }}
                    >
                      <i className="fas fa-list-ol" />
                    </button>
                  </div>
                  <div className="btn-group btn-group-sm">
                    <button
                      type="button"
                      className="btn btn-outline-secondary"
                      title="Remove formatting"
                      onMouseDown={(e) => {
                        e.preventDefault();
                        document.execCommand('removeFormat');
                      }}
                    >
                      <i className="fas fa-eraser" />
                    </button>
                  </div>
                </div>
                <div className="d-flex tc-flex-gap-xs">
                  {draftUndoStack.length > 0 && (
                    <button
                      type="button"
                      className="btn btn-outline-warning btn-sm"
                      title="Undo: restore the previous draft before last regeneration"
                      onClick={onUndo}
                    >
                      <i className="fas fa-undo mr-1" />
                      Undo
                    </button>
                  )}
                  <button
                    type="button"
                    className="btn btn-outline-info btn-sm"
                    title="Regenerate a new AI draft (current text will be saved for undo)"
                    onClick={onRegenerate}
                  >
                    <i className="fas fa-sync-alt mr-1" />
                    Regenerate
                  </button>
                </div>
              </div>
              <ContentEditableDraft value={draftText} onChange={onDraftTextChange} />
              <div className="d-flex justify-content-between mt-2 flex-shrink-0">
                <small className="text-muted">{draftText.length} characters</small>
              </div>
            </>
          )}
        </div>
        <div className="tc-modal-footer">
          <button
            className="btn btn-outline-secondary"
            onClick={handleClose}
            disabled={draftGenerating}
          >
            <i className="fas fa-times mr-1" />
            Discard
          </button>
          <button
            className="btn btn-primary"
            onClick={onSend}
            disabled={draftGenerating || !draftText.trim() || !!draftError}
          >
            <i className="fas fa-paper-plane mr-1" />
            Send to Patient
          </button>
        </div>
      </div>
    </div>
  );
};

export default DraftEditorModal;
