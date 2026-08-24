import { api } from './api';

/**
 * Take a whole package to the user's computer in one action.
 *
 * A package is many files on purpose — volumes are bounded so a single zip close fits inside a
 * shared host's execution limit, and each one downloads and resumes independently. That is the
 * right shape for the server and a miserable one for the person clicking Download sixty times.
 *
 * There is deliberately no "download it all as one zip" on the server. The package is already
 * compressed, so re-zipping it would spend the whole site's size in disk and time to produce a
 * file that is no smaller, cannot be resumed, and has to be unpacked again at the other end.
 * The work is done here instead, where the loop is free.
 */

/**
 * Whether this browser can write into a folder the user picks.
 *
 * @return {boolean} True when the File System Access API is available.
 */
export const canSaveToFolder = () =>
	'function' === typeof window.showDirectoryPicker;

/**
 * Walk to (and create) a subdirectory inside the chosen folder.
 *
 * @param {Object} root  Directory handle.
 * @param {Array}  parts Directory names.
 * @return {Promise<Object>} The innermost directory handle.
 */
async function folderFor( root, parts ) {
	let here = root;

	for ( const part of parts ) {
		// eslint-disable-next-line no-await-in-loop
		here = await here.getDirectoryHandle( part, { create: true } );
	}

	return here;
}

/**
 * Copy every file into a folder the user picks, one at a time.
 *
 * Streamed through to disk rather than buffered: a part can be a hundred megabytes and a
 * loosely stored file can be far larger than that, and holding one in memory to save it would
 * fail on exactly the sites that need this most.
 *
 * The folder structure is preserved, because the destination's folder picker reads it — hand it
 * this folder and every file is already where the manifest says it should be.
 *
 * @param {Object}   options
 * @param {Array}    options.files      Package files, as `{ name }`.
 * @param {Function} options.onProgress Called with `{ index, total, name, sent, bytes }`.
 * @param {Object}   options.stop       `{ current: boolean }`, set to true to abandon.
 * @return {Promise<Object>} `{ ok, error, folder }`.
 */
export async function saveAllToFolder( { files, onProgress, stop } ) {
	let root;

	try {
		root = await window.showDirectoryPicker( { mode: 'readwrite' } );
	} catch ( e ) {
		// Dismissing the picker is a decision, not a failure.
		return { ok: false, cancelled: true };
	}

	for ( let i = 0; i < files.length; i++ ) {
		if ( stop.current ) {
			return { ok: false, cancelled: true };
		}

		const file = files[ i ];
		const parts = file.name.split( '/' );
		const base = parts.pop();

		try {
			/* eslint-disable no-await-in-loop */
			const dir = await folderFor( root, parts );
			const handle = await dir.getFileHandle( base, { create: true } );
			const writer = await handle.createWritable();

			const response = await fetch( api.downloadUrl( file.name ), {
				credentials: 'same-origin',
			} );

			if ( ! response.ok || ! response.body ) {
				await writer.abort();

				return {
					ok: false,
					error: `${ file.name }: the server answered ${ response.status }.`,
				};
			}

			const reader = response.body.getReader();
			let sent = 0;

			for (;;) {
				const { done, value } = await reader.read();

				if ( done ) {
					break;
				}

				if ( stop.current ) {
					await reader.cancel();
					await writer.abort();

					return { ok: false, cancelled: true };
				}

				await writer.write( value );
				sent += value.length;

				onProgress( {
					index: i,
					total: files.length,
					name: file.name,
					sent,
					bytes: file.bytes,
				} );
			}

			await writer.close();
			/* eslint-enable no-await-in-loop */
		} catch ( e ) {
			return { ok: false, error: `${ file.name }: ${ e.message }` };
		}
	}

	return { ok: true, folder: root.name };
}

/**
 * Hand every file to the browser's own downloader, in order.
 *
 * The fallback for browsers without the File System Access API. Everything lands in the
 * downloads folder as a flat list of names, which the destination's file picker can still take,
 * and the browser asks once whether this site may download several files. There is no way to
 * observe when each one finishes, so this reports what it has *started* rather than pretending
 * to know more than it does.
 *
 * @param {Object}   options
 * @param {Array}    options.files      Package files, as `{ name }`.
 * @param {Function} options.onProgress Called with `{ index, total, name }`.
 * @param {Object}   options.stop       `{ current: boolean }`, set to true to abandon.
 * @return {Promise<Object>} `{ ok }`.
 */
export async function downloadOneByOne( { files, onProgress, stop } ) {
	for ( let i = 0; i < files.length; i++ ) {
		if ( stop.current ) {
			return { ok: false, cancelled: true };
		}

		const file = files[ i ];
		const link = document.createElement( 'a' );

		link.href = api.downloadUrl( file.name );
		link.download = file.name.split( '/' ).pop();
		link.rel = 'noopener';
		document.body.appendChild( link );
		link.click();
		link.remove();

		onProgress( { index: i, total: files.length, name: file.name } );

		// Spaced out because a burst of navigations is what makes a browser decide the page is
		// misbehaving and silently drop the rest.
		// eslint-disable-next-line no-await-in-loop
		await new Promise( ( resolve ) => setTimeout( resolve, 900 ) );
	}

	return { ok: true };
}
