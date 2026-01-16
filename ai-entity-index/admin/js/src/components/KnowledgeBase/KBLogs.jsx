/**
 * KBLogs Component
 *
 * Log viewer for Knowledge Base operations.
 */

import { useState, useCallback, useMemo, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';

/**
 * Event type configurations.
 */
const EVENT_TYPES = [
  { value: '', label: 'All Events' },
  { value: 'document_built', label: 'Document Built' },
  { value: 'chunk_built', label: 'Chunk Built' },
  { value: 'embed_batch', label: 'Embed Batch' },
  { value: 'search', label: 'Search' },
  { value: 'error', label: 'Error' },
];

/**
 * Log level configurations.
 */
const LOG_LEVELS = [
  { value: '', label: 'All Levels', color: 'bg-gray-100 text-gray-700' },
  { value: 'info', label: 'INFO', color: 'bg-blue-100 text-blue-700' },
  { value: 'warning', label: 'WARNING', color: 'bg-yellow-100 text-yellow-700' },
  { value: 'error', label: 'ERROR', color: 'bg-red-100 text-red-700' },
];

/**
 * Fetch KB logs.
 */
const fetchKBLogs = async ({ page, pageSize, eventType, level, date }) => {
  const params = new URLSearchParams({
    page: page.toString(),
    per_page: pageSize.toString(),
    ...(eventType && { event_type: eventType }),
    ...(level && { level }),
    ...(date && { date }),
  });

  const response = await fetch(
    `${window.vibeAiData?.apiUrl || '/wp-json/vibe-ai/v1'}/kb/logs?${params}`,
    {
      headers: {
        'X-WP-Nonce': window.vibeAiData?.nonce || '',
      },
    }
  );

  if (!response.ok) {
    throw new Error('Failed to fetch logs');
  }

  const data = await response.json();
  const totalCount = parseInt(response.headers.get('X-WP-Total') || '0', 10);
  const totalPages = parseInt(response.headers.get('X-WP-TotalPages') || '1', 10);

  return {
    logs: data,
    totalCount,
    totalPages,
  };
};

/**
 * Log level badge component.
 */
function LevelBadge({ level }) {
  const levelConfig = LOG_LEVELS.find((l) => l.value === level?.toLowerCase()) || LOG_LEVELS[0];

  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${levelConfig.color}`}>
      {(level || 'INFO').toUpperCase()}
    </span>
  );
}

/**
 * Event type badge component.
 */
function EventTypeBadge({ eventType }) {
  const typeColors = {
    document_built: 'bg-green-100 text-green-700',
    chunk_built: 'bg-blue-100 text-blue-700',
    embed_batch: 'bg-purple-100 text-purple-700',
    search: 'bg-indigo-100 text-indigo-700',
    error: 'bg-red-100 text-red-700',
  };

  const color = typeColors[eventType] || 'bg-gray-100 text-gray-700';
  const label = eventType?.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) || 'Unknown';

  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${color}`}>
      {label}
    </span>
  );
}

/**
 * Single log entry component.
 */
function LogEntry({ log, isExpanded, onToggle }) {
  const hasContext = log.context && Object.keys(log.context).length > 0;

  return (
    <div
      className={`
        border rounded-lg overflow-hidden transition-colors
        ${log.level === 'error' ? 'border-red-200 bg-red-50' : ''}
        ${log.level === 'warning' ? 'border-yellow-200 bg-yellow-50' : ''}
        ${log.level !== 'error' && log.level !== 'warning' ? 'border-gray-200 bg-white' : ''}
      `}
    >
      <button
        onClick={onToggle}
        className="w-full px-4 py-3 flex items-start gap-3 text-left hover:bg-opacity-80 transition-colors"
      >
        {/* Expand/collapse indicator */}
        {hasContext ? (
          <svg
            className={`w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0 transition-transform ${isExpanded ? 'rotate-90' : ''}`}
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
          </svg>
        ) : (
          <div className="w-4" />
        )}

        {/* Timestamp */}
        <span className="text-xs text-gray-400 font-mono flex-shrink-0 mt-0.5">
          {new Date(log.timestamp).toLocaleTimeString()}
        </span>

        {/* Level badge */}
        <LevelBadge level={log.level} />

        {/* Event type badge */}
        {log.event_type && <EventTypeBadge eventType={log.event_type} />}

        {/* Message */}
        <span className="text-sm text-gray-700 flex-1 truncate">
          {log.message}
        </span>
      </button>

      {/* Expanded context */}
      {isExpanded && hasContext && (
        <div className="px-4 pb-4 pt-0">
          <div className="ml-8 p-3 bg-gray-800 rounded-lg overflow-x-auto">
            <pre className="text-xs text-gray-300 font-mono whitespace-pre-wrap">
              {JSON.stringify(log.context, null, 2)}
            </pre>
          </div>
        </div>
      )}
    </div>
  );
}

/**
 * KBLogs main component.
 *
 * @param {Object} props - Component props.
 * @param {Object} props.vibeAiData - Localized data from WordPress.
 * @returns {JSX.Element} KBLogs element.
 */
export default function KBLogs({ vibeAiData }) {
  // State
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(50);
  const [eventType, setEventType] = useState('');
  const [level, setLevel] = useState('');
  const [date, setDate] = useState('');
  const [expandedLogs, setExpandedLogs] = useState(new Set());
  const [autoRefresh, setAutoRefresh] = useState(false);

  // Fetch logs
  const { data, isLoading, isError, error, isFetching, refetch } = useQuery({
    queryKey: ['kb-logs', page, pageSize, eventType, level, date],
    queryFn: () => fetchKBLogs({ page, pageSize, eventType, level, date }),
    keepPreviousData: true,
    staleTime: 30000,
    refetchInterval: autoRefresh ? 5000 : false,
  });

  // Toggle log expansion
  const toggleExpanded = useCallback((logId) => {
    setExpandedLogs((prev) => {
      const next = new Set(prev);
      if (next.has(logId)) {
        next.delete(logId);
      } else {
        next.add(logId);
      }
      return next;
    });
  }, []);

  // Reset page when filters change
  const handleFilterChange = useCallback((setter) => (value) => {
    setter(value);
    setPage(1);
  }, []);

  // Clear all filters
  const handleClearFilters = useCallback(() => {
    setEventType('');
    setLevel('');
    setDate('');
    setPage(1);
  }, []);

  // Summary stats
  const stats = useMemo(() => {
    if (!data?.logs) return { info: 0, warning: 0, error: 0 };

    return data.logs.reduce(
      (acc, log) => {
        const logLevel = log.level?.toLowerCase();
        if (logLevel === 'info') acc.info++;
        else if (logLevel === 'warning') acc.warning++;
        else if (logLevel === 'error') acc.error++;
        return acc;
      },
      { info: 0, warning: 0, error: 0 }
    );
  }, [data?.logs]);

  // Loading state
  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="flex flex-col items-center gap-3">
          <div className="w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
          <span className="text-sm text-gray-500">Loading logs...</span>
        </div>
      </div>
    );
  }

  // Error state
  if (isError) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="bg-red-50 border border-red-200 rounded-lg p-6 max-w-md">
          <div className="flex items-start gap-3">
            <svg className="w-5 h-5 text-red-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div>
              <h3 className="font-medium text-red-800">Failed to load logs</h3>
              <p className="text-sm text-red-600 mt-1">{error?.message}</p>
            </div>
          </div>
        </div>
      </div>
    );
  }

  const today = new Date().toISOString().split('T')[0];

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">KB Logs</h1>
          <p className="mt-1 text-slate-500">
            View Knowledge Base indexing and search logs
          </p>
        </div>
        <div className="flex items-center gap-4">
          {isFetching && !isLoading && (
            <div className="flex items-center gap-2 text-sm text-gray-500">
              <div className="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
              Refreshing...
            </div>
          )}

          {/* Auto-refresh toggle */}
          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="checkbox"
              checked={autoRefresh}
              onChange={(e) => setAutoRefresh(e.target.checked)}
              className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
            />
            <span className="text-sm text-gray-600">Auto-refresh</span>
          </label>

          <button
            onClick={() => refetch()}
            className="inline-flex items-center gap-1 px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-md transition-colors"
          >
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            Refresh
          </button>
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-3 gap-4">
        <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-blue-700">Info</span>
            <span className="text-2xl font-bold text-blue-900">{stats.info}</span>
          </div>
        </div>
        <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-yellow-700">Warnings</span>
            <span className="text-2xl font-bold text-yellow-900">{stats.warning}</span>
          </div>
        </div>
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-red-700">Errors</span>
            <span className="text-2xl font-bold text-red-900">{stats.error}</span>
          </div>
        </div>
      </div>

      {/* Filters */}
      <div className="bg-white rounded-lg border border-gray-200 p-4">
        <div className="flex flex-wrap items-center gap-4">
          {/* Event type filter */}
          <div className="flex items-center gap-2">
            <label className="text-sm font-medium text-gray-700">Event:</label>
            <select
              value={eventType}
              onChange={(e) => handleFilterChange(setEventType)(e.target.value)}
              className="px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
              {EVENT_TYPES.map((t) => (
                <option key={t.value} value={t.value}>{t.label}</option>
              ))}
            </select>
          </div>

          {/* Level filter */}
          <div className="flex items-center gap-2">
            <label className="text-sm font-medium text-gray-700">Level:</label>
            <select
              value={level}
              onChange={(e) => handleFilterChange(setLevel)(e.target.value)}
              className="px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
              {LOG_LEVELS.map((l) => (
                <option key={l.value} value={l.value}>{l.label}</option>
              ))}
            </select>
          </div>

          {/* Date filter */}
          <div className="flex items-center gap-2">
            <label className="text-sm font-medium text-gray-700">Date:</label>
            <input
              type="date"
              value={date}
              max={today}
              onChange={(e) => handleFilterChange(setDate)(e.target.value)}
              className="px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
          </div>

          {/* Clear filters */}
          {(eventType || level || date) && (
            <button
              onClick={handleClearFilters}
              className="inline-flex items-center gap-1.5 px-3 py-2 text-sm text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-md transition-colors"
            >
              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
              Clear filters
            </button>
          )}

          {/* Results count */}
          <span className="text-sm text-gray-500 ml-auto">
            {data?.totalCount || 0} entries
          </span>
        </div>
      </div>

      {/* Log entries */}
      {data?.logs?.length === 0 ? (
        <div className="bg-white rounded-lg border border-gray-200 p-8 text-center">
          <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
          <h3 className="mt-2 text-sm font-medium text-gray-900">No logs found</h3>
          <p className="mt-1 text-sm text-gray-500">
            {eventType || level || date
              ? 'Try adjusting your filters'
              : 'KB logs will appear here as documents are indexed'}
          </p>
        </div>
      ) : (
        <div className="space-y-2">
          {data?.logs?.map((log) => (
            <LogEntry
              key={log.id}
              log={log}
              isExpanded={expandedLogs.has(log.id)}
              onToggle={() => toggleExpanded(log.id)}
            />
          ))}
        </div>
      )}

      {/* Pagination */}
      {data?.totalPages > 1 && (
        <div className="bg-white rounded-lg border border-gray-200 px-4 py-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2 text-sm text-gray-700">
              <span>
                Page {page} of {data.totalPages}
              </span>
              <select
                value={pageSize}
                onChange={(e) => {
                  setPageSize(Number(e.target.value));
                  setPage(1);
                }}
                className="px-2 py-1 text-sm border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                {[25, 50, 100].map((size) => (
                  <option key={size} value={size}>{size} per page</option>
                ))}
              </select>
            </div>

            <div className="flex items-center gap-1">
              <button
                onClick={() => setPage(1)}
                disabled={page === 1}
                className="p-1 rounded border border-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-100"
              >
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                </svg>
              </button>
              <button
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page === 1}
                className="p-1 rounded border border-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-100"
              >
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                </svg>
              </button>
              <button
                onClick={() => setPage((p) => Math.min(data.totalPages, p + 1))}
                disabled={page >= data.totalPages}
                className="p-1 rounded border border-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-100"
              >
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
              </button>
              <button
                onClick={() => setPage(data.totalPages)}
                disabled={page >= data.totalPages}
                className="p-1 rounded border border-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-100"
              >
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                </svg>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
