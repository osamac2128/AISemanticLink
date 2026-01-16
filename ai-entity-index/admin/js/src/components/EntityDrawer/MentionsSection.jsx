/**
 * MentionsSection - Display top mentions where entity appears
 *
 * Features:
 * - Top 5 snippets where entity appears
 * - Post title and link
 * - Confidence score
 * - Context snippet
 */

import { useQuery } from '@tanstack/react-query';

// Fetch mentions for entity
const fetchEntityMentions = async (entityId) => {
    const response = await fetch(
        `${window.vibeAI?.restUrl || '/wp-json/'}vibe-ai/v1/entities/${entityId}/mentions?limit=5`,
        {
            headers: {
                'X-WP-Nonce': window.vibeAI?.nonce || '',
            },
        }
    );

    if (!response.ok) {
        throw new Error('Failed to fetch mentions');
    }

    return response.json();
};

// Confidence badge component
function ConfidenceBadge({ confidence }) {
    const percentage = Math.round(confidence * 100);

    let colorClass = 'bg-green-100 text-green-700';
    if (percentage < 60) {
        colorClass = 'bg-red-100 text-red-700';
    } else if (percentage < 85) {
        colorClass = 'bg-yellow-100 text-yellow-700';
    }

    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${colorClass}`}>
            {percentage}%
        </span>
    );
}

export default function MentionsSection({ entityId }) {
    const { data: mentions, isLoading, isError } = useQuery({
        queryKey: ['entity-mentions', entityId],
        queryFn: () => fetchEntityMentions(entityId),
        enabled: !!entityId,
        staleTime: 60000, // 1 minute
    });

    return (
        <div className="p-6">
            <h3 className="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-4">
                Mention Context
            </h3>

            {isLoading && (
                <div className="flex items-center justify-center py-8">
                    <div className="flex items-center gap-2 text-sm text-gray-500">
                        <div className="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
                        Loading mentions...
                    </div>
                </div>
            )}

            {isError && (
                <div className="text-center py-6 bg-red-50 rounded-lg">
                    <p className="text-sm text-red-600">Failed to load mentions</p>
                </div>
            )}

            {!isLoading && !isError && mentions?.length === 0 && (
                <div className="text-center py-6 bg-gray-50 rounded-lg border border-dashed border-gray-300">
                    <svg className="mx-auto h-8 w-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <p className="mt-2 text-sm text-gray-500">No mentions found</p>
                    <p className="text-xs text-gray-400">This entity has not been linked to any posts yet</p>
                </div>
            )}

            {!isLoading && !isError && mentions?.length > 0 && (
                <div className="space-y-3">
                    {mentions.map((mention) => (
                        <div
                            key={mention.id}
                            className="bg-white border border-gray-200 rounded-lg p-4 hover:border-gray-300 transition-colors"
                        >
                            {/* Header */}
                            <div className="flex items-start justify-between gap-3 mb-2">
                                <div className="flex items-center gap-2 min-w-0">
                                    <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <a
                                        href={mention.post_url || `${window.vibeAI?.adminUrl || '/wp-admin/'}post.php?post=${mention.post_id}&action=edit`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-sm font-medium text-gray-900 hover:text-blue-600 truncate"
                                        title={mention.post_title}
                                    >
                                        {mention.post_title || `Post #${mention.post_id}`}
                                    </a>
                                </div>
                                <ConfidenceBadge confidence={mention.confidence} />
                            </div>

                            {/* Context snippet */}
                            {mention.context_snippet && (
                                <div className="mt-2 text-sm text-gray-600 bg-gray-50 rounded p-3 border-l-2 border-gray-300">
                                    <span className="italic">
                                        "...{mention.context_snippet}..."
                                    </span>
                                </div>
                            )}

                            {/* Meta info */}
                            <div className="mt-2 flex items-center gap-3 text-xs text-gray-400">
                                {mention.is_primary && (
                                    <span className="inline-flex items-center gap-1 text-blue-600">
                                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                                        </svg>
                                        Primary entity
                                    </span>
                                )}
                                <span>
                                    Post ID: {mention.post_id}
                                </span>
                            </div>
                        </div>
                    ))}

                    {mentions.length === 5 && (
                        <p className="text-center text-xs text-gray-500 pt-2">
                            Showing top 5 mentions. View all in the Entity Grid.
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
