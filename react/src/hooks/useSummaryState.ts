/**
 * Summary State Hook
 * ===================
 *
 * Encapsulates all summary-modal state and handlers.
 */

import { useState, useCallback } from 'react';
import type { Note } from '../types';

interface UseSummaryStateOptions {
  generateSummary: (conversationId: number | string) => Promise<{ summary: string }>;
  addNote: (conversationId: number | string, content: string) => Promise<{ note_id: number }>;
  getConversationId: () => number | string | undefined;
  onNoteAdded: (note: Note) => void;
}

export function useSummaryState({ generateSummary, addNote, getConversationId, onNoteAdded }: UseSummaryStateOptions) {
  const [open, setOpen] = useState(false);
  const [generating, setGenerating] = useState(false);
  const [text, setText] = useState('');
  const [error, setError] = useState<string | null>(null);

  const generate = useCallback(async () => {
    const convId = getConversationId();
    if (!convId) return;

    // Open modal immediately so the user sees loading state
    setOpen(true);
    setGenerating(true);
    setError(null);
    try {
      const response = await generateSummary(convId);
      setText(response.summary);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to generate summary');
    } finally {
      setGenerating(false);
    }
  }, [generateSummary, getConversationId]);

  const saveAsNote = useCallback(async () => {
    const convId = getConversationId();
    if (!convId || !text.trim()) return;

    try {
      const response = await addNote(convId, text);
      onNoteAdded({
        id: response.note_id,
        id_llmConversations: typeof convId === 'string' ? parseInt(convId, 10) : convId,
        id_users: 0,
        content: text,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
      });
      setOpen(false);
      setText('');
      setError(null);
    } catch (err) {
      console.error('Failed to save summary as note:', err);
    }
  }, [text, addNote, getConversationId, onNoteAdded]);

  const retry = useCallback(() => {
    setError(null);
    generate();
  }, [generate]);

  const close = useCallback(() => {
    setOpen(false);
    setText('');
    setError(null);
  }, []);

  return { open, generating, text, error, generate, saveAsNote, retry, close };
}
