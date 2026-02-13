/**
 * NotesPanel – Clinical notes sidebar with list, add, edit, delete
 */

import React from 'react';
import { MarkdownRenderer } from '../shared/MarkdownRenderer';
import type { Note } from '../../types';
import type { TherapistDashboardLabels } from '../../types';

export interface NotesPanelProps {
  notes: Note[];
  newNote: string;
  onNewNoteChange: (value: string) => void;
  onAddNote: () => void | Promise<void>;
  editingNoteId: number | null;
  editingNoteText: string;
  onEditStart: (noteId: number, text: string) => void;
  onEditCancel: () => void;
  onEditTextChange: (value: string) => void;
  onEditSave: () => void | Promise<void>;
  onDeleteNote: (noteId: number) => void | Promise<void>;
  labels: TherapistDashboardLabels;
}

export const NotesPanel: React.FC<NotesPanelProps> = ({
  notes,
  newNote,
  onNewNoteChange,
  onAddNote,
  editingNoteId,
  editingNoteText,
  onEditStart,
  onEditCancel,
  onEditTextChange,
  onEditSave,
  onDeleteNote,
  labels,
}) => (
  <div className="card border-0 shadow-sm">
    <div className="card-header bg-light py-2">
      <h6 className="mb-0">
        <i className="fas fa-sticky-note mr-2" />
        {labels.notesHeading}
      </h6>
    </div>
    <div className="card-body p-2 tc-notes-list">
      {notes.length === 0 ? (
        <p className="text-muted text-center mb-0 small">No notes yet.</p>
      ) : (
        notes.map((n) => (
          <div key={n.id} className="tc-note-item mb-2 p-2 rounded">
            <div className="d-flex justify-content-between text-muted mb-1">
              <small className="font-weight-bold">{n.author_name}</small>
              <div className="d-flex align-items-center tc-flex-gap-sm">
                <small>{new Date(n.created_at).toLocaleDateString()}</small>
                <button
                  className="btn btn-link btn-sm p-0 text-muted"
                  title="Edit note"
                  onClick={() => onEditStart(n.id, n.content)}
                >
                  <i className="fas fa-pencil-alt tc-font-sm" />
                </button>
                <button
                  className="btn btn-link btn-sm p-0 text-danger"
                  title="Delete note"
                  onClick={() => onDeleteNote(n.id)}
                >
                  <i className="fas fa-trash-alt tc-font-sm" />
                </button>
              </div>
            </div>
            {editingNoteId === n.id ? (
              <div>
                <textarea
                  className="form-control form-control-sm mb-1"
                  rows={2}
                  value={editingNoteText}
                  onChange={(e) => onEditTextChange(e.target.value)}
                />
                <div className="d-flex tc-flex-gap-xs">
                  <button
                    className="btn btn-primary btn-sm py-0 px-2"
                    onClick={onEditSave}
                    disabled={!editingNoteText.trim()}
                  >
                    Save
                  </button>
                  <button className="btn btn-outline-secondary btn-sm py-0 px-2" onClick={onEditCancel}>
                    Cancel
                  </button>
                </div>
              </div>
            ) : (
              <>
                <div className="mb-0 small tc-markdown tc-note-content">
                  <MarkdownRenderer content={n.content} />
                </div>
                {n.last_edited_by_name && (
                  <small className="text-muted tc-font-xs">
                    <i className="fas fa-edit mr-1" />
                    Last edited by {n.last_edited_by_name}
                  </small>
                )}
              </>
            )}
          </div>
        ))
      )}
    </div>
    <div className="card-footer bg-white p-2">
      <textarea
        className="form-control form-control-sm mb-2"
        rows={2}
        value={newNote}
        onChange={(e) => onNewNoteChange(e.target.value)}
        placeholder={labels.addNotePlaceholder}
      />
      <button
        className="btn btn-outline-primary btn-sm"
        onClick={onAddNote}
        disabled={!newNote.trim()}
      >
        <i className="fas fa-plus mr-1" />
        {labels.addNoteButton}
      </button>
    </div>
  </div>
);

export default NotesPanel;
