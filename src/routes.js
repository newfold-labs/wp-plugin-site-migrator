import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Navigate, useNavigate, useRoutes } from 'react-router-dom';
import { Start } from './components/screens/Start';
import { Pair } from './components/screens/Pair';
import { Compatibility } from './components/screens/Compatibility';
import { Exporting } from './components/screens/Exporting';
import { Download } from './components/screens/Download';
import { Receive } from './components/screens/Receive';
import { Choose } from './components/screens/import/Choose';
import { Review } from './components/screens/import/Review';
import { Running } from './components/screens/import/Running';
import { Done } from './components/screens/import/Done';
import { Layout } from './components/Layout';
import { Loading } from './components/Loading';
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
		// An import in flight outranks anything on the export side: this site may already have
		// been replaced, and dropping the user on the export screen would hide that.
		api.import.state().then( ( imported ) => {
			// A finished import that has been kept or undone is over: the decision screen has
			// nothing left to ask, and landing there on every visit is what makes starting
			// again feel impossible. It is still reachable, and still renders correctly, for
			// anyone who follows a link to it.
			const undecided =
				imported.complete &&
				! imported.rolled_back &&
				! imported.confirmed;

			if ( ! imported.failed && undecided ) {
				navigate( '/import/done', { replace: true } );
				return;
			}

			if ( ! imported.failed && imported.in_progress ) {
				navigate( '/import/run', { replace: true } );
				return;
			}

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
		} );
	}, [ navigate ] );

	// Two requests decide this, and until they answer the page has nothing on it at all. It is
	// the first thing anybody sees on every load, so it says so rather than showing a blank
	// admin page that looks like the plugin failed to start.
	return (
		<Layout title={ __( 'Site Migrator', 'nfd-site-migrator' ) }>
			<div className="nfd-sm-card">
				<Loading>
					{ __(
						'Picking up where you left off…',
						'nfd-site-migrator'
					) }
				</Loading>
			</div>
		</Layout>
	);
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
		{ path: '/import', element: <Choose /> },
		{ path: '/import/review', element: <Review /> },
		{ path: '/import/run', element: <Running /> },
		{ path: '/import/done', element: <Done /> },
		{ path: '*', element: <Navigate to="/" replace /> },
	] );
}
