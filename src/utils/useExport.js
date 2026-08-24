import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { api } from './api';

/**
 * Drives the export by calling `step` in a loop.
 *
 * The browser is the scheduler. There is no queue to fall behind and no status option to go
 * stale: the request that reports progress is the request that did the work, so the number on
 * screen cannot describe something that is not happening.
 *
 * That leaves one thing a reloaded tab cannot know — what happened before it existed — which is
 * what `hydrate` is for. Progress is read back from the checkpoint on mount, so the screen draws
 * the run it is joining instead of a row of zeroes until the first step returns.
 *
 * @return {Object} Export state and controls.
 */
export function useExport() {
	const [ state, setState ] = useState( {
		hydrated: false,
		running: false,
		paused: false,
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
		baseBytes: 0,
		error: '',
	} );

	// Survives re-renders without causing them, so the loop can be stopped from outside.
	const stop = useRef( false );

	// Lets Pause abandon the request that is already in flight instead of waiting for it.
	const inflight = useRef( null );

	/**
	 * Copy a server report into the state, whether it came from a step or from the checkpoint.
	 *
	 * @param {Object} report Server report.
	 * @param {Object} s      Previous state.
	 * @return {Object} Next state.
	 */
	const absorb = ( report, s ) => ( {
		...s,
		stage: report.stage || s.stage,
		part: report.part || '',
		files: report.files ?? s.files,
		bytes: report.bytes ?? s.bytes,
		partIndex: report.part_index ?? s.partIndex,
		partCount: report.part_count ?? s.partCount,
		plannedFiles: report.planned_files ?? s.plannedFiles,
		plannedBytes: report.planned_bytes ?? s.plannedBytes,
	} );

	// What the export already did, before this tab asked it to do any more.
	const hydrate = useCallback( async () => {
		const snapshot = await api.exportState();

		if ( snapshot.failed ) {
			setState( ( s ) => ( { ...s, hydrated: true } ) );
			return snapshot;
		}

		setState( ( s ) => ( {
			...absorb( snapshot, s ),
			hydrated: true,
			paused: !! snapshot.paused,
			done: !! snapshot.complete,
		} ) );

		return snapshot;
	}, [] );

	const loop = useCallback( async () => {
		stop.current = false;

		// Resuming is as much an instruction as pausing was, and the server is holding the
		// answer for the next tab that asks.
		api.exportPause( false );

		setState( ( s ) => ( {
			...s,
			running: true,
			paused: false,
			error: '',
			// Rate is measured over this run only. Counting bytes a previous run wrote against
			// the seconds this one has been going would put the estimate out by however long
			// the export was paused for.
			startedAt: Date.now(),
			baseBytes: s.bytes,
		} ) );

		for (;;) {
			if ( stop.current ) {
				setState( ( s ) => ( { ...s, running: false } ) );
				return;
			}

			const controller = new AbortController();
			inflight.current = controller;

			// eslint-disable-next-line no-await-in-loop
			const step = await api.exportStep( controller.signal );

			inflight.current = null;

			// An aborted request is not a failure; it is what Pause does.
			if ( stop.current ) {
				setState( ( s ) => ( { ...s, running: false } ) );
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
				...absorb( step, s ),
				running: ! step.done,
				done: !! step.done,
			} ) );

			if ( step.done ) {
				return;
			}
		}
	}, [] );

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

		// Recorded on the server, because a pause the user has to repeat after every reload is
		// not a pause.
		api.exportPause( true );

		setState( ( s ) => ( { ...s, running: false, paused: true } ) );
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

	return { ...state, hydrate, start: loop, pause };
}
