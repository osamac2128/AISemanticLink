/**
 * Spinner Component
 *
 * A loading indicator with multiple sizes and variants.
 */

/**
 * Spinner sizes.
 */
const SIZES = {
  xs: 'w-3 h-3',
  sm: 'w-4 h-4',
  md: 'w-6 h-6',
  lg: 'w-8 h-8',
  xl: 'w-12 h-12',
};

/**
 * Spinner border widths.
 */
const BORDERS = {
  xs: 'border',
  sm: 'border-2',
  md: 'border-2',
  lg: 'border-[3px]',
  xl: 'border-4',
};

/**
 * Spinner colors.
 */
const COLORS = {
  primary: 'border-wp-primary',
  white: 'border-white',
  slate: 'border-slate-600',
  current: 'border-current',
};

/**
 * Spinner component for loading states.
 *
 * @param {Object} props - Component props.
 * @param {string} props.size - Spinner size (xs, sm, md, lg, xl).
 * @param {string} props.color - Spinner color (primary, white, slate, current).
 * @param {string} props.className - Additional CSS classes.
 * @returns {JSX.Element} Spinner element.
 */
export default function Spinner({
  size = 'md',
  color = 'primary',
  className = '',
}) {
  const sizeClasses = SIZES[size] || SIZES.md;
  const borderClasses = BORDERS[size] || BORDERS.md;
  const colorClasses = COLORS[color] || COLORS.primary;

  return (
    <div
      className={`
        inline-block rounded-full
        border-transparent border-t-current
        animate-spin
        ${sizeClasses}
        ${borderClasses}
        ${colorClasses}
        ${className}
      `.trim()}
      role="status"
      aria-label="Loading"
    >
      <span className="sr-only">Loading...</span>
    </div>
  );
}

/**
 * Full-screen loading overlay.
 */
Spinner.Overlay = function SpinnerOverlay({
  message = 'Loading...',
  visible = true,
}) {
  if (!visible) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-white/80 backdrop-blur-sm">
      <div className="flex flex-col items-center gap-3">
        <Spinner size="xl" />
        <p className="text-sm font-medium text-slate-600">{message}</p>
      </div>
    </div>
  );
};

/**
 * Inline loading state.
 */
Spinner.Inline = function SpinnerInline({
  message = 'Loading...',
  size = 'sm',
}) {
  return (
    <div className="flex items-center gap-2 text-slate-500">
      <Spinner size={size} color="slate" />
      <span className="text-sm">{message}</span>
    </div>
  );
};

/**
 * Card loading skeleton.
 */
Spinner.Skeleton = function SpinnerSkeleton({
  lines = 3,
  className = '',
}) {
  return (
    <div className={`animate-pulse space-y-3 ${className}`}>
      {Array.from({ length: lines }).map((_, i) => (
        <div
          key={i}
          className="h-4 bg-slate-200 rounded"
          style={{ width: `${100 - i * 15}%` }}
        />
      ))}
    </div>
  );
};
