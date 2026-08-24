import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import { Layout } from '../Layout';
import { Loading } from '../Loading';
import { api } from '../../utils/api';
import { ago } from '../../utils/time';
import { SOURCE_STEPS } from '../../steps';

/**
 * Pair with the destination, or fall back to a pasted profile.
 *
 * @param {Object}   props
 * @param {Function} props.onResult Called with the comparison result.
 * @return {Element} The screen.
 */
export const Pair = ( { onResult } ) => {
	const [ url, setUrl ] = useState( '' );
	const [ code, setCode ] = useState( '' );
	const [ blob, setBlob ] = useState( '' );
	const [ manual, setManual ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ saved, setSaved ] = useState( null );
	const [ checked, setChecked ] = useState( false );
	const navigate = useNavigate();

	// Coming back to this screen — through the stepper, or after a reload — must not cost the
	// user another trip to the destination for a code. The pairing is already on the server.
	useEffect( () => {
		let live = true;

		api.destination.get().then( ( found ) => {
			if ( ! live ) {
				return;
			}

			setChecked( true );

			if ( ! found.failed && found.saved ) {
				setSaved( found );
			}
		} );

		return () => {
			live = false;
		};
	}, [] );

	const repair = async () => {
		setSaved( null );
		setError( '' );
		await api.destination.forget();
	};

	const submit = async () => {
		setBusy( true );
		setError( '' );

		const result = manual
			? await api.compare( { profile: blob } )
			: await api.compare( { url, code } );

		setBusy( false );

		if ( result.failed ) {
			setError( result.error );
			return;
		}

		if ( ! result.ok ) {
			setError( result.error );
			// Unreachable is not a dead end: it is exactly when the pasted profile is for.
			if ( result.unreachable ) {
				setManual( true );
			}
			return;
		}

		onResult( result, manual ? { profile: blob } : { url, code } );
		navigate( '/compatibility' );
	};

	const carryOn = () => {
		onResult( saved );
		navigate( '/compatibility' );
	};

	// Already paired: show who with, and let them carry on. The alternative — an empty form
	// asking for a code that expired fifteen minutes ago — is the same screen pretending it has
	// never met them.
	if ( saved ) {
		return (
			<Layout
				steps={ SOURCE_STEPS }
				step="pair"
				eyebrow={ __( 'Source', 'nfd-site-migrator' ) }
				title={ __( 'Destination', 'nfd-site-migrator' ) }
				intro={ __(
					'This site is already paired. Nothing further is needed from the destination unless you want to move to a different one.',
					'nfd-site-migrator'
				) }
			>
				<div className="nfd-sm-card" id="nfd-sm-paired">
					<p className="nfd-sm-eyebrow">
						{ __( 'Paired with', 'nfd-site-migrator' ) }
					</p>
					<p className="nfd-sm-mono">
						{ saved.destination?.site_url || saved.url }
					</p>
					<p className="nfd-sm-hint">
						{ 'pasted' === saved.via
							? sprintf(
									/* translators: 1: how long ago, 2: WordPress version, 3: PHP version. */
									__(
										'From the profile you pasted, %1$s. WordPress %2$s, PHP %3$s.',
										'nfd-site-migrator'
									),
									ago( saved.fetched_at ),
									saved.destination?.wp_version || '?',
									saved.destination?.php || '?'
							  )
							: sprintf(
									/* translators: 1: how long ago, 2: WordPress version, 3: PHP version. */
									__(
										'Read live from the destination %1$s. WordPress %2$s, PHP %3$s.',
										'nfd-site-migrator'
									),
									ago( saved.fetched_at ),
									saved.destination?.wp_version || '?',
									saved.destination?.php || '?'
							  ) }
					</p>
				</div>

				<div className="nfd-sm-actions">
					<button
						type="button"
						className="nfd-sm-btn nfd-sm-btn--primary"
						id="nfd-sm-continue-paired"
						onClick={ carryOn }
					>
						{ __( 'Continue', 'nfd-site-migrator' ) }
					</button>

					<button
						type="button"
						className="nfd-sm-link"
						id="nfd-sm-repair"
						onClick={ repair }
					>
						{ __(
							'Pair with a different destination',
							'nfd-site-migrator'
						) }
					</button>
				</div>
			</Layout>
		);
	}

	return (
		<Layout
			steps={ SOURCE_STEPS }
			step="pair"
			eyebrow={ __( 'Source', 'nfd-site-migrator' ) }
			title={ __( 'Pair with the destination', 'nfd-site-migrator' ) }
			intro={ __(
				'Open Site Migrator on the destination and choose Receive a site. It will show you an address and a pairing code. Paste both here and this site will read the destination’s setup directly.',
				'nfd-site-migrator'
			) }
		>
			{ error && (
				<div className="nfd-sm-note nfd-sm-note--stop">{ error }</div>
			) }

			{ ! checked && (
				<div className="nfd-sm-card">
					<Loading>
						{ __(
							'Looking for a destination you have already paired with…',
							'nfd-site-migrator'
						) }
					</Loading>
				</div>
			) }

			{ checked && ! manual && (
				<div className="nfd-sm-card nfd-sm-form">
					<label htmlFor="nfd-sm-dest-url">
						{ __( 'Destination address', 'nfd-site-migrator' ) }
					</label>
					<input
						id="nfd-sm-dest-url"
						type="url"
						className="nfd-sm-input"
						placeholder="https://newsite.example.com"
						value={ url }
						onChange={ ( e ) => setUrl( e.target.value ) }
					/>

					<label htmlFor="nfd-sm-dest-code">
						{ __( 'Pairing code', 'nfd-site-migrator' ) }
					</label>
					<input
						id="nfd-sm-dest-code"
						type="text"
						className="nfd-sm-input"
						placeholder="A7K2-9F3P-XQ41"
						value={ code }
						onChange={ ( e ) => setCode( e.target.value ) }
					/>
					<p className="nfd-sm-hint">
						{ __(
							'Single use, expires in 15 minutes. It lets this site read the destination’s versions and free space — nothing else.',
							'nfd-site-migrator'
						) }
					</p>
				</div>
			) }

			{ checked && manual && (
				<div className="nfd-sm-card nfd-sm-form">
					<label htmlFor="nfd-sm-profile">
						{ __(
							'Paste the destination’s profile',
							'nfd-site-migrator'
						) }
					</label>
					<textarea
						id="nfd-sm-profile"
						className="nfd-sm-input nfd-sm-textarea"
						rows="5"
						placeholder="NFDSM1-…"
						value={ blob }
						onChange={ ( e ) => setBlob( e.target.value ) }
					/>
					<p className="nfd-sm-hint">
						{ __(
							'Copy it from the destination’s pairing screen. This works when the destination cannot be reached over the network.',
							'nfd-site-migrator'
						) }
					</p>
				</div>
			) }

			<div
				className={ checked ? 'nfd-sm-actions' : 'nfd-sm-hidden-input' }
			>
				<button
					type="button"
					className="nfd-sm-btn nfd-sm-btn--primary"
					id="nfd-sm-check-compatibility"
					disabled={ busy || ( manual ? ! blob : ! url || ! code ) }
					onClick={ submit }
				>
					{ busy
						? __( 'Checking…', 'nfd-site-migrator' )
						: __( 'Check compatibility', 'nfd-site-migrator' ) }
				</button>

				<button
					type="button"
					className="nfd-sm-link"
					onClick={ () => {
						setManual( ! manual );
						setError( '' );
					} }
				>
					{ manual
						? __(
								'Use a pairing code instead',
								'nfd-site-migrator'
						  )
						: __(
								'Destination unreachable? Paste a profile instead',
								'nfd-site-migrator'
						  ) }
				</button>

				<button
					type="button"
					className="nfd-sm-link"
					onClick={ () => {
						onResult( { skipped: true } );
						navigate( '/export' );
					} }
				>
					{ __(
						'No destination yet — export without checking',
						'nfd-site-migrator'
					) }
				</button>
			</div>
		</Layout>
	);
};
