import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import { Layout } from '../Layout';
import { api } from '../../utils/api';

/**
 * The destination side of the handshake: mint a code for the source to use.
 *
 * The code is minted here, on the site that would be overwritten. That is what stops a site
 * being targeted without someone having stood on it and asked — the source has nothing to
 * authenticate with otherwise.
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

	const refresh = () =>
		api.pairing.status().then( ( s ) => ! s.failed && setStatus( s ) );

	useEffect( () => {
		refresh();
	}, [] );

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
			eyebrow={ __( 'Destination', 'nfd-site-migrator' ) }
			title={ __( 'Give the source this code', 'nfd-site-migrator' ) }
			intro={ __(
				'Paste both of these into the export screen on the site you are moving from. The source will read this site’s versions and free space to confirm the move will work.',
				'nfd-site-migrator'
			) }
		>
			{ error && (
				<div className="nfd-sm-note nfd-sm-note--stop">{ error }</div>
			) }

			{ ! code && (
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

			{ code && (
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
