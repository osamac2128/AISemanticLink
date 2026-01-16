/**
 * ChunkViewer Component
 *
 * Modal to view chunks for a specific document.
 */

import { useState, useCallback, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from '../common/Button';
import Badge from '../common/Badge';

/**
 * Fetch document chunks.
 */
const fetchDocChunks = async (postId) => {
  const response = await fetch(
    `${window.vibeAiData?.apiUrl || '/wp-json/vibe-ai/v1'}/kb/docs/${postId}`,
    {
      headers: {
        'X-WP-Nonce': window.vibeAiData?.nonce || '',
      },
    }
  );

  if (!response.ok) {
    throw new Error('Failed to fetch document chunks');
  }

  return response.json();
};

/**
 * Copy to clipboard utility.
 */
async function copyToClipboard(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    return false;
  }
}

/**
 * Single chunk card component.
 */
function ChunkCard({ chunk, index, isExpanded, onToggleExpand }) {
  const [copied, setCopied] = useState(false);

  const handleCopyAnchor = useCallback(async () => {
    if (chunk.anchor) {
      const success = await copyToClipboard(chunk.anchor);
      if (success) {
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      }
    }
  }, [chunk.anchor]);

  return (
    <div className="border border-gray-200 rounded-lg overflow-hidden bg-white">
      {/* Header */}
      <div className="px-4 py-3 bg-gray-50 border-b border-gray-200">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            {/* Chunk index */}
            <span className="inline-flex items-center justify-center w-6 h-6 text-xs font-semibold text-gray-600 bg-gray-200 rounded-full">
              {index + 1}
            </span>

            {/* Heading path */}
            {chunk.heading_path && chunk.heading_path.length > 0 && (
              <div className="flex items-center gap-1 text-sm text-gray-600">
                {chunk.heading_path.map((heading, i) => (
                  <span key={i} className="flex items-center">
                    {i > 0 && <span className="mx-1 text-gray-400">/</span>}
                    <span>{heading}</span>
                  </span>
                ))}
              </div>
            )}
          </div>

          <div className="flex items-center gap-3">
            {/* Token count */}
            <span className="text-xs text-gray-500">
              ~{chunk.token_count || 0} tokens
            </span>

            {/* Has vector indicator */}
            {chunk.has_vector ? (
              <Badge variant="success" size="sm">
                <svg className="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                </svg>
                Vector
              </Badge>
            ) : (
              <Badge variant="warning" size="sm">
                <svg className="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                </svg>
                No Vector
              </Badge>
            )}
          </div>
        </div>
      </div>

      {/* Content */}
      <div className="px-4 py-3">
        {/* Anchor */}
        {chunk.anchor && (
          <div className="flex items-center gap-2 mb-3">
            <span className="text-xs text-gray-500">Anchor:</span>
            <code className="px-2 py-0.5 text-xs font-mono bg-gray-100 rounded">
              #{chunk.anchor}
            </code>
            <button
              onClick={handleCopyAnchor}
              className="text-gray-400 hover:text-gray-600"
              title="Copy anchor"
            >
              {copied ? (
                <svg className="w-4 h-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                </svg>
              ) : (
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
              )}
            </button>
          </div>
        )}

        {/* Text content */}
        <div className="text-sm text-gray-700 leading-relaxed">
          {isExpanded ? (
            <p className="whitespace-pre-wrap">{chunk.text}</p>
          ) : (
            <p>
              {chunk.text?.length > 300
                ? chunk.text.substring(0, 300).trim() + '...'
                : chunk.text}
            </p>
          )}
        </div>

        {/* Expand toggle */}
        {chunk.text?.length > 300 && (
          <button
            onClick={onToggleExpand}
            className="mt-2 text-sm text-blue-600 hover:text-blue-800"
          >
            {isExpanded ? 'Show less' : 'Expand'}
          </button>
        )}
      </div>
    </div>
  );
}

/**
 * ChunkViewer modal component.
 *
 * @param {Object} props - Component props.
 * @param {Object} props.doc - Document object with post_id and title.
 * @param {Function} props.onClose - Close handler.
 * @param {Function} props.onReindex - Reindex handler.
 * @returns {JSX.Element} ChunkViewer element.
 */
export default function ChunkViewer({ doc, onClose, onReindex }) {
  const [expandedChunks, setExpandedChunks] = useState(new Set());

  // Fetch chunks
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['kb-doc-chunks', doc.post_id],
    queryFn: () => fetchDocChunks(doc.post_id),
    enabled: !!doc.post_id,
  });

  // Close on escape key
  useEffect(() => {
    const handleEscape = (e) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };

    document.addEventListener('keydown', handleEscape);
    return () => document.removeEventListener('keydown', handleEscape);
  }, [onClose]);

  // Prevent body scroll when modal is open
  useEffect(() => {
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = '';
    };
  }, []);

  // Toggle chunk expansion
  const toggleExpand = useCallback((index) => {
    setExpandedChunks((prev) => {
      const next = new Set(prev);
      if (next.has(index)) {
        next.delete(index);
      } else {
        next.add(index);
      }
      return next;
    });
  }, []);

  // Handle reindex
  const handleReindex = useCallback(() => {
    if (onReindex) {
      onReindex(doc.post_id);
    }
  }, [doc.post_id, onReindex]);

  const chunks = data?.chunks || [];

  return (
    <div className="fixed inset-0 z-50 overflow-hidden">
      {/* Backdrop */}
      <div
        className="absolute inset-0 bg-black bg-opacity-50 transition-opacity"
        onClick={onClose}
      />

      {/* Drawer */}
      <div className="absolute right-0 top-0 bottom-0 w-full max-w-2xl bg-white shadow-xl flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-white">
          <div className="flex-1 min-w-0">
            <h2 className="text-lg font-semibold text-gray-900 truncate">
              {doc.title}
            </h2>
            <p className="text-sm text-gray-500 mt-0.5">
              {chunks.length} chunk{chunks.length !== 1 ? 's' : ''} indexed
            </p>
          </div>
          <div className="flex items-center gap-3 ml-4">
            <Button
              variant="secondary"
              size="sm"
              onClick={handleReindex}
            >
              Reindex Chunks
            </Button>
            <button
              onClick={onClose}
              className="p-2 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100"
            >
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto px-6 py-4">
          {isLoading ? (
            <div className="flex items-center justify-center h-64">
              <div className="flex flex-col items-center gap-3">
                <div className="w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
                <span className="text-sm text-gray-500">Loading chunks...</span>
              </div>
            </div>
          ) : isError ? (
            <div className="bg-red-50 border border-red-200 rounded-lg p-4">
              <div className="flex items-start gap-3">
                <svg className="w-5 h-5 text-red-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <div>
                  <h3 className="font-medium text-red-800">Failed to load chunks</h3>
                  <p className="text-sm text-red-600 mt-1">{error?.message}</p>
                  <button
                    onClick={() => refetch()}
                    className="mt-2 text-sm text-red-700 hover:text-red-800 underline"
                  >
                    Try again
                  </button>
                </div>
              </div>
            </div>
          ) : chunks.length === 0 ? (
            <div className="text-center py-12">
              <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              <h3 className="mt-2 text-sm font-medium text-gray-900">No chunks found</h3>
              <p className="mt-1 text-sm text-gray-500">
                This document has not been chunked yet. Try reindexing.
              </p>
              <Button
                variant="primary"
                size="sm"
                className="mt-4"
                onClick={handleReindex}
              >
                Reindex Document
              </Button>
            </div>
          ) : (
            <div className="space-y-4">
              {/* Summary */}
              <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <span className="text-sm text-gray-600">
                  Total: {chunks.length} chunks, ~{chunks.reduce((acc, c) => acc + (c.token_count || 0), 0)} tokens
                </span>
                <div className="flex items-center gap-2">
                  <span className="text-sm text-gray-500">
                    {chunks.filter((c) => c.has_vector).length} with vectors
                  </span>
                </div>
              </div>

              {/* Chunk list */}
              {chunks.map((chunk, index) => (
                <ChunkCard
                  key={chunk.id || index}
                  chunk={chunk}
                  index={index}
                  isExpanded={expandedChunks.has(index)}
                  onToggleExpand={() => toggleExpand(index)}
                />
              ))}
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="px-6 py-4 border-t border-gray-200 bg-gray-50">
          <div className="flex items-center justify-between">
            <a
              href={doc.url}
              target="_blank"
              rel="noopener noreferrer"
              className="text-sm text-blue-600 hover:text-blue-800 inline-flex items-center gap-1"
            >
              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
              </svg>
              View Original
            </a>
            <Button variant="secondary" onClick={onClose}>
              Close
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}
