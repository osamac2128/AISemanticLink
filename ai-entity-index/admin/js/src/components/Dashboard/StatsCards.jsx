/**
 * StatsCards Component
 *
 * Grid of statistic cards for the dashboard.
 */

import Card from '../common/Card';

/**
 * Entity icon SVG.
 */
const EntityIcon = () => (
  <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"
    />
  </svg>
);

/**
 * Mention icon SVG.
 */
const MentionIcon = () => (
  <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"
    />
  </svg>
);

/**
 * Confidence icon SVG.
 */
const ConfidenceIcon = () => (
  <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
    />
  </svg>
);

/**
 * Pending icon SVG.
 */
const PendingIcon = () => (
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
  return value.toLocaleString();
}

/**
 * Format confidence score as percentage.
 *
 * @param {number} value - Confidence score (0-1 or 0-100).
 * @returns {string} Formatted percentage.
 */
function formatConfidence(value) {
  // Handle both 0-1 and 0-100 ranges
  const percentage = value > 1 ? value : value * 100;
  return `${percentage.toFixed(1)}%`;
}

/**
 * StatsCards component.
 *
 * @param {Object} props - Component props.
 * @param {Object} props.stats - Statistics data.
 * @param {number} props.stats.total_entities - Total entity count.
 * @param {number} props.stats.total_mentions - Total mention count.
 * @param {number} props.stats.avg_confidence - Average confidence score.
 * @param {number} props.stats.posts_pending - Posts pending processing.
 * @param {boolean} props.loading - Whether data is loading.
 * @param {string} props.className - Additional CSS classes.
 * @returns {JSX.Element} StatsCards element.
 */
export default function StatsCards({
  stats = {},
  loading = false,
  className = '',
}) {
  const {
    total_entities = 0,
    total_mentions = 0,
    avg_confidence = 0,
    posts_pending = 0,
  } = stats;

  const cards = [
    {
      label: 'Total Entities',
      value: formatNumber(total_entities),
      icon: <EntityIcon />,
      color: 'text-wp-primary',
      bgColor: 'bg-wp-primary/10',
    },
    {
      label: 'Total Mentions',
      value: formatNumber(total_mentions),
      icon: <MentionIcon />,
      color: 'text-green-600',
      bgColor: 'bg-green-100',
    },
    {
      label: 'Avg Confidence',
      value: formatConfidence(avg_confidence),
      icon: <ConfidenceIcon />,
      color: 'text-amber-600',
      bgColor: 'bg-amber-100',
    },
    {
      label: 'Posts Pending',
      value: formatNumber(posts_pending),
      icon: <PendingIcon />,
      color: 'text-slate-600',
      bgColor: 'bg-slate-100',
    },
  ];

  return (
    <div className={`grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 ${className}`}>
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
