/**
 * useKB Hooks
 *
 * React Query hooks for Knowledge Base API operations.
 */

import {
	useQuery,
	useMutation,
	useQueryClient,
	keepPreviousData,
} from '@tanstack/react-query';

/**
 * Get API configuration from window.
 */
const getConfig = () => {
	const data = window.vibeAiData || {};
	return {
		apiUrl: data.apiUrl || '/wp-json/vibe-ai/v1',
		nonce: data.nonce || '',
	};
};

/**
 * Base API fetch wrapper.
 *
 * @param {string} endpoint - API endpoint.
 * @param {Object} options  - Fetch options.
 * @return {Promise<any>} API response.
 */
async function apiFetch( endpoint, options = {} ) {
	const config = getConfig();
	const url = `${ config.apiUrl }${ endpoint }`;

	const headers = {
		'X-WP-Nonce': config.nonce,
		...( options.body ? { 'Content-Type': 'application/json' } : {} ),
		...options.headers,
	};

	const response = await fetch( url, {
		...options,
		headers,
		credentials: 'same-origin',
	} );

	if ( ! response.ok ) {
		const errorData = await response.json().catch( () => ( {} ) );
		const error = new Error(
			errorData.message || `API Error: ${ response.status }`
		);
		error.status = response.status;
		throw error;
	}

	return response.json();
}

function normalizeKBDocument( document = {} ) {
	return {
		...document,
		last_indexed: document.last_indexed || document.last_indexed_at || null,
	};
}

function normalizeKBStatus( response = {} ) {
	const pipeline = response.pipeline || {};
	const progress =
		typeof pipeline.progress === 'object' ? pipeline.progress : {};
	const completed = progress.completed ?? pipeline.processed_items ?? 0;
	const failed = progress.failed ?? pipeline.failed_items ?? 0;
	const skipped = progress.skipped ?? 0;

	return {
		...response,
		pipeline: {
			...pipeline,
			current_item:
				pipeline.current_item ||
				pipeline.current_phase ||
				pipeline.phase ||
				null,
			progress:
				typeof pipeline.progress === 'number'
					? pipeline.progress
					: progress.percentage ?? 0,
			total_items: progress.total ?? pipeline.total_items ?? 0,
			processed_items: completed + failed + skipped,
			failed_items: failed,
			started_at: pipeline.started_at || response.started_at || null,
		},
		stats: {
			...response.stats,
			indexed:
				response.stats?.indexed_docs ?? response.stats?.indexed ?? 0,
			pending:
				response.stats?.pending_docs ?? response.stats?.pending ?? 0,
			chunked:
				response.stats?.chunked_docs ?? response.stats?.chunked ?? 0,
			failed: response.stats?.failed_docs ?? response.stats?.failed ?? 0,
			last_run:
				response.last_indexed_at ||
				response.stats?.last_indexed_at ||
				null,
		},
		recent_activity: response.recent_activity || [],
	};
}

function normalizeKBSettings( settings = {} ) {
	return {
		...settings,
		enabled: settings.enabled ?? settings.kb_enabled ?? false,
		kb_enabled: settings.kb_enabled ?? settings.enabled ?? false,
		post_types: settings.post_types || [ 'post', 'page' ],
		embedding_model: settings.embedding_model || 'text-embedding-3-small',
		chunk_target_tokens:
			settings.chunk_target_tokens ?? settings.chunk_size ?? 450,
		chunk_overlap_tokens:
			settings.chunk_overlap_tokens ?? settings.chunk_overlap ?? 60,
		chunk_size: settings.chunk_size ?? settings.chunk_target_tokens ?? 450,
		chunk_overlap:
			settings.chunk_overlap ?? settings.chunk_overlap_tokens ?? 60,
		auto_index: settings.auto_index ?? true,
		available_post_types: settings.available_post_types || [],
	};
}

/**
 * API client methods for KB operations.
 */
export const apiClient = {
	/**
	 * GET request.
	 * @param endpoint
	 * @param params
	 */
	get: ( endpoint, params = {} ) => {
		const queryString = new URLSearchParams(
			Object.entries( params ).filter(
				( [ , v ] ) => v !== undefined && v !== null && v !== ''
			)
		).toString();

		const url = queryString ? `${ endpoint }?${ queryString }` : endpoint;
		return apiFetch( url );
	},

	/**
	 * POST request.
	 * @param endpoint
	 * @param data
	 */
	post: ( endpoint, data = {} ) => {
		return apiFetch( endpoint, {
			method: 'POST',
			body: JSON.stringify( data ),
		} );
	},

	/**
	 * PUT request.
	 * @param endpoint
	 * @param data
	 */
	put: ( endpoint, data = {} ) => {
		return apiFetch( endpoint, {
			method: 'PUT',
			body: JSON.stringify( data ),
		} );
	},

	/**
	 * DELETE request.
	 * @param endpoint
	 */
	delete: ( endpoint ) => {
		return apiFetch( endpoint, {
			method: 'DELETE',
		} );
	},
};

// ============================================================================
// Knowledge Base Hooks
// ============================================================================

/**
 * Hook for fetching KB status.
 *
 * Polls every 2s when pipeline is running.
 *
 * @return {Object} Query result with status data.
 */
export function useKBStatus() {
	return useQuery( {
		queryKey: [ 'kb-status' ],
		queryFn: async () =>
			normalizeKBStatus( await apiClient.get( '/kb/status' ) ),
		refetchInterval: ( query ) => {
			return query?.state?.data?.pipeline?.running ? 2000 : false;
		},
		staleTime: 1000,
	} );
}

/**
 * Hook for KB search mutations.
 *
 * @return {Object} Mutation for search operations.
 */
export function useKBSearch() {
	return useMutation( {
		mutationFn: ( { query, topK, filters } ) =>
			apiClient.post( '/kb/search', {
				query,
				top_k: topK,
				filters,
			} ),
	} );
}

/**
 * Hook for fetching KB documents with pagination.
 *
 * @param {number} page    - Page number (1-indexed).
 * @param {number} perPage - Items per page.
 * @param {Object} filters - Filter parameters.
 * @return {Object} Query result with documents.
 */
export function useKBDocuments( page, perPage, filters = {} ) {
	return useQuery( {
		queryKey: [ 'kb-docs', page, perPage, filters ],
		queryFn: async () => {
			const config = getConfig();
			const params = new URLSearchParams( {
				page: page.toString(),
				per_page: perPage.toString(),
				...( filters.search && { search: filters.search } ),
				...( filters.post_type && { post_type: filters.post_type } ),
				...( filters.status && { status: filters.status } ),
			} );

			const response = await fetch(
				`${ config.apiUrl }/kb/docs?${ params }`,
				{
					headers: {
						'X-WP-Nonce': config.nonce,
					},
					credentials: 'same-origin',
				}
			);

			if ( ! response.ok ) {
				throw new Error( 'Failed to fetch documents' );
			}

			const docs = await response.json();
			const totalCount = parseInt(
				response.headers.get( 'X-WP-Total' ) || '0',
				10
			);
			const totalPages = parseInt(
				response.headers.get( 'X-WP-TotalPages' ) || '1',
				10
			);

			return {
				docs: ( docs.documents || [] ).map( normalizeKBDocument ),
				total_count: totalCount || docs.total || 0,
				total_pages: totalPages || docs.total_pages || 1,
			};
		},
		placeholderData: keepPreviousData,
		staleTime: 30000,
	} );
}

/**
 * Hook for fetching a single KB document with chunks.
 *
 * @param {number|string} postId - Post ID.
 * @return {Object} Query result with document data.
 */
export function useKBDocument( postId ) {
	return useQuery( {
		queryKey: [ 'kb-doc', postId ],
		queryFn: async () => {
			const document = await apiClient.get( `/kb/docs/${ postId }` );
			return {
				...normalizeKBDocument( document ),
				chunks: document.chunks || [],
			};
		},
		enabled: !! postId,
	} );
}

/**
 * Hook for KB reindex mutations.
 *
 * @return {Object} Mutation for reindex operations.
 */
export function useKBReindex() {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: async ( options = {} ) => {
			const postIds = Array.isArray( options.post_ids )
				? options.post_ids.filter( Boolean )
				: [];

			if ( postIds.length > 0 ) {
				const responses = await Promise.all(
					postIds.map( ( postId ) =>
						apiClient.post( `/kb/docs/${ postId }/reindex` )
					)
				);

				return responses.length === 1
					? responses[ 0 ]
					: {
							success: true,
							results: responses,
					  };
			}

			return apiClient.post( '/kb/reindex', {
				post_types: options.post_types,
				force: options.force ?? options.full ?? false,
			} );
		},
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'kb-status' ] } );
			queryClient.invalidateQueries( { queryKey: [ 'kb-docs' ] } );
		},
	} );
}

/**
 * Hook for fetching KB settings.
 *
 * @return {Object} Query result with settings data.
 */
export function useKBSettings() {
	return useQuery( {
		queryKey: [ 'kb-settings' ],
		queryFn: async () =>
			normalizeKBSettings( await apiClient.get( '/kb/settings' ) ),
		staleTime: 60000, // 1 minute
	} );
}

/**
 * Hook for updating KB settings.
 *
 * @return {Object} Mutation for updating settings.
 */
export function useUpdateKBSettings() {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( settings ) =>
			apiClient.post( '/kb/settings', {
				kb_enabled: settings.kb_enabled ?? settings.enabled,
				embedding_model: settings.embedding_model,
				chunk_size: settings.chunk_size ?? settings.chunk_target_tokens,
				chunk_overlap:
					settings.chunk_overlap ?? settings.chunk_overlap_tokens,
				post_types: settings.post_types,
				auto_index: settings.auto_index,
			} ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'kb-settings' ] } );
		},
	} );
}

/**
 * Hook for excluding/including documents.
 *
 * @return {Object} Mutation for exclude/include operations.
 */
export function useKBExclude() {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( { postIds, exclude } ) =>
			apiClient.post( '/kb/docs/exclude', {
				post_ids: postIds,
				exclude,
			} ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'kb-docs' ] } );
			queryClient.invalidateQueries( { queryKey: [ 'kb-status' ] } );
		},
	} );
}

/**
 * Hook for fetching KB logs.
 *
 * @param {Object} params - Log query parameters.
 * @return {Object} Query result with logs.
 */
export function useKBLogs( params = {} ) {
	const { perPage = 50, component = 'all', level } = params;

	return useQuery( {
		queryKey: [ 'kb-logs', perPage, component, level ],
		queryFn: async () => {
			const logs = await apiClient.get( '/kb/logs', {
				limit: perPage,
				component,
				...( level && { level } ),
			} );

			return {
				logs: logs.entries || [],
				totalCount: logs.count || ( logs.entries || [] ).length,
				totalPages: 1,
			};
		},
		placeholderData: keepPreviousData,
		staleTime: 30000,
	} );
}

// Default export with all hooks
export default {
	useKBStatus,
	useKBSearch,
	useKBDocuments,
	useKBDocument,
	useKBReindex,
	useKBSettings,
	useUpdateKBSettings,
	useKBExclude,
	useKBLogs,
	apiClient,
};
