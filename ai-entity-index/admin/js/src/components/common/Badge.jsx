/**
 * Badge Component
 *
 * A small label for status indicators and tags.
 */

/**
 * Badge variants with Tailwind classes.
 */
const VARIANTS = {
  default: 'bg-slate-100 text-slate-700',
  primary: 'bg-wp-primary/10 text-wp-primary',
  success: 'bg-green-100 text-green-700',
  warning: 'bg-yellow-100 text-yellow-700',
  error: 'bg-red-100 text-red-700',
  info: 'bg-blue-100 text-blue-700',
};

/**
 * Badge sizes with Tailwind classes.
 */
const SIZES = {
  sm: 'px-1.5 py-0.5 text-xs',
  md: 'px-2 py-1 text-xs',
  lg: 'px-2.5 py-1 text-sm',
};

/**
 * Badge component for displaying status labels.
 *
 * @param {Object} props - Component props.
 * @param {React.ReactNode} props.children - Badge content.
 * @param {string} props.variant - Badge variant (default, primary, success, warning, error, info).
 * @param {string} props.size - Badge size (sm, md, lg).
 * @param {boolean} props.dot - Show a colored dot before text.
 * @param {string} props.className - Additional CSS classes.
 * @returns {JSX.Element} Badge element.
 */
export default function Badge({
  children,
  variant = 'default',
  size = 'md',
  dot = false,
  className = '',
}) {
  const variantClasses = VARIANTS[variant] || VARIANTS.default;
  const sizeClasses = SIZES[size] || SIZES.md;

  return (
    <span
      className={`
        inline-flex items-center gap-1 font-medium rounded-full
        ${variantClasses}
        ${sizeClasses}
        ${className}
      `.trim()}
    >
      {dot && (
        <span
          className={`
            w-1.5 h-1.5 rounded-full
            ${variant === 'success' ? 'bg-green-500' : ''}
            ${variant === 'warning' ? 'bg-yellow-500' : ''}
            ${variant === 'error' ? 'bg-red-500' : ''}
            ${variant === 'info' ? 'bg-blue-500' : ''}
            ${variant === 'primary' ? 'bg-wp-primary' : ''}
            ${variant === 'default' ? 'bg-slate-500' : ''}
          `.trim()}
        />
      )}
      {children}
    </span>
  );
}

/**
 * Status-specific badge presets.
 */
Badge.Status = function StatusBadge({ status, ...props }) {
  const statusMap = {
    active: { variant: 'success', children: 'Active', dot: true },
    inactive: { variant: 'default', children: 'Inactive', dot: true },
    pending: { variant: 'warning', children: 'Pending', dot: true },
    processing: { variant: 'info', children: 'Processing', dot: true },
    error: { variant: 'error', children: 'Error', dot: true },
    complete: { variant: 'success', children: 'Complete', dot: true },
  };

  const preset = statusMap[status] || statusMap.inactive;

  return <Badge {...preset} {...props} />;
};

/**
 * Entity type badge preset.
 */
Badge.EntityType = function EntityTypeBadge({ type, ...props }) {
  const typeMap = {
    person: { variant: 'primary', children: 'Person' },
    organization: { variant: 'info', children: 'Organization' },
    location: { variant: 'success', children: 'Location' },
    event: { variant: 'warning', children: 'Event' },
    product: { variant: 'default', children: 'Product' },
    concept: { variant: 'default', children: 'Concept' },
  };

  const preset = typeMap[type?.toLowerCase()] || { variant: 'default', children: type || 'Unknown' };

  return <Badge {...preset} {...props} />;
};
