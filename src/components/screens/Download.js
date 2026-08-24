import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Layout } from '../Layout';
import { api } from '../../utils/api';

const mb = ( bytes ) => `${ ( bytes / 1048576 ).toFixed( 1 ) } MB`;

/**
 * The finished package, with per-part download links.
 *
 * @return {Element} The screen.
 */
export const Download = () => {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );

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

	return (
		<Layout
			eyebrow={ __( 'Step 5 · Source', 'nfd-site-migrator' ) }
			title={ __( 'Package ready', 'nfd-site-migrator' ) }
			intro={ __(
				'Download these to your computer, then upload them on the destination. Downloads resume if interrupted, and you can do them one at a time.',
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
					'Keep them together in one folder. On the destination you can hand it the whole folder at once, and it will put everything back where it belongs.',
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
