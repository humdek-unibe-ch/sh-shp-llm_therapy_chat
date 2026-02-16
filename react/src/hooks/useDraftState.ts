/**
 * Draft State Hook
 * =================
 *
 * Encapsulates all AI-draft modal state and handlers.
 * Keeps TherapistDashboard lean by extracting the draft workflow.
 */

import { useState, useCallback } from 'react';
import type { Draft } from '../types';

interface UseDraftStateOptions {
  createDraft: (conversationId: number | string) => Promise<{ success: boolean; draft?: Draft }>;
  sendMessage: (content: string) => Promise<void>;
  getConversationId: () => number | string | undefined;
}

export function useDraftState({ createDraft, sendMessage, getConversationId }: UseDraftStateOptions) {
  const [open, setOpen] = useState(false);
  const [text, setText] = useState('');
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [undoStack, setUndoStack] = useState<string[]>([]);

  const generate = useCallback(async () => {
    const convId = getConversationId();
    if (!convId) return;

    // Open modal immediately so the user sees loading state
    setOpen(true);
    setGenerating(true);
    setError(null);
    try {
      const response = await createDraft(convId);
      if (response.draft) {
        setText(response.draft.edited_content || response.draft.ai_content || '');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to generate draft');
    } finally {
      setGenerating(false);
    }
  }, [createDraft, getConversationId]);

  const regenerate = useCallback(async () => {
    const convId = getConversationId();
    if (!convId) return;

    if (text) setUndoStack(prev => [...prev, text]);

    setGenerating(true);
    setError(null);
    try {
      const response = await createDraft(convId);
      if (response.draft) {
        setText(response.draft.edited_content || response.draft.ai_content || '');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to regenerate draft');
    } finally {
      setGenerating(false);
    }
  }, [createDraft, getConversationId, text]);

  const undo = useCallback(() => {
    if (undoStack.length > 0) {
      setText(undoStack[undoStack.length - 1]);
      setUndoStack(prev => prev.slice(0, -1));
    }
  }, [undoStack]);

  const send = useCallback(async () => {
    if (!text.trim()) return;
    try {
      await sendMessage(text);
      setOpen(false);
      setText('');
      setError(null);
      setUndoStack([]);
    } catch (err) {
      console.error('Failed to send draft:', err);
    }
  }, [text, sendMessage]);

  const discard = useCallback(() => {
    setOpen(false);
    setText('');
    setError(null);
    setUndoStack([]);
  }, []);

  const retry = useCallback(() => {
    setError(null);
    generate();
  }, [generate]);

  return {
    open,
    text,
    generating,
    error,
    undoStack,
    setText,
    generate,
    regenerate,
    undo,
    send,
    discard,
    close: () => { setOpen(false); setError(null); },
    retry,
  };
}
