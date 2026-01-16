/**
 * KBOverview Component
 *
 * Dashboard view for Knowledge Base with stats, pipeline status, and quick actions.
 */

import { useCallback, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useKBStatus, useKBReindex } from '../../hooks/useKB';
import Card from '../common/Card';
import Button from '../common/Button';
import Badge from '../common/Badge';
import PulseBar from '../Dashboard/PulseBar';

/**
 * Document icon SVG.
 */
const DocumentIcon = () => (
  <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
    />
  </svg>
);

/**
 * Chunk icon SVG.
 */
const ChunkIcon = () => (
  <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"
    />
  </svg>
);

/**
 * Check icon SVG.
 */
const CheckIcon = () => (
  <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
    />
  </svg>
);

/**
 * Error icon SVG.
 */
const ErrorIcon = () => (
  <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
    />
  </svg>
);

/**
 * Clock icon SVG.
 */
const ClockIcon = () => (
  <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
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
 * Format large numbers with K/M suffixes.
 *
 * @param {number} value - Number to format.
 * @returns {string} Formatted number.
 */
function formatNumber(value) {
  if (value >= 1000000) {
    return `${(value / 1000000).toFixed(1)}M`;
  }
  if (value >= 1000) {
    return `${(value / 1000).toFixed(1)}K`;
  }
  return value?.toLocaleString() || '0';
}

/**
 * Format relative time.
 *
 * @param {string} timestamp - ISO timestamp.
 * @returns {string} Formatted relative time.
 */
function formatRelativeTime(timestamp) {
  if (!timestamp) return 'Never';

  const date = new Date(timestamp);
  const now = new Date();
  const diff = Math.floor((now - date) / 1000);

  if (diff < 60) return 'Just now';
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;

  return date.toLocaleDateString();
}

/**
 * Stats Cards component for KB overview.
 */
function KBStatsCards({ stats = {}, loading = false }) {
  const cards = [
    {
      label: 'Total Documents',
      value: formatNumber(stats.total_docs || 0),
      icon: <DocumentIcon />,
      color: 'text-wp-primary',
      bgColor: 'bg-wp-primary/10',
    },
    {
      label: 'Total Chunks',
      value: formatNumber(stats.total_chunks || 0),
      icon: <ChunkIcon />,
      color: 'text-blue-600',
      bgColor: 'bg-blue-100',
    },
    {
      label: 'Indexed',
      value: formatNumber(stats.indexed || 0),
      icon: <CheckIcon />,
      color: 'text-green-600',
      bgColor: 'bg-green-100',
    },
    {
      label: 'Failed',
      value: formatNumber(stats.failed || 0),
      icon: <ErrorIcon />,
      color: 'text-red-600',
      bgColor: 'bg-red-100',
    },
    {
      label: 'Last Run',
      value: formatRelativeTime(stats.last_run),
      icon: <ClockIcon />,
      color: 'text-slate-600',
      bgColor: 'bg-slate-100',
    },
  ];

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
      {cards.map((card) => (
        <Card key={card.label} className="relative overflow-hidden">
          <div className="flex items-start justify-between">
            <div className="flex-1">
              <p className="text-sm font-medium text-slate-500">{card.label}</p>
              {loading ? (
                <div className="h-8 w-20 bg-slate-200 animate-pulse rounded mt-1" />
              ) : (
                <p className="mt-1 text-2xl font-bold text-slate-900">
                  {card.value}
                </p>
              )}
            </div>
            <div className={`flex-shrink-0 p-2.5 rounded-lg ${card.bgColor} ${card.color}`}>
              {card.icon}
            </div>
          </div>
        </Card>
      ))}
    </div>
  );
}

/**
 * Recent Activity component.
 */
function RecentActivity({ activity = [] }) {
  if (!activity || activity.length === 0) {
    return (
      <div className="text-center py-8 text-slate-500">
        <DocumentIcon className="mx-auto w-12 h-12 text-slate-300 mb-3" />
        <p>No recent activity</p>
      </div>
    );
  }

  return (
    <div className="divide-y divide-slate-200">
      {activity.slice(0, 10).map((item, index) => (
        <div key={item.id || index} className="py-3 flex items-center gap-3">
          <div
            className={`
              w-2 h-2 rounded-full flex-shrink-0
              ${item.type === 'indexed' ? 'bg-green-500' : ''}
              ${item.type === 'error' ? 'bg-red-500' : ''}
              ${item.type === 'pending' ? 'bg-yellow-500' : ''}
              ${!['indexed', 'error', 'pending'].includes(item.type) ? 'bg-slate-400' : ''}
            `}
          />
          <div className="flex-1 min-w-0">
            <p className="text-sm text-slate-700 truncate">{item.title}</p>
            <p className="text-xs text-slate-500">{item.message}</p>
          </div>
          <span className="text-xs text-slate-400 flex-shrink-0">
            {formatRelativeTime(item.timestamp)}
          </span>
        </div>
      ))}
    </div>
  );
}

/**
 * KBOverview main component.
 *
 * @param {Object} props - Component props.
 * @param {Object} props.vibeAiData - Localized data from WordPress.
 * @returns {JSX.Element} KBOverview element.
 */
export default function KBOverview({ vibeAiData }) {
  const navigate = useNavigate();

  // Fetch KB status
  const { data: status, isLoading, error, refetch } = useKBStatus();

  // Reindex mutation
  const reindexMutation = useKBReindex();

  // Derived state
  const isRunning = status?.pipeline?.running || false;
  const stats = status?.stats || {};
  const activity = status?.recent_activity || [];

  // Handle reindex
  const handleReindex = useCallback(async () => {
    try {
      await reindexMutation.mutateAsync({ full: true });
      refetch();
    } catch (err) {
      // Error handled by mutation
    }
  }, [reindexMutation, refetch]);

  return (
    <div className="p-6 space-y-6">
      {/* Page header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Knowledge Base</h1>
          <p className="mt-1 text-slate-500">
            Manage document indexing for AI-powered search
          </p>
        </div>

        <div className="flex items-center gap-3">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => refetch()}
            leftIcon={<RefreshIcon />}
          >
            Refresh
          </Button>

          <Button
            variant="primary"
            onClick={handleReindex}
            loading={reindexMutation.isLoading}
            disabled={isRunning}
          >
            {isRunning ? 'Indexing...' : 'Reindex All'}
          </Button>
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
              <p className="text-sm text-red-600 mt-1">{error.message || 'Failed to load KB status'}</p>
            </div>
          </div>
        </div>
      )}

      {/* Statistics cards */}
      <KBStatsCards stats={stats} loading={isLoading} />

      {/* Pipeline status card */}
      <Card padding="lg">
        <Card.Header
          title="Pipeline Status"
          subtitle={
            isRunning
              ? `Processing: ${status?.pipeline?.current_item || 'Initializing...'}`
              : 'Pipeline is idle'
          }
          action={
            <Badge.Status
              status={isRunning ? 'processing' : status?.pipeline?.error ? 'error' : 'inactive'}
            />
          }
        />

        <Card.Body>
          <PulseBar
            progress={status?.pipeline?.progress || 0}
            isRunning={isRunning}
            startedAt={status?.pipeline?.started_at}
            label={
              isRunning
                ? `${status?.pipeline?.processed_items || 0} / ${status?.pipeline?.total_items || 0} documents processed`
                : 'Ready to index'
            }
            showPercentage
            showEta={isRunning}
          />
        </Card.Body>
      </Card>

      {/* Quick actions and recent activity */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Quick Actions */}
        <Card padding="lg">
          <Card.Header title="Quick Actions" />
          <Card.Body>
            <div className="grid grid-cols-2 gap-3">
              <button
                onClick={() => navigate('/kb/documents')}
                className="flex flex-col items-center gap-2 p-4 bg-slate-50 rounded-lg hover:bg-slate-100 transition-colors"
              >
                <DocumentIcon />
                <span className="text-sm font-medium text-slate-700">Browse Documents</span>
              </button>
              <button
                onClick={() => navigate('/kb/search')}
                className="flex flex-col items-center gap-2 p-4 bg-slate-50 rounded-lg hover:bg-slate-100 transition-colors"
              >
                <svg className="w-6 h-6 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <span className="text-sm font-medium text-slate-700">Test Search</span>
              </button>
              <button
                onClick={() => navigate('/kb/settings')}
                className="flex flex-col items-center gap-2 p-4 bg-slate-50 rounded-lg hover:bg-slate-100 transition-colors"
              >
                <svg className="w-6 h-6 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <span className="text-sm font-medium text-slate-700">KB Settings</span>
              </button>
              <button
                onClick={() => navigate('/kb/logs')}
                className="flex flex-col items-center gap-2 p-4 bg-slate-50 rounded-lg hover:bg-slate-100 transition-colors"
              >
                <svg className="w-6 h-6 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span className="text-sm font-medium text-slate-700">View Logs</span>
              </button>
            </div>
          </Card.Body>
        </Card>

        {/* Recent Activity */}
        <Card padding="lg">
          <Card.Header
            title="Recent Activity"
            action={
              <button
                onClick={() => navigate('/kb/logs')}
                className="text-sm text-wp-primary hover:text-wp-primary-hover"
              >
                View all
              </button>
            }
          />
          <Card.Body>
            <RecentActivity activity={activity} />
          </Card.Body>
        </Card>
      </div>
    </div>
  );
}
