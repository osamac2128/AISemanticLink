/**
 * AliasesSection - Alias management with pills/tags
 *
 * Features:
 * - Display aliases as pills/tags
 * - Delete button (x) on each pill
 * - Add Alias input with (+) button
 */

import { useState, useCallback, useRef, useEffect } from 'react';

export default function AliasesSection({ aliases, onAdd, onRemove }) {
    const [newAlias, setNewAlias] = useState('');
    const [error, setError] = useState(null);
    const inputRef = useRef(null);

    // Clear error when input changes
    useEffect(() => {
        if (error && newAlias) {
            setError(null);
        }
    }, [newAlias, error]);

    const handleAdd = useCallback(() => {
        const trimmed = newAlias.trim();

        if (!trimmed) {
            setError('Please enter an alias');
            return;
        }

        // Check for duplicates
        if (aliases.some((a) => a.alias.toLowerCase() === trimmed.toLowerCase())) {
            setError('This alias already exists');
            return;
        }

        onAdd(trimmed);
        setNewAlias('');
        setError(null);
        inputRef.current?.focus();
    }, [newAlias, aliases, onAdd]);

    const handleKeyDown = useCallback((e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleAdd();
        }
    }, [handleAdd]);

    return (
        <div className="p-6">
            <h3 className="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-4">
                Aliases
            </h3>

            {/* Add alias input */}
            <div className="mb-4">
                <div className="flex gap-2">
                    <input
                        ref={inputRef}
                        type="text"
                        value={newAlias}
                        onChange={(e) => setNewAlias(e.target.value)}
                        onKeyDown={handleKeyDown}
                        className={`flex-1 px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm ${
                            error ? 'border-red-300' : 'border-gray-300'
                        }`}
                        placeholder="Add a new alias..."
                    />
                    <button
                        onClick={handleAdd}
                        className="inline-flex items-center gap-1 px-3 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                        Add
                    </button>
                </div>
                {error && (
                    <p className="mt-1 text-sm text-red-600">{error}</p>
                )}
            </div>

            {/* Aliases list */}
            {aliases.length === 0 ? (
                <div className="text-center py-6 bg-gray-50 rounded-lg border border-dashed border-gray-300">
                    <svg className="mx-auto h-8 w-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                    </svg>
                    <p className="mt-2 text-sm text-gray-500">No aliases yet</p>
                    <p className="text-xs text-gray-400">Add alternate names for this entity</p>
                </div>
            ) : (
                <div className="flex flex-wrap gap-2">
                    {aliases.map((alias) => (
                        <span
                            key={alias.alias_slug || alias.alias}
                            className="inline-flex items-center gap-1 px-3 py-1.5 bg-gray-100 text-gray-700 rounded-full text-sm group hover:bg-gray-200 transition-colors"
                        >
                            {alias.alias}
                            <button
                                onClick={() => onRemove(alias.alias_slug || alias.alias.toLowerCase().replace(/\s+/g, '-'))}
                                className="ml-1 text-gray-400 hover:text-red-500 transition-colors"
                                title={`Remove "${alias.alias}"`}
                            >
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </span>
                    ))}
                </div>
            )}

            {/* Help text */}
            <p className="mt-4 text-xs text-gray-500">
                Aliases help match variations of this entity name during extraction.
                Examples: abbreviations, nicknames, alternate spellings.
            </p>
        </div>
    );
}
