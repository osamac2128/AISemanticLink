/**
 * Semantic health dashboard panel.
 *
 * Surfaces schema coverage, entity coverage, KB readiness, and crawler-facing
 * endpoint health from the backend semantic health report.
 */

import Card from '../common/Card';
import Badge from '../common/Badge';

function formatPercent( value ) {
	return `${ Math.round( ( value || 0 ) * 100 ) }%`;
}

function formatHash( value ) {
	if ( ! value ) {
		return 'Unavailable';
	}

	if ( value.length <= 16 ) {
		return value;
	}

	return `${ value.slice( 0, 8 ) }...${ value.slice( -4 ) }`;
}

function formatDate( value ) {
	if ( ! value ) {
		return 'Not available';
	}

	try {
		return new Date( value ).toLocaleString();
	} catch {
		return value;
	}
}

function variantForStatus( status ) {
	switch ( status ) {
		case 'healthy':
		case 'pass':
			return 'success';
		case 'critical':
		case 'fail':
			return 'error';
		case 'attention':
		case 'warn':
			return 'warning';
		default:
			return 'default';
	}
}

function labelForStatus( status ) {
	switch ( status ) {
		case 'healthy':
			return 'Healthy';
		case 'attention':
			return 'Needs Attention';
		case 'critical':
			return 'Critical';
		case 'pass':
			return 'Pass';
		case 'warn':
			return 'Watch';
		case 'fail':
			return 'Fail';
		default:
			return 'Unknown';
	}
}

function MetricCard( { label, value, detail } ) {
	return (
		<div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
			<p className="text-sm font-medium text-slate-500">{ label }</p>
			<p className="mt-1 text-2xl font-semibold text-slate-900">
				{ value }
			</p>
			<p className="mt-2 text-sm text-slate-600">{ detail }</p>
		</div>
	);
}

function SkeletonBlock() {
	return <div className="h-24 rounded-lg bg-slate-100 animate-pulse" />;
}

export default function SemanticHealthPanel( {
	health = {},
	loading = false,
} ) {
	const summary = health.summary || {};
	const schema = health.schema || {};
	const entities = health.entities || {};
	const knowledgeBase = health.knowledge_base || {};
	const aiPublishing = health.ai_publishing || {};
	const checks = Array.isArray( health.checks ) ? health.checks : [];

	const metrics = [
		{
			label: 'Schema Coverage',
			value: formatPercent( schema.coverage_ratio ),
			detail: `${
				schema.valid_cached_posts || 0
			} valid schema caches across ${
				schema.eligible_posts || 0
			} eligible posts`,
		},
		{
			label: 'Entity Coverage',
			value: formatPercent( entities.coverage_ratio ),
			detail: `${
				entities.posts_with_entities || 0
			} posts have extracted entities`,
		},
		{
			label: 'KB Index Coverage',
			value: formatPercent( knowledgeBase.coverage_ratio ),
			detail: `${ knowledgeBase.indexed_docs || 0 } indexed docs, ${
				knowledgeBase.failed_docs || 0
			} failing`,
		},
		{
			label: 'Recent Changes',
			value: `${ aiPublishing.changes?.changes_7d || 0 } / 7d`,
			detail: aiPublishing.changes?.last_update
				? `Last change seen ${ formatDate(
						aiPublishing.changes.last_update
				  ) }`
				: 'No indexed KB updates have been published yet',
		},
	];

	const endpoints = [
		{
			key: 'llms',
			label: 'llms.txt',
			url: aiPublishing.llms_txt?.url || '/llms.txt',
			ready: !! aiPublishing.llms_txt?.ready,
			detail: `${
				aiPublishing.llms_txt?.curated_pages || 0
			} curated pages, ${
				aiPublishing.llms_txt?.indexed_pages || 0
			} indexed pages`,
		},
		{
			key: 'sitemap',
			label: 'AI Sitemap',
			url: aiPublishing.ai_sitemap?.url || '/ai-sitemap',
			ready: !! aiPublishing.ai_sitemap?.ready,
			detail: `${
				aiPublishing.ai_sitemap?.indexed_pages || 0
			} indexed pages, hash ${ formatHash(
				aiPublishing.ai_sitemap?.content_hash || ''
			) }`,
		},
		{
			key: 'changes',
			label: 'Changes Feed',
			url: aiPublishing.changes?.url || '/changes',
			ready: !! aiPublishing.changes?.ready,
			detail: `${
				aiPublishing.changes?.changes_24h || 0
			} changes in 24h, hash ${ formatHash(
				aiPublishing.changes?.feed_hash || ''
			) }`,
		},
	];

	return (
		<Card padding="lg">
			<Card.Header
				title="Semantic SEO Health"
				subtitle={
					loading
						? 'Loading semantic SEO readiness metrics...'
						: `${ summary.checks_passed || 0 } passing checks, ${
								summary.checks_warning || 0
						  } warnings, ${ summary.checks_failed || 0 } failures`
				}
				action={
					<Badge
						variant={ variantForStatus( summary.status ) }
						size="lg"
					>
						{ loading
							? 'Checking'
							: `${ labelForStatus( summary.status ) } ${
									summary.score || 0
							  }/100` }
					</Badge>
				}
			/>

			<div className="space-y-6">
				<div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
					{ loading
						? metrics.map( ( metric ) => (
								<SkeletonBlock key={ metric.label } />
						  ) )
						: metrics.map( ( metric ) => (
								<MetricCard
									key={ metric.label }
									label={ metric.label }
									value={ metric.value }
									detail={ metric.detail }
								/>
						  ) ) }
				</div>

				<div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
					<div className="rounded-lg border border-slate-200 p-4">
						<div className="flex items-center justify-between gap-3">
							<div>
								<h3 className="text-sm font-semibold text-slate-800">
									Release Checks
								</h3>
								<p className="mt-1 text-sm text-slate-500">
									Key signals for schema freshness,
									indexability, and crawler readiness.
								</p>
							</div>
						</div>

						<div className="mt-4 space-y-3">
							{ loading ? (
								<>
									<SkeletonBlock />
									<SkeletonBlock />
								</>
							) : (
								checks.map( ( check ) => (
									<div
										key={ check.key }
										className="rounded-lg border border-slate-100 bg-slate-50 p-3"
									>
										<div className="flex items-center justify-between gap-3">
											<p className="font-medium text-slate-800">
												{ check.label }
											</p>
											<Badge
												variant={ variantForStatus(
													check.status
												) }
												size="sm"
											>
												{ labelForStatus(
													check.status
												) }
											</Badge>
										</div>
										<p className="mt-2 text-sm text-slate-600">
											{ check.message }
										</p>
									</div>
								) )
							) }
						</div>
					</div>

					<div className="rounded-lg border border-slate-200 p-4">
						<h3 className="text-sm font-semibold text-slate-800">
							AI Discovery Endpoints
						</h3>
						<p className="mt-1 text-sm text-slate-500">
							Public crawler surfaces exposed by the plugin.
						</p>

						<div className="mt-4 space-y-3">
							{ loading ? (
								<>
									<SkeletonBlock />
									<SkeletonBlock />
									<SkeletonBlock />
								</>
							) : (
								endpoints.map( ( endpoint ) => (
									<div
										key={ endpoint.key }
										className="rounded-lg border border-slate-100 bg-slate-50 p-3"
									>
										<div className="flex items-center justify-between gap-3">
											<div>
												<p className="font-medium text-slate-800">
													{ endpoint.label }
												</p>
												<code className="mt-1 block break-all text-xs text-slate-500">
													{ endpoint.url }
												</code>
											</div>
											<Badge
												variant={
													endpoint.ready
														? 'success'
														: 'warning'
												}
												size="sm"
											>
												{ endpoint.ready
													? 'Ready'
													: 'Waiting' }
											</Badge>
										</div>
										<p className="mt-2 text-sm text-slate-600">
											{ endpoint.detail }
										</p>
									</div>
								) )
							) }
						</div>
					</div>
				</div>
			</div>
		</Card>
	);
}
