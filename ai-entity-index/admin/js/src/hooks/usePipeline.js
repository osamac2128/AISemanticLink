/**
 * usePipeline Hook
 *
 * Manages pipeline start/stop operations and status.
 */

import { useState, useCallback, useRef } from 'react';
import { startPipeline, stopPipeline, getPipelineStatus } from '../api/client';

/**
 * Pipeline phases in order.
 */
export const PIPELINE_PHASES = [
  { key: 'queue', label: 'Queue', description: 'Building post queue' },
  { key: 'extract', label: 'Extract', description: 'Extracting entities from content' },
  { key: 'resolve', label: 'Resolve', description: 'Resolving entity identities' },
  { key: 'enrich', label: 'Enrich', description: 'Enriching entity data' },
  { key: 'link', label: 'Link', description: 'Creating entity links' },
  { key: 'complete', label: 'Complete', description: 'Finalizing results' },
];

/**
 * Custom hook for pipeline control.
 *
 * @returns {Object} Pipeline state and control functions.
 */
export function usePipeline() {
  const [status, setStatus] = useState({
    running: false,
    phase: null,
    progress: 0,
    current_item: null,
    total_items: 0,
    processed_items: 0,
    started_at: null,
    eta: null,
    error: null,
  });
  const [starting, setStarting] = useState(false);
  const [stopping, setStopping] = useState(false);
  const [error, setError] = useState(null);

  const mountedRef = useRef(true);

  /**
   * Start the pipeline with optional options.
   *
   * @param {Object} options - Pipeline options.
   * @param {boolean} options.full_reindex - Whether to reindex all posts.
   * @param {number[]} options.post_ids - Specific post IDs to process.
   */
  const start = useCallback(async (options = {}) => {
    setStarting(true);
    setError(null);

    try {
      const result = await startPipeline(options);

      if (mountedRef.current) {
        setStatus((prev) => ({
          ...prev,
          running: true,
          phase: 'queue',
          progress: 0,
          started_at: new Date().toISOString(),
          error: null,
          ...result.status,
        }));
      }

      return result;
    } catch (err) {
      if (mountedRef.current) {
        setError(err.message || 'Failed to start pipeline');
      }
      throw err;
    } finally {
      if (mountedRef.current) {
        setStarting(false);
      }
    }
  }, []);

  /**
   * Stop the running pipeline.
   */
  const stop = useCallback(async () => {
    setStopping(true);
    setError(null);

    try {
      const result = await stopPipeline();

      if (mountedRef.current) {
        setStatus((prev) => ({
          ...prev,
          running: false,
          phase: null,
          ...result.status,
        }));
      }

      return result;
    } catch (err) {
      if (mountedRef.current) {
        setError(err.message || 'Failed to stop pipeline');
      }
      throw err;
    } finally {
      if (mountedRef.current) {
        setStopping(false);
      }
    }
  }, []);

  /**
   * Refresh pipeline status.
   */
  const refresh = useCallback(async () => {
    try {
      const result = await getPipelineStatus();

      if (mountedRef.current) {
        setStatus((prev) => ({
          ...prev,
          ...result,
        }));
        setError(null);
      }

      return result;
    } catch (err) {
      if (mountedRef.current) {
        setError(err.message || 'Failed to fetch pipeline status');
      }
      throw err;
    }
  }, []);

  /**
   * Update status from external source (e.g., useStatus hook).
   */
  const updateStatus = useCallback((newStatus) => {
    setStatus((prev) => ({
      ...prev,
      ...newStatus,
    }));
  }, []);

  /**
   * Get the current phase index.
   */
  const getCurrentPhaseIndex = useCallback(() => {
    if (!status.phase) return -1;
    return PIPELINE_PHASES.findIndex((p) => p.key === status.phase);
  }, [status.phase]);

  /**
   * Check if a phase is complete.
   */
  const isPhaseComplete = useCallback((phaseKey) => {
    const currentIndex = getCurrentPhaseIndex();
    const phaseIndex = PIPELINE_PHASES.findIndex((p) => p.key === phaseKey);
    return phaseIndex < currentIndex;
  }, [getCurrentPhaseIndex]);

  /**
   * Check if a phase is active.
   */
  const isPhaseActive = useCallback((phaseKey) => {
    return status.running && status.phase === phaseKey;
  }, [status.running, status.phase]);

  /**
   * Calculate ETA based on progress.
   */
  const calculateEta = useCallback(() => {
    if (!status.running || !status.started_at || status.progress === 0) {
      return null;
    }

    const startTime = new Date(status.started_at).getTime();
    const elapsed = Date.now() - startTime;
    const rate = status.progress / elapsed;

    if (rate === 0) return null;

    const remaining = (100 - status.progress) / rate;
    return new Date(Date.now() + remaining);
  }, [status.running, status.started_at, status.progress]);

  return {
    // State
    status,
    running: status.running,
    phase: status.phase,
    progress: status.progress,
    starting,
    stopping,
    error,
    phases: PIPELINE_PHASES,

    // Actions
    start,
    stop,
    refresh,
    updateStatus,

    // Utilities
    getCurrentPhaseIndex,
    isPhaseComplete,
    isPhaseActive,
    calculateEta,
  };
}

export default usePipeline;
