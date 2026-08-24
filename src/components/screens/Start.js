import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import { Layout } from '../Layout';
import { Gates } from '../Gate';
import { api } from '../../utils/api';

/**
 * Choose a direction, and see whether this site can export at all.
 *
 * @return {Element} The screen.
 */
export const Start = () => {
	const [ loading, setLoading ] = useState( true );
	const [ report, setReport ] = useState( null );
	const [ profile, setProfile ] = useState( null );
	const [ error, setError ] = useState( '' );
	const navigate = useNavigate();

	useEffect( () => {
		let live = true;

		api.preflight().then( ( response ) => {
			if ( ! live ) {
				return;
			}
			if ( response.failed ) {
				setError( response.error );
			} else {
				setReport( response.report );
				setProfile( response.profile );
			}
			setLoading( false );
		} );

		return () => {
			live = false;
		};
	}, [] );

	const blocked = report && ! report.ok;

	return (
		<Layout
			eyebrow={ profile?.site_url || '' }
			title={ __(
				'Move this site, or bring one here',
				'nfd-site-migrator'
			) }
			intro={ __(
				'The plugin runs on both ends. Install it on the other site too, then start from whichever side you are on.',
				'nfd-site-migrator'
			) }
		>
			{ error && (
				<div className="nfd-sm-note nfd-sm-note--stop">{ error }</div>
			) }

			{ loading && (
				<p className="nfd-sm-muted">
					{ __( 'Checking this site…', 'nfd-site-migrator' ) }
				</p>
			) }

			{ ! loading && blocked && (
				<div className="nfd-sm-note nfd-sm-note--stop">
					{ __(
						'This site cannot be exported yet. Everything below has to be resolved first.',
						'nfd-site-migrator'
					) }
				</div>
			) }

			{ ! loading && report && <Gates report={ report } /> }

			{ ! loading && (
				<div className="nfd-sm-choices">
					<button
						type="button"
						className="nfd-sm-choice"
						id="nfd-sm-start-export"
						disabled={ blocked }
						onClick={ () => navigate( '/pair' ) }
					>
						<h3>
							{ __(
								'Send this site somewhere else',
								'nfd-site-migrator'
							) }
						</h3>
						<p>
							{ __(
								'Package this site’s content and database, then carry it to a destination running this plugin.',
								'nfd-site-migrator'
							) }
						</p>
						<span className="nfd-sm-arrow">
							{ __( 'Start export →', 'nfd-site-migrator' ) }
						</span>
					</button>

					<button
						type="button"
						className="nfd-sm-choice"
						id="nfd-sm-start-receive"
						onClick={ () => navigate( '/receive' ) }
					>
						<h3>
							{ __( 'Receive a site here', 'nfd-site-migrator' ) }
						</h3>
						<p>
							{ __(
								'Give the other site a pairing code so it can check this one is ready.',
								'nfd-site-migrator'
							) }
						</p>
						<span className="nfd-sm-arrow">
							{ __(
								'Get a pairing code →',
								'nfd-site-migrator'
							) }
						</span>
					</button>
				</div>
			) }

			{ profile && (
				<dl className="nfd-sm-facts">
					<div>
						<dt>{ __( 'WordPress', 'nfd-site-migrator' ) }</dt>
						<dd>{ profile.wp?.version }</dd>
					</div>
					<div>
						<dt>{ __( 'PHP', 'nfd-site-migrator' ) }</dt>
						<dd>{ profile.php?.version }</dd>
					</div>
					<div>
						<dt>{ __( 'Database', 'nfd-site-migrator' ) }</dt>
						<dd>{ profile.database?.version }</dd>
					</div>
					<div>
						<dt>{ __( 'Table prefix', 'nfd-site-migrator' ) }</dt>
						<dd>{ profile.wp?.prefix }</dd>
					</div>
				</dl>
			) }

			<p className="nfd-sm-muted nfd-sm-footnote">
				{ __(
					'WordPress core is not included in the package — the destination already has it. Only your content moves.',
					'nfd-site-migrator'
				) }
			</p>
		</Layout>
	);
};
