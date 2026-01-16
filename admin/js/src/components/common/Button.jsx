/**
 * Button Component
 *
 * A versatile button with multiple variants and sizes.
 */

import Spinner from './Spinner';

/**
 * Button variants with Tailwind classes.
 */
const VARIANTS = {
  primary: `
    bg-wp-primary text-white
    hover:bg-wp-primary-hover
    focus:ring-wp-primary/50
    disabled:bg-wp-primary/50
  `,
  secondary: `
    bg-slate-100 text-slate-700
    hover:bg-slate-200
    focus:ring-slate-500/50
    disabled:bg-slate-50 disabled:text-slate-400
  `,
  success: `
    bg-wp-success text-white
    hover:bg-green-700
    focus:ring-green-500/50
    disabled:bg-green-400
  `,
  danger: `
    bg-wp-accent text-white
    hover:bg-red-700
    focus:ring-red-500/50
    disabled:bg-red-400
  `,
  warning: `
    bg-wp-warning text-slate-900
    hover:bg-yellow-600
    focus:ring-yellow-500/50
    disabled:bg-yellow-300
  `,
  ghost: `
    bg-transparent text-slate-600
    hover:bg-slate-100
    focus:ring-slate-500/50
    disabled:text-slate-300
  `,
  link: `
    bg-transparent text-wp-primary
    hover:text-wp-primary-hover hover:underline
    focus:ring-wp-primary/50
    disabled:text-slate-400
  `,
};

/**
 * Button sizes with Tailwind classes.
 */
const SIZES = {
  xs: 'px-2 py-1 text-xs gap-1',
  sm: 'px-3 py-1.5 text-sm gap-1.5',
  md: 'px-4 py-2 text-sm gap-2',
  lg: 'px-5 py-2.5 text-base gap-2',
  xl: 'px-6 py-3 text-base gap-2.5',
};

/**
 * Icon-only button sizes.
 */
const ICON_SIZES = {
  xs: 'p-1',
  sm: 'p-1.5',
  md: 'p-2',
  lg: 'p-2.5',
  xl: 'p-3',
};

/**
 * Button component.
 *
 * @param {Object} props - Component props.
 * @param {React.ReactNode} props.children - Button content.
 * @param {string} props.variant - Button variant.
 * @param {string} props.size - Button size.
 * @param {boolean} props.loading - Show loading spinner.
 * @param {boolean} props.disabled - Disable the button.
 * @param {boolean} props.iconOnly - Icon-only mode (square button).
 * @param {React.ReactNode} props.leftIcon - Icon before text.
 * @param {React.ReactNode} props.rightIcon - Icon after text.
 * @param {string} props.type - Button type (button, submit, reset).
 * @param {string} props.className - Additional CSS classes.
 * @param {Function} props.onClick - Click handler.
 * @returns {JSX.Element} Button element.
 */
export default function Button({
  children,
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled = false,
  iconOnly = false,
  leftIcon = null,
  rightIcon = null,
  type = 'button',
  className = '',
  onClick,
  ...rest
}) {
  const variantClasses = VARIANTS[variant] || VARIANTS.primary;
  const sizeClasses = iconOnly
    ? ICON_SIZES[size] || ICON_SIZES.md
    : SIZES[size] || SIZES.md;

  const isDisabled = disabled || loading;

  return (
    <button
      type={type}
      disabled={isDisabled}
      onClick={onClick}
      className={`
        inline-flex items-center justify-center
        font-medium rounded-md
        transition-colors duration-150
        focus:outline-none focus:ring-2 focus:ring-offset-1
        disabled:cursor-not-allowed
        ${variantClasses}
        ${sizeClasses}
        ${className}
      `.trim()}
      {...rest}
    >
      {loading ? (
        <>
          <Spinner size={size === 'xs' || size === 'sm' ? 'xs' : 'sm'} />
          {!iconOnly && <span className="ml-1.5">Loading...</span>}
        </>
      ) : (
        <>
          {leftIcon && <span className="flex-shrink-0">{leftIcon}</span>}
          {children}
          {rightIcon && <span className="flex-shrink-0">{rightIcon}</span>}
        </>
      )}
    </button>
  );
}

/**
 * Button Group for grouping related buttons.
 */
Button.Group = function ButtonGroup({ children, className = '' }) {
  return (
    <div
      className={`
        inline-flex rounded-md shadow-sm
        [&>button]:rounded-none
        [&>button:first-child]:rounded-l-md
        [&>button:last-child]:rounded-r-md
        [&>button:not(:first-child)]:-ml-px
        ${className}
      `.trim()}
    >
      {children}
    </div>
  );
};
