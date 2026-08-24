import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { Layout } from '../Layout';
import { api } from '../../utils/api';
import {
	canSaveToFolder,
	downloadOneByOne,
	saveAllToFolder,
} from '../../utils/download';
import { SOURCE_STEPS } from '../../steps';

const mb = ( bytes ) => `${ ( bytes / 1048576 ).toFixed( 1 ) } MB`;

/**
 * The finished package, with per-part download links.
 *
 * @return {Element} The screen.
 */
export const Download = () => {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ progress, setProgress ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ done, setDone ] = useState( '' );
	const stop = useRef( { current: false } );

	useEffect( () => {
		api.exportManifest().then( ( response ) => {
			if ( response.failed ) {
				setError( response.error );
			} else if ( ! response.complete ) {
				setError(
					response.error ||
						__( 'No finished package yet.', 'nfd-site-migrator' )
				);
			} else {
				setData( response );
			}
		} );
	}, [] );

	const files = [];

	if ( data?.package ) {
		// First, and listed first, because the destination reads it to work out what the rest
		// of these files are and where each one belongs.
		files.push( {
			name: 'manifest.json',
			bytes: 0,
			sha256: '',
			kind: __( 'index — download this one', 'nfd-site-migrator' ),
		} );

		const db = data.package.database;
		if ( db?.file ) {
			files.push( {
				name: db.file,
				bytes: db.bytes,
				sha256: db.sha256,
				kind: 'database',
			} );
		}
		( data.package.parts || [] ).forEach( ( p ) =>
			files.push( {
				name: p.file,
				bytes: p.bytes,
				sha256: p.sha256,
				kind: `${ p.files } files`,
			} )
		);
		( data.package.large || [] ).forEach( ( l ) =>
			files.push( {
				name: l.file,
				bytes: l.bytes,
				sha256: l.sha256,
				kind: 'stored as-is',
			} )
		);
	}

	const toFolder = canSaveToFolder();

	const downloadAll = async () => {
		setBusy( true );
		setError( '' );
		setDone( '' );
		setProgress( null );
		stop.current.current = false;

		const run = toFolder ? saveAllToFolder : downloadOneByOne;
		const result = await run( {
			files,
			onProgress: setProgress,
			stop: stop.current,
		} );

		setBusy( false );
		setProgress( null );

		if ( result.error ) {
			setError( result.error );
			return;
		}

		if ( result.ok ) {
			setDone(
				toFolder
					? sprintf(
							/* translators: 1: file count, 2: folder name. */
							__(
								'All %1$d files saved into %2$s.',
								'nfd-site-migrator'
							),
							files.length,
							result.folder
					  )
					: sprintf(
							/* translators: %d: file count. */
							__(
								'All %d downloads started. Check your downloads folder before moving on.',
								'nfd-site-migrator'
							),
							files.length
					  )
			);
		}
	};

	return (
		<Layout
			steps={ SOURCE_STEPS }
			step="download"
			eyebrow={ __( 'Source', 'nfd-site-migrator' ) }
			title={ __( 'Package ready', 'nfd-site-migrator' ) }
			intro={ __(
				'Take these to your computer, then upload them on the destination. A package is many files so that each one stays small enough to move reliably — the button below fetches all of them for you.',
				'nfd-site-migrator'
			) }
		>
			{ error && (
				<div className="nfd-sm-note nfd-sm-note--stop">{ error }</div>
			) }

			{ data && ! data.verified && (
				<div className="nfd-sm-note nfd-sm-note--stop">
					<p>
						{ __(
							'The package does not match its own checksums. Do not use it — export again.',
							'nfd-site-migrator'
						) }
					</p>
					<ul>
						{ data.problems.map( ( p ) => (
							<li key={ p }>{ p }</li>
						) ) }
					</ul>
				</div>
			) }

			{ data && data.verified && (
				<div
					className="nfd-sm-note nfd-sm-note--pass"
					id="nfd-sm-package-verified"
				>
					{ sprintf(
						/* translators: 1: file count, 2: total size. */
						__(
							'%1$d files, %2$s, all checksums verified.',
							'nfd-site-migrator'
						),
						files.length,
						mb( data.package.totals?.bytes || 0 )
					) }
				</div>
			) }

			{ done && (
				<div className="nfd-sm-note nfd-sm-note--pass">{ done }</div>
			) }

			{ files.length > 0 && data?.verified && (
				<div className="nfd-sm-card">
					<div className="nfd-sm-actions">
						<button
							type="button"
							className="nfd-sm-btn nfd-sm-btn--primary"
							id="nfd-sm-download-all"
							disabled={ busy }
							onClick={ downloadAll }
						>
							{ busy
								? __( 'Downloading…', 'nfd-site-migrator' )
								: sprintf(
										/* translators: %d: file count. */
										__(
											'Download all %d files',
											'nfd-site-migrator'
										),
										files.length
								  ) }
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

					{ busy && progress && (
						<>
							<div className="nfd-sm-progress">
								<div
									className="nfd-sm-progress-bar"
									style={ {
										width: `${ Math.round(
											( ( progress.index +
												( progress.bytes
													? progress.sent /
													  progress.bytes
													: 1 ) ) /
												progress.total ) *
												100
										) }%`,
									} }
								/>
							</div>
							<p
								className="nfd-sm-hint"
								id="nfd-sm-download-all-status"
							>
								{ sprintf(
									/* translators: 1: current file number, 2: total, 3: file name. */
									__(
										'%1$d of %2$d · %3$s',
										'nfd-site-migrator'
									),
									progress.index + 1,
									progress.total,
									progress.name
								) }
							</p>
						</>
					) }

					<p className="nfd-sm-hint">
						{ toFolder
							? __(
									'You will be asked to choose a folder. Everything lands inside it with the structure the destination expects, so you can hand it that folder as it is.',
									'nfd-site-migrator'
							  )
							: __(
									'Your browser will ask whether this site may download several files. Say yes, and keep them together in one folder afterwards.',
									'nfd-site-migrator'
							  ) }
					</p>
				</div>
			) }

			{ files.length > 0 && (
				<table className="nfd-sm-parts">
					<thead>
						<tr>
							<th>{ __( 'File', 'nfd-site-migrator' ) }</th>
							<th>{ __( 'Size', 'nfd-site-migrator' ) }</th>
							<th>{ __( 'Checksum', 'nfd-site-migrator' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ files.map( ( f ) => (
							<tr key={ f.name }>
								<td className="nfd-sm-mono">{ f.name }</td>
								<td className="nfd-sm-mono nfd-sm-num">
									{ f.bytes ? mb( f.bytes ) : '—' }
								</td>
								<td className="nfd-sm-mono nfd-sm-hash">
									{ f.sha256
										? `${ f.sha256.slice( 0, 8 ) }…`
										: '—' }
								</td>
								<td>
									<a
										className="nfd-sm-btn"
										href={ api.downloadUrl( f.name ) }
									>
										{ __(
											'Download',
											'nfd-site-migrator'
										) }
									</a>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<div className="nfd-sm-note nfd-sm-note--info">
				{ __(
					'However you fetch them, keep them together in one folder. On the destination you can hand it the whole folder at once and it will put everything back where it belongs. Individual downloads below resume if interrupted.',
					'nfd-site-migrator'
				) }
			</div>

			<div className="nfd-sm-note nfd-sm-note--info">
				{ __(
					'Very large site? Instead of downloading and uploading through the browser, copy these files straight into wp-content/uploads/nfd-site-migrator/ on the destination over FTP. It will find them on its own.',
					'nfd-site-migrator'
				) }
			</div>
		</Layout>
	);
};
