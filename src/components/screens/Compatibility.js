import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { Navigate, useNavigate } from 'react-router-dom';
import { Layout } from '../Layout';
import { Loading } from '../Loading';
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
			'Compared against what %1$s reported %2$s. Checking again re-tests this site against that reading and confirms the destination is still answering; re-reading its facts needs a new pairing code.',
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
	const [ unreachable, setUnreachable ] = useState( '' );
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
					<Loading>
						{ __(
							'Comparing this site with the destination…',
							'nfd-site-migrator'
						) }
					</Loading>
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
		setUnreachable( '' );

		if ( canReread ) {
			const fresh = await api.compare( request );

			// An unreachable destination is the answer to the question, not a reason to go
			// looking for a different one. The fallback below is for a code that has expired;
			// taking it on *every* failure is what made a site that had gone off the air
			// produce a clean verdict, recomputed from facts nobody had been able to re-read.
			if ( ! fresh.failed && fresh.unreachable ) {
				setUnreachable( fresh.error );
				setBusy( false );
				return;
			}

			if ( ! fresh.failed && fresh.ok ) {
				setBusy( false );
				onResult( fresh, request );
				return;
			}
		}

		// Either there was no code to re-read with, or the one we had is past its fifteen
		// minutes. Re-test this site against the reading we already have — which is what
		// somebody who has just fixed something *here* is asking for — and, separately, ask
		// whether the other site is still answering. Without that second question this button
		// cannot fail, and a button that cannot fail is not a check.
		const [ stored, reach ] = await Promise.all( [
			api.destination.get(),
			api.destination.reach(),
		] );

		setBusy( false );

		if ( ! reach.failed && reach.saved && ! reach.reachable ) {
			setUnreachable( reach.error );
		}

		if ( ! stored.failed && stored.ok ) {
			onResult( stored, stored.saved ? undefined : request );
		}
	};

	return (
		<Layout
			steps={ SOURCE_STEPS }
			step="compatibility"
			eyebrow={ __( 'Source', 'nfd-site-migrator' ) }
			title={ __( 'Compatibility', 'nfd-site-migrator' ) }
			// The verdict belongs beside the heading rather than in a banner under it: it is
			// one word about the whole screen, and the list below is the evidence for it.
			badge={
				<span
					className={ `nfd-sm-verdict nfd-sm-verdict--${
						blocked ? 'block' : 'pass'
					}` }
				>
					{ blocked
						? __( 'Cannot migrate yet', 'nfd-site-migrator' )
						: __( 'Ready to migrate', 'nfd-site-migrator' ) }
				</span>
			}
			intro={ introFor( result, canReread ) }
		>
			{ unreachable && (
				<div className="nfd-sm-note nfd-sm-note--stop">
					<strong>
						{ __(
							'Could not reach the destination',
							'nfd-site-migrator'
						) }
					</strong>
					<p>{ unreachable }</p>
					<p>
						{ __(
							'Everything below is still what the destination reported when it was last read, not a fresh answer. It can be right and the site still be unavailable.',
							'nfd-site-migrator'
						) }
					</p>
					<p>
						{ __(
							'You can carry on and build the package — a package can be downloaded from here and uploaded there by hand. It is the direct transfer that needs this site to be able to open a connection to that one.',
							'nfd-site-migrator'
						) }
					</p>
				</div>
			) }

			<Gates report={ report } showPass={ true } summary={ true } />

			<div className="nfd-sm-actions">
				<button
					type="button"
					className="nfd-sm-btn nfd-sm-btn--primary"
					id="nfd-sm-build-package"
					disabled={ blocked }
					onClick={ () => navigate( '/export' ) }
				>
					{ __( 'Build the package', 'nfd-site-migrator' ) }
				</button>

				<button
					type="button"
					className="nfd-sm-btn"
					id="nfd-sm-choose-contents"
					onClick={ () => navigate( '/contents' ) }
				>
					{ __( 'Choose what to include', 'nfd-site-migrator' ) }
				</button>

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
			</div>
		</Layout>
	);
};
