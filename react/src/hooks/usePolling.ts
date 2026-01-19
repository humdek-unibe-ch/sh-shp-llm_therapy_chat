/**
 * Polling Hook
 * =============
 * 
 * Provides polling functionality for near real-time updates.
 */

import { useEffect, useRef, useCallback } from 'react';

interface UsePollingOptions {
  /** Callback to execute on each poll */
  callback: () => Promise<void> | void;
  /** Polling interval in milliseconds */
  interval: number;
  /** Whether polling is enabled */
  enabled?: boolean;
  /** Run callback immediately on mount */
  immediate?: boolean;
}

interface UsePollingReturn {
  /** Start polling */
  start: () => void;
  /** Stop polling */
  stop: () => void;
  /** Whether polling is active */
  isPolling: boolean;
}

export function usePolling({
  callback,
  interval,
  enabled = true,
  immediate = false,
}: UsePollingOptions): UsePollingReturn {
  const intervalRef = useRef<number | null>(null);
  const isPollingRef = useRef(false);
  const callbackRef = useRef(callback);

  // Update callback ref when callback changes
  callbackRef.current = callback;

  /**
   * Start polling
   */
  const start = useCallback(() => {
    if (intervalRef.current !== null) return;

    isPollingRef.current = true;

    const poll = async () => {
      try {
        await callbackRef.current();
      } catch (err) {
        console.error('Polling error:', err);
      }
    };

    // Run immediately if requested
    if (immediate) {
      poll();
    }

    intervalRef.current = window.setInterval(poll, interval);
  }, [interval, immediate]);

  /**
   * Stop polling
   */
  const stop = useCallback(() => {
    if (intervalRef.current !== null) {
      window.clearInterval(intervalRef.current);
      intervalRef.current = null;
      isPollingRef.current = false;
    }
  }, []);

  // Start/stop based on enabled prop
  useEffect(() => {
    if (enabled) {
      start();
    } else {
      stop();
    }

    return stop;
  }, [enabled, start, stop]);

  // Update interval when it changes
  useEffect(() => {
    if (enabled && intervalRef.current !== null) {
      stop();
      start();
    }
  }, [interval, enabled, start, stop]);

  return {
    start,
    stop,
    isPolling: isPollingRef.current,
  };
}
