/**
 * The compatibility screen's re-check, against a destination that is not there.
 *
 * Found live: a source paired with a destination days earlier, the destination went away, and
 * Check again answered "Ready to migrate". Nothing was lying -- the verdict is recomputed from
 * facts the destination reported once, and re-reading those facts needs a fresh pairing code, so
 * the whole round trip stayed inside this site and could not fail. A button that cannot fail is
 * not a check, and this is the screen the user is on when deciding whether to start.
 *
 * Unit tests cover `Pairing::reach()` itself. What they cannot cover is the half that was
 * actually broken: a UI that had no error state at all, and swallowed the transport failure it
 * did get. That only shows up by clicking the button.
 */

import { test, expect } from '@playwright/test';
import { auth, wordpress, utils } from '../helpers/index.mjs';

// Port 9 is discard. Nothing listens, so the connection is refused immediately rather than
// spending the request timeout -- which keeps this test measuring the plugin and not the clock.
const NOWHERE = 'http://127.0.0.1:9';

/**
 * Pair this site with a destination that does not exist.
 *
 * Built from this site's own profile so it is complete, current and schema-correct, with the
 * address swapped for one nothing answers on. The pasted route is the one way to establish a
 * destination without a second WordPress to mint a code.
 *
 * @param {import('@playwright/test').Page} page    Playwright page.
 * @param {string}                          address Where the destination claims to be.
 * @return {Promise<Object>} What `/preflight/compare` answered.
 */
async function pairWithNowhere( page, address ) {
	return page.evaluate( async ( url ) => {
		const { restRouteUrl, nonce } = window.nfdSiteMigrator;

		const ask = ( route, options = {} ) =>
			fetch( restRouteUrl + route, {
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': nonce,
					'Content-Type': 'application/json',
				},
				...options,
			} ).then( ( response ) => response.json() );

		const { profile } = await ask( 'preflight' );

		profile.site_url = url;
		profile.home_url = url;

		const json = JSON.stringify( profile );
		const blob =
			'NFDSM1-' +
			btoa( String.fromCharCode( ...new TextEncoder().encode( json ) ) );

		return ask( 'preflight/compare', {
			method: 'POST',
			body: JSON.stringify( { profile: blob } ),
		} );
	}, address );
}

test.describe( 'Compatibility', () => {
	test( 'says so when the destination cannot be reached', async ( {
		page,
	} ) => {
		await auth.navigateToAdminPage( page, utils.PLUGIN_PAGE );
		await wordpress.waitForApp( page );

		const paired = await pairWithNowhere( page, NOWHERE );

		expect( paired.ok, 'the fixture failed to pair' ).toBe( true );

		// A full load, not a hash change: the bundle does not re-run for the latter, which is
		// its own long-standing trap on these screens.
		await auth.navigateToAdminPage(
			page,
			`${ utils.PLUGIN_PAGE }#/compatibility`
		);
		await wordpress.waitForApp( page );

		// The verdict is drawn before anything crosses the network, and that is not the bug --
		// it is the remembered reading, honestly labelled.
		await expect( page.locator( '.nfd-sm-gates' ) ).toBeVisible();
		await expect( page.locator( '.nfd-sm-note--stop' ) ).toHaveCount( 0 );

		await page.getByRole( 'button', { name: 'Check again' } ).click();

		await expect(
			page.getByText( 'Could not reach the destination' )
		).toBeVisible();

		// And it must not turn a network failure into a migration failure: a package can still
		// be built, downloaded here and uploaded there by hand.
		await expect( page.locator( '#nfd-sm-build-package' ) ).toBeEnabled();
	} );
} );
