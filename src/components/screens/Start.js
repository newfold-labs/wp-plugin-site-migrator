import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import { Layout } from '../Layout';
import { Loading } from '../Loading';
import { Gates } from '../Gate';
import { api } from '../../utils/api';
import { SOURCE_STEPS } from '../../steps';

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
	const [ moving, setMoving ] = useState( '' );
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

	// Uploads not writable is a permission somebody with only wp-admin cannot change, so the
	// storage block carries the places that could be used instead.
	const alternatives =
		report?.blocking?.find( ( check ) => 'storage' === check.id )?.context
			?.alternatives || [];

	const moveStorage = ( location ) => {
		setMoving( location );
		setError( '' );

		api.moveStorage( location ).then( ( response ) => {
			if ( response.failed ) {
				setError( response.error );
			} else {
				setReport( response.report );
				setProfile( response.profile );
			}
			setMoving( '' );
		} );
	};

	return (
		<Layout
			steps={ SOURCE_STEPS }
			step="start"
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
				<div className="nfd-sm-card">
					<Loading>
						{ __( 'Checking this site…', 'nfd-site-migrator' ) }
					</Loading>
				</div>
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

			{ ! loading && alternatives.length > 0 && (
				<div className="nfd-sm-card">
					<h3>
						{ __(
							'Keep the migration files somewhere else',
							'nfd-site-migrator'
						) }
					</h3>
					<p className="nfd-sm-muted">
						{ __(
							'The package holds this site’s whole database, so it is kept in a folder only this site can read. Choose once; the plugin uses it until it is deleted.',
							'nfd-site-migrator'
						) }
					</p>
					<ul className="nfd-sm-gate-list">
						{ alternatives.map( ( option ) => (
							<li key={ option.id }>
								<p className="nfd-sm-gate-label">
									{ String( option.label ) }
								</p>
								<p className="nfd-sm-gate-fix">
									<code>{ String( option.path ) }</code>
								</p>
								<p className="nfd-sm-gate-fix">
									{ String( option.detail ) }
								</p>
								<button
									type="button"
									className="nfd-sm-btn"
									disabled={ '' !== moving }
									onClick={ () => moveStorage( option.id ) }
								>
									{ moving === option.id
										? __(
												'Setting up…',
												'nfd-site-migrator'
										  )
										: __(
												'Use this folder',
												'nfd-site-migrator'
										  ) }
								</button>
							</li>
						) ) }
					</ul>
				</div>
			) }

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
