/**
 * KBSettings Component
 *
 * Configuration panel for Knowledge Base settings.
 */

import { useState, useEffect, useCallback } from 'react';
import { useKBSettings, useUpdateKBSettings } from '../../hooks/useKB';
import Button from '../common/Button';

/**
 * Common post types for WordPress.
 */
const DEFAULT_POST_TYPES = [
  { name: 'post', label: 'Posts', description: 'Standard blog posts' },
  { name: 'page', label: 'Pages', description: 'Static pages' },
  { name: 'product', label: 'Products', description: 'WooCommerce products' },
  { name: 'article', label: 'Articles', description: 'Custom article type' },
];

/**
 * Default KB settings.
 */
const DEFAULT_SETTINGS = {
  enabled: true,
  post_types: ['post', 'page'],
  embedding_model: 'text-embedding-3-small',
  chunk_target_tokens: 450,
  chunk_overlap_tokens: 60,
  batch_size: 25,
  max_scan_vectors: 10000,
  pii_detection: false,
  block_on_pii: false,
};

/**
 * Range slider component.
 */
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

/**
 * Toggle switch component.
 */
function Toggle({ id, label, description, checked, onChange }) {
  return (
    <label htmlFor={id} className="flex items-start gap-3 cursor-pointer">
      <div className="relative flex-shrink-0">
        <input
          id={id}
          type="checkbox"
          checked={checked}
          onChange={(e) => onChange(e.target.checked)}
          className="sr-only"
        />
        <div
          className={`
            w-10 h-6 rounded-full transition-colors
            ${checked ? 'bg-blue-600' : 'bg-gray-300'}
          `}
        />
        <div
          className={`
            absolute top-1 left-1 w-4 h-4 bg-white rounded-full transition-transform
            ${checked ? 'translate-x-4' : 'translate-x-0'}
          `}
        />
      </div>
      <div className="flex-1">
        <span className="text-sm font-medium text-gray-900">{label}</span>
        {description && (
          <p className="text-xs text-gray-500 mt-0.5">{description}</p>
        )}
      </div>
    </label>
  );
}

/**
 * Post types checkbox list.
 */
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

/**
 * KBSettings main component.
 *
 * @param {Object} props - Component props.
 * @param {Object} props.vibeAiData - Localized data from WordPress.
 * @returns {JSX.Element} KBSettings element.
 */
export default function KBSettings({ vibeAiData }) {
  // Fetch settings
  const { data: settings, isLoading, isError, error } = useKBSettings();

  // Update mutation
  const updateMutation = useUpdateKBSettings();

  // Form state
  const [formData, setFormData] = useState(null);
  const [hasChanges, setHasChanges] = useState(false);

  // Initialize form data when settings load
  useEffect(() => {
    if (settings) {
      setFormData({
        enabled: settings.enabled ?? DEFAULT_SETTINGS.enabled,
        post_types: settings.post_types || DEFAULT_SETTINGS.post_types,
        embedding_model: settings.embedding_model || DEFAULT_SETTINGS.embedding_model,
        chunk_target_tokens: settings.chunk_target_tokens || DEFAULT_SETTINGS.chunk_target_tokens,
        chunk_overlap_tokens: settings.chunk_overlap_tokens || DEFAULT_SETTINGS.chunk_overlap_tokens,
        batch_size: settings.batch_size || DEFAULT_SETTINGS.batch_size,
        max_scan_vectors: settings.max_scan_vectors || DEFAULT_SETTINGS.max_scan_vectors,
        pii_detection: settings.pii_detection ?? DEFAULT_SETTINGS.pii_detection,
        block_on_pii: settings.block_on_pii ?? DEFAULT_SETTINGS.block_on_pii,
      });
      setHasChanges(false);
    }
  }, [settings]);

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
  const handleSave = useCallback(async () => {
    if (!formData) return;

    try {
      await updateMutation.mutateAsync(formData);
      setHasChanges(false);
    } catch (err) {
      // Error handled by mutation
    }
  }, [formData, updateMutation]);

  // Handle reset to defaults
  const handleReset = useCallback(() => {
    if (window.confirm('Are you sure you want to reset all settings to defaults?')) {
      setFormData(DEFAULT_SETTINGS);
      setHasChanges(true);
    }
  }, []);

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
    <div className="p-6 max-w-3xl mx-auto">
      {/* Header */}
      <div className="mb-8">
        <h1 className="text-2xl font-bold text-slate-800">Knowledge Base Settings</h1>
        <p className="mt-1 text-slate-500">
          Configure document indexing and embedding settings
        </p>
      </div>

      <div className="space-y-8">
        {/* Enable KB */}
        <section className="bg-white rounded-lg border border-gray-200 overflow-hidden">
          <div className="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h2 className="text-lg font-medium text-gray-900">General</h2>
          </div>
          <div className="px-6 py-6">
            <Toggle
              id="kb-enabled"
              label="Enable Knowledge Base"
              description="When enabled, documents will be indexed for AI-powered search"
              checked={formData.enabled}
              onChange={(value) => handleChange('enabled', value)}
            />
          </div>
        </section>

        {/* Post Types */}
        <section className="bg-white rounded-lg border border-gray-200 overflow-hidden">
          <div className="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h2 className="text-lg font-medium text-gray-900">Post Types</h2>
            <p className="text-sm text-gray-500 mt-0.5">
              Select which post types should be indexed
            </p>
          </div>
          <div className="px-6 py-6">
            <PostTypesCheckboxes
              postTypes={settings?.available_post_types || DEFAULT_POST_TYPES}
              selected={formData.post_types}
              onChange={(value) => handleChange('post_types', value)}
            />

            {formData.post_types.length === 0 && (
              <div className="mt-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                <p className="text-sm text-amber-800">
                  <strong>Warning:</strong> No post types selected. No content will be indexed.
                </p>
              </div>
            )}
          </div>
        </section>

        {/* Embedding Settings */}
        <section className="bg-white rounded-lg border border-gray-200 overflow-hidden">
          <div className="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h2 className="text-lg font-medium text-gray-900">Embedding Model</h2>
            <p className="text-sm text-gray-500 mt-0.5">
              Configure the embedding model for vector search
            </p>
          </div>
          <div className="px-6 py-6">
            <label htmlFor="embedding-model" className="block text-sm font-medium text-gray-700 mb-2">
              Model Name
            </label>
            <input
              id="embedding-model"
              type="text"
              value={formData.embedding_model}
              onChange={(e) => handleChange('embedding_model', e.target.value)}
              placeholder="text-embedding-3-small"
              className="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
            <p className="mt-1 text-xs text-gray-500">
              OpenAI embedding model to use (e.g., text-embedding-3-small, text-embedding-3-large)
            </p>
          </div>
        </section>

        {/* Chunk Settings */}
        <section className="bg-white rounded-lg border border-gray-200 overflow-hidden">
          <div className="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h2 className="text-lg font-medium text-gray-900">Chunk Settings</h2>
            <p className="text-sm text-gray-500 mt-0.5">
              Configure how documents are split into chunks
            </p>
          </div>
          <div className="px-6 py-6 space-y-6">
            <RangeSlider
              id="chunk-target-tokens"
              label="Target Tokens per Chunk"
              value={formData.chunk_target_tokens}
              min={200}
              max={800}
              step={50}
              onChange={(value) => handleChange('chunk_target_tokens', value)}
              displayValue={`${formData.chunk_target_tokens} tokens`}
              helpText="Target size for each chunk. Larger chunks provide more context but may be less precise."
            />

            <RangeSlider
              id="chunk-overlap-tokens"
              label="Overlap Tokens"
              value={formData.chunk_overlap_tokens}
              min={20}
              max={150}
              step={10}
              onChange={(value) => handleChange('chunk_overlap_tokens', value)}
              displayValue={`${formData.chunk_overlap_tokens} tokens`}
              helpText="Number of tokens to overlap between consecutive chunks for continuity."
            />
          </div>
        </section>

        {/* Batch Settings */}
        <section className="bg-white rounded-lg border border-gray-200 overflow-hidden">
          <div className="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h2 className="text-lg font-medium text-gray-900">Batch Settings</h2>
            <p className="text-sm text-gray-500 mt-0.5">
              Configure processing batch sizes
            </p>
          </div>
          <div className="px-6 py-6 space-y-6">
            <div>
              <label htmlFor="batch-size" className="block text-sm font-medium text-gray-700 mb-2">
                Batch Size
              </label>
              <input
                id="batch-size"
                type="number"
                min={5}
                max={100}
                value={formData.batch_size}
                onChange={(e) => handleChange('batch_size', parseInt(e.target.value, 10) || 25)}
                className="w-32 px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
              <p className="mt-1 text-xs text-gray-500">
                Number of documents to process per batch (5-100)
              </p>
            </div>

            <div>
              <label htmlFor="max-scan-vectors" className="block text-sm font-medium text-gray-700 mb-2">
                Max Scan Vectors
              </label>
              <input
                id="max-scan-vectors"
                type="number"
                min={1000}
                max={50000}
                step={1000}
                value={formData.max_scan_vectors}
                onChange={(e) => handleChange('max_scan_vectors', parseInt(e.target.value, 10) || 10000)}
                className="w-32 px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
              <p className="mt-1 text-xs text-gray-500">
                Maximum vectors to scan during search (1,000-50,000)
              </p>
            </div>
          </div>
        </section>

        {/* Safety Controls */}
        <section className="bg-white rounded-lg border border-gray-200 overflow-hidden">
          <div className="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h2 className="text-lg font-medium text-gray-900">Safety Controls</h2>
            <p className="text-sm text-gray-500 mt-0.5">
              Configure privacy and safety settings
            </p>
          </div>
          <div className="px-6 py-6 space-y-4">
            <Toggle
              id="pii-detection"
              label="PII Detection"
              description="Scan documents for personally identifiable information during indexing"
              checked={formData.pii_detection}
              onChange={(value) => handleChange('pii_detection', value)}
            />

            <Toggle
              id="block-on-pii"
              label="Block Indexing on PII"
              description="Prevent indexing documents that contain detected PII"
              checked={formData.block_on_pii}
              onChange={(value) => handleChange('block_on_pii', value)}
            />

            {formData.block_on_pii && !formData.pii_detection && (
              <div className="p-3 bg-amber-50 border border-amber-200 rounded-lg">
                <p className="text-sm text-amber-800">
                  <strong>Note:</strong> PII Detection must be enabled for this setting to take effect.
                </p>
              </div>
            )}
          </div>
        </section>

        {/* Save/Reset Buttons */}
        <div className="flex items-center justify-between py-4">
          <div className="flex items-center gap-4">
            {hasChanges && (
              <span className="text-sm text-amber-600 flex items-center gap-1">
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                You have unsaved changes
              </span>
            )}
            {updateMutation.isSuccess && !hasChanges && (
              <span className="text-sm text-green-600 flex items-center gap-1">
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                </svg>
                Settings saved successfully
              </span>
            )}
            {updateMutation.isError && (
              <span className="text-sm text-red-600 flex items-center gap-1">
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                {updateMutation.error?.message || 'Failed to save settings'}
              </span>
            )}
          </div>

          <div className="flex items-center gap-3">
            <Button
              variant="secondary"
              onClick={handleReset}
            >
              Reset to Defaults
            </Button>
            <Button
              variant="primary"
              onClick={handleSave}
              disabled={!hasChanges}
              loading={updateMutation.isLoading}
            >
              <svg className="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
              </svg>
              Save Settings
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}
