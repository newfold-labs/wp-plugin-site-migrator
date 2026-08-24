import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { Navigate, useNavigate } from 'react-router-dom';
import { Layout } from '../Layout';
import { Gates } from '../Gate';
import { api } from '../../utils/api';
import { ago } from '../../utils/time';
import { SOURCE_STEPS } from '../../steps';

/**
 * Say where these facts came from, and how old they are.
 *
 * @param {Object}  result The comparison result.
 * @param {boolean} live   Whether the destination can still be re-read without a new code.
 * @return {string} A sentence for under the heading.
 */
const introFor = ( result, live ) => {
	if ( 'pasted' === result.via ) {
		return __(
			'Read from the profile you pasted. Generate a fresh one on the destination if anything below is out of date.',
			'nfd-site-migrator'
		);
	}

	if ( live ) {
		return sprintf(
			/* translators: %s: destination site URL. */
			__(
				'Read live from %s a moment ago. Fix anything blocking and check again — no need to go back and forth.',
				'nfd-site-migrator'
			),
			result.destination?.site_url || ''
		);
	}

	return sprintf(
		/* translators: 1: destination site URL, 2: how long ago it was read. */
		__(
			'Compared against what %1$s reported %2$s. Checking again re-tests this site against that reading; reading the destination again needs a new pairing code.',
			'nfd-site-migrator'
		),
		result.destination?.site_url || '',
		ago( result.fetched_at )
	);
};

/**
 * The verdict, with a re-check that does not require leaving the page.
 *
 * Most blocked gates are fixable in a minute — update core, free some disk. Being able to fix
 * and re-check in place is the main practical reason the profile is fetched live rather than
 * pasted.
 *
 * The verdict itself is never stored, only the destination's facts. It is a statement about two
 * sites and one of them is this one, which the user has quite possibly just changed in order to
 * fix whatever was blocking; a remembered answer would be describing the site as it was.
 *
 * @param {Object}   props
 * @param {Object}   props.result   The comparison result.
 * @param {Function} props.onResult Called with a fresh result after re-checking.
 * @param {Object}   props.request  What was submitted, so it can be repeated.
 * @return {Element} The screen.
 */
export const Compatibility = ( { result, onResult, request } ) => {
	const [ busy, setBusy ] = useState( false );
	const [ looked, setLooked ] = useState( false );
	const navigate = useNavigate();

	// `onResult` is rebuilt on every render of the router, so the effect below cannot rely on
	// its dependencies to run once.
	const asked = useRef( false );

	// A reload, or arriving here from the stepper, loses the result but not the pairing. Ask
	// the server to compare again rather than sending the user back for another code.
	useEffect( () => {
		if ( result || asked.current ) {
			return undefined;
		}

		asked.current = true;
		let mounted = true;

		api.destination.get().then( ( found ) => {
			if ( ! mounted ) {
				return;
			}

			setLooked( true );

			if ( ! found.failed && found.saved ) {
				onResult( found );
			}
		} );

		return () => {
			mounted = false;
		};
	}, [ result, onResult ] );

	if ( ! result ) {
		return looked ? (
			<Navigate to="/pair" replace />
		) : (
			<Layout
				steps={ SOURCE_STEPS }
				step="compatibility"
				eyebrow={ __( 'Source', 'nfd-site-migrator' ) }
				title={ __( 'Compatibility', 'nfd-site-migrator' ) }
			>
				<div className="nfd-sm-card">
					<p className="nfd-sm-hint">
						{ __( 'Checking…', 'nfd-site-migrator' ) }
					</p>
				</div>
			</Layout>
		);
	}

	const report = result.report;
	const blocked = ! report?.ok;

	// With the code still in hand, re-checking re-reads the destination. Without it — a reload,
	// or a step back and forward — it re-tests this site against the reading we already have,
	// which is what a user fixing something *here* actually wants. Reading the other site again
	// needs a new code, and the line under the heading says so rather than implying more.
	const canReread = !! request && ! result.saved;

	const recheck = async () => {
		setBusy( true );

		let fresh = canReread
			? await api.compare( request )
			: await api.destination.get();

		// The code lives fifteen minutes and this screen can outlive it. Falling back to the
		// reading we already have beats telling somebody their code is wrong when the only
		// thing that changed is the clock — and `saved` on the result is what then makes the
		// line under the heading stop promising a live read.
		if ( fresh.failed || ! fresh.ok ) {
			fresh = await api.destination.get();
		}

		setBusy( false );

		if ( ! fresh.failed && fresh.ok ) {
			onResult( fresh, fresh.saved ? undefined : request );
		}
	};

	return (
		<Layout
			steps={ SOURCE_STEPS }
			step="compatibility"
			eyebrow={ __( 'Source', 'nfd-site-migrator' ) }
			title={ __( 'Compatibility', 'nfd-site-migrator' ) }
			intro={ introFor( result, canReread ) }
		>
			<div
				className={ `nfd-sm-note nfd-sm-note--${
					blocked ? 'stop' : 'pass'
				}` }
			>
				{ blocked
					? __(
							'This migration cannot go ahead yet.',
							'nfd-site-migrator'
					  )
					: __( 'Ready to migrate.', 'nfd-site-migrator' ) }
			</div>

			<Gates report={ report } showPass={ true } />

			<div className="nfd-sm-actions">
				<button
					type="button"
					className="nfd-sm-btn"
					disabled={ busy }
					onClick={ recheck }
				>
					{ busy
						? __( 'Checking…', 'nfd-site-migrator' )
						: __( 'Check again', 'nfd-site-migrator' ) }
				</button>

				<button
					type="button"
					className="nfd-sm-btn nfd-sm-btn--primary"
					id="nfd-sm-build-package"
					disabled={ blocked }
					onClick={ () => navigate( '/export' ) }
				>
					{ __( 'Build the package', 'nfd-site-migrator' ) }
				</button>
			</div>
		</Layout>
	);
};
