import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Layout } from '../../Layout';
import { api, importToken } from '../../../utils/api';
import { DESTINATION_STEPS } from '../../../steps';

const size = ( bytes ) => {
	if ( bytes >= 1073741824 ) {
		return `${ ( bytes / 1073741824 ).toFixed( 1 ) } GB`;
	}
	return `${ ( bytes / 1048576 ).toFixed( 1 ) } MB`;
};

/**
 * The last screen before the only irreversible thing this plugin does.
 *
 * Everything here is read from the destination, not assumed: the compatibility gates run
 * against this server as it is right now, and the account list is produced by the same function
 * that will perform the merge. A preview computed by separate code is a preview that can
 * disagree with reality, and this is the screen where somebody decides to replace a website.
 *
 * @return {Element} The screen.
 */
export const Review = () => {
	const navigate = useNavigate();
	const [ params ] = useSearchParams();
	const dir = params.get( 'dir' ) || '';

	const [ preview, setPreview ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ understood, setUnderstood ] = useState( false );

	useEffect( () => {
		api.import.preview( dir ).then( ( response ) => {
			if ( response.failed ) {
				setError( response.error );
			} else {
				setPreview( response );
			}
		} );
	}, [ dir ] );

	const begin = async () => {
		setBusy( true );
		setError( '' );

		const started = await api.import.start( dir );

		if ( started.failed ) {
			setBusy( false );
			setError( started.error );
			return;
		}

		importToken.set( started.token );

		navigate(
			`/import/run${ dir ? `?dir=${ encodeURIComponent( dir ) }` : '' }`
		);
	};

	if ( error ) {
		return (
			<Layout
				steps={ DESTINATION_STEPS }
				step="review"
				eyebrow={ __( 'Destination', 'nfd-site-migrator' ) }
				title={ __(
					'This package cannot be used',
					'nfd-site-migrator'
				) }
			>
				<div className="nfd-sm-note nfd-sm-note--stop">{ error }</div>
				<div className="nfd-sm-actions">
					<button
						type="button"
						className="nfd-sm-btn"
						onClick={ () => navigate( '/import' ) }
					>
						{ __( 'Back', 'nfd-site-migrator' ) }
					</button>
				</div>
			</Layout>
		);
	}

	if ( ! preview ) {
		return (
			<Layout
				steps={ DESTINATION_STEPS }
				step="review"
				eyebrow={ __( 'Destination', 'nfd-site-migrator' ) }
				title={ __( 'Checking the package…', 'nfd-site-migrator' ) }
			/>
		);
	}

	const blocking = preview.report?.blocking || [];
	const warnings = preview.report?.warnings || [];
	const users = preview.users || {};
	const source = preview.package?.source || {};

	return (
		<Layout
			steps={ DESTINATION_STEPS }
			step="review"
			eyebrow={ __( 'Destination', 'nfd-site-migrator' ) }
			title={ __( 'Check this before you commit', 'nfd-site-migrator' ) }
			intro={ sprintf(
				/* translators: 1: source URL, 2: destination URL. */
				__(
					'Everything below describes what would happen to %2$s if you import %1$s. Nothing has been written yet.',
					'nfd-site-migrator'
				),
				source.site_url || __( 'this package', 'nfd-site-migrator' ),
				preview.target?.site_url || ''
			) }
		>
			{ preview.problems?.length > 0 && (
				<div className="nfd-sm-note nfd-sm-note--stop">
					<p>
						{ __(
							'The package is damaged or incomplete:',
							'nfd-site-migrator'
						) }
					</p>
					<ul>
						{ preview.problems.map( ( p ) => (
							<li key={ p }>{ p }</li>
						) ) }
					</ul>
				</div>
			) }

			{ blocking.length > 0 && (
				<div className="nfd-sm-note nfd-sm-note--stop">
					<p>
						{ __(
							'This site cannot accept the package as it stands:',
							'nfd-site-migrator'
						) }
					</p>
					<ul>
						{ blocking.map( ( c ) => (
							<li key={ c.id }>{ c.label }</li>
						) ) }
					</ul>
				</div>
			) }

			{ warnings.map( ( c ) => (
				<div key={ c.id } className="nfd-sm-note nfd-sm-note--warn">
					{ c.label }
				</div>
			) ) }

			<div className="nfd-sm-card">
				<p className="nfd-sm-eyebrow">
					{ __( 'What arrives', 'nfd-site-migrator' ) }
				</p>
				<dl className="nfd-sm-facts">
					<div>
						<dt>{ __( 'From', 'nfd-site-migrator' ) }</dt>
						<dd>{ source.site_url }</dd>
					</div>
					<div>
						<dt>{ __( 'WordPress', 'nfd-site-migrator' ) }</dt>
						<dd>{ source.wp_version }</dd>
					</div>
					<div>
						<dt>{ __( 'Contents', 'nfd-site-migrator' ) }</dt>
						<dd>
							{ sprintf(
								/* translators: 1: file count, 2: size. */
								__( '%1$d files, %2$s', 'nfd-site-migrator' ),
								preview.package?.totals?.files || 0,
								size( preview.package?.totals?.bytes || 0 )
							) }
						</dd>
					</div>
					<div>
						<dt>{ __( 'Table prefix', 'nfd-site-migrator' ) }</dt>
						<dd className="nfd-sm-mono">
							{ source.table_prefix }
							{ ' → ' }
							{ preview.target?.prefix || '' }
						</dd>
					</div>
				</dl>
			</div>

			<div className="nfd-sm-card nfd-sm-card--stop">
				<p className="nfd-sm-eyebrow">
					{ __( 'What this replaces', 'nfd-site-migrator' ) }
				</p>
				<p>
					{ __(
						'Every post, page, comment, setting, plugin and theme on this site is replaced by the ones in the package. This site’s own content does not survive.',
						'nfd-site-migrator'
					) }
				</p>
				<p>
					{ sprintf(
						/* translators: %d: number of days. */
						__(
							'The tables being replaced are kept for %d days, so this can be undone.',
							'nfd-site-migrator'
						),
						preview.backup_days || 30
					) }
				</p>
			</div>

			<div className="nfd-sm-card">
				<p className="nfd-sm-eyebrow">
					{ __( 'Who can sign in afterwards', 'nfd-site-migrator' ) }
				</p>

				{ ! users.available && (
					<p className="nfd-sm-hint">{ users.reason }</p>
				) }

				{ users.available && (
					<>
						<p>
							{ __(
								'Accounts on this site are kept, not replaced. Where the same person exists on both, they keep the password they already use here.',
								'nfd-site-migrator'
							) }
						</p>
						<table className="nfd-sm-parts">
							<thead>
								<tr>
									<th>
										{ __( 'Account', 'nfd-site-migrator' ) }
									</th>
									<th>
										{ __(
											'Signs in as',
											'nfd-site-migrator'
										) }
									</th>
									<th>
										{ __(
											'Password',
											'nfd-site-migrator'
										) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ ( users.matched || [] ).map( ( m ) => (
									<tr key={ `m${ m.dest_id }` }>
										<td>{ m.email }</td>
										<td className="nfd-sm-mono">
											{ m.source_login }
											{ m.source_login !==
												m.dest_login && (
												<span className="nfd-sm-muted">
													{ ` (was ${ m.dest_login })` }
												</span>
											) }
										</td>
										<td>
											{ __(
												'the one you use here',
												'nfd-site-migrator'
											) }
										</td>
									</tr>
								) ) }
								{ ( users.carried || [] ).map( ( c ) => (
									<tr key={ `c${ c.dest_id }` }>
										<td>{ c.email }</td>
										<td className="nfd-sm-mono">
											{ c.login }
											{ c.login !== c.requested_login && (
												<span className="nfd-sm-warn">
													{ ` (renamed from ${ c.requested_login })` }
												</span>
											) }
										</td>
										<td>
											{ __(
												'unchanged',
												'nfd-site-migrator'
											) }
										</td>
									</tr>
								) ) }
								{ ( users.source_only || [] ).map( ( s ) => (
									<tr key={ `s${ s.id }` }>
										<td>{ s.email }</td>
										<td className="nfd-sm-mono">
											{ s.login }
										</td>
										<td>
											{ __(
												'their password from the other site',
												'nfd-site-migrator'
											) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>

						{ ( users.carried || [] ).some(
							( c ) => c.login !== c.requested_login
						) && (
							<p className="nfd-sm-hint">
								{ __(
									'A username was renamed because a different person on the other site already uses it. Both accounts are kept; you can merge them yourself afterwards if they are the same person.',
									'nfd-site-migrator'
								) }
							</p>
						) }
					</>
				) }
			</div>

			{ preview.manual?.length > 0 && (
				<div className="nfd-sm-card">
					<p className="nfd-sm-eyebrow">
						{ __( 'Worth knowing', 'nfd-site-migrator' ) }
					</p>
					{ preview.manual.map( ( step ) => (
						<div key={ step.id }>
							<p>{ step.label }</p>
							{ step.block && (
								<pre className="nfd-sm-block">
									{ step.block }
								</pre>
							) }
						</div>
					) ) }
				</div>
			) }

			{ preview.has_backup && (
				<div className="nfd-sm-note nfd-sm-note--warn">
					{ __(
						'This site still holds the tables a previous import replaced. Confirm or undo that one first — they are the only copy of what was here before it.',
						'nfd-site-migrator'
					) }
				</div>
			) }

			{ preview.ok && ! preview.has_backup && (
				<div className="nfd-sm-card nfd-sm-card--commit">
					<label className="nfd-sm-check" htmlFor="nfd-sm-understood">
						<input
							id="nfd-sm-understood"
							type="checkbox"
							checked={ understood }
							onChange={ ( e ) =>
								setUnderstood( e.target.checked )
							}
						/>
						<span>
							{ sprintf(
								/* translators: %s: destination URL. */
								__(
									'I understand that the content currently on %s will be replaced.',
									'nfd-site-migrator'
								),
								preview.target?.site_url || ''
							) }
						</span>
					</label>

					<div className="nfd-sm-actions">
						<button
							type="button"
							className="nfd-sm-btn nfd-sm-btn--danger"
							id="nfd-sm-begin-import"
							disabled={ ! understood || busy }
							onClick={ begin }
						>
							{ busy
								? __( 'Starting…', 'nfd-site-migrator' )
								: __( 'Import it', 'nfd-site-migrator' ) }
						</button>
						<button
							type="button"
							className="nfd-sm-btn"
							onClick={ () => navigate( '/import' ) }
						>
							{ __( 'Not yet', 'nfd-site-migrator' ) }
						</button>
					</div>
				</div>
			) }
		</Layout>
	);
};
