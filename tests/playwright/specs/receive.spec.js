/**
 * The destination's first screen, which is also where it waits.
 *
 * `/receive` mints the pairing code, and until it joined the destination journey it was a dead
 * end: a user handed over a code and the screen stood still while the package that arrived for
 * them announced itself somewhere they had no reason to look. It now polls the link for an offer
 * and turns into the button that starts the transfer, which makes it the screen most able to
 * break in the way this suite exists to catch — it fetches on mount, on an interval, and renders
 * a different heading depending on the answer.
 *
 * The offer itself needs a second site, so what is asserted here is the half that can be: the
 * screen mounts, it is on the map, and with nothing on offer it shows the code rather than a
 * start button for a transfer that does not exist.
 */

import { test, expect } from '@playwright/test';
import { auth, wordpress, utils } from '../helpers/index.mjs';

const RECEIVE = `${ utils.PLUGIN_PAGE }#/receive`;

test.describe( 'Receive', () => {
	test( 'mounts, and is a step of the destination journey', async ( {
		page,
	} ) => {
		const errors = [];

		page.on( 'pageerror', ( error ) => errors.push( error.message ) );

		await auth.navigateToAdminPage( page, RECEIVE );
		await wordpress.waitForApp( page );

		await expect( page.locator( '#nfd-sm-issue-code' ) ).toBeVisible();

		// The stepper is the thing that was missing: this screen rendered outside both journeys,
		// so the plugin's own map did not contain the first thing a destination does.
		await expect(
			page.locator( '.nfd-sm-journey' ).getByText( 'Connect' )
		).toBeVisible();

		expect( errors ).toEqual( [] );
	} );

	test( 'shows a code, and no transfer to start', async ( { page } ) => {
		await auth.navigateToAdminPage( page, RECEIVE );
		await wordpress.waitForApp( page );

		await page.locator( '#nfd-sm-issue-code' ).click();

		await expect( page.locator( '#nfd-sm-pairing-code' ) ).toBeVisible();
		await expect( page.locator( '#nfd-sm-pairing-code' ) ).not.toBeEmpty();

		// Nothing is being offered to this site, so the waiting room must not claim otherwise.
		// The poll has had a turn by now -- it runs on mount, before the click above.
		await expect( page.locator( '#nfd-sm-start-transfer' ) ).toHaveCount(
			0
		);
	} );
} );
