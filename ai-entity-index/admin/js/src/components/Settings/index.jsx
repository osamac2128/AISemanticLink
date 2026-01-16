/**
 * Settings - Configuration page for AI Entity Index
 *
 * Sections:
 * - API Settings: Model selection dropdown
 * - Processing Settings: Batch size slider, Confidence threshold slider
 * - Post Types: Checkboxes for which post types to process
 * - Save Settings button
 */

import { useState, useEffect, useCallback } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

// Available AI models
const AI_MODELS = [
    {
        value: 'anthropic/claude-3.5-sonnet',
        label: 'Claude 3.5 Sonnet',
        description: 'Accuracy-first extraction (Premium)',
        tier: 'premium',
    },
    {
        value: 'openai/gpt-4o-mini',
        label: 'GPT-4o Mini',
        description: 'Speed/cost optimization (Standard)',
        tier: 'standard',
    },
    {
        value: 'anthropic/claude-3-haiku',
        label: 'Claude 3 Haiku',
        description: 'High-volume, lower accuracy (Budget)',
        tier: 'budget',
    },
];

// Fetch settings
const fetchSettings = async () => {
    const response = await fetch(
        `${window.vibeAI?.restUrl || '/wp-json/'}vibe-ai/v1/settings`,
        {
            headers: {
                'X-WP-Nonce': window.vibeAI?.nonce || '',
            },
        }
    );

    if (!response.ok) {
        throw new Error('Failed to fetch settings');
    }

    return response.json();
};

// Update settings
const updateSettings = async (settings) => {
    const response = await fetch(
        `${window.vibeAI?.restUrl || '/wp-json/'}vibe-ai/v1/settings`,
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.vibeAI?.nonce || '',
            },
            body: JSON.stringify(settings),
        }
    );

    if (!response.ok) {
        throw new Error('Failed to save settings');
    }

    return response.json();
};

// Range slider component
function RangeSlider({ id, label, value, min, max, step, onChange, helpText, displayValue }) {
    return (
        <div>
            <div className="flex items-center justify-between mb-2">
                <label htmlFor={id} className="block text-sm font-medium text-gray-700">
                    {label}
                </label>
                <span className="text-sm font-semibold text-blue-600">
                    {displayValue || value}
                </span>
            </div>
            <input
                id={id}
                type="range"
                value={value}
                min={min}
                max={max}
                step={step}
                onChange={(e) => onChange(parseFloat(e.target.value))}
                className="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-blue-600"
            />
            {helpText && (
                <p className="mt-1 text-xs text-gray-500">{helpText}</p>
            )}
        </div>
    );
}

// Post types checkbox list
function PostTypesCheckboxes({ postTypes, selected, onChange }) {
    const handleToggle = (postType) => {
        const newSelected = selected.includes(postType)
            ? selected.filter((pt) => pt !== postType)
            : [...selected, postType];
        onChange(newSelected);
    };

    return (
        <div className="space-y-2">
            {postTypes.map((postType) => (
                <label
                    key={postType.name}
                    className="flex items-start gap-3 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100 transition-colors"
                >
                    <input
                        type="checkbox"
                        checked={selected.includes(postType.name)}
                        onChange={() => handleToggle(postType.name)}
                        className="mt-0.5 w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                    />
                    <div>
                        <span className="text-sm font-medium text-gray-900">
                            {postType.label}
                        </span>
                        {postType.count !== undefined && (
                            <span className="ml-2 text-xs text-gray-500">
                                ({postType.count} items)
                            </span>
                        )}
                        {postType.description && (
                            <p className="text-xs text-gray-500 mt-0.5">
                                {postType.description}
                            </p>
                        )}
                    </div>
                </label>
            ))}
        </div>
    );
}

export default function Settings() {
    const queryClient = useQueryClient();
    const [formData, setFormData] = useState(null);
    const [hasChanges, setHasChanges] = useState(false);

    // Fetch settings
    const { data: settings, isLoading, isError, error } = useQuery({
        queryKey: ['settings'],
        queryFn: fetchSettings,
    });

    // Initialize form data when settings load
    useEffect(() => {
        if (settings) {
            setFormData({
                ai_model: settings.ai_model || 'anthropic/claude-3.5-sonnet',
                batch_size: settings.batch_size || 25,
                confidence_threshold: settings.confidence_threshold || 0.6,
                post_types: settings.post_types || ['post', 'page'],
            });
            setHasChanges(false);
        }
    }, [settings]);

    // Save mutation
    const saveMutation = useMutation({
        mutationFn: updateSettings,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['settings'] });
            setHasChanges(false);
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
        if (!formData) return;
        saveMutation.mutate(formData);
    }, [formData, saveMutation]);

    // Loading state
    if (isLoading) {
        return (
            <div className="flex items-center justify-center h-64">
                <div className="flex flex-col items-center gap-3">
                    <div className="w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
                    <span className="text-sm text-gray-500">Loading settings...</span>
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
                            <h3 className="font-medium text-red-800">Failed to load settings</h3>
                            <p className="text-sm text-red-600 mt-1">{error?.message}</p>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    if (!formData) return null;

    return (
        <div className="max-w-3xl mx-auto">
            {/* Header */}
            <div className="mb-8">
                <h1 className="text-2xl font-semibold text-gray-900">Settings</h1>
                <p className="text-sm text-gray-500 mt-1">
                    Configure the AI Entity Index plugin settings
                </p>
            </div>

            <div className="space-y-8">
                {/* API Settings */}
                <section className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div className="px-6 py-4 bg-gray-50 border-b border-gray-200">
                        <h2 className="text-lg font-medium text-gray-900">API Settings</h2>
                        <p className="text-sm text-gray-500 mt-0.5">
                            Configure the AI model used for entity extraction
                        </p>
                    </div>
                    <div className="px-6 py-6">
                        <div>
                            <label htmlFor="ai-model" className="block text-sm font-medium text-gray-700 mb-3">
                                AI Model
                            </label>
                            <div className="space-y-2">
                                {AI_MODELS.map((model) => (
                                    <label
                                        key={model.value}
                                        className={`
                                            flex items-start gap-3 p-4 rounded-lg border cursor-pointer transition-colors
                                            ${formData.ai_model === model.value
                                                ? 'border-blue-500 bg-blue-50'
                                                : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'
                                            }
                                        `}
                                    >
                                        <input
                                            type="radio"
                                            name="ai-model"
                                            value={model.value}
                                            checked={formData.ai_model === model.value}
                                            onChange={() => handleChange('ai_model', model.value)}
                                            className="mt-0.5 w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500"
                                        />
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium text-gray-900">
                                                    {model.label}
                                                </span>
                                                <span className={`
                                                    inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                                    ${model.tier === 'premium' ? 'bg-purple-100 text-purple-700' : ''}
                                                    ${model.tier === 'standard' ? 'bg-blue-100 text-blue-700' : ''}
                                                    ${model.tier === 'budget' ? 'bg-green-100 text-green-700' : ''}
                                                `}>
                                                    {model.tier}
                                                </span>
                                            </div>
                                            <p className="text-sm text-gray-500 mt-0.5">
                                                {model.description}
                                            </p>
                                        </div>
                                    </label>
                                ))}
                            </div>
                        </div>

                        <div className="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                            <div className="flex items-start gap-2">
                                <svg className="w-5 h-5 text-amber-500 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <div className="text-sm text-amber-800">
                                    <strong>Note:</strong> API key must be configured in wp-config.php as{' '}
                                    <code className="bg-amber-100 px-1 rounded">VIBE_AI_OPENROUTER_KEY</code>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* Processing Settings */}
                <section className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div className="px-6 py-4 bg-gray-50 border-b border-gray-200">
                        <h2 className="text-lg font-medium text-gray-900">Processing Settings</h2>
                        <p className="text-sm text-gray-500 mt-0.5">
                            Adjust batch processing and quality thresholds
                        </p>
                    </div>
                    <div className="px-6 py-6 space-y-6">
                        <RangeSlider
                            id="batch-size"
                            label="Batch Size"
                            value={formData.batch_size}
                            min={5}
                            max={50}
                            step={5}
                            onChange={(value) => handleChange('batch_size', value)}
                            displayValue={`${formData.batch_size} posts/batch`}
                            helpText="Number of posts to process in each batch. Higher values are faster but may hit rate limits."
                        />

                        <RangeSlider
                            id="confidence-threshold"
                            label="Confidence Threshold"
                            value={formData.confidence_threshold}
                            min={0.4}
                            max={0.95}
                            step={0.05}
                            onChange={(value) => handleChange('confidence_threshold', value)}
                            displayValue={`${Math.round(formData.confidence_threshold * 100)}%`}
                            helpText="Minimum confidence score for entities to be included in Schema.org output."
                        />

                        <div className="p-3 bg-gray-50 rounded-lg">
                            <h4 className="text-sm font-medium text-gray-700 mb-2">Confidence Tiers</h4>
                            <div className="grid grid-cols-3 gap-2 text-xs">
                                <div className="p-2 bg-green-100 rounded text-green-700">
                                    <strong>High (85-100%)</strong>
                                    <br />Auto-approve
                                </div>
                                <div className="p-2 bg-yellow-100 rounded text-yellow-700">
                                    <strong>Medium (60-84%)</strong>
                                    <br />Flag for review
                                </div>
                                <div className="p-2 bg-red-100 rounded text-red-700">
                                    <strong>Low (40-59%)</strong>
                                    <br />Exclude from Schema
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* Post Types */}
                <section className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div className="px-6 py-4 bg-gray-50 border-b border-gray-200">
                        <h2 className="text-lg font-medium text-gray-900">Post Types</h2>
                        <p className="text-sm text-gray-500 mt-0.5">
                            Select which post types should be processed for entity extraction
                        </p>
                    </div>
                    <div className="px-6 py-6">
                        <PostTypesCheckboxes
                            postTypes={settings?.available_post_types || [
                                { name: 'post', label: 'Posts', description: 'Standard blog posts' },
                                { name: 'page', label: 'Pages', description: 'Static pages' },
                            ]}
                            selected={formData.post_types}
                            onChange={(value) => handleChange('post_types', value)}
                        />

                        {formData.post_types.length === 0 && (
                            <div className="mt-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                                <p className="text-sm text-amber-800">
                                    <strong>Warning:</strong> No post types selected. Entity extraction will not process any content.
                                </p>
                            </div>
                        )}
                    </div>
                </section>

                {/* Save Button */}
                <div className="flex items-center justify-between py-4">
                    <div>
                        {hasChanges && (
                            <span className="text-sm text-amber-600 flex items-center gap-1">
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                You have unsaved changes
                            </span>
                        )}
                        {saveMutation.isSuccess && !hasChanges && (
                            <span className="text-sm text-green-600 flex items-center gap-1">
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                </svg>
                                Settings saved successfully
                            </span>
                        )}
                        {saveMutation.isError && (
                            <span className="text-sm text-red-600 flex items-center gap-1">
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                {saveMutation.error?.message || 'Failed to save settings'}
                            </span>
                        )}
                    </div>

                    <button
                        onClick={handleSave}
                        disabled={!hasChanges || saveMutation.isLoading}
                        className="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                        {saveMutation.isLoading ? (
                            <>
                                <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                Saving...
                            </>
                        ) : (
                            <>
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                </svg>
                                Save Settings
                            </>
                        )}
                    </button>
                </div>
            </div>
        </div>
    );
}
