/**
 * Note Editor Hook
 * =================
 *
 * Encapsulates clinical note CRUD state and handlers.
 */

import { useState, useCallback } from 'react';
import type { Note } from '../types';

interface UseNoteEditorOptions {
  api: {
    addNote: (conversationId: number | string, content: string) => Promise<{ note_id: number }>;
    editNote: (noteId: number, content: string) => Promise<{ success: boolean }>;
    deleteNote: (noteId: number) => Promise<{ success: boolean }>;
  };
  getConversationId: () => number | string | undefined;
  onNoteAdded: (note: Note) => void;
  onNoteUpdated: (id: number, update: Partial<Note>) => void;
  onNoteDeleted: (id: number) => void;
}

export function useNoteEditor({ api, getConversationId, onNoteAdded, onNoteUpdated, onNoteDeleted }: UseNoteEditorOptions) {
  const [newNote, setNewNote] = useState('');
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editingText, setEditingText] = useState('');

  const add = useCallback(async () => {
    const convId = getConversationId();
    if (!convId || !newNote.trim()) return;

    try {
      const response = await api.addNote(convId, newNote);
      onNoteAdded({
        id: response.note_id,
        id_llmConversations: typeof convId === 'string' ? parseInt(convId, 10) : convId,
        id_users: 0,
        content: newNote,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
      });
      setNewNote('');
    } catch (err) {
      console.error('Failed to add note:', err);
    }
  }, [newNote, api, getConversationId, onNoteAdded]);

  const save = useCallback(async () => {
    if (!editingId || !editingText.trim()) return;

    try {
      await api.editNote(editingId, editingText);
      onNoteUpdated(editingId, { content: editingText });
      setEditingId(null);
      setEditingText('');
    } catch (err) {
      console.error('Failed to edit note:', err);
    }
  }, [editingId, editingText, api, onNoteUpdated]);

  const remove = useCallback(async (noteId: number) => {
    try {
      await api.deleteNote(noteId);
      onNoteDeleted(noteId);
    } catch (err) {
      console.error('Failed to delete note:', err);
    }
  }, [api, onNoteDeleted]);

  const startEditing = useCallback((noteId: number, text: string) => {
    setEditingId(noteId);
    setEditingText(text);
  }, []);

  const cancelEditing = useCallback(() => {
    setEditingId(null);
    setEditingText('');
  }, []);

  return {
    newNote,
    setNewNote,
    editingId,
    editingText,
    setEditingText,
    add,
    save,
    remove,
    startEditing,
    cancelEditing,
  };
}
