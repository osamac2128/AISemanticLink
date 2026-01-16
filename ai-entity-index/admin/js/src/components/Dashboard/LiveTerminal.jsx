/**
 * LiveTerminal Component
 *
 * Console-style log viewer with color-coded severity levels.
 */

import { useRef, useEffect } from 'react';

/**
 * Log level configuration with colors and labels.
 */
const LOG_LEVELS = {
  DEBUG: { label: 'DEBUG', className: 'text-slate-400' },
  INFO: { label: 'INFO', className: 'text-slate-400' },
  API: { label: 'API', className: 'text-blue-400' },
  WARN: { label: 'WARN', className: 'text-yellow-400' },
  WARNING: { label: 'WARN', className: 'text-yellow-400' },
  ERROR: { label: 'ERROR', className: 'text-red-400' },
  SUCCESS: { label: 'OK', className: 'text-green-400' },
};

/**
 * Format timestamp for display.
 *
 * @param {string|number} timestamp - ISO string or Unix timestamp.
 * @returns {string} Formatted time string.
 */
function formatTimestamp(timestamp) {
  const date = new Date(timestamp);
  return date.toLocaleTimeString('en-US', {
    hour12: false,
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  });
}

/**
 * Single log entry component.
 *
 * @param {Object} props - Component props.
 * @param {Object} props.entry - Log entry data.
 * @returns {JSX.Element} Log entry element.
 */
function LogEntry({ entry }) {
  const levelConfig = LOG_LEVELS[entry.level?.toUpperCase()] || LOG_LEVELS.INFO;

  return (
    <div className="terminal-line flex gap-3 py-0.5 hover:bg-slate-800/50">
      {/* Timestamp */}
      <span className="text-slate-500 flex-shrink-0 select-none">
        [{formatTimestamp(entry.timestamp || entry.time || Date.now())}]
      </span>

      {/* Level badge */}
      <span className={`flex-shrink-0 font-semibold w-14 ${levelConfig.className}`}>
        {levelConfig.label}
      </span>

      {/* Message */}
      <span
        className={`flex-1 break-all ${
          entry.level?.toUpperCase() === 'ERROR' ? 'text-red-300' : 'text-slate-300'
        }`}
      >
        {entry.message}
      </span>
    </div>
  );
}

/**
 * LiveTerminal component.
 *
 * @param {Object} props - Component props.
 * @param {Array} props.logs - Array of log entries.
 * @param {number} props.maxEntries - Maximum entries to display (default 50).
 * @param {boolean} props.autoScroll - Whether to auto-scroll to bottom.
 * @param {string} props.title - Terminal title.
 * @param {string} props.className - Additional CSS classes.
 * @param {Function} props.onClear - Callback when clear button clicked.
 * @returns {JSX.Element} LiveTerminal element.
 */
export default function LiveTerminal({
  logs = [],
  maxEntries = 50,
  autoScroll = true,
  title = 'Pipeline Output',
  className = '',
  onClear,
}) {
  const containerRef = useRef(null);
  const shouldAutoScroll = useRef(autoScroll);

  // Limit displayed entries
  const displayedLogs = logs.slice(-maxEntries);

  // Auto-scroll effect
  useEffect(() => {
    if (shouldAutoScroll.current && containerRef.current) {
      containerRef.current.scrollTop = containerRef.current.scrollHeight;
    }
  }, [logs]);

  // Handle scroll to detect manual scrolling
  const handleScroll = () => {
    if (!containerRef.current) return;

    const { scrollTop, scrollHeight, clientHeight } = containerRef.current;
    const isAtBottom = scrollHeight - scrollTop - clientHeight < 50;

    shouldAutoScroll.current = isAtBottom;
  };

  return (
    <div
      className={`bg-slate-900 rounded-lg overflow-hidden flex flex-col ${className}`}
    >
      {/* Terminal header */}
      <div className="flex items-center justify-between px-4 py-2 bg-slate-800 border-b border-slate-700">
        <div className="flex items-center gap-2">
          {/* Traffic light buttons */}
          <div className="flex gap-1.5">
            <span className="w-3 h-3 rounded-full bg-red-500" />
            <span className="w-3 h-3 rounded-full bg-yellow-500" />
            <span className="w-3 h-3 rounded-full bg-green-500" />
          </div>
          <span className="text-sm font-medium text-slate-400 ml-2">{title}</span>
        </div>

        <div className="flex items-center gap-2">
          {/* Entry count */}
          <span className="text-xs text-slate-500">
            {displayedLogs.length} / {maxEntries} entries
          </span>

          {/* Clear button */}
          {onClear && (
            <button
              onClick={onClear}
              className="px-2 py-1 text-xs text-slate-400 hover:text-slate-200 hover:bg-slate-700 rounded transition-colors"
            >
              Clear
            </button>
          )}
        </div>
      </div>

      {/* Log content */}
      <div
        ref={containerRef}
        onScroll={handleScroll}
        className="flex-1 px-4 py-3 overflow-y-auto font-mono text-sm scrollbar-thin scrollbar-dark min-h-[200px] max-h-[400px]"
      >
        {displayedLogs.length === 0 ? (
          <div className="text-slate-500 italic">
            No log entries yet. Start the pipeline to see output.
          </div>
        ) : (
          displayedLogs.map((entry, index) => (
            <LogEntry key={entry.id || index} entry={entry} />
          ))
        )}
      </div>

      {/* Status bar */}
      <div className="px-4 py-1.5 bg-slate-800 border-t border-slate-700 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <span className="flex items-center gap-1.5">
            <span className="w-2 h-2 rounded-full bg-green-500 animate-pulse" />
            <span className="text-xs text-slate-400">Live</span>
          </span>
        </div>

        {/* Legend */}
        <div className="flex items-center gap-3 text-xs">
          {Object.entries(LOG_LEVELS).slice(0, 4).map(([key, config]) => (
            <span key={key} className="flex items-center gap-1">
              <span className={`${config.className}`}>{config.label}</span>
            </span>
          ))}
        </div>
      </div>
    </div>
  );
}

/**
 * Compact log viewer for inline display.
 */
LiveTerminal.Compact = function CompactTerminal({ logs = [], maxEntries = 5 }) {
  const displayedLogs = logs.slice(-maxEntries);

  return (
    <div className="bg-slate-900 rounded px-3 py-2 font-mono text-xs">
      {displayedLogs.length === 0 ? (
        <span className="text-slate-500">Waiting for logs...</span>
      ) : (
        displayedLogs.map((entry, index) => {
          const levelConfig = LOG_LEVELS[entry.level?.toUpperCase()] || LOG_LEVELS.INFO;
          return (
            <div key={index} className="truncate">
              <span className={levelConfig.className}>{levelConfig.label}:</span>{' '}
              <span className="text-slate-400">{entry.message}</span>
            </div>
          );
        })
      )}
    </div>
  );
};
