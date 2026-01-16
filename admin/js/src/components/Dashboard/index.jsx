/**
 * Dashboard Component
 *
 * Main dashboard view with pipeline status and statistics.
 */

import { useCallback } from 'react';
import useStatus from '../../hooks/useStatus';
import usePipeline from '../../hooks/usePipeline';
import Card from '../common/Card';
import Button from '../common/Button';
import Badge from '../common/Badge';
import PhaseStepper from './PhaseStepper';
import PulseBar from './PulseBar';
import StatsCards from './StatsCards';
import LiveTerminal from './LiveTerminal';

/**
 * Play icon SVG.
 */
const PlayIcon = () => (
  <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
    <path
      fillRule="evenodd"
      d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z"
      clipRule="evenodd"
    />
  </svg>
);

/**
 * Stop icon SVG.
 */
const StopIcon = () => (
  <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
    <path
      fillRule="evenodd"
      d="M10 18a8 8 0 100-16 8 8 0 000 16zM8 7a1 1 0 00-1 1v4a1 1 0 001 1h4a1 1 0 001-1V8a1 1 0 00-1-1H8z"
      clipRule="evenodd"
    />
  </svg>
);

/**
 * Refresh icon SVG.
 */
const RefreshIcon = () => (
  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
    />
  </svg>
);

/**
 * Dashboard component.
 *
 * @param {Object} props - Component props.
 * @param {Object} props.vibeAiData - Localized data from WordPress.
 * @returns {JSX.Element} Dashboard element.
 */
export default function Dashboard({ vibeAiData }) {
  // Hooks
  const {
    status,
    stats,
    logs,
    loading: statusLoading,
    error: statusError,
    isRunning,
    refresh: refreshStatus,
  } = useStatus();

  const {
    start,
    stop,
    starting,
    stopping,
    error: pipelineError,
    phases,
  } = usePipeline();

  // Handlers
  const handleStart = useCallback(async () => {
    try {
      await start();
      refreshStatus();
    } catch (err) {
      // Error is handled by usePipeline
    }
  }, [start, refreshStatus]);

  const handleStop = useCallback(async () => {
    try {
      await stop();
      refreshStatus();
    } catch (err) {
      // Error is handled by usePipeline
    }
  }, [stop, refreshStatus]);

  const handleFullReindex = useCallback(async () => {
    try {
      await start({ full_reindex: true });
      refreshStatus();
    } catch (err) {
      // Error is handled by usePipeline
    }
  }, [start, refreshStatus]);

  // Error display
  const error = statusError || pipelineError;

  return (
    <div className="p-6 space-y-6">
      {/* Page header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Dashboard</h1>
          <p className="mt-1 text-slate-500">
            Monitor and control the entity extraction pipeline
          </p>
        </div>

        <div className="flex items-center gap-3">
          <Button
            variant="ghost"
            size="sm"
            onClick={refreshStatus}
            leftIcon={<RefreshIcon />}
          >
            Refresh
          </Button>

          {isRunning ? (
            <Button
              variant="danger"
              onClick={handleStop}
              loading={stopping}
              leftIcon={<StopIcon />}
            >
              Stop Pipeline
            </Button>
          ) : (
            <Button.Group>
              <Button
                variant="primary"
                onClick={handleStart}
                loading={starting}
                leftIcon={<PlayIcon />}
              >
                Start Pipeline
              </Button>
              <Button
                variant="secondary"
                onClick={handleFullReindex}
                loading={starting}
                title="Reprocess all posts"
              >
                Full Reindex
              </Button>
            </Button.Group>
          )}
        </div>
      </div>

      {/* Error alert */}
      {error && (
        <div className="p-4 bg-red-50 border border-red-200 rounded-lg">
          <div className="flex items-start gap-3">
            <svg
              className="w-5 h-5 text-red-500 mt-0.5"
              fill="currentColor"
              viewBox="0 0 20 20"
            >
              <path
                fillRule="evenodd"
                d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                clipRule="evenodd"
              />
            </svg>
            <div>
              <h3 className="font-medium text-red-800">Error</h3>
              <p className="text-sm text-red-600 mt-1">{error}</p>
            </div>
          </div>
        </div>
      )}

      {/* Statistics cards */}
      <StatsCards stats={stats} loading={statusLoading} />

      {/* Pipeline status card */}
      <Card padding="lg">
        <Card.Header
          title="Pipeline Status"
          subtitle={
            isRunning
              ? `Processing: ${status.pipeline?.current_item || 'Initializing...'}`
              : 'Pipeline is idle'
          }
          action={
            <Badge.Status
              status={isRunning ? 'processing' : status.pipeline?.error ? 'error' : 'inactive'}
            />
          }
        />

        <Card.Body>
          {/* Phase stepper */}
          <div className="mb-8">
            <PhaseStepper
              currentPhase={status.pipeline?.phase}
              errorPhase={status.pipeline?.error ? status.pipeline.phase : null}
              isRunning={isRunning}
              phases={phases}
            />
          </div>

          {/* Progress bar */}
          <PulseBar
            progress={status.pipeline?.progress || 0}
            isRunning={isRunning}
            startedAt={status.pipeline?.started_at}
            label={
              isRunning
                ? `${status.pipeline?.processed_items || 0} / ${status.pipeline?.total_items || 0} items processed`
                : 'Ready to start'
            }
            showPercentage
            showEta={isRunning}
          />
        </Card.Body>
      </Card>

      {/* Live terminal */}
      <Card padding="none">
        <LiveTerminal
          logs={logs}
          maxEntries={50}
          autoScroll
          title="Pipeline Output"
        />
      </Card>

      {/* Quick actions */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card hover className="cursor-pointer" onClick={() => window.location.href = '#/entities'}>
          <div className="flex items-center gap-4">
            <div className="p-3 bg-wp-primary/10 rounded-lg">
              <svg className="w-6 h-6 text-wp-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
              </svg>
            </div>
            <div>
              <h3 className="font-semibold text-slate-800">Browse Entities</h3>
              <p className="text-sm text-slate-500">View and manage extracted entities</p>
            </div>
          </div>
        </Card>

        <Card hover className="cursor-pointer" onClick={() => window.location.href = '#/settings'}>
          <div className="flex items-center gap-4">
            <div className="p-3 bg-green-100 rounded-lg">
              <svg className="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
            </div>
            <div>
              <h3 className="font-semibold text-slate-800">Configure Settings</h3>
              <p className="text-sm text-slate-500">API keys, prompts, and options</p>
            </div>
          </div>
        </Card>

        <Card hover className="cursor-pointer" onClick={() => window.location.href = '#/logs'}>
          <div className="flex items-center gap-4">
            <div className="p-3 bg-amber-100 rounded-lg">
              <svg className="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </div>
            <div>
              <h3 className="font-semibold text-slate-800">View Full Logs</h3>
              <p className="text-sm text-slate-500">Detailed processing history</p>
            </div>
          </div>
        </Card>
      </div>
    </div>
  );
}
