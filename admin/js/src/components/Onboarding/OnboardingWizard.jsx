import { useState } from 'react';
import StepApiKey from './StepApiKey';
import StepTestConnection from './StepTestConnection';
import StepFirstScan from './StepFirstScan';
import StepComplete from './StepComplete';

const STEPS = [
	{ label: 'API Key', description: 'Enter your OpenRouter key' },
	{ label: 'Test', description: 'Verify the connection' },
	{ label: 'First Scan', description: 'Run initial extraction' },
	{ label: 'Complete', description: 'You\'re all set!' },
];

export default function OnboardingWizard( { onComplete } ) {
	const [ currentStep, setCurrentStep ] = useState( 0 );
	const [ apiKey, setApiKey ] = useState( '' );

	const goNext = () => setCurrentStep( ( s ) => Math.min( s + 1, STEPS.length - 1 ) );
	const goBack = () => setCurrentStep( ( s ) => Math.max( s - 1, 0 ) );

	const stepComponents = [
		<StepApiKey
			key="api-key"
			apiKey={ apiKey }
			onApiKeyChange={ setApiKey }
			onNext={ goNext }
		/>,
		<StepTestConnection
			key="test"
			apiKey={ apiKey }
			onNext={ goNext }
			onBack={ goBack }
		/>,
		<StepFirstScan
			key="scan"
			onNext={ goNext }
			onBack={ goBack }
		/>,
		<StepComplete
			key="complete"
			onComplete={ onComplete }
		/>,
	];

	return (
		<div className="min-h-[80vh] flex items-center justify-center p-6">
			<div className="w-full max-w-xl">
				<div className="text-center mb-8">
					<div className="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-blue-600 mb-4">
						<svg className="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={ 2 } d="M13 10V3L4 14h7v7l9-11h-7z" />
						</svg>
					</div>
					<h1 className="text-2xl font-bold text-slate-800">
						Welcome to AI Entity Index
					</h1>
					<p className="text-slate-500 mt-2">
						Let's get you set up in 4 quick steps
					</p>
				</div>

				<div className="flex items-center justify-center gap-1 mb-8">
					{ STEPS.map( ( step, i ) => (
						<div key={ i } className="flex items-center">
							<div
								className={ `flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium transition-colors ${
									i < currentStep
										? 'bg-blue-100 text-blue-700'
										: i === currentStep
										? 'bg-blue-600 text-white'
										: 'bg-slate-100 text-slate-400'
								}` }
							>
								{ i < currentStep ? (
									<svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
										<path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
									</svg>
								) : (
									<span>{ i + 1 }</span>
								) }
								<span className="hidden sm:inline">{ step.label }</span>
							</div>
							{ i < STEPS.length - 1 && (
								<div className={ `w-6 h-0.5 mx-1 rounded ${ i < currentStep ? 'bg-blue-300' : 'bg-slate-200' }` } />
							) }
						</div>
					) ) }
				</div>

				<div className="bg-white rounded-xl border border-slate-200 shadow-sm">
					{ stepComponents[ currentStep ] }
				</div>
			</div>
		</div>
	);
}
