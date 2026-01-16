/**
 * ActivityLog - Historical job execution log
 *
 * Features:
 * - Filterable by level (INFO, WARN, ERROR)
 * - Date picker
 * - Paginated log entries
 * - Expandable entries to show full context
 */

import { useState, useCallback, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';

// Log levels configuration
const LOG_LEVELS = [
    { value: 'all', label: 'All Levels', color: 'bg-gray-100 text-gray-700' },
    { value: 'info', label: 'INFO', color: 'bg-blue-100 text-blue-700' },
    { value: 'warn', label: 'WARN', color: 'bg-yellow-100 text-yellow-700' },
    { value: 'error', label: 'ERROR', color: 'bg-red-100 text-red-700' },
    { value: 'api', label: 'API', color: 'bg-purple-100 text-purple-700' },
];

// Fetch logs
const fetchLogs = async ({ page, pageSize, level, date }) => {
    const params = new URLSearchParams({
        page: page.toString(),
        per_page: pageSize.toString(),
        ...(level && level !== 'all' && { level }),
        ...(date && { date }),
    });

    const response = await fetch(
        `${window.vibeAI?.restUrl || '/wp-json/'}vibe-ai/v1/logs?${params}`,
        {
            headers: {
                'X-WP-Nonce': window.vibeAI?.nonce || '',
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

// Log level badge component
function LevelBadge({ level }) {
    const levelConfig = LOG_LEVELS.find((l) => l.value === level.toLowerCase()) || LOG_LEVELS[0];

    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${levelConfig.color}`}>
            {level.toUpperCase()}
        </span>
    );
}

// Single log entry component
function LogEntry({ log, isExpanded, onToggle }) {
    const hasContext = log.context && Object.keys(log.context).length > 0;

    return (
        <div
            className={`
                border rounded-lg overflow-hidden transition-colors
                ${log.level === 'error' ? 'border-red-200 bg-red-50' : ''}
                ${log.level === 'warn' ? 'border-yellow-200 bg-yellow-50' : ''}
                ${log.level !== 'error' && log.level !== 'warn' ? 'border-gray-200 bg-white' : ''}
            `}
        >
            <button
                onClick={onToggle}
                className="w-full px-4 py-3 flex items-start gap-3 text-left hover:bg-opacity-80 transition-colors"
            >
                {/* Expand/collapse indicator */}
                {hasContext && (
                    <svg
                        className={`w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0 transition-transform ${isExpanded ? 'rotate-90' : ''}`}
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                    </svg>
                )}
                {!hasContext && <div className="w-4" />}

                {/* Timestamp */}
                <span className="text-xs text-gray-400 font-mono flex-shrink-0 mt-0.5">
                    {new Date(log.timestamp).toLocaleTimeString()}
                </span>

                {/* Level badge */}
                <LevelBadge level={log.level} />

                {/* Message */}
                <span className="text-sm text-gray-700 flex-1">
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

// Date picker component
function DatePicker({ value, onChange }) {
    const today = new Date().toISOString().split('T')[0];

    return (
        <input
            type="date"
            value={value || ''}
            max={today}
            onChange={(e) => onChange(e.target.value || null)}
            className="px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
        />
    );
}

export default function ActivityLog() {
    // State
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(50);
    const [level, setLevel] = useState('all');
    const [date, setDate] = useState(null);
    const [expandedLogs, setExpandedLogs] = useState(new Set());

    // Fetch logs
    const { data, isLoading, isError, error, isFetching } = useQuery({
        queryKey: ['activity-logs', page, pageSize, level, date],
        queryFn: () => fetchLogs({ page, pageSize, level, date }),
        keepPreviousData: true,
        staleTime: 30000, // 30 seconds
        refetchInterval: 60000, // Refetch every minute
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
    const handleLevelChange = useCallback((newLevel) => {
        setLevel(newLevel);
        setPage(1);
    }, []);

    const handleDateChange = useCallback((newDate) => {
        setDate(newDate);
        setPage(1);
    }, []);

    const handleClearFilters = useCallback(() => {
        setLevel('all');
        setDate(null);
        setPage(1);
    }, []);

    // Summary stats
    const stats = useMemo(() => {
        if (!data?.logs) return { info: 0, warn: 0, error: 0 };

        return data.logs.reduce(
            (acc, log) => {
                if (log.level === 'info' || log.level === 'api') acc.info++;
                else if (log.level === 'warn') acc.warn++;
                else if (log.level === 'error') acc.error++;
                return acc;
            },
            { info: 0, warn: 0, error: 0 }
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

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">Activity Log</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Historical job execution and system events
                    </p>
                </div>
                {isFetching && !isLoading && (
                    <div className="flex items-center gap-2 text-sm text-gray-500">
                        <div className="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
                        Refreshing...
                    </div>
                )}
            </div>

            {/* Stats Cards */}
            <div className="grid grid-cols-3 gap-4">
                <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <div className="flex items-center justify-between">
                        <span className="text-sm font-medium text-blue-700">Info/API</span>
                        <span className="text-2xl font-bold text-blue-900">{stats.info}</span>
                    </div>
                </div>
                <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <div className="flex items-center justify-between">
                        <span className="text-sm font-medium text-yellow-700">Warnings</span>
                        <span className="text-2xl font-bold text-yellow-900">{stats.warn}</span>
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
                    {/* Level filter */}
                    <div className="flex items-center gap-2">
                        <label className="text-sm font-medium text-gray-700">Level:</label>
                        <select
                            value={level}
                            onChange={(e) => handleLevelChange(e.target.value)}
                            className="px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                            {LOG_LEVELS.map((l) => (
                                <option key={l.value} value={l.value}>
                                    {l.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    {/* Date filter */}
                    <div className="flex items-center gap-2">
                        <label className="text-sm font-medium text-gray-700">Date:</label>
                        <DatePicker value={date} onChange={handleDateChange} />
                    </div>

                    {/* Clear filters */}
                    {(level !== 'all' || date) && (
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
                        {level !== 'all' || date
                            ? 'Try adjusting your filters'
                            : 'Activity logs will appear here as the pipeline processes content'}
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
                                    <option key={size} value={size}>
                                        {size} per page
                                    </option>
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
