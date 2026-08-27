import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import { Layout } from '../../Layout';
import { Loading } from '../../Loading';
import { api } from '../../../utils/api';
import {
	expectedFiles,
	placeFile,
	readText,
	uploadPackage,
} from '../../../utils/upload';
import { DESTINATION_STEPS } from '../../../steps';

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
 * Choose a package: upload one, or use one already on the server.
 *
 * The upload is sliced because the hosts this plugin exists for cap a single request at 8MB and
 * a package is routinely gigabytes. The folder already on the server is the escape hatch for
 * when even that is unreasonable — put it there over FTP and this finds it.
 *
 * @return {Element} The screen.
 */
export const Choose = () => {
	const navigate = useNavigate();
	const stop = useRef( { current: false } );
	const picker = useRef( null );
	const folderPicker = useRef( null );

	const [ sources, setSources ] = useState( null );
	const [ selected, setSelected ] = useState( null );
	const [ progress, setProgress ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	// The path whose delete has been asked for but not yet confirmed, and the one being
	// deleted. Two steps rather than a browser confirm dialog: this removes a whole site from
	// the disk, and the sentence explaining that has nowhere to go in a native prompt.
	const [ confirming, setConfirming ] = useState( '' );
	const [ discarding, setDiscarding ] = useState( '' );

	// Directory selection is not a React property, and the two attributes that enable it differ
	// by browser. Set on the node itself so neither is dropped on the way through JSX.
	useEffect( () => {
		const node = folderPicker.current;

		if ( node ) {
			node.setAttribute( 'webkitdirectory', '' );
			node.setAttribute( 'directory', '' );
			node.setAttribute( 'mozdirectory', '' );
		}
	}, [] );

	useEffect( () => {
		api.import.sources().then( ( response ) => {
			if ( response.failed ) {
				setError( response.error );
			} else {
				setSources( response );
			}
		} );

		const flag = stop.current;

		return () => {
			flag.current = true;
		};
	}, [] );

	/**
	 * Delete a package that is already on this server.
	 *
	 * @param {string} path Absolute package directory.
	 */
	const discard = async ( path ) => {
		setDiscarding( path );
		setError( '' );

		const response = await api.import.discard( path );

		setDiscarding( '' );
		setConfirming( '' );

		if ( response.failed ) {
			setError( response.error );
			return;
		}

		setSources( ( current ) => ( {
			...current,
			discovered: response.discovered,
		} ) );
	};

	/**
	 * Work out what was selected, and whether it is a whole package.
	 *
	 * @param {FileList} list Selected files.
	 */
	const inspect = async ( list ) => {
		setError( '' );
		setSelected( null );

		const chosen = Array.from( list || [] );
		const manifestFile = chosen.find( ( f ) => 'manifest.json' === f.name );

		if ( ! manifestFile ) {
			setError(
				__(
					'That selection has no manifest.json in it. Pick the whole package folder, or include the manifest.json you downloaded alongside the other files.',
					'nfd-site-migrator'
				)
			);
			return;
		}

		let manifest;

		try {
			manifest = JSON.parse( await readText( manifestFile ) );
		} catch ( e ) {
			setError(
				__(
					'The manifest.json in that selection could not be read. It may have been altered or only partly downloaded.',
					'nfd-site-migrator'
				)
			);
			return;
		}

		const expected = expectedFiles( manifest );
		const matched = [];
		const extra = [];

		chosen.forEach( ( file ) => {
			const path = placeFile( file, expected );

			if ( path ) {
				matched.push( { path, file } );
			} else {
				extra.push( file.name );
			}
		} );

		const have = matched.map( ( m ) => m.path );
		const missing = expected.filter( ( path ) => ! have.includes( path ) );

		setSelected( {
			manifest,
			files: matched,
			missing,
			extra,
			bytes: matched.reduce( ( sum, m ) => sum + m.file.size, 0 ),
		} );
	};

	const upload = async () => {
		setBusy( true );
		setError( '' );
		stop.current.current = false;

		const result = await uploadPackage( {
			files: selected.files,
			chunkSize: sources?.chunk_size || 1048576,
			stop: stop.current,
			onProgress: setProgress,
		} );

		if ( ! result.ok ) {
			setBusy( false );
			setError(
				result.error || __( 'The upload stopped.', 'nfd-site-migrator' )
			);
			return;
		}

		const check = await api.import.uploadVerify();

		setBusy( false );

		if ( check.failed || ! check.ok ) {
			setError(
				check.failed
					? check.error
					: sprintf(
							/* translators: %s: list of problems. */
							__(
								'The uploaded package does not match its own checksums: %s',
								'nfd-site-migrator'
							),
							( check.problems || [] ).join( ' ' )
					  )
			);
			return;
		}

		navigate( '/import/review' );
	};

	const openDiscovered = ( path ) => {
		navigate( `/import/review?dir=${ encodeURIComponent( path ) }` );
	};

	const percent = progress?.total
		? Math.min(
				100,
				Math.round( ( progress.sent / progress.total ) * 100 )
		  )
		: 0;

	return (
		<Layout
			steps={ DESTINATION_STEPS }
			safetyDetail={ __(
				'Nothing is imported until you have seen what it would do. This site is unchanged.',
				'nfd-site-migrator'
			) }
			step="choose"
			eyebrow={ __( 'Destination', 'nfd-site-migrator' ) }
			title={ __( 'Bring in a package', 'nfd-site-migrator' ) }
			intro={ __(
				'Nothing on this site changes yet. You will see exactly what the package would do before anything is written.',
				'nfd-site-migrator'
			) }
		>
			{ error && (
				<div className="nfd-sm-note nfd-sm-note--stop">{ error }</div>
			) }

			{ ! sources && ! error && (
				<div className="nfd-sm-card">
					<Loading>
						{ __(
							'Looking for a package already on this server…',
							'nfd-site-migrator'
						) }
					</Loading>
				</div>
			) }

			<div className="nfd-sm-card">
				<p className="nfd-sm-eyebrow">
					{ __( 'Fetch it from the source', 'nfd-site-migrator' ) }
				</p>
				<p className="nfd-sm-hint">
					{ __(
						'If the site you are moving from can be reached over the internet, this site downloads the package straight from it — nothing goes through your computer, and an interrupted transfer picks up where it stopped. Generate a transfer key there first.',
						'nfd-site-migrator'
					) }
				</p>
				<div className="nfd-sm-actions">
					<button
						type="button"
						className="nfd-sm-btn nfd-sm-btn--primary"
						id="nfd-sm-go-pull"
						onClick={ () => navigate( '/import/pull' ) }
					>
						{ __( 'Fetch from the source', 'nfd-site-migrator' ) }
					</button>
				</div>
			</div>

			{ sources?.discovered?.length > 0 && (
				<div className="nfd-sm-card">
					<p className="nfd-sm-eyebrow">
						{ __( 'Already on this server', 'nfd-site-migrator' ) }
					</p>
					<p className="nfd-sm-hint">
						{ __(
							'Found without uploading anything. This is the quickest route for a large site.',
							'nfd-site-migrator'
						) }
					</p>
					<ul className="nfd-sm-list">
						{ sources.discovered.map( ( found ) => (
							<li key={ found.path }>
								<div>
									<strong>
										{ found.source || found.label }
									</strong>
									<span className="nfd-sm-muted">
										{ ' · ' }
										{ size( found.bytes ) }
										{ found.created_at
											? ` · ${ found.created_at.slice(
													0,
													10
											  ) }`
											: '' }
										{ found.source &&
										found.source === sources.site_url
											? ` · ${ __(
													'made by this site',
													'nfd-site-migrator'
											  ) }`
											: '' }
									</span>
									<div className="nfd-sm-mono nfd-sm-hint">
										{ found.path }
									</div>
									{ confirming === found.path && (
										<p className="nfd-sm-hint">
											{ sprintf(
												/* translators: %s: package size, e.g. "2.2 GB". */
												__(
													'Delete this package and free %s? It holds a whole site, and nothing here can bring it back.',
													'nfd-site-migrator'
												),
												size( found.bytes )
											) }
										</p>
									) }
								</div>
								{ confirming === found.path ? (
									<div className="nfd-sm-actions">
										<button
											type="button"
											className="nfd-sm-btn"
											disabled={ '' !== discarding }
											onClick={ () =>
												setConfirming( '' )
											}
										>
											{ __(
												'Keep it',
												'nfd-site-migrator'
											) }
										</button>
										<button
											type="button"
											className="nfd-sm-btn nfd-sm-btn--danger"
											disabled={ '' !== discarding }
											onClick={ () =>
												discard( found.path )
											}
										>
											{ discarding === found.path
												? __(
														'Deleting…',
														'nfd-site-migrator'
												  )
												: __(
														'Delete it',
														'nfd-site-migrator'
												  ) }
										</button>
									</div>
								) : (
									<div className="nfd-sm-actions">
										<button
											type="button"
											className="nfd-sm-btn"
											onClick={ () =>
												setConfirming( found.path )
											}
										>
											{ __(
												'Delete',
												'nfd-site-migrator'
											) }
										</button>
										<button
											type="button"
											className="nfd-sm-btn"
											onClick={ () =>
												openDiscovered( found.path )
											}
										>
											{ __(
												'Use this',
												'nfd-site-migrator'
											) }
										</button>
									</div>
								) }
							</li>
						) ) }
					</ul>
				</div>
			) }

			<div className="nfd-sm-card">
				<p className="nfd-sm-eyebrow">
					{ __( 'Upload from this computer', 'nfd-site-migrator' ) }
				</p>

				<input
					ref={ folderPicker }
					type="file"
					className="nfd-sm-hidden-input"
					multiple
					onChange={ ( e ) => inspect( e.target.files ) }
				/>
				<input
					ref={ picker }
					type="file"
					className="nfd-sm-hidden-input"
					multiple
					onChange={ ( e ) => inspect( e.target.files ) }
				/>

				<div className="nfd-sm-actions">
					<button
						type="button"
						className="nfd-sm-btn nfd-sm-btn--primary"
						disabled={ busy }
						onClick={ () => folderPicker.current?.click() }
					>
						{ __(
							'Choose the package folder',
							'nfd-site-migrator'
						) }
					</button>
					<button
						type="button"
						className="nfd-sm-btn"
						disabled={ busy }
						onClick={ () => picker.current?.click() }
					>
						{ __( 'Choose files instead', 'nfd-site-migrator' ) }
					</button>
				</div>

				<p className="nfd-sm-hint">
					{ __(
						'Uploads are sent in small pieces and resume where they stopped, so a dropped connection is not a lost upload.',
						'nfd-site-migrator'
					) }
				</p>
			</div>

			{ selected && (
				<div className="nfd-sm-card" id="nfd-sm-selection">
					<p className="nfd-sm-eyebrow">
						{ __( 'Selected', 'nfd-site-migrator' ) }
					</p>
					<p>
						{ sprintf(
							/* translators: 1: site URL, 2: file count, 3: total size. */
							__(
								'%1$s — %2$d files, %3$s',
								'nfd-site-migrator'
							),
							selected.manifest?.source?.site_url ||
								__( 'Unknown source', 'nfd-site-migrator' ),
							selected.files.length,
							size( selected.bytes )
						) }
					</p>

					{ selected.missing.length > 0 && (
						<div className="nfd-sm-note nfd-sm-note--stop">
							<p>
								{ __(
									'Some of the package is missing. Add these and select again — an incomplete package cannot be imported.',
									'nfd-site-migrator'
								) }
							</p>
							<ul>
								{ selected.missing.map( ( m ) => (
									<li key={ m } className="nfd-sm-mono">
										{ m }
									</li>
								) ) }
							</ul>
						</div>
					) }

					{ selected.extra.length > 0 && (
						<p className="nfd-sm-hint">
							{ sprintf(
								/* translators: %d: file count. */
								__(
									'%d selected file(s) are not part of this package and will be ignored.',
									'nfd-site-migrator'
								),
								selected.extra.length
							) }
						</p>
					) }

					{ busy && (
						<div className="nfd-sm-progress">
							<div
								className="nfd-sm-progress-bar"
								style={ { width: `${ percent }%` } }
							/>
							<p className="nfd-sm-hint">
								{ sprintf(
									/* translators: 1: percent, 2: sent, 3: total, 4: current file. */
									__(
										'%1$d%% — %2$s of %3$s%4$s',
										'nfd-site-migrator'
									),
									percent,
									size( progress?.sent || 0 ),
									size( progress?.total || 0 ),
									progress?.path
										? ` · ${ progress.path }`
										: ''
								) }
							</p>
						</div>
					) }

					<div className="nfd-sm-actions">
						<button
							type="button"
							className="nfd-sm-btn nfd-sm-btn--primary"
							id="nfd-sm-upload"
							disabled={ busy || selected.missing.length > 0 }
							onClick={ upload }
						>
							{ busy
								? __( 'Uploading…', 'nfd-site-migrator' )
								: __( 'Upload it', 'nfd-site-migrator' ) }
						</button>
						{ busy && (
							<button
								type="button"
								className="nfd-sm-btn"
								onClick={ () => {
									stop.current.current = true;
								} }
							>
								{ __( 'Stop', 'nfd-site-migrator' ) }
							</button>
						) }
					</div>
				</div>
			) }
		</Layout>
	);
};
