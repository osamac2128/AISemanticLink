/**
 * useStatus Hook
 *
 * Fetches and polls the pipeline status.
 * Automatically polls every 2 seconds when pipeline is running.
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import { fetchStatus } from '../api/client';

/**
 * Default status state.
 */
const DEFAULT_STATUS = {
  pipeline: {
    running: false,
    phase: null,
    progress: 0,
    current_item: null,
    total_items: 0,
    processed_items: 0,
    started_at: null,
    eta: null,
    error: null,
  },
  stats: {
    total_entities: 0,
    total_mentions: 0,
    avg_confidence: 0,
    posts_pending: 0,
    posts_processed: 0,
  },
  logs: [],
};

/**
 * Polling interval in milliseconds.
 */
const POLL_INTERVAL = 2000;

/**
 * Custom hook for pipeline status management.
 *
 * @param {Object} options - Hook options.
 * @param {boolean} options.autoStart - Whether to fetch immediately on mount.
 * @returns {Object} Status state and control functions.
 */
export function useStatus(options = {}) {
  const { autoStart = true } = options;

  const [status, setStatus] = useState(DEFAULT_STATUS);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const pollIntervalRef = useRef(null);
  const mountedRef = useRef(true);

  /**
   * Fetch the current status from the API.
   */
  const refresh = useCallback(async () => {
    try {
      const data = await fetchStatus();

      if (mountedRef.current) {
        setStatus((prev) => ({
          ...prev,
          ...data,
          pipeline: {
            ...prev.pipeline,
            ...data.pipeline,
          },
          stats: {
            ...prev.stats,
            ...data.stats,
          },
          logs: data.logs || prev.logs,
        }));
        setError(null);
      }
    } catch (err) {
      if (mountedRef.current) {
        setError(err.message || 'Failed to fetch status');
      }
    } finally {
      if (mountedRef.current) {
        setLoading(false);
      }
    }
  }, []);

  /**
   * Start polling for status updates.
   */
  const startPolling = useCallback(() => {
    if (pollIntervalRef.current) {
      return;
    }

    pollIntervalRef.current = setInterval(() => {
      refresh();
    }, POLL_INTERVAL);
  }, [refresh]);

  /**
   * Stop polling for status updates.
   */
  const stopPolling = useCallback(() => {
    if (pollIntervalRef.current) {
      clearInterval(pollIntervalRef.current);
      pollIntervalRef.current = null;
    }
  }, []);

  // Effect: Initial fetch
  useEffect(() => {
    mountedRef.current = true;

    if (autoStart) {
      refresh();
    }

    return () => {
      mountedRef.current = false;
    };
  }, [autoStart, refresh]);

  // Effect: Auto-poll when pipeline is running
  useEffect(() => {
    if (status.pipeline?.running) {
      startPolling();
    } else {
      stopPolling();
    }

    return () => {
      stopPolling();
    };
  }, [status.pipeline?.running, startPolling, stopPolling]);

  return {
    // State
    status,
    pipeline: status.pipeline,
    stats: status.stats,
    logs: status.logs,
    loading,
    error,
    isRunning: status.pipeline?.running || false,

    // Actions
    refresh,
    startPolling,
    stopPolling,
  };
}

export default useStatus;
