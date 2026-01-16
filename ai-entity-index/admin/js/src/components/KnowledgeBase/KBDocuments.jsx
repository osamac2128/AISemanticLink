/**
 * KBDocuments Component
 *
 * Document list with TanStack Table for Knowledge Base management.
 */

import { useState, useMemo, useCallback, useRef, useEffect } from 'react';
import {
  useReactTable,
  getCoreRowModel,
  flexRender,
} from '@tanstack/react-table';
import { useKBDocuments, useKBReindex } from '../../hooks/useKB';
import Badge from '../common/Badge';
import Button from '../common/Button';
import ChunkViewer from './ChunkViewer';

/**
 * Status badge configurations.
 */
const STATUS_CONFIGS = {
  indexed: { variant: 'success', label: 'Indexed' },
  pending: { variant: 'warning', label: 'Pending' },
  error: { variant: 'error', label: 'Error' },
  excluded: { variant: 'default', label: 'Excluded' },
};

/**
 * Post type options.
 */
const POST_TYPE_OPTIONS = [
  { value: '', label: 'All Types' },
  { value: 'post', label: 'Posts' },
  { value: 'page', label: 'Pages' },
  { value: 'product', label: 'Products' },
  { value: 'article', label: 'Articles' },
];

/**
 * Status filter options.
 */
const STATUS_OPTIONS = [
  { value: '', label: 'All Statuses' },
  { value: 'indexed', label: 'Indexed' },
  { value: 'pending', label: 'Pending' },
  { value: 'error', label: 'Error' },
  { value: 'excluded', label: 'Excluded' },
];

/**
 * Checkbox component for row selection.
 */
function IndeterminateCheckbox({ indeterminate, checked, onChange, ...rest }) {
  const ref = useRef(null);

  useEffect(() => {
    if (typeof indeterminate === 'boolean' && ref.current) {
      ref.current.indeterminate = !checked && indeterminate;
    }
  }, [indeterminate, checked]);

  return (
    <input
      type="checkbox"
      ref={ref}
      checked={checked}
      onChange={onChange}
      className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
      {...rest}
    />
  );
}

/**
 * Status badge component.
 */
function StatusBadge({ status }) {
  const config = STATUS_CONFIGS[status] || STATUS_CONFIGS.pending;
  return <Badge variant={config.variant} dot>{config.label}</Badge>;
}

/**
 * Actions dropdown component.
 */
function ActionsDropdown({ doc, onReindex, onToggleExclude, onViewChunks }) {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef(null);

  // Close dropdown on outside click
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
        setIsOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  return (
    <div className="relative" ref={dropdownRef}>
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="p-1 rounded hover:bg-gray-100"
      >
        <svg className="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
          <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
        </svg>
      </button>

      {isOpen && (
        <div className="absolute right-0 mt-1 w-48 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-10">
          <button
            onClick={() => {
              onReindex(doc.post_id);
              setIsOpen(false);
            }}
            className="w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100"
          >
            Reindex
          </button>
          <button
            onClick={() => {
              onToggleExclude(doc.post_id);
              setIsOpen(false);
            }}
            className="w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100"
          >
            {doc.status === 'excluded' ? 'Include' : 'Exclude'}
          </button>
          <button
            onClick={() => {
              onViewChunks(doc);
              setIsOpen(false);
            }}
            className="w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100"
          >
            View Chunks
          </button>
        </div>
      )}
    </div>
  );
}

/**
 * KBDocuments main component.
 *
 * @param {Object} props - Component props.
 * @param {Object} props.vibeAiData - Localized data from WordPress.
 * @returns {JSX.Element} KBDocuments element.
 */
export default function KBDocuments({ vibeAiData }) {
  // State
  const [pagination, setPagination] = useState({ pageIndex: 0, pageSize: 25 });
  const [rowSelection, setRowSelection] = useState({});
  const [filters, setFilters] = useState({
    search: '',
    post_type: '',
    status: '',
  });
  const [selectedDoc, setSelectedDoc] = useState(null);
  const [isChunkViewerOpen, setIsChunkViewerOpen] = useState(false);

  // Fetch documents
  const { data, isLoading, isError, error, isFetching, refetch } = useKBDocuments(
    pagination.pageIndex + 1,
    pagination.pageSize,
    filters
  );

  // Reindex mutation
  const reindexMutation = useKBReindex();

  // Handle search input with debounce
  const [searchInput, setSearchInput] = useState('');
  useEffect(() => {
    const timer = setTimeout(() => {
      setFilters((prev) => ({ ...prev, search: searchInput }));
      setPagination((prev) => ({ ...prev, pageIndex: 0 }));
    }, 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  // Handlers
  const handleReindex = useCallback(async (postId) => {
    try {
      await reindexMutation.mutateAsync({ post_ids: [postId] });
      refetch();
    } catch (err) {
      // Error handled by mutation
    }
  }, [reindexMutation, refetch]);

  const handleToggleExclude = useCallback(async (postId) => {
    // This would call an exclude/include endpoint
    console.log('Toggle exclude for:', postId);
    refetch();
  }, [refetch]);

  const handleViewChunks = useCallback((doc) => {
    setSelectedDoc(doc);
    setIsChunkViewerOpen(true);
  }, []);

  const handleBulkReindex = useCallback(async () => {
    const selectedIds = Object.keys(rowSelection)
      .filter((key) => rowSelection[key])
      .map((key) => data?.docs?.[parseInt(key, 10)]?.post_id)
      .filter(Boolean);

    if (selectedIds.length === 0) return;

    try {
      await reindexMutation.mutateAsync({ post_ids: selectedIds });
      setRowSelection({});
      refetch();
    } catch (err) {
      // Error handled by mutation
    }
  }, [rowSelection, data?.docs, reindexMutation, refetch]);

  const handleBulkExclude = useCallback(async () => {
    const selectedIds = Object.keys(rowSelection)
      .filter((key) => rowSelection[key])
      .map((key) => data?.docs?.[parseInt(key, 10)]?.post_id)
      .filter(Boolean);

    if (selectedIds.length === 0) return;

    console.log('Bulk exclude:', selectedIds);
    setRowSelection({});
    refetch();
  }, [rowSelection, data?.docs, refetch]);

  // Table columns
  const columns = useMemo(() => [
    // Checkbox column
    {
      id: 'select',
      header: ({ table }) => (
        <IndeterminateCheckbox
          checked={table.getIsAllRowsSelected()}
          indeterminate={table.getIsSomeRowsSelected()}
          onChange={table.getToggleAllRowsSelectedHandler()}
        />
      ),
      cell: ({ row }) => (
        <IndeterminateCheckbox
          checked={row.getIsSelected()}
          onChange={row.getToggleSelectedHandler()}
        />
      ),
      size: 40,
    },
    // Title column
    {
      accessorKey: 'title',
      header: 'Title',
      cell: ({ row }) => (
        <div className="max-w-xs">
          <a
            href={row.original.url}
            target="_blank"
            rel="noopener noreferrer"
            className="text-sm font-medium text-blue-600 hover:text-blue-800 hover:underline truncate block"
          >
            {row.original.title}
          </a>
        </div>
      ),
      size: 250,
    },
    // Post Type column
    {
      accessorKey: 'post_type',
      header: 'Type',
      cell: ({ row }) => (
        <span className="text-sm text-gray-600 capitalize">
          {row.original.post_type}
        </span>
      ),
      size: 100,
    },
    // Status column
    {
      accessorKey: 'status',
      header: 'Status',
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
      size: 120,
    },
    // Chunks column
    {
      accessorKey: 'chunk_count',
      header: 'Chunks',
      cell: ({ row }) => (
        <span className="inline-flex items-center justify-center min-w-[2rem] px-2 py-0.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-full">
          {row.original.chunk_count || 0}
        </span>
      ),
      size: 80,
    },
    // Last Indexed column
    {
      accessorKey: 'last_indexed',
      header: 'Last Indexed',
      cell: ({ row }) => {
        const date = row.original.last_indexed;
        if (!date) return <span className="text-gray-400">-</span>;

        return (
          <span className="text-sm text-gray-600">
            {new Date(date).toLocaleDateString()}
          </span>
        );
      },
      size: 120,
    },
    // Actions column
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => (
        <ActionsDropdown
          doc={row.original}
          onReindex={handleReindex}
          onToggleExclude={handleToggleExclude}
          onViewChunks={handleViewChunks}
        />
      ),
      size: 60,
    },
  ], [handleReindex, handleToggleExclude, handleViewChunks]);

  // Initialize table
  const table = useReactTable({
    data: data?.docs || [],
    columns,
    state: {
      rowSelection,
      pagination,
    },
    pageCount: data?.total_pages || 1,
    onRowSelectionChange: setRowSelection,
    onPaginationChange: setPagination,
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
    enableRowSelection: true,
  });

  // Selected count
  const selectedCount = Object.keys(rowSelection).filter((key) => rowSelection[key]).length;

  // Loading state
  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="flex flex-col items-center gap-3">
          <div className="w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
          <span className="text-sm text-gray-500">Loading documents...</span>
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
              <h3 className="font-medium text-red-800">Failed to load documents</h3>
              <p className="text-sm text-red-600 mt-1">{error?.message}</p>
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Documents</h1>
          <p className="mt-1 text-slate-500">
            Manage documents in the Knowledge Base - {data?.total_count || 0} total
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
      <div className="bg-white rounded-lg border border-gray-200 p-4">
        <div className="flex flex-wrap items-center gap-4">
          {/* Search */}
          <div className="flex-1 min-w-[200px]">
            <input
              type="text"
              placeholder="Search documents..."
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              className="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
          </div>

          {/* Post Type Filter */}
          <select
            value={filters.post_type}
            onChange={(e) => {
              setFilters((prev) => ({ ...prev, post_type: e.target.value }));
              setPagination((prev) => ({ ...prev, pageIndex: 0 }));
            }}
            className="px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            {POST_TYPE_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>{opt.label}</option>
            ))}
          </select>

          {/* Status Filter */}
          <select
            value={filters.status}
            onChange={(e) => {
              setFilters((prev) => ({ ...prev, status: e.target.value }));
              setPagination((prev) => ({ ...prev, pageIndex: 0 }));
            }}
            className="px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            {STATUS_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>{opt.label}</option>
            ))}
          </select>

          {/* Clear Filters */}
          {(filters.search || filters.post_type || filters.status) && (
            <button
              onClick={() => {
                setFilters({ search: '', post_type: '', status: '' });
                setSearchInput('');
                setPagination((prev) => ({ ...prev, pageIndex: 0 }));
              }}
              className="text-sm text-gray-600 hover:text-gray-900"
            >
              Clear filters
            </button>
          )}
        </div>
      </div>

      {/* Bulk Actions */}
      {selectedCount > 0 && (
        <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-blue-700">
              {selectedCount} document{selectedCount > 1 ? 's' : ''} selected
            </span>
            <div className="flex items-center gap-2">
              <Button
                variant="secondary"
                size="sm"
                onClick={handleBulkReindex}
                loading={reindexMutation.isLoading}
              >
                Reindex Selected
              </Button>
              <Button
                variant="secondary"
                size="sm"
                onClick={handleBulkExclude}
              >
                Exclude Selected
              </Button>
              <button
                onClick={() => setRowSelection({})}
                className="text-sm text-blue-600 hover:text-blue-800 ml-2"
              >
                Clear selection
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Table */}
      {data?.docs?.length === 0 ? (
        <div className="bg-white rounded-lg border border-gray-200 p-8 text-center">
          <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
          <h3 className="mt-2 text-sm font-medium text-gray-900">No documents found</h3>
          <p className="mt-1 text-sm text-gray-500">
            {filters.search || filters.post_type || filters.status
              ? 'Try adjusting your filters'
              : 'Start by configuring post types in KB Settings'}
          </p>
        </div>
      ) : (
        <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                {table.getHeaderGroups().map((headerGroup) => (
                  <tr key={headerGroup.id}>
                    {headerGroup.headers.map((header) => (
                      <th
                        key={header.id}
                        className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                        style={{ width: header.getSize() }}
                      >
                        {header.isPlaceholder
                          ? null
                          : flexRender(header.column.columnDef.header, header.getContext())}
                      </th>
                    ))}
                  </tr>
                ))}
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {table.getRowModel().rows.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50">
                    {row.getVisibleCells().map((cell) => (
                      <td key={cell.id} className="px-4 py-3 whitespace-nowrap text-sm">
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          <div className="bg-gray-50 px-4 py-3 border-t border-gray-200">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2 text-sm text-gray-700">
                <span>
                  Showing {pagination.pageIndex * pagination.pageSize + 1} to{' '}
                  {Math.min((pagination.pageIndex + 1) * pagination.pageSize, data?.total_count || 0)}{' '}
                  of {data?.total_count || 0} documents
                </span>
              </div>

              <div className="flex items-center gap-2">
                <select
                  value={pagination.pageSize}
                  onChange={(e) =>
                    setPagination({ pageIndex: 0, pageSize: Number(e.target.value) })
                  }
                  className="px-2 py-1 text-sm border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  {[10, 25, 50, 100].map((size) => (
                    <option key={size} value={size}>{size} per page</option>
                  ))}
                </select>

                <div className="flex items-center gap-1">
                  <button
                    onClick={() => setPagination((prev) => ({ ...prev, pageIndex: 0 }))}
                    disabled={pagination.pageIndex === 0}
                    className="p-1 rounded border border-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-100"
                  >
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                    </svg>
                  </button>
                  <button
                    onClick={() => setPagination((prev) => ({ ...prev, pageIndex: prev.pageIndex - 1 }))}
                    disabled={pagination.pageIndex === 0}
                    className="p-1 rounded border border-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-100"
                  >
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                    </svg>
                  </button>
                  <span className="px-3 py-1 text-sm">
                    Page {pagination.pageIndex + 1} of {data?.total_pages || 1}
                  </span>
                  <button
                    onClick={() => setPagination((prev) => ({ ...prev, pageIndex: prev.pageIndex + 1 }))}
                    disabled={pagination.pageIndex >= (data?.total_pages || 1) - 1}
                    className="p-1 rounded border border-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-100"
                  >
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                    </svg>
                  </button>
                  <button
                    onClick={() => setPagination((prev) => ({ ...prev, pageIndex: (data?.total_pages || 1) - 1 }))}
                    disabled={pagination.pageIndex >= (data?.total_pages || 1) - 1}
                    className="p-1 rounded border border-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-100"
                  >
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Chunk Viewer Modal */}
      {isChunkViewerOpen && selectedDoc && (
        <ChunkViewer
          doc={selectedDoc}
          onClose={() => {
            setIsChunkViewerOpen(false);
            setSelectedDoc(null);
          }}
          onReindex={handleReindex}
        />
      )}
    </div>
  );
}
