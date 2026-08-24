import { api, restEndpoint } from './api';

/**
 * Push a package to the destination in slices.
 *
 * Two things make this more than a loop over `fetch`. The server decides the chunk size, from
 * its own `post_max_size` — guessing it is the difference between an upload that works and a
 * 413 nobody can interpret. And every chunk declares the byte offset it belongs at, so a
 * resumed upload continues from what is actually on disk rather than from anything this tab
 * remembers.
 */

/**
 * Where a selected file belongs inside the package.
 *
 * Two ways in, because there are two ways people end up holding a package. Choosing the folder
 * gives every file a relative path already, which is exact. Choosing loose files — what you
 * have after downloading each part from the source — gives only names, so they are matched
 * against the paths the manifest says it expects. `plugins.zip` is unambiguous; the manifest
 * knows it lives at `parts/plugins.zip`.
 *
 * @param {File}  file     The selected file.
 * @param {Array} expected Relative paths the manifest declares.
 * @return {string} Relative path, or '' when it does not belong to this package.
 */
export function placeFile( file, expected ) {
	const relative = ( file.webkitRelativePath || '' )
		.split( '/' )
		.slice( 1 )
		.join( '/' );

	if ( relative && expected.includes( relative ) ) {
		return relative;
	}

	if ( 'manifest.json' === file.name ) {
		return 'manifest.json';
	}

	const matches = expected.filter(
		( path ) => path.split( '/' ).pop() === file.name
	);

	// Exactly one candidate, or none. A basename that matches two expected paths is ambiguous,
	// and guessing which one it is would put a file somewhere plausible and wrong.
	return 1 === matches.length ? matches[ 0 ] : '';
}

/**
 * Every file a manifest says a package contains.
 *
 * @param {Object} manifest Parsed manifest.json.
 * @return {Array} Relative paths.
 */
export function expectedFiles( manifest ) {
	const files = [ 'manifest.json' ];

	if ( manifest?.database?.file ) {
		files.push( manifest.database.file );
	}

	( manifest?.parts || [] ).forEach(
		( p ) => p.file && files.push( p.file )
	);
	( manifest?.large || [] ).forEach(
		( l ) => l.file && files.push( l.file )
	);

	return files;
}

/**
 * Read a File as text.
 *
 * @param {File} file The file.
 * @return {Promise<string>} Its contents.
 */
export function readText( file ) {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = () => resolve( reader.result );
		reader.onerror = () => reject( reader.error );
		reader.readAsText( file );
	} );
}

/**
 * Send one file, continuing from whatever the server already has.
 *
 * @param {Object}   options
 * @param {string}   options.path       Relative path within the package.
 * @param {File}     options.file       The file to send.
 * @param {number}   options.offset     Bytes the server already holds.
 * @param {number}   options.chunkSize  Bytes per request.
 * @param {Function} options.onProgress Called with bytes sent since the last call.
 * @param {Object}   options.stop       `{ current: boolean }`; set to abandon the upload.
 * @return {Promise<Object>} `{ ok, error }`.
 */
async function sendFile( { path, file, offset, chunkSize, onProgress, stop } ) {
	let sent = offset;

	while ( sent < file.size ) {
		if ( stop.current ) {
			return { ok: false, error: '' };
		}

		const slice = file.slice(
			sent,
			Math.min( sent + chunkSize, file.size )
		);

		// Raw body rather than FormData: multipart is governed by upload_max_filesize, which on
		// the hosts this exists for is the smaller of the two limits, and it base64-inflates
		// bytes that are already binary.
		const url = restEndpoint( 'import/upload/chunk', {
			path,
			offset: sent,
		} );

		let response;

		try {
			response = await fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/octet-stream' },
				body: slice,
			} );
		} catch ( e ) {
			return {
				ok: false,
				error: `The connection dropped while sending ${ path }. It will carry on from here if you try again.`,
			};
		}

		if ( ! response.ok ) {
			let message = `${ path }: the server refused a chunk (HTTP ${ response.status }).`;

			try {
				const body = await response.json();
				if ( body?.message ) {
					message = `${ path }: ${ body.message }`;
				}
			} catch ( e ) {
				// A 413 from a proxy is often HTML, not JSON. The status is the useful part.
				if ( 413 === response.status ) {
					message = `${ path }: the server rejected a chunk as too large. Its limit may be lower than it reports.`;
				}
			}

			return { ok: false, error: message };
		}

		sent += slice.size;
		onProgress( slice.size );
	}

	return { ok: true, error: '' };
}

/**
 * Upload a whole package.
 *
 * @param {Object}   options
 * @param {Array}    options.files      `{ path, file }` pairs.
 * @param {number}   options.chunkSize  Bytes per request.
 * @param {Function} options.onProgress Called with `{ sent, total, path }`.
 * @param {Object}   options.stop       `{ current: boolean }`.
 * @return {Promise<Object>} `{ ok, error }`.
 */
export async function uploadPackage( { files, chunkSize, onProgress, stop } ) {
	const state = await api.import.uploadState( files.map( ( f ) => f.path ) );

	if ( state.failed ) {
		return { ok: false, error: state.error };
	}

	const total = files.reduce( ( sum, f ) => sum + f.file.size, 0 );

	const received = state.received || {};
	const size = state.chunk_size || chunkSize;

	// Clamped per file, not against the total: a stale byte count for one file would otherwise
	// be allowed to fill the whole progress bar.
	let sent = files.reduce(
		( sum, f ) => sum + Math.min( received[ f.path ] || 0, f.file.size ),
		0
	);

	onProgress( { sent, total, path: '' } );

	for ( const entry of files ) {
		const already = Math.min(
			received[ entry.path ] || 0,
			entry.file.size
		);

		if ( already >= entry.file.size ) {
			continue;
		}

		const result = await sendFile( {
			path: entry.path,
			file: entry.file,
			offset: already,
			chunkSize: size,
			stop,
			onProgress: ( bytes ) => {
				sent += bytes;
				onProgress( { sent, total, path: entry.path } );
			},
		} );

		if ( ! result.ok ) {
			return result;
		}
	}

	return { ok: true, error: '' };
}
