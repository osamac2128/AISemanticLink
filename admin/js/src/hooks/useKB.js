/**
 * useKB Hooks
 *
 * React Query hooks for Knowledge Base API operations.
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

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
 * @param {Object} options - Fetch options.
 * @returns {Promise<any>} API response.
 */
async function apiFetch(endpoint, options = {}) {
  const config = getConfig();
  const url = `${config.apiUrl}${endpoint}`;

  const headers = {
    'Content-Type': 'application/json',
    'X-WP-Nonce': config.nonce,
    ...options.headers,
  };

  const response = await fetch(url, {
    ...options,
    headers,
    credentials: 'same-origin',
  });

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}));
    const error = new Error(errorData.message || `API Error: ${response.status}`);
    error.status = response.status;
    throw error;
  }

  return response.json();
}

/**
 * API client methods for KB operations.
 */
export const apiClient = {
  /**
   * GET request.
   */
  get: (endpoint, params = {}) => {
    const queryString = new URLSearchParams(
      Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '')
    ).toString();

    const url = queryString ? `${endpoint}?${queryString}` : endpoint;
    return apiFetch(url);
  },

  /**
   * POST request.
   */
  post: (endpoint, data = {}) => {
    return apiFetch(endpoint, {
      method: 'POST',
      body: JSON.stringify(data),
    });
  },

  /**
   * PUT request.
   */
  put: (endpoint, data = {}) => {
    return apiFetch(endpoint, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
  },

  /**
   * DELETE request.
   */
  delete: (endpoint) => {
    return apiFetch(endpoint, {
      method: 'DELETE',
    });
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
 * @returns {Object} Query result with status data.
 */
export function useKBStatus() {
  return useQuery({
    queryKey: ['kb-status'],
    queryFn: () => apiClient.get('/kb/status'),
    refetchInterval: (data) => {
      // Poll every 2s if pipeline is running
      return data?.pipeline?.running ? 2000 : false;
    },
    staleTime: 1000,
  });
}

/**
 * Hook for KB search mutations.
 *
 * @returns {Object} Mutation for search operations.
 */
export function useKBSearch() {
  return useMutation({
    mutationFn: ({ query, topK, filters }) =>
      apiClient.post('/kb/search', {
        query,
        top_k: topK,
        filters,
      }),
  });
}

/**
 * Hook for fetching KB documents with pagination.
 *
 * @param {number} page - Page number (1-indexed).
 * @param {number} perPage - Items per page.
 * @param {Object} filters - Filter parameters.
 * @returns {Object} Query result with documents.
 */
export function useKBDocuments(page, perPage, filters = {}) {
  return useQuery({
    queryKey: ['kb-docs', page, perPage, filters],
    queryFn: async () => {
      const config = getConfig();
      const params = new URLSearchParams({
        page: page.toString(),
        per_page: perPage.toString(),
        ...(filters.search && { search: filters.search }),
        ...(filters.post_type && { post_type: filters.post_type }),
        ...(filters.status && { status: filters.status }),
      });

      const response = await fetch(`${config.apiUrl}/kb/docs?${params}`, {
        headers: {
          'X-WP-Nonce': config.nonce,
        },
        credentials: 'same-origin',
      });

      if (!response.ok) {
        throw new Error('Failed to fetch documents');
      }

      const docs = await response.json();
      const totalCount = parseInt(response.headers.get('X-WP-Total') || '0', 10);
      const totalPages = parseInt(response.headers.get('X-WP-TotalPages') || '1', 10);

      return {
        docs,
        total_count: totalCount,
        total_pages: totalPages,
      };
    },
    keepPreviousData: true,
    staleTime: 30000,
  });
}

/**
 * Hook for fetching a single KB document with chunks.
 *
 * @param {number|string} postId - Post ID.
 * @returns {Object} Query result with document data.
 */
export function useKBDocument(postId) {
  return useQuery({
    queryKey: ['kb-doc', postId],
    queryFn: () => apiClient.get(`/kb/docs/${postId}`),
    enabled: !!postId,
  });
}

/**
 * Hook for KB reindex mutations.
 *
 * @returns {Object} Mutation for reindex operations.
 */
export function useKBReindex() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (options = {}) => apiClient.post('/kb/reindex', options),
    onSuccess: () => {
      queryClient.invalidateQueries(['kb-status']);
      queryClient.invalidateQueries(['kb-docs']);
    },
  });
}

/**
 * Hook for fetching KB settings.
 *
 * @returns {Object} Query result with settings data.
 */
export function useKBSettings() {
  return useQuery({
    queryKey: ['kb-settings'],
    queryFn: () => apiClient.get('/kb/settings'),
    staleTime: 60000, // 1 minute
  });
}

/**
 * Hook for updating KB settings.
 *
 * @returns {Object} Mutation for updating settings.
 */
export function useUpdateKBSettings() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (settings) => apiClient.post('/kb/settings', settings),
    onSuccess: () => {
      queryClient.invalidateQueries(['kb-settings']);
    },
  });
}

/**
 * Hook for excluding/including documents.
 *
 * @returns {Object} Mutation for exclude/include operations.
 */
export function useKBExclude() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ postIds, exclude }) =>
      apiClient.post('/kb/docs/exclude', {
        post_ids: postIds,
        exclude,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries(['kb-docs']);
      queryClient.invalidateQueries(['kb-status']);
    },
  });
}

/**
 * Hook for fetching KB logs.
 *
 * @param {Object} params - Log query parameters.
 * @returns {Object} Query result with logs.
 */
export function useKBLogs(params = {}) {
  const { page = 1, perPage = 50, eventType, level, date } = params;

  return useQuery({
    queryKey: ['kb-logs', page, perPage, eventType, level, date],
    queryFn: async () => {
      const config = getConfig();
      const queryParams = new URLSearchParams({
        page: page.toString(),
        per_page: perPage.toString(),
        ...(eventType && { event_type: eventType }),
        ...(level && { level }),
        ...(date && { date }),
      });

      const response = await fetch(`${config.apiUrl}/kb/logs?${queryParams}`, {
        headers: {
          'X-WP-Nonce': config.nonce,
        },
        credentials: 'same-origin',
      });

      if (!response.ok) {
        throw new Error('Failed to fetch logs');
      }

      const logs = await response.json();
      const totalCount = parseInt(response.headers.get('X-WP-Total') || '0', 10);
      const totalPages = parseInt(response.headers.get('X-WP-TotalPages') || '1', 10);

      return {
        logs,
        totalCount,
        totalPages,
      };
    },
    keepPreviousData: true,
    staleTime: 30000,
  });
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
