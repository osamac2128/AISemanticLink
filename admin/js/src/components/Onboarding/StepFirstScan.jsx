import { useState, useEffect, useRef } from 'react';
import { startPipeline } from '../../api/client';
import { toast } from 'sonner';

export default function StepFirstScan( { onNext, onBack } ) {
	const [ status, setStatus ] = useState( 'idle' );
	const mountedRef = useRef( true );

	useEffect( () => {
		return () => {
			mountedRef.current = false;
		};
	}, [] );

	const handleStartScan = async () => {
		setStatus( 'starting' );
		try {
			await startPipeline();
			toast.success( 'First scan started!' );
			setStatus( 'started' );
			const timerId = setTimeout( () => {
				if ( mountedRef.current ) onNext();
			}, 1500 );
			return () => clearTimeout( timerId );
		} catch ( err ) {
			setStatus( 'error' );
			toast.error( err.message || 'Failed to start scan.' );
		}
	};

	return (
		<div className="p-8">
			<div className="flex items-center gap-3 mb-2">
				<div className="p-2 bg-blue-50 rounded-lg">
					<svg className="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
					</svg>
				</div>
				<h2 className="text-lg font-semibold text-slate-800">Run Your First Scan</h2>
			</div>
			<p className="text-sm text-slate-500 mb-6 ml-11">
				The pipeline will scan your first batch of posts and extract entities. You can monitor progress from the dashboard.
			</p>

			<div className="flex flex-col items-center py-6">
				{ status === 'idle' && (
					<div className="text-center space-y-4">
						<div className="p-6 bg-slate-50 rounded-xl border border-slate-200 max-w-sm">
							<div className="grid grid-cols-3 gap-3 text-center">
								<div>
									<p className="text-lg font-bold text-blue-600">~25</p>
									<p className="text-xs text-slate-500">Posts to scan</p>
								</div>
								<div>
									<p className="text-lg font-bold text-blue-600">AI</p>
									<p className="text-xs text-slate-500">Entity extraction</p>
								</div>
								<div>
									<p className="text-lg font-bold text-blue-600">Auto</p>
									<p className="text-xs text-slate-500">Schema markup</p>
								</div>
							</div>
						</div>
						<button
							onClick={ handleStartScan }
							className="inline-flex items-center gap-2 px-6 py-3 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors"
						>
							<svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
								<path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clipRule="evenodd" />
							</svg>
							Start First Scan
						</button>
						<button
							onClick={ onNext }
							className="block mx-auto text-sm text-slate-400 hover:text-slate-600 transition-colors"
						>
							Skip for now
						</button>
					</div>
				) }

				{ status === 'starting' && (
					<div className="flex flex-col items-center gap-3">
						<div className="w-10 h-10 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
						<p className="text-sm text-slate-500">Starting pipeline...</p>
					</div>
				) }

				{ status === 'started' && (
					<div className="flex flex-col items-center gap-3">
						<div className="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
							<svg className="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M5 13l4 4L19 7" />
							</svg>
						</div>
						<p className="text-sm font-medium text-green-700">Scan started!</p>
						<p className="text-xs text-slate-500">Proceeding to finish setup...</p>
					</div>
				) }

				{ status === 'error' && (
					<div className="flex flex-col items-center gap-3">
						<div className="w-12 h-12 rounded-full bg-amber-100 flex items-center justify-center">
							<svg className="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
							</svg>
						</div>
						<p className="text-sm font-medium text-amber-700">Could not start scan</p>
						<p className="text-xs text-slate-500">You can start it later from the dashboard.</p>
						<button
							onClick={ onNext }
							className="mt-2 inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors"
						>
							Continue anyway
						</button>
					</div>
				) }
			</div>

			<div className="mt-6 flex justify-start">
				{ status === 'idle' && (
					<button
						onClick={ onBack }
						className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium text-slate-600 bg-slate-50 rounded-lg hover:bg-slate-100 transition-colors"
					>
						<svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M15 19l-7-7 7-7" />
						</svg>
						Back
					</button>
				) }
			</div>
		</div>
	);
}
