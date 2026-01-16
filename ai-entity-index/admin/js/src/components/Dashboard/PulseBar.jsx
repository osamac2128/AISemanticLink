/**
 * PulseBar Component
 *
 * Progress bar with animated stripes and ETA display.
 */

import { useMemo } from 'react';

/**
 * Format remaining time as human-readable string.
 *
 * @param {number} seconds - Remaining seconds.
 * @returns {string} Formatted time string.
 */
function formatTimeRemaining(seconds) {
  if (seconds <= 0) return 'Almost done...';

  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = Math.floor(seconds % 60);

  if (hours > 0) {
    return `${hours}h ${minutes}m remaining`;
  }

  if (minutes > 0) {
    return `${minutes}m ${secs}s remaining`;
  }

  return `${secs}s remaining`;
}

/**
 * PulseBar component.
 *
 * @param {Object} props - Component props.
 * @param {number} props.progress - Progress percentage (0-100).
 * @param {boolean} props.isRunning - Whether animation should play.
 * @param {string} props.startedAt - ISO timestamp when started.
 * @param {string} props.label - Optional label text.
 * @param {boolean} props.showPercentage - Whether to show percentage.
 * @param {boolean} props.showEta - Whether to show ETA.
 * @param {string} props.variant - Color variant (primary, success, warning, error).
 * @param {string} props.size - Bar height (sm, md, lg).
 * @param {string} props.className - Additional CSS classes.
 * @returns {JSX.Element} PulseBar element.
 */
export default function PulseBar({
  progress = 0,
  isRunning = false,
  startedAt = null,
  label = null,
  showPercentage = true,
  showEta = true,
  variant = 'primary',
  size = 'md',
  className = '',
}) {
  // Clamp progress between 0 and 100
  const clampedProgress = Math.min(100, Math.max(0, progress));

  // Calculate ETA
  const eta = useMemo(() => {
    if (!isRunning || !startedAt || clampedProgress <= 0 || clampedProgress >= 100) {
      return null;
    }

    const startTime = new Date(startedAt).getTime();
    const elapsed = (Date.now() - startTime) / 1000; // seconds
    const rate = clampedProgress / elapsed; // percent per second

    if (rate <= 0) return null;

    const remaining = (100 - clampedProgress) / rate;
    return remaining;
  }, [isRunning, startedAt, clampedProgress]);

  // Variant colors
  const variantColors = {
    primary: 'bg-wp-primary',
    success: 'bg-phase-complete',
    warning: 'bg-wp-warning',
    error: 'bg-phase-error',
  };

  // Size classes
  const sizeClasses = {
    sm: 'h-1.5',
    md: 'h-2.5',
    lg: 'h-4',
  };

  const barColor = variantColors[variant] || variantColors.primary;
  const barHeight = sizeClasses[size] || sizeClasses.md;

  return (
    <div className={`w-full ${className}`}>
      {/* Label and percentage */}
      {(label || showPercentage) && (
        <div className="flex items-center justify-between mb-2">
          {label && (
            <span className="text-sm font-medium text-slate-700">{label}</span>
          )}
          {showPercentage && (
            <span className="text-sm font-semibold text-slate-600">
              {clampedProgress.toFixed(1)}%
            </span>
          )}
        </div>
      )}

      {/* Progress bar container */}
      <div className={`relative w-full bg-slate-200 rounded-full overflow-hidden ${barHeight}`}>
        {/* Progress fill */}
        <div
          className={`
            absolute top-0 left-0 h-full rounded-full
            transition-all duration-300 ease-out
            ${barColor}
            ${isRunning ? 'progress-stripes animate-stripe' : ''}
          `}
          style={{ width: `${clampedProgress}%` }}
        />
      </div>

      {/* ETA display */}
      {showEta && eta !== null && (
        <div className="mt-2 flex items-center justify-between text-xs text-slate-500">
          <span>
            {isRunning ? 'Processing...' : 'Idle'}
          </span>
          <span>{formatTimeRemaining(eta)}</span>
        </div>
      )}
    </div>
  );
}

/**
 * Compact progress indicator.
 */
PulseBar.Compact = function CompactPulseBar({ progress, isRunning, className = '' }) {
  const clampedProgress = Math.min(100, Math.max(0, progress));

  return (
    <div className={`flex items-center gap-2 ${className}`}>
      <div className="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
        <div
          className={`
            h-full bg-wp-primary rounded-full
            transition-all duration-300
            ${isRunning ? 'progress-stripes animate-stripe' : ''}
          `}
          style={{ width: `${clampedProgress}%` }}
        />
      </div>
      <span className="text-xs font-medium text-slate-500 w-10 text-right">
        {clampedProgress.toFixed(0)}%
      </span>
    </div>
  );
};
