/**
 * Summary Modal Component
 * =======================
 *
 * Modal for displaying and saving AI-generated conversation summaries.
 * Uses the shared modal system for consistent styling and behavior.
 */

import React from 'react';
import { Modal, ModalHeader, ModalBody, ModalFooter, ModalLoadingState, ModalErrorState } from '../shared/Modal';
import { MarkdownRenderer } from '../shared/MarkdownRenderer';

export interface SummaryModalProps {
  open: boolean;
  onClose: () => void;
  generating: boolean;
  error: string | null;
  summaryText: string;
  subjectName?: string;
  onRetry: () => void;
  onSaveAsNote: () => void;
}

export const SummaryModal: React.FC<SummaryModalProps> = ({
  open,
  onClose,
  generating,
  error,
  summaryText,
  subjectName,
  onRetry,
  onSaveAsNote,
}) => (
  <Modal open={open} onClose={onClose} title="Conversation Summary">
    <ModalHeader
      title={
        <>
          <i className="fas fa-file-alt mr-2" />
          Conversation Summary
          {subjectName && (
            <small className="ml-2 font-weight-normal">for {subjectName}</small>
          )}
        </>
      }
      onClose={onClose}
    />

    <ModalBody>
      {generating ? (
        <ModalLoadingState
          message="Generating conversation summary..."
          subMessage="This may take a moment."
          size="lg"
          variant="secondary"
        />
      ) : error ? (
        <ModalErrorState error={error} onRetry={onRetry} retryText="Retry" variant="danger" />
      ) : (
        <>
          <p className="text-muted small mb-2 flex-shrink-0">
            <i className="fas fa-info-circle mr-1" />
            AI-generated clinical summary. You can save it as a note.
          </p>
          <div className="border rounded p-3 bg-light tc-draft-editor tc-markdown tc-summary-content">
            <MarkdownRenderer content={summaryText} />
          </div>
        </>
      )}
    </ModalBody>

    <ModalFooter>
      <button className="btn btn-outline-secondary" onClick={onClose}>
        Close
      </button>
      <button
        className="btn btn-success"
        onClick={onSaveAsNote}
        disabled={generating || !summaryText.trim() || !!error}
      >
        <i className="fas fa-save mr-1" />
        Save as Clinical Note
      </button>
    </ModalFooter>
  </Modal>
);

export default SummaryModal;
