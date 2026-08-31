/**
 * The admin app, unstubbed.
 *
 * Every assertion goes through the real REST API on a real WordPress. That is the point: the
 * Cypress suite this replaced intercepted every route, so it proved React can render fixtures —
 * and by the end it was clicking element IDs that had not existed in `src/` since the rework.
 *
 * What it watches for is the failure this plugin keeps having: **a blank admin page**. It has been
 * caused by a wrong `plugin_dir_url()` under a symlink, by a REST payload handing React an object
 * where it wanted a string, and by a resume redirect that returned `null`. All three look
 * identical to a user and none are visible to a unit test.
 */

import { test, expect } from '@playwright/test';
import { auth, wordpress, utils } from '../helpers/index.mjs';

test.describe( 'Admin app', () => {
	test.beforeEach( async ( { page } ) => {
		await auth.navigateToAdminPage( page, utils.PLUGIN_PAGE );
	} );

	test( 'mounts and renders rather than leaving an empty div', async ( {
		page,
	} ) => {
		const errors = [];

		page.on( 'pageerror', ( error ) => errors.push( error.message ) );

		await wordpress.waitForApp( page );

		expect( errors, 'the page threw while rendering' ).toEqual( [] );
	} );

	test( 'offers both halves of a migration', async ( { page } ) => {
		await wordpress.waitForApp( page );

		// The Start screen is the only place both roles are offered; which one a site plays is
		// decided here rather than at install time.
		await expect(
			page.getByText( 'Send this site somewhere else' )
		).toBeVisible();
		await expect( page.getByText( 'Receive a site here' ) ).toBeVisible();
	} );

	test( 'loads its own stylesheet, script and fonts', async ( { page } ) => {
		const failed = wordpress.watchPluginAssets( page );

		await page.reload();
		await wordpress.waitForApp( page );

		expect( failed, 'plugin assets failed to load' ).toEqual( [] );
	} );

	test( 'renders what preflight found, from the real REST API', async ( {
		page,
	} ) => {
		await wordpress.waitForApp( page );

		// Nothing is intercepted, so this list only exists if the app called the REST API, got a
		// report back and rendered it.
		//
		// Asserted on the list of checks rather than on any particular verdict, because the
		// verdict legitimately differs by how the site is served: under Apache the `.htaccess`
		// the plugin writes works and the storage check passes, while PHP's built-in server
		// ignores it — exactly as nginx does — and the same check blocks. An assertion on the
		// refusal passed under one server and failed under the other, which is a test measuring
		// its environment rather than the code.
		await expect( page.locator( '.nfd-sm-gates' ) ).toBeVisible();
		await expect( page.locator( '.nfd-sm-gate' ).first() ).toBeVisible();
	} );
} );
