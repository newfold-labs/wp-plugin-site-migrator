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
		error: '',
	} );

	// Survives re-renders without causing them, so the loop can be stopped from outside.
	const stop = useRef( false );

	const loop = useCallback( async () => {
		stop.current = false;
		setState( ( s ) => ( { ...s, running: true, error: '' } ) );

		for (;;) {
			if ( stop.current ) {
				setState( ( s ) => ( { ...s, running: false } ) );
				return;
			}

			// eslint-disable-next-line no-await-in-loop
			const step = await api.exportStep();

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

	const pause = useCallback( () => {
		stop.current = true;
	}, [] );

	// A closed tab must not leave a loop believing it is still in charge.
	useEffect(
		() => () => {
			stop.current = true;
		},
		[]
	);

	return { ...state, start: loop, pause };
}
