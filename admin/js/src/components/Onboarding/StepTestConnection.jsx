import { useState } from 'react';
import { testConnection } from '../../api/client';

export default function StepTestConnection( { apiKey, onNext, onBack } ) {
	const [ status, setStatus ] = useState( 'idle' );
	const [ errorMsg, setErrorMsg ] = useState( '' );

	const handleTest = async () => {
		setStatus( 'testing' );
		setErrorMsg( '' );
		try {
			const result = await testConnection( apiKey );
			if ( result.success ) {
				setStatus( 'success' );
			} else {
				setStatus( 'error' );
				setErrorMsg( result.error || 'Connection failed.' );
			}
		} catch ( err ) {
			setStatus( 'error' );
			setErrorMsg( err.message || 'Connection failed.' );
		}
	};

	return (
		<div className="p-8">
			<div className="flex items-center gap-3 mb-2">
				<div className="p-2 bg-blue-50 rounded-lg">
					<svg className="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.14 0M1.394 9.393c5.857-5.858 15.355-5.858 21.213 0" />
					</svg>
				</div>
				<h2 className="text-lg font-semibold text-slate-800">Test Your Connection</h2>
			</div>
			<p className="text-sm text-slate-500 mb-6 ml-11">
				We'll verify your API key works by making a quick test call to OpenRouter.
			</p>

			<div className="flex flex-col items-center py-6">
				{ status === 'idle' && (
					<button
						onClick={ handleTest }
						className="inline-flex items-center gap-2 px-6 py-3 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors"
					>
						<svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M13 10V3L4 14h7v7l9-11h-7z" />
						</svg>
						Test Connection
					</button>
				) }

				{ status === 'testing' && (
					<div className="flex flex-col items-center gap-3">
						<div className="w-10 h-10 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
						<p className="text-sm text-slate-500">Testing connection...</p>
					</div>
				) }

				{ status === 'success' && (
					<div className="flex flex-col items-center gap-3">
						<div className="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
							<svg className="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M5 13l4 4L19 7" />
							</svg>
						</div>
						<div className="text-center">
							<p className="text-sm font-medium text-green-700">Connection successful!</p>
							<p className="text-xs text-slate-500 mt-1">Your API key is valid and ready to use.</p>
						</div>
					</div>
				) }

				{ status === 'error' && (
					<div className="flex flex-col items-center gap-3">
						<div className="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
							<svg className="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M6 18L18 6M6 6l12 12" />
							</svg>
						</div>
						<div className="text-center">
							<p className="text-sm font-medium text-red-700">Connection failed</p>
							<p className="text-xs text-red-500 mt-1 max-w-xs">{ errorMsg }</p>
						</div>
						<button
							onClick={ handleTest }
							className="mt-2 inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors"
						>
							<svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
							</svg>
							Retry
						</button>
					</div>
				) }
			</div>

			<div className="mt-6 flex justify-between">
				<button
					onClick={ onBack }
					className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium text-slate-600 bg-slate-50 rounded-lg hover:bg-slate-100 transition-colors"
				>
					<svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M15 19l-7-7 7-7" />
					</svg>
					Back
				</button>
				{ status === 'success' && (
					<button
						onClick={ onNext }
						className="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors"
					>
						Continue
						<svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M9 5l7 7-7 7" />
						</svg>
					</button>
				) }
			</div>
		</div>
	);
}
