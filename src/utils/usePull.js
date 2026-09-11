import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { api } from './api';

/**
 * Drives a direct transfer by calling `pull/step` in a loop.
 *
 * The same shape as `useExport`, and for the same reason: the request that reports progress is
 * the request that fetched the bytes, so the number on screen cannot describe something that is
 * not happening. What differs is where the truth lives. The export keeps a checkpoint; a pull
 * keeps nothing — the server answers "how far along is this" by measuring the files on disk. So
 * a reloaded tab, a second tab, and a browser on another machine all see the same transfer, and
 * `hydrate` is a plain read rather than a recovery.
 *
 * @return {Object} Transfer state and controls.
 */
export function usePull() {
	const [ state, setState ] = useState( {
		hydrated: false,
		connected: false,
		running: false,
		done: false,
		source: '',
		filesDone: 0,
		filesTotal: 0,
		bytesDone: 0,
		bytesTotal: 0,
		current: '',
		notes: [],
		error: '',
		// Measured across this tab's own steps, so the estimate describes the link actually in
		// use rather than an average over a transfer that was paused overnight.
		startedAt: 0,
		baseBytes: 0,
	} );

	const stop = useRef( false );
	const inflight = useRef( null );

	/**
	 * Copy a server report into the state.
	 *
	 * @param {Object} report Server report.
	 * @param {Object} s      Previous state.
	 * @return {Object} Next state.
	 */
	const absorb = ( report, s ) => ( {
		...s,
		hydrated: true,
		connected: report.connected ?? s.connected,
		done: !! report.done,
		source: report.source || s.source,
		filesDone: report.files_done ?? s.filesDone,
		filesTotal: report.files_total ?? s.filesTotal,
		bytesDone: report.bytes_done ?? s.bytesDone,
		bytesTotal: report.bytes_total ?? s.bytesTotal,
		current: report.current ?? '',
		// Notes are things the transfer had to say about itself — a part refetched, a stale
		// staging directory cleared. They accumulate rather than replace, because a step that
		// has nothing to report should not erase what the last one said.
		notes: report.notes?.length
			? [ ...s.notes, ...report.notes ].slice( -6 )
			: s.notes,
		error: report.error || '',
	} );

	const hydrate = useCallback( async () => {
		const report = await api.import.pull.state();

		setState( ( s ) =>
			report.failed
				? { ...s, hydrated: true, error: report.error }
				: absorb( report, s )
		);

		return report;
	}, [] );

	useEffect( () => {
		hydrate();

		return () => {
			stop.current = true;
			inflight.current?.abort();
		};
	}, [ hydrate ] );

	/**
	 * Fetch until the package is here, the user stops, or something goes wrong.
	 */
	const run = useCallback( async () => {
		stop.current = false;

		setState( ( s ) => ( {
			...s,
			running: true,
			error: '',
			startedAt: s.startedAt || Date.now(),
			baseBytes: s.startedAt ? s.baseBytes : s.bytesDone,
		} ) );

		for (;;) {
			const controller = new AbortController();
			inflight.current = controller;

			// eslint-disable-next-line no-await-in-loop
			const report = await api.import.pull.step( controller.signal );

			inflight.current = null;

			if ( stop.current ) {
				setState( ( s ) => ( { ...s, running: false } ) );
				return;
			}

			if ( report.failed ) {
				setState( ( s ) => ( {
					...s,
					running: false,
					error: report.error,
				} ) );
				return;
			}

			setState( ( s ) => absorb( report, s ) );

			if ( report.error ) {
				setState( ( s ) => ( { ...s, running: false } ) );
				return;
			}

			if ( report.done ) {
				setState( ( s ) => ( { ...s, running: false, done: true } ) );
				return;
			}
		}
	}, [] );

	/**
	 * Stop after the request in flight comes back.
	 *
	 * Unlike the export's pause this does not abandon it: a range request is seconds, not the
	 * two minutes a zip volume takes to close, so waiting costs nothing and abandoning would
	 * throw away bytes already on the wire.
	 */
	const halt = useCallback( () => {
		stop.current = true;
		setState( ( s ) => ( { ...s, running: false } ) );
	}, [] );

	/**
	 * Point this site at a source.
	 *
	 * @param {string} url Source site URL.
	 * @param {string} key Transfer key.
	 * @return {Promise<Object>} The server's answer.
	 */
	const connect = useCallback( async ( url, key ) => {
		const response = await api.import.pull.connect( url, key );

		if ( ! response.failed ) {
			setState( ( s ) =>
				absorb( response, {
					...s,
					notes: [],
					startedAt: 0,
					baseBytes: 0,
				} )
			);
		}

		return response;
	}, [] );

	/**
	 * Take up the offer the paired source is making.
	 *
	 * The same absorb as `connect`, deliberately: the server claims the key and connects in one
	 * request, so what comes back is the same snapshot, and a screen that called the route
	 * directly would start stepping against a state that still said "not connected".
	 *
	 * @return {Promise<Object>} The server's answer.
	 */
	const claim = useCallback( async () => {
		const response = await api.import.pull.link();

		if ( ! response.failed ) {
			setState( ( s ) =>
				absorb( response, {
					...s,
					notes: [],
					startedAt: 0,
					baseBytes: 0,
				} )
			);
		}

		return response;
	}, [] );

	/**
	 * Forget the source, keeping what has already arrived.
	 */
	const disconnect = useCallback( async () => {
		stop.current = true;

		const response = await api.import.pull.stop();

		setState( ( s ) =>
			response.failed
				? { ...s, error: response.error }
				: absorb( response, { ...s, running: false, notes: [] } )
		);
	}, [] );

	return { state, connect, claim, disconnect, run, halt, hydrate };
}
