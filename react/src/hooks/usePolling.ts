/**
 * Polling Hook
 * =============
 *
 * Provides interval-based polling for near real-time updates.
 * Starts/stops automatically based on the `enabled` flag.
 */

import { useEffect, useRef, useCallback } from 'react';

interface UsePollingOptions {
  callback: () => Promise<void> | void;
  interval: number;
  enabled?: boolean;
}

export function usePolling({ callback, interval, enabled = true }: UsePollingOptions): void {
  const cbRef = useRef(callback);
  cbRef.current = callback;

  const tick = useCallback(async () => {
    try {
      await cbRef.current();
    } catch (err) {
      console.error('Polling error:', err);
    }
  }, []);

  useEffect(() => {
    if (!enabled || interval <= 0) return;
    const id = window.setInterval(tick, interval);
    return () => window.clearInterval(id);
  }, [enabled, interval, tick]);
}
