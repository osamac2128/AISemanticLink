/**
 * Header Component
 *
 * Top navigation bar for the admin interface.
 */

import Badge from '../common/Badge';

/**
 * Menu icon SVG.
 */
const MenuIcon = () => (
  <svg
    className="w-5 h-5"
    fill="none"
    stroke="currentColor"
    viewBox="0 0 24 24"
  >
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M4 6h16M4 12h16M4 18h16"
    />
  </svg>
);

/**
 * External link icon SVG.
 */
const ExternalLinkIcon = () => (
  <svg
    className="w-4 h-4"
    fill="none"
    stroke="currentColor"
    viewBox="0 0 24 24"
  >
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
    />
  </svg>
);

/**
 * Header component.
 *
 * @param {Object} props - Component props.
 * @param {Function} props.onToggleSidebar - Callback to toggle sidebar.
 * @param {Object} props.vibeAiData - Localized data from WordPress.
 * @returns {JSX.Element} Header element.
 */
export default function Header({ onToggleSidebar, vibeAiData }) {
  return (
    <header className="sticky top-0 z-30 bg-white border-b border-slate-200">
      <div className="flex items-center justify-between h-14 px-4">
        {/* Left section */}
        <div className="flex items-center gap-4">
          {/* Sidebar toggle */}
          <button
            onClick={onToggleSidebar}
            className="p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
            aria-label="Toggle sidebar"
          >
            <MenuIcon />
          </button>

          {/* Logo and title */}
          <div className="flex items-center gap-3">
            <div className="flex items-center justify-center w-8 h-8 bg-gradient-to-br from-wp-primary to-blue-600 rounded-lg">
              <span className="text-white font-bold text-sm">AI</span>
            </div>
            <div>
              <h1 className="text-lg font-semibold text-slate-800">
                Entity Index
              </h1>
            </div>
            <Badge variant="info" size="sm">
              Beta
            </Badge>
          </div>
        </div>

        {/* Right section */}
        <div className="flex items-center gap-3">
          {/* Documentation link */}
          <a
            href="https://developer.example.com/ai-entity-index"
            target="_blank"
            rel="noopener noreferrer"
            className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-slate-600 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition-colors"
          >
            <span>Docs</span>
            <ExternalLinkIcon />
          </a>

          {/* Back to WordPress */}
          {vibeAiData?.adminUrl && (
            <a
              href={vibeAiData.adminUrl}
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-slate-600 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition-colors"
            >
              <svg
                className="w-4 h-4"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M11 17l-5-5m0 0l5-5m-5 5h12"
                />
              </svg>
              <span>WP Admin</span>
            </a>
          )}
        </div>
      </div>
    </header>
  );
}
