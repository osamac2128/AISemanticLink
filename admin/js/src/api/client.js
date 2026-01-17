/**
 * AI Entity Index API Client
 *
 * Handles all REST API communication with the WordPress backend.
 */

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
 * Base fetch wrapper with WordPress authentication.
 *
 * @param {string} endpoint - API endpoint (relative to apiUrl).
 * @param {Object} options - Fetch options.
 * @returns {Promise<any>} - Parsed JSON response.
 * @throws {Error} - On network or API error.
 */
async function apiFetch(endpoint, options = {}) {
  const config = getConfig();
  const url = `${config.apiUrl}${endpoint}`;

  if (!config.nonce) {
    console.error('CRITICAL: VibeAiData Nonce is MISSING!', window.vibeAiData);
    // alert('VibeAI Error: Nonce missing. Please report this.');
  }

  /* 
   * SIMPLIFICATION:
   * Only add Content-Type: application/json if there is a body.
   * Sending it on GET requests triggers 403s on some strict server configs (ModSecurity etc).
   */
  const headers = {
    'X-WP-Nonce': config.nonce,
    ...options.headers,
  };

  // Add JSON content type only if we have a body (POST/PUT/PATCH)
  if (options.body) {
    headers['Content-Type'] = 'application/json';
  }

  // Use console.error to ensure visibility in user's filters
  console.log(`[DEBUG] API Request: ${endpoint}`, { headers, config });

  const response = await fetch(url, {
    ...options,
    headers,
    credentials: 'same-origin',
  });

  // Handle non-JSON responses
  const contentType = response.headers.get('content-type');
  const isJson = contentType && contentType.includes('application/json');

  if (!response.ok) {
    if (response.status === 403) {
      console.error('CRITICAL: 403 Forbidden. Headers sent:', headers);
      console.error('Window VibeData:', window.vibeAiData);
    }

    let errorMessage = `API Error: ${response.status} ${response.statusText}`;

    if (isJson) {
      try {
        const errorData = await response.json();
        errorMessage = errorData.message || errorData.error || errorMessage;
      } catch {
        // Use default error message
      }
    }

    const error = new Error(errorMessage);
    error.status = response.status;
    error.response = response;
    throw error;
  }

  if (!isJson) {
    return { success: true };
  }

  return response.json();
}

// ============================================================================
// Status Endpoints
// ============================================================================

/**
 * Fetch current pipeline status and statistics.
 *
 * @returns {Promise<Object>} Status object with pipeline state and stats.
 */
export async function fetchStatus() {
  return apiFetch('/status');
}

// ============================================================================
// Entity Endpoints
// ============================================================================

/**
 * Fetch paginated list of entities.
 *
 * @param {Object} params - Query parameters.
 * @param {number} params.page - Page number (1-indexed).
 * @param {number} params.per_page - Items per page.
 * @param {string} params.search - Search term.
 * @param {string} params.type - Entity type filter.
 * @param {string} params.orderby - Sort field.
 * @param {string} params.order - Sort direction (asc/desc).
 * @returns {Promise<Object>} Paginated entities with meta.
 */
export async function fetchEntities(params = {}) {
  const queryParams = new URLSearchParams();

  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      queryParams.append(key, value);
    }
  });

  const queryString = queryParams.toString();
  const endpoint = queryString ? `/entities?${queryString}` : '/entities';

  return apiFetch(endpoint);
}

/**
 * Fetch a single entity by ID.
 *
 * @param {number|string} id - Entity ID.
 * @returns {Promise<Object>} Entity data with mentions.
 */
export async function fetchEntity(id) {
  return apiFetch(`/entities/${id}`);
}

/**
 * Update an entity.
 *
 * @param {number|string} id - Entity ID.
 * @param {Object} data - Fields to update.
 * @returns {Promise<Object>} Updated entity data.
 */
export async function updateEntity(id, data) {
  return apiFetch(`/entities/${id}`, {
    method: 'PUT',
    body: JSON.stringify(data),
  });
}

/**
 * Delete an entity.
 *
 * @param {number|string} id - Entity ID.
 * @returns {Promise<Object>} Deletion confirmation.
 */
export async function deleteEntity(id) {
  return apiFetch(`/entities/${id}`, {
    method: 'DELETE',
  });
}

/**
 * Merge multiple entities into one.
 *
 * @param {Object} params - Merge parameters.
 * @param {number} params.target_id - ID of entity to merge into.
 * @param {number[]} params.source_ids - IDs of entities to merge from.
 * @returns {Promise<Object>} Merged entity data.
 */
export async function mergeEntities({ target_id, source_ids }) {
  return apiFetch('/entities/merge', {
    method: 'POST',
    body: JSON.stringify({ target_id, source_ids }),
  });
}

// ============================================================================
// Pipeline Endpoints
// ============================================================================

/**
 * Start the entity extraction pipeline.
 *
 * @param {Object} options - Pipeline options.
 * @param {boolean} options.full_reindex - Whether to reindex all posts.
 * @param {number[]} options.post_ids - Specific post IDs to process.
 * @returns {Promise<Object>} Pipeline start confirmation.
 */
export async function startPipeline(options = {}) {
  return apiFetch('/pipeline/start', {
    method: 'POST',
    body: JSON.stringify(options),
  });
}

/**
 * Stop the running pipeline.
 *
 * @returns {Promise<Object>} Pipeline stop confirmation.
 */
export async function stopPipeline() {
  return apiFetch('/pipeline/stop', {
    method: 'POST',
  });
}

/**
 * Get current pipeline status.
 *
 * @returns {Promise<Object>} Pipeline status with progress.
 */
export async function getPipelineStatus() {
  return apiFetch('/pipeline/status');
}

// ============================================================================
// Log Endpoints
// ============================================================================

/**
 * Fetch recent log entries.
 *
 * @param {Object} params - Query parameters.
 * @param {number} params.limit - Maximum entries to return.
 * @param {string} params.level - Minimum log level filter.
 * @param {number} params.since - Timestamp to fetch logs after.
 * @returns {Promise<Object>} Log entries array.
 */
export async function fetchLogs(params = {}) {
  const queryParams = new URLSearchParams();

  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null) {
      queryParams.append(key, value);
    }
  });

  const queryString = queryParams.toString();
  const endpoint = queryString ? `/logs?${queryString}` : '/logs';

  return apiFetch(endpoint);
}

// ============================================================================
// Settings Endpoints
// ============================================================================

/**
 * Fetch plugin settings.
 *
 * @returns {Promise<Object>} Current settings.
 */
export async function fetchSettings() {
  return apiFetch('/settings');
}

/**
 * Update plugin settings.
 *
 * @param {Object} settings - Settings to update.
 * @returns {Promise<Object>} Updated settings.
 */
export async function updateSettings(settings) {
  return apiFetch('/settings', {
    method: 'PUT',
    body: JSON.stringify(settings),
  });
}

// Default export with all methods
export default {
  fetchStatus,
  fetchEntities,
  fetchEntity,
  updateEntity,
  deleteEntity,
  mergeEntities,
  startPipeline,
  stopPipeline,
  getPipelineStatus,
  fetchLogs,
  fetchSettings,
  updateSettings,
};
