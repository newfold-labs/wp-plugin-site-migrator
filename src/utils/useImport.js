import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { api, importToken, updateNonce } from './api';

/**
 * Human labels for the stages, in the order they run.
 *
 * The swap is named rather than folded into "importing", because it is the one moment the
 * destination actually changes and the person watching deserves to know when it passes.
 */
export const STAGES = [
	{ id: 'precheck', label: 'Checking this server', safe: true },
	{ id: 'files', label: 'Restoring files', safe: true },
	{
		id: 'database',
		label: 'Loading the database alongside the live one',
		safe: true,
	},
	{ id: 'transform', label: 'Rewriting addresses', safe: true },
	{ id: 'users', label: 'Reconciling accounts', safe: true },
	{ id: 'validate', label: 'Checking what arrived', safe: true },
	{ id: 'swap', label: 'Switching over', safe: false },
	{ id: 'fixups', label: 'Tidying up', safe: false },
	{ id: 'done', label: 'Done', safe: false },
];

/**
 * Drive an import by calling `step` in a loop.
 *
 * The same shape as the export loop, with one addition that matters: `swapped` latches. Once
 * the rename has run the site has genuinely changed, and no later render is allowed to go back
 * to telling the user it has not.
 *
 * @param {string} dir Package directory, or '' for the uploaded one.
 * @return {Object} Loop state and controls.
 */
export function useImport( dir ) {
	const [ state, setState ] = useState( null );
	const [ running, setRunning ] = useState( false );
	const [ swapped, setSwapped ] = useState( false );
	const [ error, setError ] = useState( '' );
	const stop = useRef( false );

	useEffect( () => {
		return () => {
			stop.current = true;
		};
	}, [] );

	const run = useCallback( async () => {
		setRunning( true );
		setError( '' );
		stop.current = false;

		let latest = null;

		do {
			// eslint-disable-next-line no-await-in-loop
			latest = await api.import.step( dir );

			if ( stop.current ) {
				setRunning( false );
				return;
			}

			if ( latest.failed ) {
				setError( latest.error );
				setRunning( false );
				return;
			}

			// The swap invalidates the nonce this page was rendered with. Adopting the fresh
			// one keeps the rest of wp-admin usable afterwards, not just these endpoints.
			updateNonce( latest.nonce );

			setState( latest );

			if ( latest.swapped ) {
				setSwapped( true );
			}

			if ( latest.error ) {
				setError( latest.error );
				setRunning( false );
				return;
			}
		} while ( ! latest.done );

		// The window is over the moment the run is: nothing else should be able to use the
		// token, and the browser has no further need of it.
		importToken.clear();
		setRunning( false );
	}, [ dir ] );

	return { state, running, swapped, error, run, stop };
}
