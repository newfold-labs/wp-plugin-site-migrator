/**
 * Runs once before the suite.
 *
 * Playwright starts `webServer` *before* this, so anything that has to exist for the server to
 * boot belongs in the server command rather than here. What is left is site configuration.
 */

import { execSync } from 'child_process';
import utils from './helpers/utils.mjs';

/**
 * Set the permalink structure the suite expects.
 *
 * Deliberately **plain** permalinks, which is the default on a fresh install and common on the
 * shared hosts this plugin exists for. It is also the harder case: a site on plain permalinks
 * serves only `?rest_route=` and answers `/wp-json/…` with a redirect to its home page, which
 * arrives as HTML and reads as "the plugin is not installed there" (finding 3.17). Testing the
 * pretty-permalink case would quietly stop covering that.
 */
export default async function globalSetup() {
	if (
		'builtin' === process.env.NFD_E2E_SERVER ||
		process.env.NFD_E2E_SKIP_SETUP
	) {
		return;
	}

	try {
		execSync( "npx wp-env run cli -- wp rewrite structure ''", {
			stdio: 'inherit',
			timeout: 60000,
		} );
	} catch ( error ) {
		utils.log(
			`Could not set the permalink structure: ${ error.message }`,
			'red'
		);
		throw error;
	}
}
