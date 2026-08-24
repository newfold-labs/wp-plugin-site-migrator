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
		return {
			failed: true,
			error:
				error?.message ||
				'The site did not respond. It may still be working — try again in a moment.',
		};
	}
}

export const api = {
	preflight: () => call( { path: `${ BASE }/preflight` } ),

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
	exportManifest: () => call( { path: `${ BASE }/export/manifest` } ),
	exportVerify: () => call( { path: `${ BASE }/export/verify` } ),
	exportCancel: () =>
		call( { path: `${ BASE }/export/cancel`, method: 'POST' } ),

	import: {
		sources: () => stableCall( 'import/sources' ),

		uploadState: ( files ) =>
			stableCall( 'import/upload/state', {
				method: 'POST',
				data: { files },
			} ),

		uploadReset: () =>
			stableCall( 'import/upload/reset', { method: 'POST' } ),

		uploadVerify: () =>
			stableCall( 'import/upload/verify', { method: 'POST' } ),

		preview: ( dir ) =>
			stableCall( 'import/preview', {
				method: 'POST',
				data: dir ? { dir } : {},
			} ),

		start: ( dir, mode ) =>
			stableCall( 'import/start', {
				method: 'POST',
				data: { dir, mode },
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
	},

	downloadUrl: ( file ) => restEndpoint( 'export/download', { file } ),
};
