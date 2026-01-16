/**
 * PhaseStepper Component
 *
 * Horizontal timeline showing pipeline phases with visual states.
 */

/**
 * Check icon SVG.
 */
const CheckIcon = () => (
  <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
    <path
      fillRule="evenodd"
      d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
      clipRule="evenodd"
    />
  </svg>
);

/**
 * Error icon SVG.
 */
const ErrorIcon = () => (
  <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
    <path
      fillRule="evenodd"
      d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
      clipRule="evenodd"
    />
  </svg>
);

/**
 * Default pipeline phases.
 */
const DEFAULT_PHASES = [
  { key: 'queue', label: 'Queue', description: 'Building post queue' },
  { key: 'extract', label: 'Extract', description: 'Extracting entities' },
  { key: 'resolve', label: 'Resolve', description: 'Resolving identities' },
  { key: 'enrich', label: 'Enrich', description: 'Enriching data' },
  { key: 'link', label: 'Link', description: 'Creating links' },
  { key: 'complete', label: 'Complete', description: 'Finalizing' },
];

/**
 * Get the state of a phase.
 *
 * @param {string} phaseKey - The phase key.
 * @param {string} currentPhase - The current active phase.
 * @param {number} currentIndex - Index of current phase.
 * @param {number} phaseIndex - Index of this phase.
 * @param {string} errorPhase - Phase where error occurred.
 * @param {boolean} isRunning - Whether pipeline is running.
 * @returns {string} Phase state: pending, active, complete, or error.
 */
function getPhaseState(phaseKey, currentPhase, currentIndex, phaseIndex, errorPhase, isRunning) {
  if (errorPhase === phaseKey) {
    return 'error';
  }

  if (!isRunning && !currentPhase) {
    return 'pending';
  }

  if (phaseIndex < currentIndex) {
    return 'complete';
  }

  if (phaseKey === currentPhase && isRunning) {
    return 'active';
  }

  return 'pending';
}

/**
 * PhaseStepper component.
 *
 * @param {Object} props - Component props.
 * @param {string} props.currentPhase - Current active phase key.
 * @param {string} props.errorPhase - Phase where error occurred (if any).
 * @param {boolean} props.isRunning - Whether pipeline is running.
 * @param {Array} props.phases - Phase definitions (optional).
 * @param {string} props.className - Additional CSS classes.
 * @returns {JSX.Element} PhaseStepper element.
 */
export default function PhaseStepper({
  currentPhase,
  errorPhase = null,
  isRunning = false,
  phases = DEFAULT_PHASES,
  className = '',
}) {
  const currentIndex = phases.findIndex((p) => p.key === currentPhase);

  return (
    <div className={`w-full ${className}`}>
      <div className="relative flex items-center justify-between">
        {/* Connection line */}
        <div className="absolute top-5 left-0 right-0 h-0.5 bg-slate-200" />

        {/* Completed portion of line */}
        {currentIndex > 0 && (
          <div
            className="absolute top-5 left-0 h-0.5 bg-phase-complete transition-all duration-500"
            style={{
              width: `${((currentIndex) / (phases.length - 1)) * 100}%`,
            }}
          />
        )}

        {/* Phase nodes */}
        {phases.map((phase, index) => {
          const state = getPhaseState(
            phase.key,
            currentPhase,
            currentIndex,
            index,
            errorPhase,
            isRunning
          );

          return (
            <div key={phase.key} className="relative flex flex-col items-center z-10">
              {/* Node circle */}
              <div
                className={`
                  w-10 h-10 rounded-full flex items-center justify-center
                  transition-all duration-300
                  ${state === 'pending' ? 'bg-slate-200 text-slate-400' : ''}
                  ${state === 'active' ? 'bg-phase-active text-white phase-pulse' : ''}
                  ${state === 'complete' ? 'bg-phase-complete text-white' : ''}
                  ${state === 'error' ? 'bg-phase-error text-white' : ''}
                `}
              >
                {state === 'complete' && <CheckIcon />}
                {state === 'error' && <ErrorIcon />}
                {(state === 'pending' || state === 'active') && (
                  <span className="text-sm font-semibold">{index + 1}</span>
                )}
              </div>

              {/* Label */}
              <div className="mt-2 text-center">
                <p
                  className={`
                    text-sm font-medium
                    ${state === 'active' ? 'text-phase-active' : ''}
                    ${state === 'complete' ? 'text-phase-complete' : ''}
                    ${state === 'error' ? 'text-phase-error' : ''}
                    ${state === 'pending' ? 'text-slate-400' : ''}
                  `}
                >
                  {phase.label}
                </p>
                <p className="text-xs text-slate-400 mt-0.5 max-w-[80px]">
                  {phase.description}
                </p>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
