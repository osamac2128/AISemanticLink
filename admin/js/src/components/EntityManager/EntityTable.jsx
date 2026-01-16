/**
 * EntityTable - TanStack Table implementation for entities
 *
 * Features:
 * - Column sorting
 * - Row selection with checkboxes
 * - Inline editing (name and type)
 * - Sync indicator for propagating entities
 * - Pagination controls
 */

import { useMemo, useState, useEffect, useRef } from 'react';
import {
    useReactTable,
    getCoreRowModel,
    flexRender,
} from '@tanstack/react-table';
import { ENTITY_TYPES, ENTITY_STATUSES } from './index';

// Sync indicator component
function SyncIndicator({ isPropagating, mentionCount }) {
    if (!isPropagating) return null;

    return (
        <div className="relative group">
            <svg
                className="w-4 h-4 text-blue-500 animate-spin"
                fill="none"
                viewBox="0 0 24 24"
            >
                <circle
                    className="opacity-25"
                    cx="12"
                    cy="12"
                    r="10"
                    stroke="currentColor"
                    strokeWidth="4"
                />
                <path
                    className="opacity-75"
                    fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                />
            </svg>
            <div className="absolute left-full ml-2 top-1/2 -translate-y-1/2 bg-gray-900 text-white text-xs rounded px-2 py-1 whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10">
                Syncing Schema to {mentionCount} posts...
            </div>
        </div>
    );
}

// Inline text input for editing
function InlineTextInput({ value, onSave, onCancel }) {
    const [inputValue, setInputValue] = useState(value);
    const inputRef = useRef(null);

    useEffect(() => {
        inputRef.current?.focus();
        inputRef.current?.select();
    }, []);

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            onSave(inputValue);
        } else if (e.key === 'Escape') {
            onCancel();
        }
    };

    return (
        <input
            ref={inputRef}
            type="text"
            value={inputValue}
            onChange={(e) => setInputValue(e.target.value)}
            onBlur={() => onSave(inputValue)}
            onKeyDown={handleKeyDown}
            className="w-full px-2 py-1 text-sm border border-blue-500 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
    );
}

// Inline dropdown for type editing
function InlineTypeSelect({ value, onSave, onCancel }) {
    const selectRef = useRef(null);

    useEffect(() => {
        selectRef.current?.focus();
    }, []);

    const handleChange = (e) => {
        onSave(e.target.value);
    };

    return (
        <select
            ref={selectRef}
            value={value}
            onChange={handleChange}
            onBlur={onCancel}
            className="w-full px-2 py-1 text-sm border border-blue-500 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
            {ENTITY_TYPES.map((type) => (
                <option key={type.value} value={type.value}>
                    {type.label}
                </option>
            ))}
        </select>
    );
}

// Status badge component
function StatusBadge({ status }) {
    const statusConfig = ENTITY_STATUSES.find((s) => s.value === status) || ENTITY_STATUSES[0];

    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${statusConfig.color}`}>
            {statusConfig.label}
        </span>
    );
}

// Checkbox component for row selection
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

export default function EntityTable({
    data,
    totalCount,
    totalPages,
    pagination,
    onPaginationChange,
    sorting,
    onSortingChange,
    rowSelection,
    onRowSelectionChange,
    onEntityClick,
    onInlineEdit,
    editingCell,
    onEditingCellChange,
}) {
    // Define columns
    const columns = useMemo(
        () => [
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
                        indeterminate={row.getIsSomeSelected()}
                        onChange={row.getToggleSelectedHandler()}
                    />
                ),
                size: 40,
            },
            // Name column (sortable, searchable, inline editable)
            {
                accessorKey: 'name',
                header: ({ column }) => (
                    <button
                        onClick={() => column.toggleSorting()}
                        className="flex items-center gap-1 font-medium text-gray-900 hover:text-blue-600"
                    >
                        Name
                        {column.getIsSorted() === 'asc' && (
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 15l7-7 7 7" />
                            </svg>
                        )}
                        {column.getIsSorted() === 'desc' && (
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                            </svg>
                        )}
                        {!column.getIsSorted() && (
                            <svg className="w-4 h-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                            </svg>
                        )}
                    </button>
                ),
                cell: ({ row }) => {
                    const entity = row.original;
                    const isEditing = editingCell?.id === entity.id && editingCell?.field === 'name';

                    if (isEditing) {
                        return (
                            <InlineTextInput
                                value={entity.name}
                                onSave={(value) => onInlineEdit(entity.id, 'name', value)}
                                onCancel={() => onEditingCellChange(null)}
                            />
                        );
                    }

                    return (
                        <div className="flex items-center gap-2">
                            <button
                                onClick={() => onEntityClick(entity.id)}
                                className="font-medium text-blue-600 hover:text-blue-800 hover:underline text-left"
                            >
                                {entity.name}
                            </button>
                            <button
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onEditingCellChange({ id: entity.id, field: 'name' });
                                }}
                                className="opacity-0 group-hover:opacity-100 p-1 text-gray-400 hover:text-gray-600"
                                title="Edit name"
                            >
                                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                </svg>
                            </button>
                            <SyncIndicator
                                isPropagating={entity.is_propagating}
                                mentionCount={entity.mention_count}
                            />
                        </div>
                    );
                },
                size: 250,
            },
            // Type column (sortable, dropdown filter)
            {
                accessorKey: 'type',
                header: ({ column }) => (
                    <button
                        onClick={() => column.toggleSorting()}
                        className="flex items-center gap-1 font-medium text-gray-900 hover:text-blue-600"
                    >
                        Type
                        {column.getIsSorted() === 'asc' && (
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 15l7-7 7 7" />
                            </svg>
                        )}
                        {column.getIsSorted() === 'desc' && (
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                            </svg>
                        )}
                    </button>
                ),
                cell: ({ row }) => {
                    const entity = row.original;
                    const isEditing = editingCell?.id === entity.id && editingCell?.field === 'type';
                    const typeLabel = ENTITY_TYPES.find((t) => t.value === entity.type)?.label || entity.type;

                    if (isEditing) {
                        return (
                            <InlineTypeSelect
                                value={entity.type}
                                onSave={(value) => onInlineEdit(entity.id, 'type', value)}
                                onCancel={() => onEditingCellChange(null)}
                            />
                        );
                    }

                    return (
                        <button
                            onClick={() => onEditingCellChange({ id: entity.id, field: 'type' })}
                            className="inline-flex items-center gap-1 px-2 py-1 text-sm text-gray-700 bg-gray-100 rounded hover:bg-gray-200"
                        >
                            {typeLabel}
                            <svg className="w-3 h-3 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                    );
                },
                size: 140,
            },
            // Mentions column (sortable)
            {
                accessorKey: 'mention_count',
                header: ({ column }) => (
                    <button
                        onClick={() => column.toggleSorting()}
                        className="flex items-center gap-1 font-medium text-gray-900 hover:text-blue-600"
                    >
                        Mentions
                        {column.getIsSorted() === 'asc' && (
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 15l7-7 7 7" />
                            </svg>
                        )}
                        {column.getIsSorted() === 'desc' && (
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                            </svg>
                        )}
                    </button>
                ),
                cell: ({ row }) => (
                    <span className="inline-flex items-center justify-center min-w-[2rem] px-2 py-0.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-full">
                        {row.original.mention_count || 0}
                    </span>
                ),
                size: 100,
            },
            // Status column (sortable, multi-select filter)
            {
                accessorKey: 'status',
                header: ({ column }) => (
                    <button
                        onClick={() => column.toggleSorting()}
                        className="flex items-center gap-1 font-medium text-gray-900 hover:text-blue-600"
                    >
                        Status
                        {column.getIsSorted() === 'asc' && (
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 15l7-7 7 7" />
                            </svg>
                        )}
                        {column.getIsSorted() === 'desc' && (
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                            </svg>
                        )}
                    </button>
                ),
                cell: ({ row }) => <StatusBadge status={row.original.status} />,
                size: 120,
            },
            // Schema Map link column
            {
                id: 'schema_map',
                header: 'Schema Map',
                cell: ({ row }) => {
                    const entity = row.original;
                    if (!entity.same_as_url) return <span className="text-gray-400">-</span>;

                    return (
                        <a
                            href={entity.same_as_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 hover:underline"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                            Link
                        </a>
                    );
                },
                size: 100,
            },
        ],
        [editingCell, onEntityClick, onInlineEdit, onEditingCellChange]
    );

    // Initialize table
    const table = useReactTable({
        data,
        columns,
        state: {
            sorting,
            rowSelection,
            pagination,
        },
        pageCount: totalPages,
        onSortingChange,
        onRowSelectionChange,
        onPaginationChange,
        getCoreRowModel: getCoreRowModel(),
        manualSorting: true,
        manualPagination: true,
        enableRowSelection: true,
    });

    // Empty state
    if (data.length === 0) {
        return (
            <div className="bg-white rounded-lg border border-gray-200 p-8 text-center">
                <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                </svg>
                <h3 className="mt-2 text-sm font-medium text-gray-900">No entities found</h3>
                <p className="mt-1 text-sm text-gray-500">
                    Start by running the extraction pipeline to discover entities in your content.
                </p>
            </div>
        );
    }

    return (
        <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
            {/* Table */}
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
                                            : flexRender(
                                                header.column.columnDef.header,
                                                header.getContext()
                                            )}
                                    </th>
                                ))}
                            </tr>
                        ))}
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {table.getRowModel().rows.map((row) => (
                            <tr
                                key={row.id}
                                className={`group hover:bg-gray-50 ${
                                    row.original.is_propagating ? 'bg-blue-50' : ''
                                }`}
                            >
                                {row.getVisibleCells().map((cell) => (
                                    <td
                                        key={cell.id}
                                        className="px-4 py-3 whitespace-nowrap text-sm"
                                    >
                                        {flexRender(
                                            cell.column.columnDef.cell,
                                            cell.getContext()
                                        )}
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
                            {Math.min(
                                (pagination.pageIndex + 1) * pagination.pageSize,
                                totalCount
                            )}{' '}
                            of {totalCount} entities
                        </span>
                    </div>

                    <div className="flex items-center gap-2">
                        <select
                            value={pagination.pageSize}
                            onChange={(e) =>
                                onPaginationChange({
                                    pageIndex: 0,
                                    pageSize: Number(e.target.value),
                                })
                            }
                            className="px-2 py-1 text-sm border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            {[10, 25, 50, 100].map((size) => (
                                <option key={size} value={size}>
                                    {size} per page
                                </option>
                            ))}
                        </select>

                        <div className="flex items-center gap-1">
                            <button
                                onClick={() =>
                                    onPaginationChange({
                                        ...pagination,
                                        pageIndex: 0,
                                    })
                                }
                                disabled={pagination.pageIndex === 0}
                                className="p-1 rounded border border-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-100"
                            >
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                                </svg>
                            </button>
                            <button
                                onClick={() =>
                                    onPaginationChange({
                                        ...pagination,
                                        pageIndex: pagination.pageIndex - 1,
                                    })
                                }
                                disabled={pagination.pageIndex === 0}
                                className="p-1 rounded border border-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-100"
                            >
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>
                            <span className="px-3 py-1 text-sm">
                                Page {pagination.pageIndex + 1} of {totalPages}
                            </span>
                            <button
                                onClick={() =>
                                    onPaginationChange({
                                        ...pagination,
                                        pageIndex: pagination.pageIndex + 1,
                                    })
                                }
                                disabled={pagination.pageIndex >= totalPages - 1}
                                className="p-1 rounded border border-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-100"
                            >
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                            <button
                                onClick={() =>
                                    onPaginationChange({
                                        ...pagination,
                                        pageIndex: totalPages - 1,
                                    })
                                }
                                disabled={pagination.pageIndex >= totalPages - 1}
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
    );
}
