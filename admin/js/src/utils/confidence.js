/**
 * Shared confidence tier utility.
 *
 * Centralises the confidence thresholds and display metadata used by
 * EntityTable and MentionsSection so both components stay in sync.
 *
 * Thresholds follow the Config::CONFIDENCE_HIGH / CONFIDENCE_MEDIUM pattern:
 *   - High:   >= 80%
 *   - Medium: >= 50%
 *   - Low:    <  50%
 */

/**
 * Get confidence tier information from a confidence score.
 *
 * @param {number} confidence - Confidence score between 0 and 1.
 * @returns {{ tier: string, pct: number, color: string, tooltip: string }}
 */
export function getConfidenceTier( confidence ) {
	const pct = Math.round( ( confidence || 0 ) * 100 );

	if ( pct >= 80 ) {
		return {
			tier: 'high',
			pct,
			color: 'bg-green-100 text-green-800',
			tooltip: `AI confidence: ${ pct }%. Very likely correct.`,
		};
	}

	if ( pct >= 50 ) {
		return {
			tier: 'medium',
			pct,
			color: 'bg-yellow-100 text-yellow-800',
			tooltip: `AI confidence: ${ pct }%. Needs review.`,
		};
	}

	return {
		tier: 'low',
		pct,
		color: 'bg-red-100 text-red-800',
		tooltip: `AI confidence: ${ pct }%. Likely incorrect, review recommended.`,
	};
}
