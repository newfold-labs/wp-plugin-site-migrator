import apiFetch from '@wordpress/api-fetch';

const BASE = '/nfd-site-migrator/v1';

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

	pairing: {
		issue: () => call( { path: `${ BASE }/pairing/code`, method: 'POST' } ),
		revoke: () =>
			call( { path: `${ BASE }/pairing/code`, method: 'DELETE' } ),
		status: () => call( { path: `${ BASE }/pairing/status` } ),
	},

	exportState: () => call( { path: `${ BASE }/export/state` } ),
	exportStep: () => call( { path: `${ BASE }/export/step`, method: 'POST' } ),
	exportManifest: () => call( { path: `${ BASE }/export/manifest` } ),
	exportCancel: () =>
		call( { path: `${ BASE }/export/cancel`, method: 'POST' } ),

	downloadUrl: ( file ) => {
		const { restUrl = '/wp-json/nfd-site-migrator/v1/', nonce = '' } =
			window.nfdSiteMigrator || {};

		return (
			`${ restUrl }export/download` +
			`?file=${ encodeURIComponent( file ) }` +
			`&_wpnonce=${ encodeURIComponent( nonce ) }`
		);
	},
};
