/**
 * AI Entity Index API Client
 *
 * Handles all REST API communication with the WordPress backend.
 */

import { toast } from 'sonner';

/**
 * Get vibeAiData from window, with fallbacks.
 */
const getConfig = () => {
	const data = window.vibeAiData || {};
	return {
		apiUrl: data.apiUrl || '/wp-json/vibe-ai/v1',
		nonce: data.nonce || '',
	};
};

/**
 * Shared request logic: nonce check, header construction, fetch, and error parsing.
 *
 * @param {string} endpoint - API endpoint (relative to apiUrl).
 * @param {Object} options  - Fetch options.
 * @return {Promise<{response: Response, isJson: boolean}>} Raw response with metadata.
 * @throws {Error} - On network or API error.
 */
async function baseRequest( endpoint, options = {} ) {
	const config = getConfig();
	const url = `${ config.apiUrl }${ endpoint }`;

	if ( ! config.nonce ) {
		throw new Error( 'Security nonce is missing for API request' );
	}

	const headers = {
		'X-WP-Nonce': config.nonce,
		...options.headers,
	};

	if ( options.body ) {
		headers[ 'Content-Type' ] = 'application/json';
	}

	const response = await fetch( url, {
		...options,
		headers,
		credentials: 'same-origin',
	} );

	const contentType = response.headers.get( 'content-type' );
	const isJson = contentType && contentType.includes( 'application/json' );

	if ( ! response.ok ) {
		let errorMessage = `API Error: ${ response.status } ${ response.statusText }`;

		if ( isJson ) {
			try {
				const errorData = await response.json();
				errorMessage =
					errorData.message || errorData.error || errorMessage;
			} catch {
				// Use default error message
			}
		}

		const error = new Error( errorMessage );
		error.status = response.status;
		error.response = response;
		throw error;
	}

	return { response, isJson };
}

/**
 * Base fetch wrapper with WordPress authentication.
 *
 * @param {string} endpoint - API endpoint (relative to apiUrl).
 * @param {Object} options  - Fetch options.
 * @return {Promise<any>} - Parsed JSON response.
 * @throws {Error} - On network or API error.
 */
export async function apiFetch( endpoint, options = {} ) {
	const { response, isJson } = await baseRequest( endpoint, options );

	// Handle 204 No Content
	if ( response.status === 204 ) {
		return null;
	}

	if ( ! isJson ) {
		return { success: true };
	}

	return response.json();
}

export async function apiFetchPaginated( endpoint, options = {} ) {
	const { response, isJson } = await baseRequest( endpoint, options );

	const data = isJson ? await response.json() : { success: true };

	return {
		data,
		totalCount: parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 ),
		totalPages: parseInt(
			response.headers.get( 'X-WP-TotalPages' ) || '1',
			10
		),
	};
}

function pickFirstFilterValue( value ) {
	if ( Array.isArray( value ) ) {
		return value[ 0 ] || '';
	}

	if ( typeof value === 'string' ) {
		return (
			value
				.split( ',' )
				.map( ( item ) => item.trim() )
				.filter( Boolean )[ 0 ] || ''
		);
	}

	return value || '';
}

function normalizeLogEntry( entry = {} ) {
	const normalizedLevel = String( entry.level || 'info' ).toLowerCase();

	return {
		...entry,
		level: normalizedLevel === 'warning' ? 'warn' : normalizedLevel,
	};
}

function normalizeMainSettings( response = {} ) {
	const settings = response.settings || response;
	const availablePostTypes = Array.isArray( settings.available_post_types )
		? settings.available_post_types
		: ( settings.supported_post_types || [] ).map( ( name ) => ( {
				name,
				label: name.charAt( 0 ).toUpperCase() + name.slice( 1 ),
		  } ) );

	return {
		...settings,
		ai_model:
			settings.ai_model ||
			settings.extraction_model ||
			'anthropic/claude-opus-4.5',
		batch_size: settings.batch_size || 25,
		confidence_threshold: settings.confidence_threshold ?? 0.6,
		post_types: settings.post_types ||
			settings.default_post_types || [ 'post', 'page' ],
		available_post_types: availablePostTypes,
	};
}

// ============================================================================
// Status Endpoints
// ============================================================================

/**
 * Fetch current pipeline status and statistics.
 *
 * @return {Promise<Object>} Status object with pipeline state and stats.
 */
export async function fetchStatus() {
	return apiFetch( '/status' );
}

// ============================================================================
// Entity Endpoints
// ============================================================================

/**
 * Fetch paginated list of entities.
 *
 * @param {Object} params          - Query parameters.
 * @param {number} params.page     - Page number (1-indexed).
 * @param {number} params.per_page - Items per page.
 * @param {string} params.search   - Search term.
 * @param {string} params.type     - Entity type filter.
 * @param {string} params.orderby  - Sort field.
 * @param {string} params.order    - Sort direction (asc/desc).
 * @return {Promise<Object>} Paginated entities with meta.
 */
export async function fetchEntities( params = {} ) {
	const queryParams = new URLSearchParams();
	const normalizedParams = { ...params };

	const type = pickFirstFilterValue( params.types ?? params.type );
	if ( type ) {
		normalizedParams.type = type;
	}
	delete normalizedParams.types;

	const status = pickFirstFilterValue( params.statuses ?? params.status );
	if ( status ) {
		normalizedParams.status = status;
	}
	delete normalizedParams.statuses;

	Object.entries( normalizedParams ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== null && value !== '' ) {
			queryParams.append( key, value );
		}
	} );

	const queryString = queryParams.toString();
	const endpoint = queryString ? `/entities?${ queryString }` : '/entities';

	const result = await apiFetchPaginated( endpoint );
	return {
		entities: result.data?.entities || [],
		totalCount: result.totalCount || result.data?.total || 0,
		totalPages: result.totalPages || result.data?.pages || 1,
	};
}

/**
 * Fetch a single entity by ID.
 *
 * @param {number|string} id - Entity ID.
 * @return {Promise<Object>} Entity data with mentions.
 */
export async function fetchEntity( id ) {
	return apiFetch( `/entities/${ id }` );
}

/**
 * Update an entity.
 *
 * @param {number|string} id   - Entity ID.
 * @param {Object}        data - Fields to update.
 * @return {Promise<Object>} Updated entity data.
 */
export async function updateEntity( id, data ) {
	return apiFetch( `/entities/${ id }`, {
		method: 'PUT',
		body: JSON.stringify( data ),
	} );
}

/**
 * Delete an entity.
 *
 * @param {number|string} id - Entity ID.
 * @return {Promise<Object>} Deletion confirmation.
 */
export async function deleteEntity( id ) {
	return apiFetch( `/entities/${ id }`, {
		method: 'DELETE',
	} );
}

/**
 * Create a new entity.
 *
 * @param {Object} data             - Entity data.
 * @param {string} data.name        - Entity name (required).
 * @param {string} data.type        - Entity type (required).
 * @param {string} [data.status]    - Entity status.
 * @param {string} [data.description] - Entity description.
 * @param {string[]} [data.aliases] - Array of alias names.
 * @return {Promise<Object>} Created entity data.
 */
export async function createEntity( data ) {
	return apiFetch( '/entities', {
		method: 'POST',
		body: JSON.stringify( data ),
	} );
}

/**
 * Merge multiple entities into one.
 *
 * @param {Object}   params            - Merge parameters.
 * @param {number}   params.target_id  - ID of entity to merge into.
 * @param {number[]} params.source_ids - IDs of entities to merge from.
 * @return {Promise<Object>} Merged entity data.
 */
export async function mergeEntities( { target_id, source_ids } ) {
	return apiFetch( '/entities/merge', {
		method: 'POST',
		body: JSON.stringify( { target_id, source_ids } ),
	} );
}

// ============================================================================
// Pipeline Endpoints
// ============================================================================

/**
 * Start the entity extraction pipeline.
 *
 * @param {Object}   options              - Pipeline options.
 * @param {boolean}  options.full_reindex - Whether to reindex all posts.
 * @param {number[]} options.post_ids     - Specific post IDs to process.
 * @return {Promise<Object>} Pipeline start confirmation.
 */
export async function startPipeline( options = {} ) {
	return apiFetch( '/pipeline/start', {
		method: 'POST',
		body: JSON.stringify( options ),
	} );
}

/**
 * Stop the running pipeline.
 *
 * @return {Promise<Object>} Pipeline stop confirmation.
 */
export async function stopPipeline() {
	return apiFetch( '/pipeline/stop', {
		method: 'POST',
	} );
}

/**
 * Get current pipeline status.
 *
 * @return {Promise<Object>} Pipeline status with progress.
 */
export async function getPipelineStatus() {
	return apiFetch( '/pipeline/status' );
}

// ============================================================================
// Log Endpoints
// ============================================================================

/**
 * Fetch recent log entries.
 *
 * @param {Object} params       - Query parameters.
 * @param {number} params.limit - Maximum entries to return.
 * @param {string} params.level - Minimum log level filter.
 * @param {number} params.since - Timestamp to fetch logs after.
 * @return {Promise<Object>} Log entries array.
 */
export async function fetchLogs( params = {} ) {
	const queryParams = new URLSearchParams();
	const normalizedParams = { ...params };

	if ( normalizedParams.level === 'warn' ) {
		normalizedParams.level = 'warning';
	} else if ( normalizedParams.level === 'api' ) {
		normalizedParams.level = 'info';
	}

	// Preserve page and per_page for server-side pagination
	const page = normalizedParams.page || 1;
	const perPage = normalizedParams.per_page || normalizedParams.limit || 50;
	normalizedParams.page = page;
	normalizedParams.per_page = perPage;
	delete normalizedParams.limit;

	Object.entries( normalizedParams ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== null ) {
			queryParams.append( key, value );
		}
	} );

	const queryString = queryParams.toString();
	const endpoint = queryString ? `/logs?${ queryString }` : '/logs';

	const result = await apiFetch( endpoint );
	return {
		logs: ( result.entries || [] ).map( normalizeLogEntry ),
		totalCount: result.total || result.count || ( result.entries || [] ).length,
		totalPages: result.total_pages || Math.ceil( ( result.total || 0 ) / perPage ) || 1,
	};
}

// ============================================================================
// Settings Endpoints
// ============================================================================

/**
 * Fetch plugin settings.
 *
 * @return {Promise<Object>} Current settings.
 */
export async function fetchSettings() {
	const response = await apiFetch( '/settings' );
	return normalizeMainSettings( response );
}

/**
 * Update plugin settings.
 *
 * @param {Object} settings - Settings to update.
 * @return {Promise<Object>} Updated settings.
 */
export async function updateSettings( settings ) {
	const payload = {
		vibe_ai_model: settings.ai_model,
		vibe_ai_batch_size: settings.batch_size,
		vibe_ai_confidence_threshold: settings.confidence_threshold,
		vibe_ai_post_types: settings.post_types,
		vibe_ai_logging_enabled: settings.logging_enabled,
		vibe_ai_log_level: settings.log_level,
	};

	return apiFetch( '/settings', {
		method: 'PUT',
		body: JSON.stringify( payload ),
	} );
}

export async function fetchEntityMentions( entityId, limit = 5 ) {
	return apiFetch( `/entities/${ entityId }/mentions?limit=${ limit }` );
}

export async function propagateEntity( id ) {
	return apiFetch( `/entities/${ id }/propagate`, { method: 'POST' } );
}

export async function forceSyncEntity( id ) {
	return apiFetch( `/entities/${ id }/force-sync`, { method: 'POST' } );
}

export async function bulkDeleteEntities( ids ) {
	return apiFetch( '/entities/bulk-delete', {
		method: 'POST',
		body: JSON.stringify( { entity_ids: ids } ),
	} );
}

export async function bulkUpdateEntitiesStatus( { ids, status } ) {
	return apiFetch( '/entities/bulk-status', {
		method: 'POST',
		body: JSON.stringify( { entity_ids: ids, status } ),
	} );
}

// ============================================================================
// Onboarding Endpoints
// ============================================================================

/**
 * Get current onboarding status.
 *
 * @return {Promise<Object>} Onboarding status with has_api_key and onboarding_complete flags.
 */
export async function getOnboardingStatus() {
	return apiFetch( '/onboarding-status' );
}

/**
 * Test an OpenRouter API key by making a minimal API call.
 *
 * @param {string} apiKey - The OpenRouter API key to validate.
 * @return {Promise<Object>} Result with success boolean or error message.
 */
export async function testConnection( apiKey ) {
	return apiFetch( '/test-connection', {
		method: 'POST',
		body: JSON.stringify( { api_key: apiKey } ),
	} );
}

/**
 * Mark onboarding as completed.
 *
 * @return {Promise<Object>} Success confirmation.
 */
export async function completeOnboarding() {
	return apiFetch( '/complete-onboarding', { method: 'POST' } );
}

/**
 * Create TanStack Query mutation options with toast notifications.
 *
 * @param {Object} options
 * @param {string} [options.successMessage] - Toast message on success.
 * @param {string} [options.errorMessage]   - Toast message on error.
 * @return {Object} Mutation options object.
 */
export function createMutationOptions( options = {} ) {
	return {
		onSuccess: () => {
			if ( options.successMessage ) {
				toast.success( options.successMessage );
			}
		},
		onError: ( error ) => {
			toast.error( options.errorMessage || error.message || 'An error occurred' );
		},
	};
}

export default {
	apiFetch,
	apiFetchPaginated,
	fetchStatus,
	fetchEntities,
	fetchEntity,
	createEntity,
	updateEntity,
	deleteEntity,
	mergeEntities,
	startPipeline,
	stopPipeline,
	getPipelineStatus,
	fetchLogs,
	fetchSettings,
	updateSettings,
	fetchEntityMentions,
	propagateEntity,
	forceSyncEntity,
	bulkDeleteEntities,
	bulkUpdateEntitiesStatus,
	getOnboardingStatus,
	testConnection,
	completeOnboarding,
};
