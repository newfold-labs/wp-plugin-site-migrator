import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Layout } from '../../Layout';
import { Loading } from '../../Loading';
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
	const [ fixPhp, setFixPhp ] = useState( false );
	const [ checking, setChecking ] = useState( false );

	// Asked again when the fix is switched, because the answer changes: a package whose only
	// blockers are fixable becomes importable, and the list says which files will be changed.
	useEffect( () => {
		let current = true;

		setChecking( true );

		api.import.preview( dir, fixPhp ).then( ( response ) => {
			if ( ! current ) {
				return;
			}

			setChecking( false );

			if ( response.failed ) {
				setError( response.error );
			} else {
				setPreview( response );
			}
		} );

		return () => {
			current = false;
		};
	}, [ dir, fixPhp ] );

	const begin = async () => {
		setBusy( true );
		setError( '' );

		const started = await api.import.start( dir, undefined, fixPhp );

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
				safetyDetail={ __(
					'This is a preview. Nothing has been written to this site yet.',
					'nfd-site-migrator'
				) }
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
				safetyDetail={ __(
					'This is a preview. Nothing has been written to this site yet.',
					'nfd-site-migrator'
				) }
				step="review"
				eyebrow={ __( 'Destination', 'nfd-site-migrator' ) }
				title={ __( 'Checking the package', 'nfd-site-migrator' ) }
			>
				<div className="nfd-sm-card">
					<Loading>
						{ __(
							'Reading the manifest and comparing it with this site. Nothing is written yet.',
							'nfd-site-migrator'
						) }
					</Loading>
				</div>
			</Layout>
		);
	}

	const blocking = preview.report?.blocking || [];
	const warnings = preview.report?.warnings || [];
	const users = preview.users || {};

	// A package can carry code and no data. Almost everything this screen says is about a
	// database arriving — who will be able to sign in, what is replaced, what can be undone — and
	// none of it is true of one that brings plugin and theme files to a site that keeps its own
	// content. Saying it anyway would be the worst kind of wrong: a warning nobody needs, on the
	// screen where warnings are supposed to mean something.
	const filesOnly = !! preview.files_only;

	// And a third shape between the two: a package that brings a few tables and settings and
	// merges them, leaving the rest of this site where it is. It is not a replacement, so it does
	// not get the replacement's warnings -- but it does write into the live options table, which
	// nothing else in the import does, so it says which settings.
	const partial = !! preview.partial;
	const merging = preview.merging || {};
	const gentle = filesOnly || partial;

	const starting = __( 'Starting…', 'nfd-site-migrator' );
	let commit = __( 'Import it', 'nfd-site-migrator' );

	if ( filesOnly ) {
		commit = __( 'Bring in the files', 'nfd-site-migrator' );
	} else if ( partial ) {
		commit = __( 'Merge it in', 'nfd-site-migrator' );
	}
	const source = preview.package?.source || {};
	const code = [ ...blocking, ...warnings ].find(
		( c ) => c.id === 'php_code'
	);
	const fixable = code?.context?.fixable || 0;

	// The files behind a check, when it names them.
	const missing = ( c ) =>
		Array.isArray( c.context?.missing ) &&
		c.context.missing.length > 0 && (
			<ul className="nfd-sm-gate-list">
				{ c.context.missing.map( ( item, index ) => (
					<li key={ index }>{ String( item ) }</li>
				) ) }
			</ul>
		);

	return (
		<Layout
			steps={ DESTINATION_STEPS }
			safetyDetail={ __(
				'This is a preview. Nothing has been written to this site yet.',
				'nfd-site-migrator'
			) }
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
							<li key={ c.id }>
								{ c.label }
								{ missing( c ) }
								{ c.context?.fix && (
									<p className="nfd-sm-gate-fix">
										{ c.context.fix }
									</p>
								) }
							</li>
						) ) }
					</ul>
				</div>
			) }

			{ warnings.map( ( c ) => (
				<div key={ c.id } className="nfd-sm-note nfd-sm-note--warn">
					<p>{ c.label }</p>
					{ missing( c ) }
				</div>
			) ) }

			{ fixable > 0 && (
				<div className="nfd-sm-card">
					<p className="nfd-sm-eyebrow">
						{ __(
							'Code this server cannot run',
							'nfd-site-migrator'
						) }
					</p>
					<label className="nfd-sm-check" htmlFor="nfd-sm-fix-php">
						<input
							id="nfd-sm-fix-php"
							type="checkbox"
							checked={ fixPhp }
							disabled={ checking || busy }
							onChange={ ( e ) => setFixPhp( e.target.checked ) }
						/>
						<span>
							{ sprintf(
								/* translators: 1: number of files, 2: PHP version. */
								__(
									'Fix %1$d file(s) as they are imported so they run on PHP %2$s',
									'nfd-site-migrator'
								),
								fixable,
								code.context?.php || ''
							) }
						</span>
					</label>
					<p className="nfd-sm-hint">
						{ __(
							'Only rewrites with an exact equivalent: $str{0} becomes $str[0], (real) becomes (float), and a nested ternary gets the parentheses PHP 7 applied. Each fixed file is checked again before it is written, and its original is kept.',
							'nfd-site-migrator'
						) }
					</p>
				</div>
			) }

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

			{ partial && (
				<div className="nfd-sm-card">
					<p className="nfd-sm-eyebrow">
						{ __( 'What this changes', 'nfd-site-migrator' ) }
					</p>
					<p>
						{ sprintf(
							/* translators: 1: number of tables, 2: number of settings. */
							__(
								'This package does not replace this site. It brings %1$d table(s) and %2$d setting(s) belonging to the plugins and themes it carries, and merges them into the database that is already here.',
								'nfd-site-migrator'
							),
							( merging.tables || [] ).length,
							( merging.options || [] ).length
						) }
					</p>
					<p>
						{ __(
							'Your posts, pages, users, comments and every other plugin’s settings stay exactly as they are. A table with the same name as one arriving is kept, so this can be undone.',
							'nfd-site-migrator'
						) }
					</p>
					{ ( merging.tables || [] ).length > 0 && (
						<ul className="nfd-sm-mono">
							{ merging.tables.map( ( name ) => (
								<li key={ name }>{ name }</li>
							) ) }
						</ul>
					) }
					{ ( merging.options || [] ).length > 0 && (
						<details className="nfd-sm-details">
							<summary>
								{ __(
									'Settings that would be written',
									'nfd-site-migrator'
								) }
							</summary>
							<ul className="nfd-sm-mono">
								{ merging.options.map( ( name ) => (
									<li key={ name }>{ name }</li>
								) ) }
							</ul>
						</details>
					) }
				</div>
			) }

			{ ! partial && filesOnly && (
				<div className="nfd-sm-card">
					<p className="nfd-sm-eyebrow">
						{ __( 'What this changes', 'nfd-site-migrator' ) }
					</p>
					<p>
						{ __(
							'This package carries no database. Plugin and theme files are written; your posts, pages, users, comments and settings are not touched, and this site keeps its own.',
							'nfd-site-migrator'
						) }
					</p>
					<p>
						{ __(
							'A plugin or theme this site already has, and the package also carries, is overwritten by the package’s copy. Anything new arrives switched off, because what switches a plugin on is a setting in the database this import does not write.',
							'nfd-site-migrator'
						) }
					</p>
					<p>
						{ __(
							'Undoing it deletes the files that were added and changes nothing else.',
							'nfd-site-migrator'
						) }
					</p>
				</div>
			) }

			{ ! partial && ! filesOnly && (
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
						{ __(
							'The tables being replaced are kept, so this can be undone — until you keep this import or start another migration.',
							'nfd-site-migrator'
						) }
					</p>
				</div>
			) }

			{ ! gentle && (
				<div className="nfd-sm-card">
					<p className="nfd-sm-eyebrow">
						{ __(
							'Who can sign in afterwards',
							'nfd-site-migrator'
						) }
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
											{ __(
												'Account',
												'nfd-site-migrator'
											) }
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
												{ c.login !==
													c.requested_login && (
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
									{ ( users.source_only || [] ).map(
										( s ) => (
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
										)
									) }
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
			) }

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
						'This site still holds the tables a previous import replaced. Starting this one discards them, which ends that import’s undo — this import is then the one that can be reversed. Go back and roll that one back first if you want what was here before it.',
						'nfd-site-migrator'
					) }
				</div>
			) }

			{ preview.ok && (
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
							{ gentle
								? sprintf(
										/* translators: %s: destination URL. */
										__(
											'I understand that plugin and theme files on %s will be overwritten where the package carries the same ones.',
											'nfd-site-migrator'
										),
										preview.target?.site_url || ''
								  )
								: sprintf(
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
							className={ `nfd-sm-btn nfd-sm-btn--${
								gentle ? 'primary' : 'danger'
							}` }
							id="nfd-sm-begin-import"
							disabled={ ! understood || busy || checking }
							onClick={ begin }
						>
							{ busy ? starting : commit }
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
