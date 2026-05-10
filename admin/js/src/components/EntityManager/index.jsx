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
import {
	useQuery,
	useMutation,
	useQueryClient,
	keepPreviousData,
} from '@tanstack/react-query';
import {
	fetchEntities as apiFetchEntities,
	updateEntity as apiUpdateEntity,
	bulkDeleteEntities,
	bulkUpdateEntitiesStatus,
} from '../../api/client';
import { toast } from 'sonner';
import EntityTable from './EntityTable';
import BulkActions from './BulkActions';
import Filters from './Filters';
import MergeModal from './MergeModal';
import CreateEntityModal from './CreateEntityModal';
import { EntityDrawer } from '../EntityDrawer';
import { ConfirmDialog } from '../common/ConfirmDialog';

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
	{ value: 'TECHNOLOGY', label: 'Technology' },
	{ value: 'BRAND', label: 'Brand' },
];

export const ENTITY_STATUSES = [
	{ value: 'raw', label: 'Raw', color: 'bg-gray-100 text-gray-700' },
	{
		value: 'reviewed',
		label: 'Reviewed',
		color: 'bg-blue-100 text-blue-700',
	},
	{
		value: 'canonical',
		label: 'Canonical',
		color: 'bg-green-100 text-green-700',
	},
	{ value: 'trash', label: 'Trash', color: 'bg-red-100 text-red-700' },
	{
		value: 'rejected',
		label: 'Rejected',
		color: 'bg-orange-100 text-orange-700',
	},
];

export default function EntityManager() {
	const queryClient = useQueryClient();

	// Table state
	const [ pagination, setPagination ] = useState( {
		pageIndex: 0,
		pageSize: 25,
	} );
	const [ sorting, setSorting ] = useState( [] );
	const [ rowSelection, setRowSelection ] = useState( {} );
	const [ filters, setFilters ] = useState( {
		search: '',
		types: [],
		statuses: [],
	} );

	// UI state
	const [ isMergeModalOpen, setIsMergeModalOpen ] = useState( false );
	const [ isCreateModalOpen, setIsCreateModalOpen ] = useState( false );
	const [ selectedEntityId, setSelectedEntityId ] = useState( null );
	const [ isDrawerOpen, setIsDrawerOpen ] = useState( false );
	const [ editingCell, setEditingCell ] = useState( null );
	const [ showBulkDeleteConfirm, setShowBulkDeleteConfirm ] = useState( false );

	// Fetch entities
	const { data, isLoading, isError, error, isFetching } = useQuery( {
		queryKey: [ 'entities', pagination, filters, sorting ],
		queryFn: () =>
			apiFetchEntities( {
				page: pagination.pageIndex + 1,
				per_page: pagination.pageSize,
				...( filters.search && { search: filters.search } ),
				...( filters.types?.length && {
					types: filters.types.join( ',' ),
				} ),
				...( filters.statuses?.length && {
					statuses: filters.statuses.join( ',' ),
				} ),
				...( sorting.length && {
					orderby: sorting[ 0 ].id,
					order: sorting[ 0 ].desc ? 'desc' : 'asc',
				} ),
			} ),
		placeholderData: keepPreviousData,
		staleTime: 30000,
	} );

	const updateMutation = useMutation( {
		mutationFn: ( { id, data: updateData } ) =>
			apiUpdateEntity( id, updateData ),
		onMutate: async ( { id, data: updateData } ) => {
			// Cancel outgoing refetches
			await queryClient.cancelQueries( { queryKey: [ 'entities' ] } );

			// Snapshot previous value
			const previousData = queryClient.getQueryData( [
				'entities',
				pagination,
				filters,
				sorting,
			] );

			// Optimistically update
			queryClient.setQueryData(
				[ 'entities', pagination, filters, sorting ],
				( old ) => {
					if ( ! old ) {
						return old;
					}
					return {
						...old,
						entities: old.entities.map( ( entity ) =>
							entity.id === id
								? { ...entity, ...updateData }
								: entity
						),
					};
				}
			);

			return { previousData };
		},
		onError: ( err, variables, context ) => {
			// Rollback on error
			if ( context?.previousData ) {
				queryClient.setQueryData(
					[ 'entities', pagination, filters, sorting ],
					context.previousData
				);
			}
			toast.error( err.message || 'Failed to update entity' );
		},
		onSettled: () => {
			queryClient.invalidateQueries( { queryKey: [ 'entities' ] } );
		},
	} );

	const deleteMutation = useMutation( {
		mutationFn: bulkDeleteEntities,
		onSuccess: () => {
			setRowSelection( {} );
			queryClient.invalidateQueries( { queryKey: [ 'entities' ] } );
			toast.success( 'Entities deleted' );
		},
		onError: ( error ) => {
			toast.error( error.message || 'Failed to delete entities' );
		},
	} );

	const statusMutation = useMutation( {
		mutationFn: bulkUpdateEntitiesStatus,
		onSuccess: () => {
			setRowSelection( {} );
			queryClient.invalidateQueries( { queryKey: [ 'entities' ] } );
			toast.success( 'Status updated' );
		},
		onError: ( error ) => {
			toast.error( error.message || 'Failed to update status' );
		},
	} );

	// Get selected entity IDs
	const selectedIds = useMemo( () => {
		if ( ! data?.entities ) {
			return [];
		}
		return Object.keys( rowSelection )
			.filter( ( key ) => rowSelection[ key ] )
			.map( ( key ) => data.entities[ parseInt( key, 10 ) ]?.id )
			.filter( Boolean );
	}, [ rowSelection, data?.entities ] );

	// Get selected entities for merge modal
	const selectedEntities = useMemo( () => {
		if ( ! data?.entities ) {
			return [];
		}
		return Object.keys( rowSelection )
			.filter( ( key ) => rowSelection[ key ] )
			.map( ( key ) => data.entities[ parseInt( key, 10 ) ] )
			.filter( Boolean );
	}, [ rowSelection, data?.entities ] );

	// Handlers
	const handleEntityClick = useCallback( ( entityId ) => {
		setSelectedEntityId( entityId );
		setIsDrawerOpen( true );
	}, [] );

	const handleInlineEdit = useCallback(
		( entityId, field, value ) => {
			updateMutation.mutate( {
				id: entityId,
				data: { [ field ]: value },
			} );
			setEditingCell( null );
		},
		[ updateMutation ]
	);

	const handleBulkDelete = useCallback( () => {
		if ( selectedIds.length === 0 ) {
			return;
		}
		setShowBulkDeleteConfirm( true );
	}, [ selectedIds ] );

	const confirmBulkDelete = useCallback( () => {
		deleteMutation.mutate( selectedIds );
		setShowBulkDeleteConfirm( false );
	}, [ selectedIds, deleteMutation ] );

	const handleBulkStatusChange = useCallback(
		( status ) => {
			if ( selectedIds.length === 0 ) {
				return;
			}
			statusMutation.mutate( { ids: selectedIds, status } );
		},
		[ selectedIds, statusMutation ]
	);

	const handleMergeClick = useCallback( () => {
		if ( selectedIds.length < 2 ) {
			return;
		}
		setIsMergeModalOpen( true );
	}, [ selectedIds ] );

	const handleFilterChange = useCallback( ( newFilters ) => {
		setFilters( newFilters );
		setPagination( ( prev ) => ( { ...prev, pageIndex: 0 } ) );
	}, [] );

	const handleClearFilters = useCallback( () => {
		setFilters( {
			search: '',
			types: [],
			statuses: [],
		} );
		setPagination( ( prev ) => ( { ...prev, pageIndex: 0 } ) );
	}, [] );

	const handleDrawerClose = useCallback( () => {
		setIsDrawerOpen( false );
		setSelectedEntityId( null );
	}, [] );

	const handleMergeComplete = useCallback( () => {
		setIsMergeModalOpen( false );
		setRowSelection( {} );
		queryClient.invalidateQueries( { queryKey: [ 'entities' ] } );
	}, [ queryClient ] );

	// Loading state
	if ( isLoading ) {
		return (
			<div className="flex items-center justify-center h-64">
				<div className="flex flex-col items-center gap-3">
					<div className="w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
					<span className="text-sm text-gray-500">
						Loading entities...
					</span>
				</div>
			</div>
		);
	}

	// Error state
	if ( isError ) {
		return (
			<div className="flex items-center justify-center h-64">
				<div className="bg-red-50 border border-red-200 rounded-lg p-6 max-w-md">
					<div className="flex items-start gap-3">
						<svg
							className="w-5 h-5 text-red-500 mt-0.5"
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path
								strokeLinecap="round"
								strokeLinejoin="round"
								strokeWidth={ 2 }
								d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
							/>
						</svg>
						<div>
							<h3 className="font-medium text-red-800">
								Failed to load entities
							</h3>
							<p className="text-sm text-red-600 mt-1">
								{ error?.message ||
									'An unexpected error occurred' }
							</p>
							<button
								onClick={ () =>
									queryClient.invalidateQueries( {
										queryKey: [ 'entities' ],
									} )
								}
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
			{ /* Header */ }
			<div className="flex items-center justify-between">
				<div>
					<h1 className="text-2xl font-semibold text-gray-900">
						Entity Manager
					</h1>
					<p className="text-sm text-gray-500 mt-1">
						Manage your semantic entities -{ ' ' }
						{ data?.totalCount || 0 } total entities
					</p>
				</div>
				<div className="flex items-center gap-3">
					<button
						onClick={ () => setIsCreateModalOpen( true ) }
						className="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 inline-flex items-center gap-1.5"
					>
						<svg
							className="w-4 h-4"
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path
								strokeLinecap="round"
								strokeLinejoin="round"
								strokeWidth={ 2 }
								d="M12 4v16m8-8H4"
							/>
						</svg>
						Create Entity
					</button>
					{ isFetching && ! isLoading && (
						<div className="flex items-center gap-2 text-sm text-gray-500">
							<div className="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
							Refreshing...
						</div>
					) }
				</div>
			</div>

			{ /* Filters */ }
			<Filters
				filters={ filters }
				onChange={ handleFilterChange }
				onClear={ handleClearFilters }
			/>

			{ /* Bulk Actions */ }
			<BulkActions
				selectedCount={ selectedIds.length }
				onMerge={ handleMergeClick }
				onDelete={ handleBulkDelete }
				onStatusChange={ handleBulkStatusChange }
				isDeleting={ deleteMutation.isPending }
				isUpdating={ statusMutation.isPending }
			/>

			{ /* Table */ }
			<EntityTable
				data={ data?.entities || [] }
				totalCount={ data?.totalCount || 0 }
				totalPages={ data?.totalPages || 1 }
				pagination={ pagination }
				onPaginationChange={ setPagination }
				sorting={ sorting }
				onSortingChange={ setSorting }
				rowSelection={ rowSelection }
				onRowSelectionChange={ setRowSelection }
				onEntityClick={ handleEntityClick }
				onInlineEdit={ handleInlineEdit }
				editingCell={ editingCell }
				onEditingCellChange={ setEditingCell }
			/>

			{ /* Merge Modal */ }
			<MergeModal
				isOpen={ isMergeModalOpen }
				onClose={ () => setIsMergeModalOpen( false ) }
				entities={ selectedEntities }
				onMergeComplete={ handleMergeComplete }
			/>

			{ /* Entity Drawer */ }
			<EntityDrawer
				isOpen={ isDrawerOpen }
				onClose={ handleDrawerClose }
				entityId={ selectedEntityId }
			/>

			<ConfirmDialog
				isOpen={ showBulkDeleteConfirm }
				title="Delete Entities"
				message={ `Are you sure you want to delete ${ selectedIds.length } entities? This action cannot be undone.` }
				confirmLabel="Delete"
				variant="danger"
				onConfirm={ confirmBulkDelete }
				onCancel={ () => setShowBulkDeleteConfirm( false ) }
			/>

			<CreateEntityModal
				isOpen={ isCreateModalOpen }
				onClose={ () => setIsCreateModalOpen( false ) }
			/>
		</div>
	);
}
