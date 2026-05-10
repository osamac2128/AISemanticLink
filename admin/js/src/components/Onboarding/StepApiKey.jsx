import { useState } from 'react';

export default function StepApiKey( { apiKey, onApiKeyChange, onNext } ) {
	const [ showKey, setShowKey ] = useState( false );
	const [ error, setError ] = useState( '' );

	const handleNext = () => {
		const trimmed = apiKey.trim();
		if ( ! trimmed ) {
			setError( 'Please enter your OpenRouter API key.' );
			return;
		}
		if ( trimmed.length < 20 ) {
			setError( 'That doesn\'t look like a valid API key. It should be longer.' );
			return;
		}
		setError( '' );
		onApiKeyChange( trimmed );
		onNext();
	};

	const handleKeyDown = ( e ) => {
		if ( e.key === 'Enter' ) {
			handleNext();
		}
	};

	return (
		<div className="p-8">
			<div className="flex items-center gap-3 mb-2">
				<div className="p-2 bg-blue-50 rounded-lg">
					<svg className="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
					</svg>
				</div>
				<h2 className="text-lg font-semibold text-slate-800">Enter Your API Key</h2>
			</div>
			<p className="text-sm text-slate-500 mb-6 ml-11">
				You'll need an OpenRouter API key to power AI entity extraction.
				{ ' ' }
				<a
					href="https://openrouter.ai/keys"
					target="_blank"
					rel="noopener noreferrer"
					className="text-blue-600 hover:text-blue-700 underline"
				>
					Get one free →
				</a>
			</p>

			<div className="space-y-3">
				<div className="relative">
					<input
						type={ showKey ? 'text' : 'password' }
						value={ apiKey }
						onChange={ ( e ) => {
							onApiKeyChange( e.target.value );
							setError( '' );
						} }
						onKeyDown={ handleKeyDown }
						placeholder="sk-or-v1-..."
						className="w-full px-4 py-3 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent pr-12"
						autoFocus
					/>
					<button
						type="button"
						onClick={ () => setShowKey( ! showKey ) }
						className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
						tabIndex={ -1 }
					>
						{ showKey ? (
							<svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
							</svg>
						) : (
							<svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
							</svg>
						) }
					</button>
				</div>

				{ error && (
					<p className="text-sm text-red-600 flex items-center gap-1">
						<svg className="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
							<path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
						</svg>
						{ error }
					</p>
				) }
			</div>

			<div className="mt-6 flex justify-end">
				<button
					onClick={ handleNext }
					className="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
				>
					Save & Continue
					<svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M9 5l7 7-7 7" />
					</svg>
				</button>
			</div>
		</div>
	);
}
