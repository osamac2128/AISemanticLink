/**
 * EntityManager - Main entity grid view
 *
 * Provides a comprehensive interface for managing entities with:
 * - TanStack Table v8 for headless table management
 * - Bulk selection and operations
 * - Inline editing capabilities
 * - Search and filtering
 * - Pagination controls
 */

import { useState, useCallback, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import EntityTable from './EntityTable';
import BulkActions from './BulkActions';
import Filters from './Filters';
import MergeModal from './MergeModal';
import { EntityDrawer } from '../EntityDrawer';

// API functions
const fetchEntities = async ({ page, pageSize, filters, sorting }) => {
    const params = new URLSearchParams({
        page: page.toString(),
        per_page: pageSize.toString(),
        ...(filters.search && { search: filters.search }),
        ...(filters.types?.length && { types: filters.types.join(',') }),
        ...(filters.statuses?.length && { statuses: filters.statuses.join(',') }),
        ...(sorting.length && {
            orderby: sorting[0].id,
            order: sorting[0].desc ? 'desc' : 'asc'
        }),
    });

    const response = await fetch(
        `${window.vibeAI?.restUrl || '/wp-json/'}vibe-ai/v1/entities?${params}`,
        {
            headers: {
                'X-WP-Nonce': window.vibeAI?.nonce || '',
            },
        }
    );

    if (!response.ok) {
        throw new Error('Failed to fetch entities');
    }

    const data = await response.json();
    const totalCount = parseInt(response.headers.get('X-WP-Total') || '0', 10);
    const totalPages = parseInt(response.headers.get('X-WP-TotalPages') || '1', 10);

    return {
        entities: data,
        totalCount,
        totalPages,
    };
};

const updateEntity = async ({ id, data }) => {
    const response = await fetch(
        `${window.vibeAI?.restUrl || '/wp-json/'}vibe-ai/v1/entities/${id}`,
        {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.vibeAI?.nonce || '',
            },
            body: JSON.stringify(data),
        }
    );

    if (!response.ok) {
        throw new Error('Failed to update entity');
    }

    return response.json();
};

const deleteEntities = async (ids) => {
    const response = await fetch(
        `${window.vibeAI?.restUrl || '/wp-json/'}vibe-ai/v1/entities/bulk-delete`,
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.vibeAI?.nonce || '',
            },
            body: JSON.stringify({ ids }),
        }
    );

    if (!response.ok) {
        throw new Error('Failed to delete entities');
    }

    return response.json();
};

const updateEntitiesStatus = async ({ ids, status }) => {
    const response = await fetch(
        `${window.vibeAI?.restUrl || '/wp-json/'}vibe-ai/v1/entities/bulk-status`,
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.vibeAI?.nonce || '',
            },
            body: JSON.stringify({ ids, status }),
        }
    );

    if (!response.ok) {
        throw new Error('Failed to update entities status');
    }

    return response.json();
};

// Entity types from the spec
export const ENTITY_TYPES = [
    { value: 'PERSON', label: 'Person' },
    { value: 'ORG', label: 'Organization' },
    { value: 'COMPANY', label: 'Company' },
    { value: 'LOCATION', label: 'Location' },
    { value: 'COUNTRY', label: 'Country' },
    { value: 'PRODUCT', label: 'Product' },
    { value: 'SOFTWARE', label: 'Software' },
    { value: 'EVENT', label: 'Event' },
    { value: 'WORK', label: 'Creative Work' },
    { value: 'CONCEPT', label: 'Concept' },
];

// Entity statuses from the spec
export const ENTITY_STATUSES = [
    { value: 'raw', label: 'Raw', color: 'bg-gray-100 text-gray-700' },
    { value: 'reviewed', label: 'Reviewed', color: 'bg-blue-100 text-blue-700' },
    { value: 'canonical', label: 'Canonical', color: 'bg-green-100 text-green-700' },
    { value: 'trash', label: 'Trash', color: 'bg-red-100 text-red-700' },
];

export default function EntityManager() {
    const queryClient = useQueryClient();

    // Table state
    const [pagination, setPagination] = useState({
        pageIndex: 0,
        pageSize: 25,
    });
    const [sorting, setSorting] = useState([]);
    const [rowSelection, setRowSelection] = useState({});
    const [filters, setFilters] = useState({
        search: '',
        types: [],
        statuses: [],
    });

    // UI state
    const [isMergeModalOpen, setIsMergeModalOpen] = useState(false);
    const [selectedEntityId, setSelectedEntityId] = useState(null);
    const [isDrawerOpen, setIsDrawerOpen] = useState(false);
    const [editingCell, setEditingCell] = useState(null);

    // Fetch entities
    const { data, isLoading, isError, error, isFetching } = useQuery({
        queryKey: ['entities', pagination, filters, sorting],
        queryFn: () => fetchEntities({
            page: pagination.pageIndex + 1,
            pageSize: pagination.pageSize,
            filters,
            sorting,
        }),
        keepPreviousData: true,
        staleTime: 30000, // 30 seconds
    });

    // Mutations
    const updateMutation = useMutation({
        mutationFn: updateEntity,
        onMutate: async ({ id, data: updateData }) => {
            // Cancel outgoing refetches
            await queryClient.cancelQueries({ queryKey: ['entities'] });

            // Snapshot previous value
            const previousData = queryClient.getQueryData(['entities', pagination, filters, sorting]);

            // Optimistically update
            queryClient.setQueryData(['entities', pagination, filters, sorting], (old) => {
                if (!old) return old;
                return {
                    ...old,
                    entities: old.entities.map((entity) =>
                        entity.id === id ? { ...entity, ...updateData } : entity
                    ),
                };
            });

            return { previousData };
        },
        onError: (err, variables, context) => {
            // Rollback on error
            if (context?.previousData) {
                queryClient.setQueryData(
                    ['entities', pagination, filters, sorting],
                    context.previousData
                );
            }
        },
        onSettled: () => {
            queryClient.invalidateQueries({ queryKey: ['entities'] });
        },
    });

    const deleteMutation = useMutation({
        mutationFn: deleteEntities,
        onSuccess: () => {
            setRowSelection({});
            queryClient.invalidateQueries({ queryKey: ['entities'] });
        },
    });

    const statusMutation = useMutation({
        mutationFn: updateEntitiesStatus,
        onSuccess: () => {
            setRowSelection({});
            queryClient.invalidateQueries({ queryKey: ['entities'] });
        },
    });

    // Get selected entity IDs
    const selectedIds = useMemo(() => {
        if (!data?.entities) return [];
        return Object.keys(rowSelection)
            .filter((key) => rowSelection[key])
            .map((key) => data.entities[parseInt(key, 10)]?.id)
            .filter(Boolean);
    }, [rowSelection, data?.entities]);

    // Get selected entities for merge modal
    const selectedEntities = useMemo(() => {
        if (!data?.entities) return [];
        return Object.keys(rowSelection)
            .filter((key) => rowSelection[key])
            .map((key) => data.entities[parseInt(key, 10)])
            .filter(Boolean);
    }, [rowSelection, data?.entities]);

    // Handlers
    const handleEntityClick = useCallback((entityId) => {
        setSelectedEntityId(entityId);
        setIsDrawerOpen(true);
    }, []);

    const handleInlineEdit = useCallback((entityId, field, value) => {
        updateMutation.mutate({ id: entityId, data: { [field]: value } });
        setEditingCell(null);
    }, [updateMutation]);

    const handleBulkDelete = useCallback(() => {
        if (selectedIds.length === 0) return;

        if (window.confirm(`Are you sure you want to delete ${selectedIds.length} entities? This action cannot be undone.`)) {
            deleteMutation.mutate(selectedIds);
        }
    }, [selectedIds, deleteMutation]);

    const handleBulkStatusChange = useCallback((status) => {
        if (selectedIds.length === 0) return;
        statusMutation.mutate({ ids: selectedIds, status });
    }, [selectedIds, statusMutation]);

    const handleMergeClick = useCallback(() => {
        if (selectedIds.length < 2) return;
        setIsMergeModalOpen(true);
    }, [selectedIds]);

    const handleFilterChange = useCallback((newFilters) => {
        setFilters(newFilters);
        setPagination((prev) => ({ ...prev, pageIndex: 0 }));
    }, []);

    const handleClearFilters = useCallback(() => {
        setFilters({
            search: '',
            types: [],
            statuses: [],
        });
        setPagination((prev) => ({ ...prev, pageIndex: 0 }));
    }, []);

    const handleDrawerClose = useCallback(() => {
        setIsDrawerOpen(false);
        setSelectedEntityId(null);
    }, []);

    const handleMergeComplete = useCallback(() => {
        setIsMergeModalOpen(false);
        setRowSelection({});
        queryClient.invalidateQueries({ queryKey: ['entities'] });
    }, [queryClient]);

    // Loading state
    if (isLoading) {
        return (
            <div className="flex items-center justify-center h-64">
                <div className="flex flex-col items-center gap-3">
                    <div className="w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
                    <span className="text-sm text-gray-500">Loading entities...</span>
                </div>
            </div>
        );
    }

    // Error state
    if (isError) {
        return (
            <div className="flex items-center justify-center h-64">
                <div className="bg-red-50 border border-red-200 rounded-lg p-6 max-w-md">
                    <div className="flex items-start gap-3">
                        <svg className="w-5 h-5 text-red-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <div>
                            <h3 className="font-medium text-red-800">Failed to load entities</h3>
                            <p className="text-sm text-red-600 mt-1">{error?.message || 'An unexpected error occurred'}</p>
                            <button
                                onClick={() => queryClient.invalidateQueries({ queryKey: ['entities'] })}
                                className="mt-3 text-sm text-red-700 hover:text-red-800 underline"
                            >
                                Try again
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">Entity Manager</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Manage your semantic entities - {data?.totalCount || 0} total entities
                    </p>
                </div>
                {isFetching && !isLoading && (
                    <div className="flex items-center gap-2 text-sm text-gray-500">
                        <div className="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
                        Refreshing...
                    </div>
                )}
            </div>

            {/* Filters */}
            <Filters
                filters={filters}
                onChange={handleFilterChange}
                onClear={handleClearFilters}
            />

            {/* Bulk Actions */}
            <BulkActions
                selectedCount={selectedIds.length}
                onMerge={handleMergeClick}
                onDelete={handleBulkDelete}
                onStatusChange={handleBulkStatusChange}
                isDeleting={deleteMutation.isLoading}
                isUpdating={statusMutation.isLoading}
            />

            {/* Table */}
            <EntityTable
                data={data?.entities || []}
                totalCount={data?.totalCount || 0}
                totalPages={data?.totalPages || 1}
                pagination={pagination}
                onPaginationChange={setPagination}
                sorting={sorting}
                onSortingChange={setSorting}
                rowSelection={rowSelection}
                onRowSelectionChange={setRowSelection}
                onEntityClick={handleEntityClick}
                onInlineEdit={handleInlineEdit}
                editingCell={editingCell}
                onEditingCellChange={setEditingCell}
            />

            {/* Merge Modal */}
            <MergeModal
                isOpen={isMergeModalOpen}
                onClose={() => setIsMergeModalOpen(false)}
                entities={selectedEntities}
                onMergeComplete={handleMergeComplete}
            />

            {/* Entity Drawer */}
            <EntityDrawer
                isOpen={isDrawerOpen}
                onClose={handleDrawerClose}
                entityId={selectedEntityId}
            />
        </div>
    );
}
