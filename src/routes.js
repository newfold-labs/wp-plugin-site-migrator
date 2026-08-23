import { useEffect, useState } from '@wordpress/element';
import { Navigate, useNavigate, useRoutes } from 'react-router-dom';
import { Start } from './components/screens/Start';
import { Pair } from './components/screens/Pair';
import { Compatibility } from './components/screens/Compatibility';
import { Exporting } from './components/screens/Exporting';
import { Download } from './components/screens/Download';
import { Receive } from './components/screens/Receive';
import { api } from './utils/api';

/**
 * Send a reloaded tab back to whichever screen matches what is actually on disk.
 *
 * Progress lives in a checkpoint file, not in this component, so a refresh mid-export is not a
 * lost export — it just needs the UI pointed at the right place.
 *
 * @return {Element} Nothing; it redirects.
 */
const Resume = () => {
	const navigate = useNavigate();

	useEffect( () => {
		api.exportState().then( ( state ) => {
			if ( state.failed ) {
				navigate( '/start', { replace: true } );
			} else if ( state.complete ) {
				navigate( '/download', { replace: true } );
			} else if ( state.in_progress ) {
				navigate( '/export', { replace: true } );
			} else {
				navigate( '/start', { replace: true } );
			}
		} );
	}, [ navigate ] );

	return null;
};

/**
 * The screen graph.
 *
 * @return {Element} The active screen.
 */
export default function Routes() {
	// The comparison result is deliberately not persisted: a verdict about another server goes
	// stale, and re-checking is one request.
	const [ result, setResult ] = useState( null );
	const [ request, setRequest ] = useState( null );

	const onResult = ( value, submitted ) => {
		setResult( value );
		if ( submitted ) {
			setRequest( submitted );
		}
	};

	return useRoutes( [
		{ path: '/', element: <Resume /> },
		{ path: '/start', element: <Start /> },
		{
			path: '/pair',
			element: (
				<Pair
					onResult={ ( value, submitted ) =>
						onResult( value, submitted )
					}
				/>
			),
		},
		{
			path: '/compatibility',
			element: (
				<Compatibility
					result={ result }
					request={ request }
					onResult={ onResult }
				/>
			),
		},
		{ path: '/export', element: <Exporting /> },
		{ path: '/download', element: <Download /> },
		{ path: '/receive', element: <Receive /> },
		{ path: '*', element: <Navigate to="/" replace /> },
	] );
}
