/**
 * IdentitySection - Entity metadata fields
 *
 * Fields:
 * - Name: Text input (canonical name)
 * - Slug: Read-only (auto-generated)
 * - Type: Select dropdown
 * - Status: Select dropdown
 */

import { ENTITY_TYPES, ENTITY_STATUSES } from '../EntityManager';

export default function IdentitySection({ formData, slug, onChange }) {
    return (
        <div className="p-6">
            <h3 className="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-4">
                Identity
            </h3>

            <div className="space-y-4">
                {/* Name */}
                <div>
                    <label htmlFor="entity-name" className="block text-sm font-medium text-gray-700 mb-1">
                        Canonical Name
                    </label>
                    <input
                        id="entity-name"
                        type="text"
                        value={formData.name}
                        onChange={(e) => onChange('name', e.target.value)}
                        className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm"
                        placeholder="Enter entity name"
                    />
                </div>

                {/* Slug (read-only) */}
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                        Slug
                    </label>
                    <div className="flex items-center gap-2">
                        <input
                            type="text"
                            value={slug || ''}
                            readOnly
                            className="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-500 cursor-not-allowed"
                        />
                        <span className="text-xs text-gray-400 whitespace-nowrap">
                            Auto-generated
                        </span>
                    </div>
                </div>

                {/* Type */}
                <div>
                    <label htmlFor="entity-type" className="block text-sm font-medium text-gray-700 mb-1">
                        Type
                    </label>
                    <select
                        id="entity-type"
                        value={formData.type}
                        onChange={(e) => onChange('type', e.target.value)}
                        className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm"
                    >
                        {ENTITY_TYPES.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </select>
                    <p className="mt-1 text-xs text-gray-500">
                        This determines the Schema.org type used in JSON-LD output.
                    </p>
                </div>

                {/* Status */}
                <div>
                    <label htmlFor="entity-status" className="block text-sm font-medium text-gray-700 mb-1">
                        Status
                    </label>
                    <select
                        id="entity-status"
                        value={formData.status}
                        onChange={(e) => onChange('status', e.target.value)}
                        className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm"
                    >
                        {ENTITY_STATUSES.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
                            </option>
                        ))}
                    </select>
                    <div className="mt-2 flex items-start gap-2 p-2 bg-gray-50 rounded text-xs text-gray-600">
                        <svg className="w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <div>
                            <strong>raw</strong>: Newly extracted, not reviewed<br />
                            <strong>reviewed</strong>: Human-verified accuracy<br />
                            <strong>canonical</strong>: Official, production-ready<br />
                            <strong>trash</strong>: Soft-deleted, excluded from Schema
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
