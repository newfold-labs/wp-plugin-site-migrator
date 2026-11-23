import apiFetch from '@wordpress/api-fetch';

const BASE = '/nfd-site-migrator/v1';
const TOKEN_KEY = 'nfd-sm-import-token';

/**
 * Our own nonce middleware, so the nonce can be replaced without a page load.
 *
 * WordPress derives the REST nonce from the acting user and their session. The import replaces
 * the users table, so the nonce the page was rendered with stops validating at the swap — and
 * every later request, ours and wp-admin's, is rejected before it reaches a permission check.
 * The import endpoints hand back a fresh one after each step; this is where it goes.
 */
const nonceMiddleware = apiFetch.createNonceMiddleware(
	window.nfdSiteMigrator?.nonce || ''
);

apiFetch.use( nonceMiddleware );

/**
 * Adopt a nonce minted after the swap.
 *
 * @param {string} nonce The new nonce.
 */
export function updateNonce( nonce ) {
	if ( ! nonce ) {
		return;
	}

	nonceMiddleware.nonce = nonce;

	if ( window.nfdSiteMigrator ) {
		// restEndpoint() reads this for the two URLs that cannot go through apiFetch.
		window.nfdSiteMigrator.nonce = nonce;
	}
}

/**
 * The import token, kept per tab.
 *
 * Halfway through an import the users table is replaced, and the cookie behind these requests
 * can stop authenticating — the merge is allowed to change the acting account's username, and
 * WordPress's auth cookie names the username. The token is what carries the second half of the
 * run. sessionStorage rather than memory so a reload in the same tab picks the run back up;
 * per tab rather than per browser because it is a credential.
 */
export const importToken = {
	get() {
		try {
			return window.sessionStorage.getItem( TOKEN_KEY ) || '';
		} catch ( e ) {
			return '';
		}
	},
	set( value ) {
		try {
			window.sessionStorage.setItem( TOKEN_KEY, value );
		} catch ( e ) {
			// A browser refusing storage is not a reason to fail the import; the run just
			// cannot survive a reload.
		}
	},
	clear() {
		try {
			window.sessionStorage.removeItem( TOKEN_KEY );
		} catch ( e ) {}
	},
};

/**
 * Headers for a request that must work after the swap.
 *
 * @return {Object} Headers, empty when there is no token yet.
 */
function importHeaders() {
	const token = importToken.get();

	return token ? { 'x-nfd-sm-import': token } : {};
}

/**
 * A full URL for a REST route, for the two places that cannot go through apiFetch.
 *
 * `rest_url()` returns one of two shapes depending on the site's permalink setting. With pretty
 * permalinks it is a path — `/wp-json/ns/v1/` — and query arguments start with `?`. With plain
 * permalinks the route is *already* a query argument — `/index.php?rest_route=/ns/v1/` — and a
 * second `?` gets swallowed into the value of the first, so the route stops resolving and
 * WordPress answers "no route was found".
 *
 * Both shapes append the route the same way; only the separator differs.
 *
 * @param {string} route Route below the namespace, e.g. `export/download`.
 * @param {Object} args  Query arguments.
 * @return {string} An absolute URL.
 */
export function restEndpoint( route, args = {} ) {
	const { restRouteUrl, restUrl, nonce = '' } = window.nfdSiteMigrator || {};

	const base =
		restRouteUrl || restUrl || '/?rest_route=/nfd-site-migrator/v1/';
	const url = base + route;
	const query = new URLSearchParams( { ...args, _wpnonce: nonce } );

	return `${ url }${ url.includes( '?' ) ? '&' : '?' }${ query.toString() }`;
}

/**
 * A REST URL for apiFetch that survives the swap.
 *
 * Passing `url` rather than `path` bypasses apiFetch's root, which WordPress fixed to
 * `/wp-json/…` when the page was rendered. An import replaces the permalink structure with the
 * source site's, and if the two differ that root stops resolving — the requests driving the
 * second half of the import start coming back as the site's home page. The `?rest_route=` form
 * does not depend on permalinks, so it cannot be invalidated by the thing it is being used to
 * carry out.
 *
 * @param {string} route Route below the namespace.
 * @return {string} An absolute URL.
 */
function stableUrl( route ) {
	const { restRouteUrl } = window.nfdSiteMigrator || {};

	return restRouteUrl ? restRouteUrl + route : null;
}

/**
 * Call a route by its stable URL, falling back to a path when the root is unknown.
 *
 * @param {string} route   Route below the namespace.
 * @param {Object} options Extra apiFetch options.
 * @return {Promise<Object>} The response, or a failure descriptor.
 */
function stableCall( route, options = {} ) {
	const url = stableUrl( route );

	return call(
		url ? { ...options, url } : { ...options, path: `${ BASE }/${ route }` }
	);
}

/**
 * Every call resolves. Failures come back as `{ failed: true, error }` rather than throwing,
 * because a rejected promise at the top of a screen is what used to leave the admin page blank
 * with nothing in it (finding 2.6).
 *
 * @param {Object} options apiFetch options.
 * @return {Promise<Object>} The response, or a failure descriptor.
 */
async function call( options ) {
	try {
		return await apiFetch( options );
	} catch ( error ) {
		if ( signedOut( error ) && promptLogin() ) {
			return {
				failed: true,
				error: 'Your WordPress session has ended. Sign in again in the box on screen, then try that once more.',
			};
		}

		return {
			failed: true,
			error:
				error?.message ||
				'The site did not respond. It may still be working — try again in a moment.',
		};
	}
}

/**
 * Whether a failure means the browser is no longer signed in.
 *
 * @param {Object} error What apiFetch threw.
 * @return {boolean} True for an expired session or a nonce that belongs to one.
 */
function signedOut( error ) {
	return [
		'rest_cookie_invalid_nonce',
		'rest_not_logged_in',
		'rest_forbidden',
	].includes( error?.code );
}

/**
 * Open WordPress's own "your session has expired" sign-in box.
 *
 * wp-admin loads it on every screen and opens it when the heartbeat notices the session is
 * gone — but the heartbeat asks every couple of minutes, and a button pressed in between used to
 * fail with nothing on screen to say why (T6). This hands it the answer the heartbeat would have.
 *
 * @return {boolean} Whether the box was there to open.
 */
function promptLogin() {
	const $ = window.jQuery;

	if ( ! $ || ! document.getElementById( 'wp-auth-check-wrap' ) ) {
		return false;
	}

	$( document ).trigger( 'heartbeat-tick', [ { 'wp-auth-check': false } ] );

	return true;
}

// Signing back in through that box mints a new REST nonce, which the heartbeat delivers to
// wp.apiFetch's own middleware — not to this one, which is a separate instance. Without this the
// first call after signing in fails on the nonce of the session that just ended.
if ( window.jQuery ) {
	window.jQuery( document ).on( 'heartbeat-tick', ( event, data ) => {
		if ( data?.rest_nonce ) {
			updateNonce( data.rest_nonce );
		}
	} );
}

export const api = {
	preflight: () => call( { path: `${ BASE }/preflight` } ),

	// Keep the migration files outside uploads; answers with a fresh preflight report.
	moveStorage: ( location ) =>
		call( {
			path: `${ BASE }/preflight/storage`,
			method: 'POST',
			data: { location },
		} ),

	// This site's own facts as pasteable text, for a destination the source cannot reach over HTTP.
	ownProfile: () => call( { path: `${ BASE }/preflight/profile` } ),

	compare: ( body ) =>
		call( {
			path: `${ BASE }/preflight/compare`,
			method: 'POST',
			data: body,
		} ),

	// The destination this site is already paired with. Read back rather than re-asked: the
	// pairing code expires in fifteen minutes and lives on the other site.
	destination: {
		get: () => call( { path: `${ BASE }/preflight/destination` } ),
		// Whether the other site still answers. A separate call from `get`, because it crosses
		// the network and `get` runs on every arrival at the screen.
		reach: () => call( { path: `${ BASE }/preflight/destination/reach` } ),
		forget: () =>
			call( {
				path: `${ BASE }/preflight/destination`,
				method: 'DELETE',
			} ),
	},

	pairing: {
		issue: () => call( { path: `${ BASE }/pairing/code`, method: 'POST' } ),
		revoke: () =>
			call( { path: `${ BASE }/pairing/code`, method: 'DELETE' } ),
		status: () => call( { path: `${ BASE }/pairing/status` } ),
	},

	exportState: () => call( { path: `${ BASE }/export/state` } ),
	exportStep: ( signal ) =>
		call( { path: `${ BASE }/export/step`, method: 'POST', signal } ),
	exportPause: ( paused ) =>
		call( {
			path: `${ BASE }/export/pause`,
			method: 'POST',
			data: { paused },
		} ),
	exportContents: () => call( { path: `${ BASE }/export/contents` } ),

	// Asked only when the database has been turned off, because answering it means reading every
	// plugin's PHP -- 43 seconds on a real thirteen-plugin site. Stepped, so each call scans for a
	// few seconds and says where to carry on from. See `ExportController::belongings()`.
	// `skip` is what the picker currently has unticked, sent as repeated `skip[]` because that is
	// what WordPress reads back as an array. A plugin that is not travelling is not asked what it
	// owns.
	exportBelongings: ( cursor = 0, offset = 0, skip = [] ) =>
		call( {
			path:
				`${ BASE }/export/belongings?cursor=${ cursor }&offset=${ offset }` +
				skip
					.map( ( one ) => `&skip[]=${ encodeURIComponent( one ) }` )
					.join( '' ),
		} ),
	exportChoose: ( selection ) =>
		call( {
			path: `${ BASE }/export/contents`,
			method: 'POST',
			data: { selection },
		} ),
	exportManifest: () => call( { path: `${ BASE }/export/manifest` } ),
	exportVerify: () => call( { path: `${ BASE }/export/verify` } ),
	exportCancel: () =>
		call( { path: `${ BASE }/export/cancel`, method: 'POST' } ),

	import: {
		sources: () => stableCall( 'import/sources' ),

		uploadState: ( files, manifest ) =>
			stableCall( 'import/upload/state', {
				method: 'POST',
				data: { files, manifest },
			} ),

		uploadReset: () =>
			stableCall( 'import/upload/reset', { method: 'POST' } ),

		uploadVerify: () =>
			stableCall( 'import/upload/verify', { method: 'POST' } ),

		discard: ( dir ) =>
			stableCall( 'import/discard', {
				method: 'POST',
				data: { dir },
			} ),

		preview: ( dir, fixPhp = false ) =>
			stableCall( 'import/preview', {
				method: 'POST',
				data: { ...( dir ? { dir } : {} ), fix_php: fixPhp },
			} ),

		start: ( dir, mode, fixPhp = false ) =>
			stableCall( 'import/start', {
				method: 'POST',
				data: { dir, mode, fix_php: fixPhp },
			} ),

		step: ( dir, mode ) =>
			stableCall( 'import/step', {
				method: 'POST',
				headers: importHeaders(),
				data: { dir, mode },
			} ),

		state: () => stableCall( 'import/state', { headers: importHeaders() } ),

		rollback: () =>
			stableCall( 'import/rollback', {
				method: 'POST',
				headers: importHeaders(),
			} ),

		confirm: () =>
			stableCall( 'import/confirm', {
				method: 'POST',
				headers: importHeaders(),
			} ),

		cancel: () =>
			stableCall( 'import/cancel', {
				method: 'POST',
				headers: importHeaders(),
			} ),

		// The destination's half of a direct transfer. The key is sent once, on connect, and
		// never comes back out: after this the server holds it and no route returns it.
		pull: {
			connect: ( url, key ) =>
				stableCall( 'import/pull/connect', {
					method: 'POST',
					data: { url, key },
				} ),

			step: ( signal ) =>
				stableCall( 'import/pull/step', { method: 'POST', signal } ),

			state: () => stableCall( 'import/pull/state' ),

			// The standing link pairing left behind. `offer` only asks; `link` claims the key
			// and connects in one request, because they are one decision.
			offer: () => stableCall( 'import/pull/offer' ),

			link: () => stableCall( 'import/pull/link', { method: 'POST' } ),

			stop: () => stableCall( 'import/pull/stop', { method: 'POST' } ),
		},
	},

	// The source's half: mint the key a destination presents, and watch it being used.
	transfer: {
		issue: () => call( { path: `${ BASE }/transfer/key`, method: 'POST' } ),
		revoke: () =>
			call( { path: `${ BASE }/transfer/key`, method: 'DELETE' } ),
		status: () => call( { path: `${ BASE }/transfer/status` } ),

		// The link, and the offer over it. No key is minted until the destination claims one.
		link: () => call( { path: `${ BASE }/transfer/link` } ),
		offer: () =>
			call( { path: `${ BASE }/transfer/link`, method: 'POST' } ),
		withdraw: () =>
			call( { path: `${ BASE }/transfer/link`, method: 'DELETE' } ),
	},

	downloadUrl: ( file ) => restEndpoint( 'export/download', { file } ),
};
