/**
 * useEntities Hook
 *
 * Fetches and manages paginated entity data.
 */

import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { fetchEntities, updateEntity, deleteEntity } from '../api/client';

/**
 * Default pagination state.
 */
const DEFAULT_PAGINATION = {
  page: 1,
  per_page: 20,
  total: 0,
  total_pages: 0,
};

/**
 * Custom hook for entity list management.
 *
 * @param {Object} initialParams - Initial query parameters.
 * @returns {Object} Entities state and control functions.
 */
export function useEntities(initialParams = {}) {
  const [entities, setEntities] = useState([]);
  const [pagination, setPagination] = useState({
    ...DEFAULT_PAGINATION,
    ...initialParams,
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [params, setParams] = useState(initialParams);

  const mountedRef = useRef(true);
  const abortControllerRef = useRef(null);

  /**
   * Fetch entities with current parameters.
   */
  const fetch = useCallback(async (queryParams = {}) => {
    // Cancel any pending request
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }

    abortControllerRef.current = new AbortController();

    setLoading(true);
    setError(null);

    const mergedParams = {
      ...params,
      ...queryParams,
      page: queryParams.page || params.page || 1,
      per_page: queryParams.per_page || params.per_page || 20,
    };

    try {
      const data = await fetchEntities(mergedParams);

      if (mountedRef.current) {
        setEntities(data.entities || data.items || []);
        setPagination({
          page: data.page || mergedParams.page,
          per_page: data.per_page || mergedParams.per_page,
          total: data.total || 0,
          total_pages: data.total_pages || 0,
        });
        setParams(mergedParams);
      }
    } catch (err) {
      if (err.name !== 'AbortError' && mountedRef.current) {
        setError(err.message || 'Failed to fetch entities');
      }
    } finally {
      if (mountedRef.current) {
        setLoading(false);
      }
    }
  }, [params]);

  /**
   * Refresh with current parameters.
   */
  const refresh = useCallback(() => {
    return fetch(params);
  }, [fetch, params]);

  /**
   * Go to a specific page.
   */
  const goToPage = useCallback((page) => {
    return fetch({ page });
  }, [fetch]);

  /**
   * Update items per page.
   */
  const setPerPage = useCallback((per_page) => {
    return fetch({ per_page, page: 1 });
  }, [fetch]);

  /**
   * Apply search filter.
   */
  const search = useCallback((searchTerm) => {
    return fetch({ search: searchTerm, page: 1 });
  }, [fetch]);

  /**
   * Apply type filter.
   */
  const filterByType = useCallback((type) => {
    return fetch({ type, page: 1 });
  }, [fetch]);

  /**
   * Apply sorting.
   */
  const sort = useCallback((orderby, order = 'asc') => {
    return fetch({ orderby, order, page: 1 });
  }, [fetch]);

  /**
   * Update a single entity in the list.
   */
  const update = useCallback(async (id, data) => {
    try {
      const updated = await updateEntity(id, data);

      if (mountedRef.current) {
        setEntities((prev) =>
          prev.map((entity) =>
            entity.id === id ? { ...entity, ...updated } : entity
          )
        );
      }

      return updated;
    } catch (err) {
      setError(err.message || 'Failed to update entity');
      throw err;
    }
  }, []);

  /**
   * Delete an entity from the list.
   */
  const remove = useCallback(async (id) => {
    try {
      await deleteEntity(id);

      if (mountedRef.current) {
        setEntities((prev) => prev.filter((entity) => entity.id !== id));
        setPagination((prev) => ({
          ...prev,
          total: Math.max(0, prev.total - 1),
        }));
      }

      return true;
    } catch (err) {
      setError(err.message || 'Failed to delete entity');
      throw err;
    }
  }, []);

  // Effect: Initial fetch
  useEffect(() => {
    mountedRef.current = true;
    fetch(initialParams);

    return () => {
      mountedRef.current = false;
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
    };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Computed values
  const hasNextPage = useMemo(
    () => pagination.page < pagination.total_pages,
    [pagination.page, pagination.total_pages]
  );

  const hasPrevPage = useMemo(
    () => pagination.page > 1,
    [pagination.page]
  );

  return {
    // State
    entities,
    pagination,
    loading,
    error,
    params,
    hasNextPage,
    hasPrevPage,

    // Actions
    fetch,
    refresh,
    goToPage,
    setPerPage,
    search,
    filterByType,
    sort,
    update,
    remove,
  };
}

export default useEntities;
