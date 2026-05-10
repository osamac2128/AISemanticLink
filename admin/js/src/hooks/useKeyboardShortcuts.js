import { useEffect, useRef } from 'react';

/**
 * Hook for registering keyboard shortcuts.
 *
 * @param {Object} handlers - Map of key combos to handler functions.
 *   Key combos: 'ctrl+k', 'ctrl+n', 'ctrl+s', 'escape', etc.
 */
export function useKeyboardShortcuts( handlers = {} ) {
	const handlersRef = useRef( handlers );
	handlersRef.current = handlers;

	useEffect( () => {
		const handleKeyDown = ( e ) => {
			const tag = e.target.tagName;
			if ( tag === 'INPUT' || tag === 'TEXTAREA' || e.target.isContentEditable ) {
				return;
			}

			const key = [];
			if ( e.ctrlKey || e.metaKey ) key.push( 'ctrl' );
			if ( e.shiftKey ) key.push( 'shift' );
			if ( e.altKey ) key.push( 'alt' );
			key.push( e.key.toLowerCase() );
			const combo = key.join( '+' );

			const handler = handlersRef.current[ combo ];
			if ( handler ) {
				e.preventDefault();
				e.stopPropagation();
				handler();
			}
		};

		window.addEventListener( 'keydown', handleKeyDown );
		return () => window.removeEventListener( 'keydown', handleKeyDown );
	}, [] );
}
