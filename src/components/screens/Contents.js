import { __, _n, sprintf } from '@wordpress/i18n';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import { Layout } from '../Layout';
import { Loading } from '../Loading';
import { api } from '../../utils/api';
import { SOURCE_STEPS } from '../../steps';

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
 * What each part is, said the way somebody who is not a developer would say it.
 *
 * The consequence of leaving one out matters more than what it contains, so each blurb ends with
 * it: a part is not a folder here, it is a decision with an outcome on the other site.
 */
const PARTS = {
	plugins: {
		label: __( 'Plugins', 'nfd-site-migrator' ),
		blurb: __(
			'Every plugin, active or not. Without them the destination loads but does almost nothing your site does.',
			'nfd-site-migrator'
		),
		heavy: true,
	},
	'mu-plugins': {
		label: __( 'Must-use plugins', 'nfd-site-migrator' ),
		blurb: __(
			'Loaded by WordPress automatically. Often put there by a host, in which case the destination has its own.',
			'nfd-site-migrator'
		),
	},
	themes: {
		label: __( 'Themes', 'nfd-site-migrator' ),
		blurb: __(
			'Every installed theme. Leave them out only if the destination already has the one this site uses.',
			'nfd-site-migrator'
		),
		heavy: true,
	},
	uploads: {
		label: __( 'Uploads — the media library', 'nfd-site-migrator' ),
		blurb: __(
			'Usually the largest part of a site by far. Leaving it out means the destination has the posts but not the images in them.',
			'nfd-site-migrator'
		),
		heavy: true,
	},
	dropins: {
		label: __( 'Drop-ins', 'nfd-site-migrator' ),
		blurb: __(
			'object-cache.php and friends. These are usually specific to the host they were written for.',
			'nfd-site-migrator'
		),
	},
	'content-other': {
		label: __( 'The rest of wp-content', 'nfd-site-migrator' ),
		blurb: __(
			'Languages, and anything a plugin has left in wp-content that is not covered above.',
			'nfd-site-migrator'
		),
	},
	'root-extras': {
		label: __( 'Top-level files', 'nfd-site-migrator' ),
		blurb: __(
			'.htaccess, robots.txt and the other small files at the root of the site. Never wp-config.php.',
			'nfd-site-migrator'
		),
	},
};

const FLAGS = {
	skip_revisions: {
		label: __( 'Leave out post revisions', 'nfd-site-migrator' ),
		noun: __( 'post revisions', 'nfd-site-migrator' ),
		blurb: __(
			'Every saved draft of every post, kept forever by default. On an old site this is routinely most of the posts table, and the published content is unaffected.',
			'nfd-site-migrator'
		),
	},
	skip_spam: {
		label: __( 'Leave out spam and trashed comments', 'nfd-site-migrator' ),
		noun: __( 'spam and trashed comments', 'nfd-site-migrator' ),
		blurb: __(
			'Comments already marked as junk or thrown away.',
			'nfd-site-migrator'
		),
	},
	skip_transients: {
		label: __( 'Leave out cached transients', 'nfd-site-migrator' ),
		noun: __( 'cached transients', 'nfd-site-migrator' ),
		blurb: __(
			'Temporary cache entries WordPress rebuilds by itself. Safe to drop, and sometimes hundreds of megabytes.',
			'nfd-site-migrator'
		),
	},
};

/**
 * The things inside a part, as the screen shows them: named, and in that order.
 *
 * A directory name is the identity — it is what the selection refuses and what `--set` takes —
 * but `advanced-hiive-config` is not what anybody calls that plugin, so the server sends the name
 * out of each plugin's or theme's own header beside its slug. Sorting happens here rather than on
 * the server because the server sorts by slug, which is right for the CLI and wrong for a list
 * somebody is reading: `nfd-site-migrator` belongs under S for Site Migrator.
 *
 * @param {Object} part One entry of the contents catalog.
 * @return {Array} `{ slug, label }` pairs, sorted by what is drawn.
 */
const namedChildren = ( part ) =>
	part.children
		.map( ( slug ) => ( {
			slug,
			label: part.labels?.[ slug ] || slug,
		} ) )
		.sort( ( a, b ) =>
			a.label.localeCompare( b.label, undefined, { sensitivity: 'base' } )
		);

/**
 * A table name with the site's prefix taken off, if it is wearing one.
 *
 * @param {string} name   Table name.
 * @param {string} prefix This site's table prefix.
 * @return {string} The name without it.
 */
const bareTable = ( name, prefix ) =>
	prefix && name.startsWith( prefix ) ? name.slice( prefix.length ) : name;

/**
 * Whether two table names mean the same table.
 *
 * This screen always holds the prefixed name, because it lists what `SHOW TABLE STATUS` returned.
 * A selection saved from the CLI may hold the bare one — the README says either will do, and
 * `Selection::skips_table()` decides it the same way server-side. Comparing raw strings here would
 * draw a table as carried while the export left it out, and then drop the refusal on the next save.
 *
 * @param {string} a      One name.
 * @param {string} b      The other.
 * @param {string} prefix This site's table prefix.
 * @return {boolean} Whether they are the same table.
 */
const sameTable = ( a, b, prefix ) =>
	bareTable( a, prefix ) === bareTable( b, prefix );

/**
 * A table name that may break after its underscores.
 *
 * Browsers break at spaces and hyphens, and `wp_woocommerce_downloadable_product_permissions` has
 * neither -- it is one word forty characters long. The CSS safety net wraps it anywhere rather
 * than let it push its grid column out of the card, which stops the overflow but reads badly:
 * `..._product_permi` / `ssions`. A `<wbr>` after each underscore gives the line breaker somewhere
 * sensible to go first, so the split lands between words and the net is only reached by a name
 * that has no underscores either.
 *
 * @param {string} name Table name.
 * @return {Array} The name, with break opportunities in it.
 */
const breakable = ( name ) => {
	const parts = name.split( '_' );

	return parts.map( ( part, i ) => (
		<Fragment key={ i }>
			{ part }
			{ i < parts.length - 1 && (
				<>
					_
					<wbr />
				</>
			) }
		</Fragment>
	) );
};

const label = ( name ) => ( PARTS[ name ] ? PARTS[ name ].label : name );

/**
 * Choose what goes into the package.
 *
 * Everything here is an exclusion: an untouched screen packages the whole site, which is what an
 * export has always done. That asymmetry is deliberate — the safe answer has to be the one you
 * get by not deciding.
 *
 * Sizes are shown for database tables and not for directories. A table's size is one row of
 * `SHOW TABLE STATUS`; a directory's is a walk of the whole site, which is the expensive half of
 * an export. Making somebody wait through most of an export to find out what to leave out of it
 * would be a strange trade, so the parts are named and the person picking already knows which of
 * them is the big one.
 *
 * @return {Element} The screen.
 */
export const Contents = () => {
	const navigate = useNavigate();
	const [ data, setData ] = useState( null );
	const [ selection, setSelection ] = useState( {
		parts: {},
		paths: {},
		database: {},
	} );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let live = true;

		api.exportContents().then( ( response ) => {
			if ( ! live ) {
				return;
			}

			if ( response.failed ) {
				setError( response.error );
				return;
			}

			setData( response );
			setSelection( {
				parts: response.selection?.parts || {},
				paths: response.selection?.paths || {},
				database: response.selection?.database || {},
			} );
		} );

		return () => {
			live = false;
		};
	}, [] );

	const wantsPart = ( name ) => selection.parts[ name ] !== false;

	const wantsPath = ( part, path ) =>
		! ( selection.paths[ part ] || [] ).includes( path );

	const togglePart = ( name ) =>
		setSelection( ( current ) => {
			const parts = { ...current.parts };

			if ( parts[ name ] === false ) {
				delete parts[ name ];
			} else {
				parts[ name ] = false;
			}

			return { ...current, parts };
		} );

	const togglePath = ( part, path ) =>
		setSelection( ( current ) => {
			const paths = { ...current.paths };
			const list = paths[ part ] || [];

			if ( list.includes( path ) ) {
				const left = list.filter( ( one ) => one !== path );

				if ( left.length ) {
					paths[ part ] = left;
				} else {
					delete paths[ part ];
				}
			} else {
				paths[ part ] = [ ...list, path ];
			}

			return { ...current, paths };
		} );

	const toggleFlag = ( flag ) =>
		setSelection( ( current ) => {
			const database = { ...current.database };

			if ( database[ flag ] ) {
				delete database[ flag ];
			} else {
				database[ flag ] = true;
			}

			return { ...current, database };
		} );

	const wantsTable = ( name ) =>
		! ( selection.database.skip_tables || [] ).some( ( one ) =>
			sameTable( one, name, data?.prefix )
		);

	const toggleTable = ( name ) =>
		setSelection( ( current ) => {
			const database = { ...current.database };
			const list = database.skip_tables || [];
			const prefix = data?.prefix;

			if ( list.some( ( one ) => sameTable( one, name, prefix ) ) ) {
				const left = list.filter(
					( one ) => ! sameTable( one, name, prefix )
				);

				if ( left.length ) {
					database.skip_tables = left;
				} else {
					delete database.skip_tables;
				}
			} else {
				// Stored as this screen knows it — prefixed. Saving from a surface that knows the
				// prefix is the right moment to settle on one spelling.
				database.skip_tables = [ ...list, name ];
			}

			return { ...current, database };
		} );

	const everything = () =>
		setSelection( { parts: {}, paths: {}, database: {} } );

	const save = async ( then ) => {
		setSaving( true );
		setError( '' );

		const response = await api.exportChoose( selection );

		setSaving( false );

		if ( response.failed ) {
			setError( response.error );
			return;
		}

		navigate( then );
	};

	// Said back locally rather than waited for from the server: the point of the summary is to
	// answer "what have I just done" while the boxes are still being ticked.
	const leaving = [];

	Object.keys( selection.parts ).forEach( ( name ) =>
		leaving.push( label( name ) )
	);

	Object.keys( selection.paths ).forEach( ( part ) =>
		leaving.push(
			sprintf(
				/* translators: 1: number of items, 2: part name. */
				__( '%1$d from %2$s', 'nfd-site-migrator' ),
				selection.paths[ part ].length,
				label( part )
			)
		)
	);

	Object.keys( FLAGS ).forEach( ( flag ) => {
		if ( selection.database[ flag ] ) {
			// The checkbox reads "Leave out post revisions"; after "Leaving out:" only the noun
			// belongs, or the sentence says it twice. Same phrases `Selection::describe()` uses
			// server-side, so the summary here and the destination's review screen agree.
			leaving.push( FLAGS[ flag ].noun );
		}
	} );

	if ( ( selection.database.skip_tables || [] ).length ) {
		leaving.push(
			sprintf(
				/* translators: %d: number of database tables. */
				_n(
					'%d database table',
					'%d database tables',
					selection.database.skip_tables.length,
					'nfd-site-migrator'
				),
				selection.database.skip_tables.length
			)
		);
	}

	const locked = !! data?.locked;

	return (
		<Layout
			steps={ SOURCE_STEPS }
			step="export"
			eyebrow={ __( 'Source', 'nfd-site-migrator' ) }
			title={ __( 'Choose what to include', 'nfd-site-migrator' ) }
			intro={ __(
				'Everything is packaged unless you say otherwise. Leaving something out makes the package smaller and the export faster — the destination simply keeps whatever it already has in that place.',
				'nfd-site-migrator'
			) }
		>
			{ error && (
				<div className="nfd-sm-note nfd-sm-note--stop">{ error }</div>
			) }

			{ ! data && ! error && (
				<div className="nfd-sm-card">
					<Loading>
						{ __( 'Reading the site…', 'nfd-site-migrator' ) }
					</Loading>
				</div>
			) }

			{ locked && (
				<div className="nfd-sm-note nfd-sm-note--warn">
					{ __(
						'An export is already under way, and it is packaging the choices it started with. Cancel it first if you want to change them.',
						'nfd-site-migrator'
					) }
				</div>
			) }

			{ data && (
				<>
					<div className="nfd-sm-card">
						<p className="nfd-sm-eyebrow">
							{ __( 'Files', 'nfd-site-migrator' ) }
						</p>

						{ data.parts.map( ( part ) => (
							<div className="nfd-sm-pick" key={ part.name }>
								<label
									className="nfd-sm-check"
									htmlFor={ `nfd-sm-part-${ part.name }` }
								>
									<input
										id={ `nfd-sm-part-${ part.name }` }
										type="checkbox"
										checked={ wantsPart( part.name ) }
										disabled={ locked }
										onChange={ () =>
											togglePart( part.name )
										}
									/>
									<span>
										<strong>{ label( part.name ) }</strong>
										<span className="nfd-sm-pick-meta">
											{ part.prefix || '/' }
										</span>
										<span className="nfd-sm-pick-blurb">
											{ PARTS[ part.name ]?.blurb || '' }
										</span>
									</span>
								</label>

								{ wantsPart( part.name ) &&
									part.children.length > 0 && (
										<details className="nfd-sm-details">
											<summary>
												{ sprintf(
													/* translators: %d: number of items inside a part. */
													_n(
														'%d thing inside — leave it out',
														'%d things inside — leave some of them out',
														part.children.length,
														'nfd-site-migrator'
													),
													part.children.length
												) }
											</summary>
											<div className="nfd-sm-pick-sub">
												{ namedChildren( part ).map(
													( child ) => (
														<label
															className="nfd-sm-check"
															key={ child.slug }
															htmlFor={ `nfd-sm-path-${ part.name }-${ child.slug }` }
														>
															<input
																id={ `nfd-sm-path-${ part.name }-${ child.slug }` }
																type="checkbox"
																checked={ wantsPath(
																	part.name,
																	child.slug
																) }
																disabled={
																	locked
																}
																onChange={ () =>
																	togglePath(
																		part.name,
																		child.slug
																	)
																}
															/>
															<span>
																{ child.label }
															</span>
														</label>
													)
												) }
											</div>
											{ part.truncated && (
												<p className="nfd-sm-hint">
													{ __(
														'Only the first few hundred are listed. The rest are packaged.',
														'nfd-site-migrator'
													) }
												</p>
											) }
										</details>
									) }
							</div>
						) ) }
					</div>

					<div className="nfd-sm-card">
						<p className="nfd-sm-eyebrow">
							{ __( 'Database', 'nfd-site-migrator' ) }
						</p>
						<p className="nfd-sm-hint">
							{ __(
								'The database always travels — it is the site. What can go is the parts of it nothing reads.',
								'nfd-site-migrator'
							) }
						</p>

						{ Object.keys( FLAGS ).map( ( flag ) => (
							<label
								className="nfd-sm-check"
								key={ flag }
								htmlFor={ `nfd-sm-flag-${ flag }` }
							>
								<input
									id={ `nfd-sm-flag-${ flag }` }
									type="checkbox"
									checked={ !! selection.database[ flag ] }
									disabled={ locked }
									onChange={ () => toggleFlag( flag ) }
								/>
								<span>
									<strong>{ FLAGS[ flag ].label }</strong>
									<span className="nfd-sm-pick-blurb">
										{ FLAGS[ flag ].blurb }
									</span>
								</span>
							</label>
						) ) }

						{ data.tables.length > 0 && (
							<details className="nfd-sm-details">
								<summary>
									{ __(
										'Tables — leave out one this site does not need',
										'nfd-site-migrator'
									) }
								</summary>
								<div className="nfd-sm-pick-sub">
									{ data.tables.map( ( table ) => (
										<label
											className="nfd-sm-check"
											key={ table.name }
											htmlFor={ `nfd-sm-table-${ table.name }` }
										>
											<input
												id={ `nfd-sm-table-${ table.name }` }
												type="checkbox"
												checked={
													table.required ||
													wantsTable( table.name )
												}
												disabled={
													locked || table.required
												}
												onChange={ () =>
													toggleTable( table.name )
												}
											/>
											<span>
												{ breakable( table.name ) }
												<span className="nfd-sm-pick-meta">
													{ table.required
														? __(
																'always carried',
																'nfd-site-migrator'
														  )
														: size( table.bytes ) }
												</span>
											</span>
										</label>
									) ) }
								</div>
							</details>
						) }
					</div>

					{ ! wantsPart( 'uploads' ) && (
						<div className="nfd-sm-note nfd-sm-note--warn">
							{ __(
								'Without the media library the destination will show broken images wherever a post refers to a file it does not have. That is fine if the files are already there — if you have copied them separately, or the site reads its media from somewhere else — and a surprise otherwise.',
								'nfd-site-migrator'
							) }
						</div>
					) }

					{ ( ! wantsPart( 'plugins' ) ||
						! wantsPart( 'themes' ) ) && (
						<div className="nfd-sm-note nfd-sm-note--warn">
							{ __(
								'The database still lists the plugins and the theme this site runs. The destination will activate what it can find and quietly drop what it cannot, so leave these out only when the other site already has them.',
								'nfd-site-migrator'
							) }
						</div>
					) }

					<div className="nfd-sm-note nfd-sm-note--info">
						{ leaving.length
							? sprintf(
									/* translators: %s: list of things being left out. */
									__(
										'Leaving out: %s. Everything else is packaged.',
										'nfd-site-migrator'
									),
									leaving.join( ', ' )
							  )
							: __(
									'Packaging the whole site.',
									'nfd-site-migrator'
							  ) }
					</div>

					<div className="nfd-sm-actions">
						<button
							type="button"
							className="nfd-sm-btn nfd-sm-btn--primary"
							id="nfd-sm-save-contents"
							disabled={ saving || locked }
							onClick={ () => save( '/export' ) }
						>
							{ saving
								? __( 'Saving…', 'nfd-site-migrator' )
								: __(
										'Save and build the package',
										'nfd-site-migrator'
								  ) }
						</button>

						<button
							type="button"
							className="nfd-sm-btn"
							disabled={ saving || locked }
							onClick={ everything }
						>
							{ __( 'Include everything', 'nfd-site-migrator' ) }
						</button>

						<button
							type="button"
							className="nfd-sm-link"
							onClick={ () => navigate( '/compatibility' ) }
						>
							{ __( 'Back', 'nfd-site-migrator' ) }
						</button>
					</div>
				</>
			) }
		</Layout>
	);
};
