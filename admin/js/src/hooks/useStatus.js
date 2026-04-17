/**
 * useStatus Hook
 *
 * Fetches and polls the pipeline status.
 * Automatically polls every 2 seconds when pipeline is running.
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import { fetchStatus } from '../api/client';

/**
 * Default status state.
 */
const DEFAULT_STATUS = {
	pipeline: {
		running: false,
		phase: null,
		progress: 0,
		current_item: null,
		total_items: 0,
		processed_items: 0,
		started_at: null,
		eta: null,
		error: null,
	},
	stats: {
		total_entities: 0,
		total_mentions: 0,
		avg_confidence: 0,
		posts_pending: 0,
		posts_processed: 0,
	},
	semantic_health: {
		summary: {
			score: 0,
			status: 'attention',
			checks_total: 0,
			checks_passed: 0,
			checks_warning: 0,
			checks_failed: 0,
		},
		schema: {
			enabled: true,
			post_types: [],
			eligible_posts: 0,
			cached_posts: 0,
			valid_cached_posts: 0,
			missing_posts: 0,
			stale_posts: 0,
			flagged_for_refresh: 0,
			disabled_posts: 0,
			coverage_ratio: 0,
			cache_version: 0,
		},
		entities: {
			total_entities: 0,
			total_mentions: 0,
			avg_confidence: 0,
			posts_with_entities: 0,
			posts_without_entities: 0,
			coverage_ratio: 0,
			avg_entities_per_post: 0,
		},
		knowledge_base: {
			enabled: false,
			post_types: [],
			eligible_posts: 0,
			tracked_docs: 0,
			indexed_docs: 0,
			pending_docs: 0,
			chunked_docs: 0,
			failed_docs: 0,
			excluded_docs: 0,
			coverage_ratio: 0,
			last_indexed_at: null,
		},
		ai_publishing: {
			llms_txt: {},
			ai_sitemap: {},
			changes: {},
		},
		checks: [],
	},
	logs: [],
};

/**
 * Polling interval in milliseconds.
 */
const POLL_INTERVAL = 2000;

/**
 * Custom hook for pipeline status management.
 *
 * @param {Object}  options           - Hook options.
 * @param {boolean} options.autoStart - Whether to fetch immediately on mount.
 * @return {Object} Status state and control functions.
 */
export function useStatus( options = {} ) {
	const { autoStart = true } = options;

	const [ status, setStatus ] = useState( DEFAULT_STATUS );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const pollIntervalRef = useRef( null );
	const mountedRef = useRef( true );

	/**
	 * Fetch the current status from the API.
	 */
	const refresh = useCallback( async () => {
		try {
			const data = await fetchStatus();

			if ( mountedRef.current ) {
				setStatus( ( prev ) => ( {
					...prev,
					...data,
					pipeline: {
						...prev.pipeline,
						...data.pipeline,
					},
					stats: {
						...prev.stats,
						...data.stats,
					},
					semantic_health: {
						...prev.semantic_health,
						...data.semantic_health,
					},
					logs: data.logs || prev.logs,
				} ) );
				setError( null );
			}
		} catch ( err ) {
			if ( mountedRef.current ) {
				setError( err.message || 'Failed to fetch status' );
			}
		} finally {
			if ( mountedRef.current ) {
				setLoading( false );
			}
		}
	}, [] );

	/**
	 * Start polling for status updates.
	 */
	const startPolling = useCallback( () => {
		if ( pollIntervalRef.current ) {
			return;
		}

		pollIntervalRef.current = setInterval( () => {
			refresh();
		}, POLL_INTERVAL );
	}, [ refresh ] );

	/**
	 * Stop polling for status updates.
	 */
	const stopPolling = useCallback( () => {
		if ( pollIntervalRef.current ) {
			clearInterval( pollIntervalRef.current );
			pollIntervalRef.current = null;
		}
	}, [] );

	// Effect: Initial fetch
	useEffect( () => {
		mountedRef.current = true;

		if ( autoStart ) {
			refresh();
		}

		return () => {
			mountedRef.current = false;
		};
	}, [ autoStart, refresh ] );

	// Effect: Auto-poll when pipeline is running
	useEffect( () => {
		if ( status.pipeline?.running ) {
			startPolling();
		} else {
			stopPolling();
		}

		return () => {
			stopPolling();
		};
	}, [ status.pipeline?.running, startPolling, stopPolling ] );

	return {
		// State
		status,
		pipeline: status.pipeline,
		stats: status.stats,
		logs: status.logs,
		loading,
		error,
		isRunning: status.pipeline?.running || false,

		// Actions
		refresh,
		startPolling,
		stopPolling,
	};
}

export default useStatus;
