import { useState } from 'react';
import { completeOnboarding } from '../../api/client';

export default function StepComplete( { onComplete } ) {
	const [ finishing, setFinishing ] = useState( false );

	const handleFinish = async () => {
		setFinishing( true );
		try {
			await completeOnboarding();
		} catch {
			// Continue even if the endpoint fails — non-critical
		}
		onComplete();
	};

	return (
		<div className="p-8">
			<div className="flex flex-col items-center text-center py-6">
				<div className="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mb-4">
					<svg className="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
					</svg>
				</div>

				<h2 className="text-xl font-bold text-slate-800 mb-2">
					You're All Set!
				</h2>
				<p className="text-sm text-slate-500 max-w-sm">
					Your AI Entity Index is configured and ready to extract semantic entities from your content.
				</p>

				<div className="mt-6 grid grid-cols-3 gap-4 max-w-sm w-full">
					<div className="p-3 bg-blue-50 rounded-lg">
						<svg className="w-5 h-5 text-blue-600 mx-auto mb-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
						</svg>
						<p className="text-xs font-medium text-blue-700">API Key</p>
						<p className="text-xs text-blue-500">Connected</p>
					</div>
					<div className="p-3 bg-green-50 rounded-lg">
						<svg className="w-5 h-5 text-green-600 mx-auto mb-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
						</svg>
						<p className="text-xs font-medium text-green-700">Pipeline</p>
						<p className="text-xs text-green-500">Ready</p>
					</div>
					<div className="p-3 bg-purple-50 rounded-lg">
						<svg className="w-5 h-5 text-purple-600 mx-auto mb-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
						</svg>
						<p className="text-xs font-medium text-purple-700">Entities</p>
						<p className="text-xs text-purple-500">Extracting</p>
					</div>
				</div>
			</div>

			<div className="mt-6 flex justify-end">
				<button
					onClick={ handleFinish }
					disabled={ finishing }
					className="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
				>
					{ finishing ? (
						<>
							<div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
							Finishing...
						</>
					) : (
						<>
							Go to Dashboard
							<svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M13 7l5 5m0 0l-5 5m5-5H6" />
							</svg>
						</>
					) }
				</button>
			</div>
		</div>
	);
}
