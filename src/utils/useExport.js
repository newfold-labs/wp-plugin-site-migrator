import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { api } from './api';

/**
 * Drives the export by calling `step` in a loop.
 *
 * The browser is the scheduler. There is no queue to fall behind and no status option to go
 * stale: the request that reports progress is the request that did the work, so the number on
 * screen cannot describe something that is not happening.
 *
 * @return {Object} Export state and controls.
 */
export function useExport() {
	const [ state, setState ] = useState( {
		running: false,
		done: false,
		stage: '',
		part: '',
		files: 0,
		bytes: 0,
		partIndex: 0,
		partCount: 0,
		plannedFiles: 0,
		plannedBytes: 0,
		startedAt: 0,
		pausing: false,
		error: '',
	} );

	// Survives re-renders without causing them, so the loop can be stopped from outside.
	const stop = useRef( false );

	// Lets Pause abandon the request that is already in flight instead of waiting for it.
	const inflight = useRef( null );

	const loop = useCallback( async () => {
		stop.current = false;
		setState( ( s ) => ( {
			...s,
			running: true,
			pausing: false,
			error: '',
		} ) );

		for (;;) {
			if ( stop.current ) {
				setState( ( s ) => ( {
					...s,
					running: false,
					pausing: false,
				} ) );
				return;
			}

			const controller = new AbortController();
			inflight.current = controller;

			// eslint-disable-next-line no-await-in-loop
			const step = await api.exportStep( controller.signal );

			inflight.current = null;

			// An aborted request is not a failure; it is what Pause does.
			if ( stop.current ) {
				setState( ( s ) => ( {
					...s,
					running: false,
					pausing: false,
				} ) );
				return;
			}

			// The previous step is still finishing on the server. Wait for it rather than
			// starting a second one on the same archive.
			if ( 'busy' === step.status ) {
				// eslint-disable-next-line no-await-in-loop
				await new Promise( ( resolve ) => setTimeout( resolve, 2000 ) );
				// eslint-disable-next-line no-continue
				continue;
			}

			if ( step.failed || step.error ) {
				setState( ( s ) => ( {
					...s,
					running: false,
					error: step.error || 'The export could not continue.',
				} ) );
				return;
			}

			setState( ( s ) => ( {
				...s,
				running: ! step.done,
				done: !! step.done,
				stage: step.stage || s.stage,
				part: step.part || '',
				files: step.files ?? s.files,
				bytes: step.bytes ?? s.bytes,
				partIndex: step.part_index ?? s.partIndex,
				partCount: step.part_count ?? s.partCount,
				plannedFiles: step.planned_files ?? s.plannedFiles,
				plannedBytes: step.planned_bytes ?? s.plannedBytes,
				startedAt: s.startedAt || Date.now(),
			} ) );

			if ( step.done ) {
				return;
			}
		}
	}, [] );

	// The loop can only stop between steps, and a step is already in flight when this is
	// clicked. Saying so is the difference between a button that looks broken and one that is
	// simply waiting — the server-side work is bounded to a few seconds precisely so that wait
	// is short.
	// Stops now, not at the end of the current step. A step cannot be interrupted once it is
	// inside a zip close, and waiting for one on slow storage is the two minutes that made this
	// button look broken. The request is abandoned instead: the server finishes it and writes
	// its checkpoint regardless, so nothing is lost, and a lock stops a quick Resume from
	// starting a second step alongside it.
	const pause = useCallback( () => {
		stop.current = true;

		if ( inflight.current ) {
			inflight.current.abort();
			inflight.current = null;
		}

		setState( ( s ) => ( { ...s, running: false, pausing: false } ) );
	}, [] );

	// A closed tab must not leave a loop believing it is still in charge.
	useEffect(
		() => () => {
			stop.current = true;

			if ( inflight.current ) {
				inflight.current.abort();
			}
		},
		[]
	);

	return { ...state, start: loop, pause };
}
