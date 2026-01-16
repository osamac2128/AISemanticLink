/**
 * KBTestSearch Component
 *
 * Search testing interface for the Knowledge Base.
 */

import { useState, useCallback, useEffect } from 'react';
import { useKBSearch } from '../../hooks/useKB';
import Button from '../common/Button';
import Badge from '../common/Badge';

/**
 * Get score badge variant based on value.
 *
 * @param {number} score - Similarity score (0-1).
 * @returns {string} Badge variant.
 */
function getScoreVariant(score) {
  if (score >= 0.8) return 'success';
  if (score >= 0.6) return 'warning';
  return 'error';
}

/**
 * Format score as percentage.
 *
 * @param {number} score - Similarity score (0-1).
 * @returns {string} Formatted percentage.
 */
function formatScore(score) {
  return `${(score * 100).toFixed(1)}%`;
}

/**
 * Truncate text with ellipsis.
 *
 * @param {string} text - Text to truncate.
 * @param {number} maxLength - Maximum length.
 * @returns {string} Truncated text.
 */
function truncateText(text, maxLength = 200) {
  if (!text || text.length <= maxLength) return text;
  return text.substring(0, maxLength).trim() + '...';
}

/**
 * Breadcrumb component for heading path.
 */
function HeadingPath({ path }) {
  if (!path || path.length === 0) return null;

  return (
    <div className="flex items-center gap-1 text-xs text-gray-500 flex-wrap">
      {path.map((heading, index) => (
        <span key={index} className="flex items-center">
          {index > 0 && (
            <svg className="w-3 h-3 mx-1 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
              <path fillRule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clipRule="evenodd" />
            </svg>
          )}
          <span className="text-gray-600">{heading}</span>
        </span>
      ))}
    </div>
  );
}

/**
 * Search result card component.
 */
function SearchResultCard({ result, onViewSource, isExpanded, onToggleExpand }) {
  return (
    <div className="bg-white rounded-lg border border-gray-200 overflow-hidden hover:border-gray-300 transition-colors">
      <div className="p-4">
        {/* Header */}
        <div className="flex items-start justify-between gap-4">
          <div className="flex-1 min-w-0">
            {/* Title */}
            <h3 className="text-sm font-medium text-gray-900 truncate">
              {result.title}
            </h3>
            {/* Heading path */}
            <HeadingPath path={result.heading_path} />
          </div>
          {/* Score badge */}
          <Badge variant={getScoreVariant(result.score)} size="sm">
            {formatScore(result.score)}
          </Badge>
        </div>

        {/* Content preview */}
        <div className="mt-3">
          <p className="text-sm text-gray-600 leading-relaxed">
            {isExpanded ? result.text : truncateText(result.text, 200)}
          </p>
        </div>

        {/* Actions */}
        <div className="mt-4 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <button
              onClick={onViewSource}
              className="inline-flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800"
            >
              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
              </svg>
              View Source
            </button>
            <button
              onClick={onToggleExpand}
              className="text-sm text-gray-500 hover:text-gray-700"
            >
              {isExpanded ? 'Show less' : 'Show details'}
            </button>
          </div>
          <span className="text-xs text-gray-400">
            {result.post_type}
          </span>
        </div>

        {/* Expanded metadata */}
        {isExpanded && (
          <div className="mt-4 pt-4 border-t border-gray-100">
            <div className="grid grid-cols-2 gap-4 text-xs">
              <div>
                <span className="text-gray-500">Post ID:</span>
                <span className="ml-2 text-gray-700">{result.post_id}</span>
              </div>
              <div>
                <span className="text-gray-500">Chunk ID:</span>
                <span className="ml-2 text-gray-700">{result.chunk_id || '-'}</span>
              </div>
              <div>
                <span className="text-gray-500">Anchor:</span>
                <span className="ml-2 text-gray-700 font-mono">{result.anchor || '-'}</span>
              </div>
              <div>
                <span className="text-gray-500">Tokens:</span>
                <span className="ml-2 text-gray-700">{result.token_count || '-'}</span>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

/**
 * Empty state component.
 */
function EmptyState({ hasQuery }) {
  return (
    <div className="text-center py-12">
      <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
      </svg>
      <h3 className="mt-2 text-sm font-medium text-gray-900">
        {hasQuery ? 'No results found' : 'Search your Knowledge Base'}
      </h3>
      <p className="mt-1 text-sm text-gray-500">
        {hasQuery
          ? 'Try adjusting your search query or filters'
          : 'Enter a search query to find relevant content chunks'}
      </p>
    </div>
  );
}

/**
 * KBTestSearch main component.
 *
 * @param {Object} props - Component props.
 * @param {Object} props.vibeAiData - Localized data from WordPress.
 * @returns {JSX.Element} KBTestSearch element.
 */
export default function KBTestSearch({ vibeAiData }) {
  // State
  const [query, setQuery] = useState('');
  const [topK, setTopK] = useState(8);
  const [filters, setFilters] = useState({
    post_type: '',
    date_from: '',
    date_to: '',
  });
  const [results, setResults] = useState(null);
  const [queryTime, setQueryTime] = useState(null);
  const [expandedResults, setExpandedResults] = useState(new Set());

  // Search mutation
  const searchMutation = useKBSearch();

  // Debounced search
  const [debouncedQuery, setDebouncedQuery] = useState('');

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(query);
    }, 500);

    return () => clearTimeout(timer);
  }, [query]);

  // Auto-search on debounced query change
  useEffect(() => {
    if (debouncedQuery.trim().length >= 3) {
      handleSearch();
    }
  }, [debouncedQuery, topK, filters]);

  // Handle search
  const handleSearch = useCallback(async () => {
    if (!query.trim()) return;

    const startTime = performance.now();

    try {
      const response = await searchMutation.mutateAsync({
        query: query.trim(),
        topK,
        filters: {
          ...(filters.post_type && { post_type: filters.post_type }),
          ...(filters.date_from && { date_from: filters.date_from }),
          ...(filters.date_to && { date_to: filters.date_to }),
        },
      });

      setResults(response.results || []);
      setQueryTime(Math.round(performance.now() - startTime));
      setExpandedResults(new Set());
    } catch (err) {
      // Error handled by mutation
      setResults([]);
    }
  }, [query, topK, filters, searchMutation]);

  // Handle view source
  const handleViewSource = useCallback((result) => {
    const url = result.anchor
      ? `${result.url}#${result.anchor}`
      : result.url;
    window.open(url, '_blank');
  }, []);

  // Toggle result expansion
  const toggleExpand = useCallback((index) => {
    setExpandedResults((prev) => {
      const next = new Set(prev);
      if (next.has(index)) {
        next.delete(index);
      } else {
        next.add(index);
      }
      return next;
    });
  }, []);

  // Clear all
  const handleClear = useCallback(() => {
    setQuery('');
    setResults(null);
    setQueryTime(null);
    setExpandedResults(new Set());
  }, []);

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-slate-800">Test Search</h1>
        <p className="mt-1 text-slate-500">
          Test semantic search against your Knowledge Base
        </p>
      </div>

      {/* Search Panel */}
      <div className="bg-white rounded-lg border border-gray-200 p-6 space-y-6">
        {/* Search input */}
        <div>
          <label htmlFor="search-query" className="block text-sm font-medium text-gray-700 mb-2">
            Search Query
          </label>
          <div className="relative">
            <input
              id="search-query"
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  handleSearch();
                }
              }}
              placeholder="Enter your search query..."
              className="w-full px-4 py-3 text-lg border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
            {query && (
              <button
                onClick={handleClear}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
              >
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            )}
          </div>
        </div>

        {/* Filters row */}
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          {/* Top-K slider */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Results (Top-K): <span className="text-blue-600 font-semibold">{topK}</span>
            </label>
            <input
              type="range"
              min={1}
              max={20}
              step={1}
              value={topK}
              onChange={(e) => setTopK(parseInt(e.target.value, 10))}
              className="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-blue-600"
            />
          </div>

          {/* Post type filter */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Post Type
            </label>
            <select
              value={filters.post_type}
              onChange={(e) => setFilters((prev) => ({ ...prev, post_type: e.target.value }))}
              className="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">All Types</option>
              <option value="post">Posts</option>
              <option value="page">Pages</option>
              <option value="product">Products</option>
            </select>
          </div>

          {/* Date from */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              From Date
            </label>
            <input
              type="date"
              value={filters.date_from}
              onChange={(e) => setFilters((prev) => ({ ...prev, date_from: e.target.value }))}
              className="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          {/* Date to */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              To Date
            </label>
            <input
              type="date"
              value={filters.date_to}
              onChange={(e) => setFilters((prev) => ({ ...prev, date_to: e.target.value }))}
              className="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
        </div>

        {/* Search button */}
        <div className="flex items-center justify-between">
          <Button
            variant="primary"
            size="lg"
            onClick={handleSearch}
            loading={searchMutation.isLoading}
            disabled={!query.trim()}
          >
            <svg className="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            Search
          </Button>

          {queryTime !== null && (
            <span className="text-sm text-gray-500">
              Query time: {queryTime}ms
            </span>
          )}
        </div>
      </div>

      {/* Error */}
      {searchMutation.isError && (
        <div className="p-4 bg-red-50 border border-red-200 rounded-lg">
          <div className="flex items-start gap-3">
            <svg className="w-5 h-5 text-red-500 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
              <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
            </svg>
            <div>
              <h3 className="font-medium text-red-800">Search failed</h3>
              <p className="text-sm text-red-600 mt-1">
                {searchMutation.error?.message || 'An error occurred while searching'}
              </p>
            </div>
          </div>
        </div>
      )}

      {/* Results */}
      <div className="space-y-4">
        {/* Results header */}
        {results !== null && results.length > 0 && (
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-medium text-gray-900">
              {results.length} result{results.length !== 1 ? 's' : ''} found
            </h2>
            <button
              onClick={() => setExpandedResults(
                expandedResults.size === results.length
                  ? new Set()
                  : new Set(results.map((_, i) => i))
              )}
              className="text-sm text-blue-600 hover:text-blue-800"
            >
              {expandedResults.size === results.length ? 'Collapse all' : 'Expand all'}
            </button>
          </div>
        )}

        {/* Results list */}
        {results === null ? (
          <EmptyState hasQuery={false} />
        ) : results.length === 0 ? (
          <EmptyState hasQuery={true} />
        ) : (
          <div className="space-y-4">
            {results.map((result, index) => (
              <SearchResultCard
                key={result.chunk_id || index}
                result={result}
                isExpanded={expandedResults.has(index)}
                onToggleExpand={() => toggleExpand(index)}
                onViewSource={() => handleViewSource(result)}
              />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
