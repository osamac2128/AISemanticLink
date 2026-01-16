/**
 * Filters - Filter controls for the entity grid
 *
 * Features:
 * - Type multi-select dropdown
 * - Status multi-select dropdown
 * - Search text input
 * - Clear filters button
 */

import { useState, useRef, useEffect, useCallback } from 'react';
import { ENTITY_TYPES, ENTITY_STATUSES } from './index';

// Multi-select dropdown component
function MultiSelectDropdown({ label, options, selected, onChange, icon }) {
    const [isOpen, setIsOpen] = useState(false);
    const dropdownRef = useRef(null);

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const toggleOption = (value) => {
        const newSelected = selected.includes(value)
            ? selected.filter((v) => v !== value)
            : [...selected, value];
        onChange(newSelected);
    };

    const selectedLabels = options
        .filter((opt) => selected.includes(opt.value))
        .map((opt) => opt.label);

    return (
        <div className="relative" ref={dropdownRef}>
            <button
                onClick={() => setIsOpen(!isOpen)}
                className={`
                    inline-flex items-center gap-2 px-3 py-2 text-sm
                    bg-white border rounded-md
                    hover:bg-gray-50 transition-colors
                    ${selected.length > 0 ? 'border-blue-500 text-blue-700' : 'border-gray-300 text-gray-700'}
                `}
            >
                {icon}
                <span>
                    {selected.length === 0
                        ? label
                        : selected.length === 1
                            ? selectedLabels[0]
                            : `${selected.length} selected`}
                </span>
                <svg
                    className={`w-4 h-4 transition-transform ${isOpen ? 'rotate-180' : ''}`}
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            {isOpen && (
                <div className="absolute left-0 mt-1 w-56 bg-white rounded-md shadow-lg border border-gray-200 z-20">
                    <div className="py-1 max-h-64 overflow-y-auto">
                        {options.map((option) => (
                            <label
                                key={option.value}
                                className="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 cursor-pointer"
                            >
                                <input
                                    type="checkbox"
                                    checked={selected.includes(option.value)}
                                    onChange={() => toggleOption(option.value)}
                                    className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                                />
                                <span className="flex items-center gap-2 text-sm text-gray-700">
                                    {option.color && (
                                        <span className={`w-2 h-2 rounded-full ${option.color.split(' ')[0]}`} />
                                    )}
                                    {option.label}
                                </span>
                            </label>
                        ))}
                    </div>
                    {selected.length > 0 && (
                        <div className="border-t border-gray-200 px-4 py-2">
                            <button
                                onClick={() => onChange([])}
                                className="text-sm text-blue-600 hover:text-blue-800"
                            >
                                Clear selection
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

// Debounce hook
function useDebounce(value, delay) {
    const [debouncedValue, setDebouncedValue] = useState(value);

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedValue(value), delay);
        return () => clearTimeout(timer);
    }, [value, delay]);

    return debouncedValue;
}

export default function Filters({ filters, onChange, onClear }) {
    const [searchInput, setSearchInput] = useState(filters.search);
    const debouncedSearch = useDebounce(searchInput, 300);

    // Update filters when debounced search changes
    useEffect(() => {
        if (debouncedSearch !== filters.search) {
            onChange({ ...filters, search: debouncedSearch });
        }
    }, [debouncedSearch, filters, onChange]);

    // Sync search input with external filter changes
    useEffect(() => {
        if (filters.search !== searchInput && filters.search === '') {
            setSearchInput('');
        }
    }, [filters.search]);

    const handleTypeChange = useCallback((types) => {
        onChange({ ...filters, types });
    }, [filters, onChange]);

    const handleStatusChange = useCallback((statuses) => {
        onChange({ ...filters, statuses });
    }, [filters, onChange]);

    const handleClear = () => {
        setSearchInput('');
        onClear();
    };

    const hasActiveFilters = filters.search || filters.types.length > 0 || filters.statuses.length > 0;

    return (
        <div className="bg-white rounded-lg border border-gray-200 p-4">
            <div className="flex flex-wrap items-center gap-3">
                {/* Search Input */}
                <div className="relative flex-1 min-w-[200px] max-w-md">
                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg className="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input
                        type="text"
                        value={searchInput}
                        onChange={(e) => setSearchInput(e.target.value)}
                        placeholder="Search entities..."
                        className="w-full pl-10 pr-4 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    />
                    {searchInput && (
                        <button
                            onClick={() => setSearchInput('')}
                            className="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    )}
                </div>

                {/* Type Filter */}
                <MultiSelectDropdown
                    label="Type"
                    options={ENTITY_TYPES}
                    selected={filters.types}
                    onChange={handleTypeChange}
                    icon={
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                        </svg>
                    }
                />

                {/* Status Filter */}
                <MultiSelectDropdown
                    label="Status"
                    options={ENTITY_STATUSES}
                    selected={filters.statuses}
                    onChange={handleStatusChange}
                    icon={
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                        </svg>
                    }
                />

                {/* Clear Filters */}
                {hasActiveFilters && (
                    <button
                        onClick={handleClear}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-md transition-colors"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Clear filters
                    </button>
                )}
            </div>

            {/* Active Filters Display */}
            {hasActiveFilters && (
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <span className="text-xs text-gray-500">Active filters:</span>

                    {filters.search && (
                        <span className="inline-flex items-center gap-1 px-2 py-1 bg-blue-100 text-blue-700 rounded-full text-xs">
                            Search: "{filters.search}"
                            <button
                                onClick={() => {
                                    setSearchInput('');
                                    onChange({ ...filters, search: '' });
                                }}
                                className="hover:text-blue-900"
                            >
                                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </span>
                    )}

                    {filters.types.map((type) => {
                        const typeLabel = ENTITY_TYPES.find((t) => t.value === type)?.label || type;
                        return (
                            <span
                                key={type}
                                className="inline-flex items-center gap-1 px-2 py-1 bg-purple-100 text-purple-700 rounded-full text-xs"
                            >
                                {typeLabel}
                                <button
                                    onClick={() => handleTypeChange(filters.types.filter((t) => t !== type))}
                                    className="hover:text-purple-900"
                                >
                                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </span>
                        );
                    })}

                    {filters.statuses.map((status) => {
                        const statusConfig = ENTITY_STATUSES.find((s) => s.value === status);
                        return (
                            <span
                                key={status}
                                className={`inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs ${statusConfig?.color || 'bg-gray-100 text-gray-700'}`}
                            >
                                {statusConfig?.label || status}
                                <button
                                    onClick={() => handleStatusChange(filters.statuses.filter((s) => s !== status))}
                                    className="hover:opacity-80"
                                >
                                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </span>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
