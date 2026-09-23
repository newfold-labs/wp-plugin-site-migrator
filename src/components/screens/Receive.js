import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import { Layout } from '../Layout';
import { api } from '../../utils/api';
import { copyText, copyLabel } from '../../utils/clipboard';
import { DESTINATION_STEPS } from '../../steps';

/**
 * Bytes, roughly, for a sentence rather than a table.
 *
 * @param {number} bytes Byte count.
 * @return {string} A short phrase.
 */
const size = ( bytes ) => {
	if ( bytes >= 1073741824 ) {
		return `${ ( bytes / 1073741824 ).toFixed( 1 ) } GB`;
	}
	if ( bytes >= 1048576 ) {
		return `${ ( bytes / 1048576 ).toFixed( 1 ) } MB`;
	}
	return `${ Math.ceil( bytes / 1024 ) } KB`;
};

/**
 * The destination side of the handshake: mint a code for the source to use, then wait here.
 *
 * The code is minted here, on the site that would be overwritten. That is what stops a site
 * being targeted without someone having stood on it and asked — the source has nothing to
 * authenticate with otherwise.
 *
 * And it is one code, for the whole migration. Pairing leaves a token behind on this site, so
 * when the source presses *Offer* there is nothing further to carry — but until now nothing here
 * said so: the screen handed over a code and then sat still, while the package that arrived for it
 * announced itself on a different screen the user had no reason to open. So this one asks, every
 * few seconds, whether a package is being offered, and turns into the button that starts it. The
 * key and the paste-a-key form are still there for a destination that was never paired; they are
 * simply not what anybody is told to do first.
 *
 * @return {Element} The screen.
 */
export const Receive = () => {
	const navigate = useNavigate();
	const [ code, setCode ] = useState( '' );
	const [ siteUrl, setSiteUrl ] = useState( '' );
	const [ status, setStatus ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ waiting, setWaiting ] = useState( null );
	const [ copied, setCopied ] = useState( '' );
	const [ profile, setProfile ] = useState( '' );

	// The same helper the source's Deliver screen uses. This screen's whole job is handing two
	// strings to another machine, and it drew them as text somebody had to select by hand.
	//
	// A failure is reported rather than swallowed. `copyText` already falls back to the only
	// thing that works on an insecure origin, so getting `false` back means the browser refused
	// both ways — and a button that quietly does nothing reads as "copied" and pastes the wrong
	// thing somewhere else.
	const copy = async ( value, what ) => {
		const done = await copyText( value );

		setCopied( done ? what : `failed:${ what }` );
		window.setTimeout( () => setCopied( '' ), 2500 );
	};

	const refresh = () =>
		api.pairing.status().then( ( s ) => ! s.failed && setStatus( s ) );

	useEffect( () => {
		refresh();
	}, [] );

	// Polled, because this is a waiting room: the user is here precisely while somebody on the
	// other site packages a site, which takes as long as it takes. The call costs the source one
	// question over the link it already holds, and it answers the same `offered: false` until an
	// administrator there presses Offer.
	useEffect( () => {
		let live = true;

		const look = () => {
			api.import.pull.offer().then( ( response ) => {
				if ( live && ! response.failed && response.offered ) {
					setWaiting( response );
				}
			} );
		};

		look();

		const timer = window.setInterval( look, 5000 );

		return () => {
			live = false;
			window.clearInterval( timer );
		};
	}, [] );

	// Folded away, because it is the answer to a question most people never have: it matters only
	// when the source cannot open an HTTP connection to this site, and offering it beside the
	// pairing code would make a one-step screen look like a two-step one.
	const reveal = async () => {
		setBusy( true );
		setError( '' );
		const response = await api.ownProfile();
		setBusy( false );

		if ( response.failed ) {
			setError( response.error );
			return;
		}

		setProfile( response.profile );
	};

	const issue = async () => {
		setBusy( true );
		setError( '' );
		const response = await api.pairing.issue();
		setBusy( false );

		if ( response.failed ) {
			setError( response.error );
			return;
		}

		setCode( response.code );
		setSiteUrl( response.site_url );
		refresh();
	};

	return (
		<Layout
			steps={ DESTINATION_STEPS }
			step="connect"
			eyebrow={ __( 'Destination', 'nfd-site-migrator' ) }
			title={
				waiting
					? __( 'A package is waiting', 'nfd-site-migrator' )
					: __( 'Give the source this code', 'nfd-site-migrator' )
			}
			intro={
				waiting
					? __(
							'The site you paired with has finished packaging and is offering it to this one. Nothing else needs carrying — the pairing left a token here, and this site fetches the package itself.',
							'nfd-site-migrator'
					  )
					: __(
							'Paste both of these into the export screen on the site you are moving from. The source will read this site’s versions and free space to confirm the move will work. Then leave this tab open: when the package is ready it appears here.',
							'nfd-site-migrator'
					  )
			}
		>
			{ error && (
				<div className="nfd-sm-note nfd-sm-note--stop">{ error }</div>
			) }

			{ waiting && (
				<div className="nfd-sm-card" id="nfd-sm-receive-offer">
					<p className="nfd-sm-eyebrow">
						{ __( 'Ready to bring over', 'nfd-site-migrator' ) }
					</p>
					<p className="nfd-sm-hint">
						{ sprintf(
							/* translators: 1: source address, 2: package size, 3: number of files. */
							__(
								'%1$s is offering %2$s across %3$d files. Starting only fetches it into a staging folder — nothing on this site changes, and you will see exactly what the package would do before anything is written.',
								'nfd-site-migrator'
							),
							waiting.site_url || waiting.url,
							size( waiting.bytes || 0 ),
							waiting.count || 0
						) }
					</p>
					<div className="nfd-sm-actions">
						<button
							type="button"
							className="nfd-sm-btn nfd-sm-btn--primary"
							id="nfd-sm-start-transfer"
							onClick={ () =>
								navigate( '/import/pull', {
									state: { start: true },
								} )
							}
						>
							{ __( 'Start the transfer', 'nfd-site-migrator' ) }
						</button>
					</div>
				</div>
			) }

			{ ! waiting && ! code && (
				<div className="nfd-sm-actions">
					<button
						type="button"
						className="nfd-sm-btn nfd-sm-btn--primary"
						id="nfd-sm-issue-code"
						disabled={ busy }
						onClick={ issue }
					>
						{ busy
							? __( 'Generating…', 'nfd-site-migrator' )
							: __(
									'Generate a pairing code',
									'nfd-site-migrator'
							  ) }
					</button>
				</div>
			) }

			{ ! waiting && code && (
				<div className="nfd-sm-card">
					<p className="nfd-sm-eyebrow">
						{ __( 'This site’s address', 'nfd-site-migrator' ) }
					</p>
					<p className="nfd-sm-paircode nfd-sm-paircode--url">
						{ siteUrl }
					</p>

					<p className="nfd-sm-eyebrow">
						{ __( 'Pairing code', 'nfd-site-migrator' ) }
					</p>
					<p className="nfd-sm-paircode" id="nfd-sm-pairing-code">
						{ code }
					</p>

					<p className="nfd-sm-hint">
						{ __(
							'Single use, expires in 15 minutes. It only allows reading this site’s setup — no content, no logins, no changes.',
							'nfd-site-migrator'
						) }
					</p>
					<p className="nfd-sm-hint">
						{ __(
							'It also saves you the trip back. Once the two sites have been introduced, the source can offer the finished package straight to this one, and the import screen here will show it waiting — with nothing else to copy.',
							'nfd-site-migrator'
						) }
					</p>

					<div className="nfd-sm-actions">
						<button
							type="button"
							className="nfd-sm-btn"
							id="nfd-sm-copy-url"
							onClick={ () => copy( siteUrl, 'url' ) }
						>
							{ copyLabel(
								copied,
								'url',
								__( 'Copy address', 'nfd-site-migrator' )
							) }
						</button>
						<button
							type="button"
							className="nfd-sm-btn nfd-sm-btn--primary"
							id="nfd-sm-copy-code"
							onClick={ () => copy( code, 'code' ) }
						>
							{ copyLabel(
								copied,
								'code',
								__( 'Copy code', 'nfd-site-migrator' )
							) }
						</button>
						<button
							type="button"
							className="nfd-sm-btn"
							onClick={ issue }
						>
							{ __( 'New code', 'nfd-site-migrator' ) }
						</button>
					</div>
				</div>
			) }

			{ status?.paired && (
				<div className="nfd-sm-note nfd-sm-note--pass">
					{ __( 'Paired with ', 'nfd-site-migrator' ) }
					<span className="nfd-sm-mono">{ status.paired }</span>
				</div>
			) }

			{ ! waiting && (
				<details
					className="nfd-sm-details"
					id="nfd-sm-profile-fallback"
				>
					<summary>
						{ __(
							'Source cannot reach this site? Send it a profile instead',
							'nfd-site-migrator'
						) }
					</summary>

					<div className="nfd-sm-card">
						<p className="nfd-sm-hint">
							{ __(
								'A pairing code only works if the source can open a connection to this site — which it cannot behind a firewall, on a local machine, or past an HTTP password. This is the same facts written down: paste it into “Paste a profile instead” on the source’s pairing screen and the compatibility check runs there without either site calling the other.',
								'nfd-site-migrator'
							) }
						</p>

						{ ! profile && (
							<div className="nfd-sm-actions">
								<button
									type="button"
									className="nfd-sm-btn"
									id="nfd-sm-show-profile"
									disabled={ busy }
									onClick={ reveal }
								>
									{ busy
										? __( 'Reading…', 'nfd-site-migrator' )
										: __(
												'Show this site’s profile',
												'nfd-site-migrator'
										  ) }
								</button>
							</div>
						) }

						{ profile && (
							<>
								<p
									className="nfd-sm-paircode nfd-sm-paircode--long"
									id="nfd-sm-profile-blob"
								>
									{ profile }
								</p>
								<div className="nfd-sm-actions">
									<button
										type="button"
										className="nfd-sm-btn nfd-sm-btn--primary"
										onClick={ () =>
											copy( profile, 'profile' )
										}
									>
										{ copyLabel(
											copied,
											'profile',
											__(
												'Copy profile',
												'nfd-site-migrator'
											)
										) }
									</button>
								</div>
								<p className="nfd-sm-hint">
									{ __(
										'Versions, paths and free space — no content, no logins. It goes stale after a week, so generate a fresh one if the export is not done by then.',
										'nfd-site-migrator'
									) }
								</p>
							</>
						) }
					</div>
				</details>
			) }

			<div className="nfd-sm-card">
				<p className="nfd-sm-eyebrow">
					{ __( 'Already have the package?', 'nfd-site-migrator' ) }
				</p>
				<p className="nfd-sm-hint">
					{ __(
						'You do not need a pairing code to import — that is only for checking compatibility before the export. Nothing on this site changes until you have seen exactly what the package would do and confirmed it.',
						'nfd-site-migrator'
					) }
				</p>
				<div className="nfd-sm-actions">
					<button
						type="button"
						className="nfd-sm-btn nfd-sm-btn--primary"
						id="nfd-sm-go-import"
						onClick={ () => navigate( '/import' ) }
					>
						{ __( 'Import a package', 'nfd-site-migrator' ) }
					</button>
				</div>
			</div>
		</Layout>
	);
};
