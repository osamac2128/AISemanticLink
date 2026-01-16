/**
 * BulkActions - Toolbar for bulk entity operations
 *
 * Features:
 * - Merge Selected (enabled when 2+ selected)
 * - Set Status dropdown (enabled when 1+ selected)
 * - Delete button (enabled when 1+ selected)
 */

import { useState, useRef, useEffect } from 'react';
import { ENTITY_STATUSES } from './index';

export default function BulkActions({
    selectedCount,
    onMerge,
    onDelete,
    onStatusChange,
    isDeleting,
    isUpdating,
}) {
    const [isStatusDropdownOpen, setIsStatusDropdownOpen] = useState(false);
    const dropdownRef = useRef(null);

    // Close dropdown when clicking outside
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
                setIsStatusDropdownOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const handleStatusSelect = (status) => {
        onStatusChange(status);
        setIsStatusDropdownOpen(false);
    };

    // Don't render if nothing is selected
    if (selectedCount === 0) {
        return null;
    }

    return (
        <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <span className="inline-flex items-center justify-center w-6 h-6 bg-blue-600 text-white text-sm font-medium rounded-full">
                        {selectedCount}
                    </span>
                    <span className="text-sm font-medium text-blue-900">
                        {selectedCount === 1 ? 'entity' : 'entities'} selected
                    </span>
                </div>

                <div className="flex items-center gap-2">
                    {/* Merge Button */}
                    <button
                        onClick={onMerge}
                        disabled={selectedCount < 2}
                        className={`
                            inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-md
                            ${selectedCount >= 2
                                ? 'bg-purple-600 text-white hover:bg-purple-700'
                                : 'bg-gray-200 text-gray-400 cursor-not-allowed'
                            }
                            transition-colors
                        `}
                        title={selectedCount < 2 ? 'Select at least 2 entities to merge' : 'Merge selected entities'}
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                        </svg>
                        Merge
                    </button>

                    {/* Status Dropdown */}
                    <div className="relative" ref={dropdownRef}>
                        <button
                            onClick={() => setIsStatusDropdownOpen(!isStatusDropdownOpen)}
                            disabled={isUpdating}
                            className={`
                                inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-md
                                bg-white border border-gray-300 text-gray-700
                                hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed
                                transition-colors
                            `}
                        >
                            {isUpdating ? (
                                <>
                                    <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                    </svg>
                                    Updating...
                                </>
                            ) : (
                                <>
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                                    </svg>
                                    Set Status
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                    </svg>
                                </>
                            )}
                        </button>

                        {isStatusDropdownOpen && (
                            <div className="absolute right-0 mt-1 w-40 bg-white rounded-md shadow-lg border border-gray-200 z-10">
                                <div className="py-1">
                                    {ENTITY_STATUSES.map((status) => (
                                        <button
                                            key={status.value}
                                            onClick={() => handleStatusSelect(status.value)}
                                            className="w-full px-4 py-2 text-left text-sm hover:bg-gray-100 flex items-center gap-2"
                                        >
                                            <span className={`w-2 h-2 rounded-full ${status.color.split(' ')[0]}`} />
                                            {status.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Delete Button */}
                    <button
                        onClick={onDelete}
                        disabled={isDeleting}
                        className={`
                            inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-md
                            bg-red-600 text-white hover:bg-red-700
                            disabled:opacity-50 disabled:cursor-not-allowed
                            transition-colors
                        `}
                    >
                        {isDeleting ? (
                            <>
                                <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                Deleting...
                            </>
                        ) : (
                            <>
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                                Delete
                            </>
                        )}
                    </button>
                </div>
            </div>
        </div>
    );
}
