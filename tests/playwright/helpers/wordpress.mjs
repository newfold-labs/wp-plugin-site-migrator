/**
 * WordPress-shaped assertions and waits.
 */

/**
 * Wait for the plugin's React app to have rendered something.
 *
 * The mount point existing proves nothing — it is printed by PHP, and every blank-page bug this
 * plugin has had left it present and empty. What matters is a child inside it.
 *
 * @param {import('@playwright/test').Page} page    Playwright page.
 * @param {number}                          timeout Milliseconds.
 * @return {import('@playwright/test').Locator} The app root.
 */
async function waitForApp( page, timeout = 20000 ) {
	const app = page.locator( '#nfd-sm-app' );

	await app.waitFor( { state: 'attached', timeout } );
	await app.locator( ':scope > *' ).first().waitFor( { state: 'visible', timeout } );

	return app;
}

/**
 * Collect requests for this plugin's own assets that failed.
 *
 * `plugin_dir_url()` cannot express a symlinked plugin directory, which is why
 * `nfd_sm_plugin_url()` exists. A regression there shows up here as a 404.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {Array} Mutated as responses arrive.
 */
function watchPluginAssets( page ) {
	const failed = [];

	page.on( 'response', ( response ) => {
		if ( response.url().includes( 'nfd-site-migrator' ) && response.status() >= 400 ) {
			failed.push( `${ response.status() } ${ response.url() }` );
		}
	} );

	return failed;
}

export default { waitForApp, watchPluginAssets };
