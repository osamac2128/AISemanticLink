/**
 * Card Component
 *
 * A container component for grouping related content.
 */

/**
 * Card padding sizes.
 */
const PADDING = {
  none: '',
  sm: 'p-3',
  md: 'p-4',
  lg: 'p-6',
};

/**
 * Main Card component.
 *
 * @param {Object} props - Component props.
 * @param {React.ReactNode} props.children - Card content.
 * @param {string} props.padding - Padding size (none, sm, md, lg).
 * @param {boolean} props.hover - Enable hover effects.
 * @param {boolean} props.border - Show border.
 * @param {string} props.className - Additional CSS classes.
 * @returns {JSX.Element} Card element.
 */
export default function Card({
  children,
  padding = 'md',
  hover = false,
  border = true,
  className = '',
  ...rest
}) {
  const paddingClasses = PADDING[padding] || PADDING.md;

  return (
    <div
      className={`
        bg-white rounded-lg shadow-sm
        ${border ? 'border border-slate-200' : ''}
        ${hover ? 'hover:shadow-md hover:border-slate-300 transition-shadow' : ''}
        ${paddingClasses}
        ${className}
      `.trim()}
      {...rest}
    >
      {children}
    </div>
  );
}

/**
 * Card Header component.
 */
Card.Header = function CardHeader({
  children,
  title,
  subtitle,
  action,
  className = '',
}) {
  return (
    <div
      className={`
        flex items-start justify-between gap-4
        pb-4 mb-4 border-b border-slate-200
        ${className}
      `.trim()}
    >
      <div className="flex-1 min-w-0">
        {title && (
          <h3 className="text-lg font-semibold text-slate-800 truncate">
            {title}
          </h3>
        )}
        {subtitle && (
          <p className="mt-1 text-sm text-slate-500">{subtitle}</p>
        )}
        {children}
      </div>
      {action && <div className="flex-shrink-0">{action}</div>}
    </div>
  );
};

/**
 * Card Body component.
 */
Card.Body = function CardBody({ children, className = '' }) {
  return <div className={`${className}`}>{children}</div>;
};

/**
 * Card Footer component.
 */
Card.Footer = function CardFooter({ children, className = '' }) {
  return (
    <div
      className={`
        pt-4 mt-4 border-t border-slate-200
        flex items-center justify-end gap-3
        ${className}
      `.trim()}
    >
      {children}
    </div>
  );
};

/**
 * Stat Card - specialized for displaying statistics.
 */
Card.Stat = function StatCard({
  label,
  value,
  change,
  changeType = 'neutral',
  icon,
  loading = false,
  className = '',
}) {
  const changeColors = {
    positive: 'text-green-600',
    negative: 'text-red-600',
    neutral: 'text-slate-500',
  };

  return (
    <Card className={`${className}`} padding="md">
      <div className="flex items-start justify-between">
        <div className="flex-1">
          <p className="text-sm font-medium text-slate-500">{label}</p>
          {loading ? (
            <div className="h-8 w-20 bg-slate-200 animate-pulse rounded mt-1" />
          ) : (
            <p className="mt-1 text-2xl font-semibold text-slate-900">{value}</p>
          )}
          {change !== undefined && (
            <p className={`mt-1 text-sm ${changeColors[changeType]}`}>
              {changeType === 'positive' && '+'}
              {change}
            </p>
          )}
        </div>
        {icon && (
          <div className="flex-shrink-0 p-2 bg-slate-100 rounded-lg text-slate-600">
            {icon}
          </div>
        )}
      </div>
    </Card>
  );
};
