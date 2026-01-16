/**
 * SemanticLinksSection - Schema.org linking fields
 *
 * Fields:
 * - Wikipedia URL: URL input with "Fetch" button
 * - Wikidata ID: Text input with Q[0-9]+ validation
 * - Description: Textarea (500 char max)
 */

import { useState, useCallback } from 'react';

// Fetch Wikipedia data
const fetchWikipediaData = async (url) => {
    // Extract title from Wikipedia URL
    const match = url.match(/wikipedia\.org\/wiki\/(.+)$/);
    if (!match) {
        throw new Error('Invalid Wikipedia URL format');
    }

    const title = decodeURIComponent(match[1]);

    // Use Wikipedia API to get extract and Wikidata ID
    const apiUrl = `https://en.wikipedia.org/api/rest_v1/page/summary/${encodeURIComponent(title)}`;

    const response = await fetch(apiUrl);

    if (!response.ok) {
        throw new Error('Failed to fetch Wikipedia data');
    }

    return response.json();
};

export default function SemanticLinksSection({ formData, onChange }) {
    const [isFetching, setIsFetching] = useState(false);
    const [fetchError, setFetchError] = useState(null);

    // Validate Wikidata ID format (Q followed by digits)
    const isValidWikidataId = useCallback((id) => {
        if (!id) return true; // Empty is valid
        return /^Q\d+$/.test(id);
    }, []);

    const wikidataIdValid = isValidWikidataId(formData.wikidata_id);

    // Handle Wikipedia fetch
    const handleFetchWikipedia = useCallback(async () => {
        if (!formData.same_as_url) return;

        setIsFetching(true);
        setFetchError(null);

        try {
            const data = await fetchWikipediaData(formData.same_as_url);

            // Auto-populate description
            if (data.extract && !formData.description) {
                const truncated = data.extract.substring(0, 500);
                onChange('description', truncated);
            }

            // Try to extract Wikidata ID from response
            if (data.wikibase_item && !formData.wikidata_id) {
                onChange('wikidata_id', data.wikibase_item);
            }
        } catch (err) {
            setFetchError(err.message);
        } finally {
            setIsFetching(false);
        }
    }, [formData.same_as_url, formData.description, formData.wikidata_id, onChange]);

    // Character count for description
    const descriptionLength = formData.description?.length || 0;
    const maxDescription = 500;
    const isDescriptionOverLimit = descriptionLength > maxDescription;

    return (
        <div className="p-6">
            <h3 className="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-4">
                Semantic Links
            </h3>

            <div className="space-y-4">
                {/* Wikipedia URL */}
                <div>
                    <label htmlFor="entity-wikipedia" className="block text-sm font-medium text-gray-700 mb-1">
                        Wikipedia URL
                    </label>
                    <div className="flex gap-2">
                        <input
                            id="entity-wikipedia"
                            type="url"
                            value={formData.same_as_url}
                            onChange={(e) => onChange('same_as_url', e.target.value)}
                            className="flex-1 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm"
                            placeholder="https://en.wikipedia.org/wiki/..."
                        />
                        <button
                            onClick={handleFetchWikipedia}
                            disabled={!formData.same_as_url || isFetching}
                            className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {isFetching ? (
                                <>
                                    <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                    </svg>
                                    Fetching...
                                </>
                            ) : (
                                <>
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                    </svg>
                                    Fetch
                                </>
                            )}
                        </button>
                    </div>
                    {fetchError && (
                        <p className="mt-1 text-sm text-red-600">{fetchError}</p>
                    )}
                    <p className="mt-1 text-xs text-gray-500">
                        Used as sameAs property in Schema.org output
                    </p>
                </div>

                {/* Wikidata ID */}
                <div>
                    <label htmlFor="entity-wikidata" className="block text-sm font-medium text-gray-700 mb-1">
                        Wikidata ID
                    </label>
                    <input
                        id="entity-wikidata"
                        type="text"
                        value={formData.wikidata_id}
                        onChange={(e) => onChange('wikidata_id', e.target.value.toUpperCase())}
                        className={`w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm ${
                            !wikidataIdValid ? 'border-red-300 bg-red-50' : 'border-gray-300'
                        }`}
                        placeholder="Q12345"
                    />
                    {!wikidataIdValid && (
                        <p className="mt-1 text-sm text-red-600">
                            Invalid format. Wikidata IDs must start with Q followed by numbers (e.g., Q12345)
                        </p>
                    )}
                    {formData.wikidata_id && wikidataIdValid && (
                        <p className="mt-1 text-xs text-gray-500">
                            <a
                                href={`https://www.wikidata.org/wiki/${formData.wikidata_id}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-blue-600 hover:underline"
                            >
                                View on Wikidata
                            </a>
                        </p>
                    )}
                </div>

                {/* Description */}
                <div>
                    <label htmlFor="entity-description" className="block text-sm font-medium text-gray-700 mb-1">
                        Description
                    </label>
                    <textarea
                        id="entity-description"
                        value={formData.description}
                        onChange={(e) => onChange('description', e.target.value)}
                        rows={4}
                        maxLength={maxDescription + 50} // Allow slight overflow to show warning
                        className={`w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm resize-none ${
                            isDescriptionOverLimit ? 'border-red-300 bg-red-50' : 'border-gray-300'
                        }`}
                        placeholder="Brief description for Schema.org output"
                    />
                    <div className="mt-1 flex justify-between text-xs">
                        <span className="text-gray-500">
                            Used as the description property in Schema.org
                        </span>
                        <span className={isDescriptionOverLimit ? 'text-red-600 font-medium' : 'text-gray-400'}>
                            {descriptionLength}/{maxDescription}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    );
}
