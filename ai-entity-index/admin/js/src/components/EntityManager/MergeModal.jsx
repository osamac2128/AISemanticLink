/**
 * MergeModal - Modal for merging selected entities
 *
 * Features:
 * - Lists selected entities with radio buttons
 * - "Which entity should be the Master?" prompt
 * - Preview of merge effects (aliases transferred, mentions updated)
 * - Confirm/Cancel buttons
 */

import { useState, useEffect } from 'react';
import { useMutation } from '@tanstack/react-query';

// API function to merge entities
const mergeEntities = async ({ targetId, sourceIds }) => {
    const response = await fetch(
        `${window.vibeAI?.restUrl || '/wp-json/'}vibe-ai/v1/entities/merge`,
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.vibeAI?.nonce || '',
            },
            body: JSON.stringify({
                target_id: targetId,
                source_ids: sourceIds,
            }),
        }
    );

    if (!response.ok) {
        const error = await response.json();
        throw new Error(error.message || 'Failed to merge entities');
    }

    return response.json();
};

export default function MergeModal({
    isOpen,
    onClose,
    entities,
    onMergeComplete,
}) {
    const [selectedMasterId, setSelectedMasterId] = useState(null);

    // Reset selection when modal opens with new entities
    useEffect(() => {
        if (isOpen && entities.length > 0) {
            // Default to entity with highest mention count
            const defaultMaster = entities.reduce((prev, curr) =>
                (curr.mention_count || 0) > (prev.mention_count || 0) ? curr : prev
            );
            setSelectedMasterId(defaultMaster.id);
        }
    }, [isOpen, entities]);

    // Merge mutation
    const mergeMutation = useMutation({
        mutationFn: mergeEntities,
        onSuccess: () => {
            onMergeComplete();
        },
    });

    // Calculate merge preview
    const mergePreview = {
        aliasesToTransfer: entities
            .filter((e) => e.id !== selectedMasterId)
            .reduce((count, e) => count + (e.alias_count || 0) + 1, 0), // +1 for entity name becoming alias
        mentionsToUpdate: entities
            .filter((e) => e.id !== selectedMasterId)
            .reduce((count, e) => count + (e.mention_count || 0), 0),
        entitiesToMerge: entities.filter((e) => e.id !== selectedMasterId).length,
    };

    const handleConfirm = () => {
        if (!selectedMasterId) return;

        const sourceIds = entities
            .filter((e) => e.id !== selectedMasterId)
            .map((e) => e.id);

        mergeMutation.mutate({
            targetId: selectedMasterId,
            sourceIds,
        });
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            {/* Backdrop */}
            <div
                className="fixed inset-0 bg-black bg-opacity-50 transition-opacity"
                onClick={onClose}
            />

            {/* Modal */}
            <div className="flex min-h-full items-center justify-center p-4">
                <div className="relative bg-white rounded-lg shadow-xl max-w-lg w-full">
                    {/* Header */}
                    <div className="px-6 py-4 border-b border-gray-200">
                        <div className="flex items-center justify-between">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Merge Entities
                            </h3>
                            <button
                                onClick={onClose}
                                className="text-gray-400 hover:text-gray-500"
                            >
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <p className="mt-1 text-sm text-gray-500">
                            Select which entity should be the canonical master. All other entities will be merged into it.
                        </p>
                    </div>

                    {/* Body */}
                    <div className="px-6 py-4">
                        <label className="block text-sm font-medium text-gray-700 mb-3">
                            Which entity should be the Master?
                        </label>

                        <div className="space-y-2 max-h-64 overflow-y-auto">
                            {entities.map((entity) => (
                                <label
                                    key={entity.id}
                                    className={`
                                        flex items-center gap-3 p-3 rounded-lg border cursor-pointer
                                        transition-colors
                                        ${selectedMasterId === entity.id
                                            ? 'border-blue-500 bg-blue-50'
                                            : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'
                                        }
                                    `}
                                >
                                    <input
                                        type="radio"
                                        name="master-entity"
                                        value={entity.id}
                                        checked={selectedMasterId === entity.id}
                                        onChange={() => setSelectedMasterId(entity.id)}
                                        className="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500"
                                    />
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium text-gray-900 truncate">
                                                {entity.name}
                                            </span>
                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">
                                                {entity.type}
                                            </span>
                                        </div>
                                        <div className="text-sm text-gray-500 mt-0.5">
                                            {entity.mention_count || 0} mentions
                                            {entity.alias_count > 0 && ` | ${entity.alias_count} aliases`}
                                        </div>
                                    </div>
                                    {selectedMasterId === entity.id && (
                                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                                            Master
                                        </span>
                                    )}
                                </label>
                            ))}
                        </div>

                        {/* Preview */}
                        {selectedMasterId && (
                            <div className="mt-4 p-4 bg-gray-50 rounded-lg">
                                <h4 className="text-sm font-medium text-gray-700 mb-2">
                                    Merge Preview
                                </h4>
                                <ul className="space-y-1 text-sm text-gray-600">
                                    <li className="flex items-center gap-2">
                                        <svg className="w-4 h-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                        </svg>
                                        {mergePreview.entitiesToMerge} entities will be merged
                                    </li>
                                    <li className="flex items-center gap-2">
                                        <svg className="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                        </svg>
                                        {mergePreview.aliasesToTransfer} aliases will be transferred
                                    </li>
                                    <li className="flex items-center gap-2">
                                        <svg className="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                        </svg>
                                        {mergePreview.mentionsToUpdate} mentions will be updated
                                    </li>
                                </ul>
                            </div>
                        )}

                        {/* Error message */}
                        {mergeMutation.isError && (
                            <div className="mt-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                                <div className="flex items-start gap-2">
                                    <svg className="w-5 h-5 text-red-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <div>
                                        <p className="text-sm font-medium text-red-800">Merge failed</p>
                                        <p className="text-sm text-red-600">{mergeMutation.error?.message}</p>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Footer */}
                    <div className="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                        <button
                            onClick={onClose}
                            disabled={mergeMutation.isLoading}
                            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={handleConfirm}
                            disabled={!selectedMasterId || mergeMutation.isLoading}
                            className="px-4 py-2 text-sm font-medium text-white bg-purple-600 rounded-md hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-2"
                        >
                            {mergeMutation.isLoading ? (
                                <>
                                    <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                    </svg>
                                    Merging...
                                </>
                            ) : (
                                <>
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                    </svg>
                                    Confirm Merge
                                </>
                            )}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
