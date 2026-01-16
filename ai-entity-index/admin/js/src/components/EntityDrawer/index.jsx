/**
 * EntityDrawer - Right-side slide-over panel for entity editing
 *
 * Triggered by clicking entity name in the grid.
 * Sections:
 * 1. Identity (Metadata): Name, Slug, Type, Status
 * 2. Semantic Links: Wikipedia URL, Wikidata ID, Description
 * 3. Aliases: Pills/tags with management
 * 4. Mention Context: Top 5 snippets where entity appears
 */

import { useState, useEffect, useCallback } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import IdentitySection from './IdentitySection';
import SemanticLinksSection from './SemanticLinksSection';
import AliasesSection from './AliasesSection';
import MentionsSection from './MentionsSection';
import ActionButtons from './ActionButtons';

// Fetch single entity
const fetchEntity = async (entityId) => {
    const response = await fetch(
        `${window.vibeAI?.restUrl || '/wp-json/'}vibe-ai/v1/entities/${entityId}`,
        {
            headers: {
                'X-WP-Nonce': window.vibeAI?.nonce || '',
            },
        }
    );

    if (!response.ok) {
        throw new Error('Failed to fetch entity');
    }

    return response.json();
};

// Update entity
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

// Delete entity
const deleteEntity = async (id) => {
    const response = await fetch(
        `${window.vibeAI?.restUrl || '/wp-json/'}vibe-ai/v1/entities/${id}`,
        {
            method: 'DELETE',
            headers: {
                'X-WP-Nonce': window.vibeAI?.nonce || '',
            },
        }
    );

    if (!response.ok) {
        throw new Error('Failed to delete entity');
    }

    return response.json();
};

// Propagate entity changes
const propagateEntity = async (id) => {
    const response = await fetch(
        `${window.vibeAI?.restUrl || '/wp-json/'}vibe-ai/v1/entities/${id}/propagate`,
        {
            method: 'POST',
            headers: {
                'X-WP-Nonce': window.vibeAI?.nonce || '',
            },
        }
    );

    if (!response.ok) {
        throw new Error('Failed to start propagation');
    }

    return response.json();
};

// Force sync entity
const forceSyncEntity = async (id) => {
    const response = await fetch(
        `${window.vibeAI?.restUrl || '/wp-json/'}vibe-ai/v1/entities/${id}/force-sync`,
        {
            method: 'POST',
            headers: {
                'X-WP-Nonce': window.vibeAI?.nonce || '',
            },
        }
    );

    if (!response.ok) {
        throw new Error('Failed to force sync');
    }

    return response.json();
};

export function EntityDrawer({ isOpen, onClose, entityId }) {
    const queryClient = useQueryClient();
    const [formData, setFormData] = useState(null);
    const [hasChanges, setHasChanges] = useState(false);

    // Fetch entity data
    const { data: entity, isLoading, isError, error } = useQuery({
        queryKey: ['entity', entityId],
        queryFn: () => fetchEntity(entityId),
        enabled: isOpen && !!entityId,
        staleTime: 0, // Always fetch fresh data
    });

    // Initialize form data when entity loads
    useEffect(() => {
        if (entity) {
            setFormData({
                name: entity.name || '',
                type: entity.type || 'CONCEPT',
                status: entity.status || 'raw',
                same_as_url: entity.same_as_url || '',
                wikidata_id: entity.wikidata_id || '',
                description: entity.description || '',
                aliases: entity.aliases || [],
            });
            setHasChanges(false);
        }
    }, [entity]);

    // Update mutation
    const updateMutation = useMutation({
        mutationFn: updateEntity,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['entities'] });
            queryClient.invalidateQueries({ queryKey: ['entity', entityId] });
            setHasChanges(false);
        },
    });

    // Delete mutation
    const deleteMutation = useMutation({
        mutationFn: deleteEntity,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['entities'] });
            onClose();
        },
    });

    // Propagate mutation
    const propagateMutation = useMutation({
        mutationFn: propagateEntity,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['entities'] });
            queryClient.invalidateQueries({ queryKey: ['entity', entityId] });
        },
    });

    // Force sync mutation
    const forceSyncMutation = useMutation({
        mutationFn: forceSyncEntity,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['entities'] });
            queryClient.invalidateQueries({ queryKey: ['entity', entityId] });
        },
    });

    // Handle form changes
    const handleChange = useCallback((field, value) => {
        setFormData((prev) => {
            if (!prev) return prev;
            const updated = { ...prev, [field]: value };
            setHasChanges(true);
            return updated;
        });
    }, []);

    // Handle save
    const handleSave = useCallback(() => {
        if (!formData || !entityId) return;
        updateMutation.mutate({ id: entityId, data: formData });
    }, [formData, entityId, updateMutation]);

    // Handle save and propagate
    const handleSaveAndPropagate = useCallback(async () => {
        if (!formData || !entityId) return;

        try {
            await updateMutation.mutateAsync({ id: entityId, data: formData });
            await propagateMutation.mutateAsync(entityId);
        } catch (err) {
            // Error handling is done in mutation onError
        }
    }, [formData, entityId, updateMutation, propagateMutation]);

    // Handle force sync
    const handleForceSync = useCallback(() => {
        if (!entityId) return;
        forceSyncMutation.mutate(entityId);
    }, [entityId, forceSyncMutation]);

    // Handle delete
    const handleDelete = useCallback(() => {
        if (!entityId) return;

        if (window.confirm('Are you sure you want to delete this entity? This will set its status to "trash".')) {
            deleteMutation.mutate(entityId);
        }
    }, [entityId, deleteMutation]);

    // Handle close with unsaved changes warning
    const handleClose = useCallback(() => {
        if (hasChanges) {
            if (window.confirm('You have unsaved changes. Are you sure you want to close?')) {
                onClose();
            }
        } else {
            onClose();
        }
    }, [hasChanges, onClose]);

    // Handle alias operations
    const handleAddAlias = useCallback((alias) => {
        setFormData((prev) => {
            if (!prev) return prev;
            if (prev.aliases.some((a) => a.alias === alias)) return prev; // Already exists
            const updated = {
                ...prev,
                aliases: [...prev.aliases, { alias, alias_slug: alias.toLowerCase().replace(/\s+/g, '-') }],
            };
            setHasChanges(true);
            return updated;
        });
    }, []);

    const handleRemoveAlias = useCallback((aliasSlug) => {
        setFormData((prev) => {
            if (!prev) return prev;
            const updated = {
                ...prev,
                aliases: prev.aliases.filter((a) => a.alias_slug !== aliasSlug),
            };
            setHasChanges(true);
            return updated;
        });
    }, []);

    // Don't render if not open
    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 overflow-hidden">
            {/* Backdrop */}
            <div
                className="absolute inset-0 bg-black bg-opacity-25 transition-opacity"
                onClick={handleClose}
            />

            {/* Drawer panel */}
            <div className="absolute inset-y-0 right-0 flex max-w-full pl-10">
                <div className="w-screen max-w-lg">
                    <div className="flex h-full flex-col bg-white shadow-xl">
                        {/* Header */}
                        <div className="px-6 py-4 border-b border-gray-200 bg-gray-50">
                            <div className="flex items-start justify-between">
                                <div>
                                    <h2 className="text-lg font-semibold text-gray-900">
                                        {isLoading ? 'Loading...' : formData?.name || 'Entity Details'}
                                    </h2>
                                    {entity?.slug && (
                                        <p className="text-sm text-gray-500 mt-0.5">
                                            /{entity.slug}
                                        </p>
                                    )}
                                </div>
                                <button
                                    onClick={handleClose}
                                    className="rounded-md text-gray-400 hover:text-gray-500 focus:outline-none"
                                >
                                    <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            {/* Propagation status */}
                            {entity?.is_propagating && (
                                <div className="mt-3 flex items-center gap-2 text-sm text-blue-600">
                                    <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                    </svg>
                                    Propagating changes to {entity.mention_count} posts...
                                </div>
                            )}
                        </div>

                        {/* Content */}
                        <div className="flex-1 overflow-y-auto">
                            {isLoading && (
                                <div className="flex items-center justify-center h-64">
                                    <div className="flex flex-col items-center gap-3">
                                        <div className="w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
                                        <span className="text-sm text-gray-500">Loading entity...</span>
                                    </div>
                                </div>
                            )}

                            {isError && (
                                <div className="p-6">
                                    <div className="bg-red-50 border border-red-200 rounded-lg p-4">
                                        <div className="flex items-start gap-3">
                                            <svg className="w-5 h-5 text-red-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <div>
                                                <h3 className="font-medium text-red-800">Failed to load entity</h3>
                                                <p className="text-sm text-red-600 mt-1">{error?.message}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {!isLoading && !isError && formData && (
                                <div className="divide-y divide-gray-200">
                                    {/* Section 1: Identity */}
                                    <IdentitySection
                                        formData={formData}
                                        slug={entity?.slug}
                                        onChange={handleChange}
                                    />

                                    {/* Section 2: Semantic Links */}
                                    <SemanticLinksSection
                                        formData={formData}
                                        onChange={handleChange}
                                    />

                                    {/* Section 3: Aliases */}
                                    <AliasesSection
                                        aliases={formData.aliases}
                                        onAdd={handleAddAlias}
                                        onRemove={handleRemoveAlias}
                                    />

                                    {/* Section 4: Mentions */}
                                    <MentionsSection entityId={entityId} />
                                </div>
                            )}
                        </div>

                        {/* Footer Actions */}
                        {!isLoading && !isError && formData && (
                            <ActionButtons
                                onSave={handleSave}
                                onSaveAndPropagate={handleSaveAndPropagate}
                                onForceSync={handleForceSync}
                                onDelete={handleDelete}
                                isSaving={updateMutation.isLoading}
                                isPropagating={propagateMutation.isLoading}
                                isSyncing={forceSyncMutation.isLoading}
                                isDeleting={deleteMutation.isLoading}
                                hasChanges={hasChanges}
                                error={updateMutation.error || deleteMutation.error || propagateMutation.error || forceSyncMutation.error}
                            />
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

export default EntityDrawer;
