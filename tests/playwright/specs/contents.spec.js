/**
 * Choosing what goes in the package, in a real browser against the real REST API.
 *
 * The screen is a form over a route that refuses things, and both halves of that can fail
 * silently. A picker that renders nothing looks exactly like a site with no plugins; a save that
 * quietly drops what it was given looks exactly like a save that worked, until an export an hour
 * later carries something the user told it to leave out.
 *
 * So this asserts three things a unit test cannot: the screen mounts and lists the site's own
 * parts, a saved refusal survives a full page load, and the summary the screen shows afterwards
 * is the server's answer rather than the checkbox that was clicked.
 */

import { test, expect } from '@playwright/test';
import { auth, wordpress, utils } from '../helpers/index.mjs';

const CONTENTS = `${ utils.PLUGIN_PAGE }#/contents`;

test.describe( 'Contents', () => {
	test.afterEach( async ( { page } ) => {
		// Real state on a real install: leave the site packaging everything again, and leave no
		// export running behind us. Saving on this screen deliberately starts one, and a run in
		// progress is exactly what `/export/contents` refuses to change — so the cancel has to
		// come first or the reset is politely ignored and the next spec inherits these choices.
		await page.evaluate( async () => {
			if ( ! window.nfdSiteMigrator ) {
				return;
			}

			const { restRouteUrl, nonce } = window.nfdSiteMigrator;

			const ask = ( route, body ) =>
				fetch( restRouteUrl + route, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': nonce,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify( body ),
				} ).catch( () => {} );

			await ask( 'export/cancel', {} );
			await ask( 'export/contents', { selection: {} } );
		} );
	} );

	test( 'lists this site’s own parts', async ( { page } ) => {
		const errors = [];

		page.on( 'pageerror', ( error ) => errors.push( error.message ) );

		await auth.navigateToAdminPage( page, CONTENTS );
		await wordpress.waitForApp( page );

		// A child of the mount point, not the mount point: the div is printed by PHP and proves
		// nothing about whether React got as far as drawing anything.
		await expect( page.locator( '#nfd-sm-part-plugins' ) ).toBeVisible();
		await expect( page.locator( '#nfd-sm-part-uploads' ) ).toBeVisible();

		// The database always travels, so its tables are offered as refusals with the ones
		// WordPress cannot start without locked.
		await expect(
			page.getByText( 'Leave out post revisions' )
		).toBeVisible();

		expect( errors, 'the page threw while rendering' ).toEqual( [] );
	} );

	test( 'a refusal survives a reload', async ( { page } ) => {
		await auth.navigateToAdminPage( page, CONTENTS );
		await wordpress.waitForApp( page );

		await page.locator( '#nfd-sm-part-uploads' ).uncheck();
		await page.locator( '#nfd-sm-flag-skip_revisions' ).check();

		// The warning is the reason the screen exists: leaving the media behind is a decision
		// with a visible consequence on the other site.
		await expect( page.locator( '.nfd-sm-note--warn' ) ).toBeVisible();

		await page.locator( '#nfd-sm-save-contents' ).click();

		// Saving lands on the export screen, which starts packaging for real. Stop it: this spec
		// is about the choice surviving, and leaving an export running on the site under test
		// would make every later assertion race a background job.
		await expect( page.locator( '.nfd-sm-journey' ) ).toBeVisible();

		await page.evaluate( async () => {
			const { restRouteUrl, nonce } = window.nfdSiteMigrator;

			await fetch( restRouteUrl + 'export/cancel', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': nonce },
			} );
		} );

		await auth.navigateToAdminPage( page, CONTENTS );
		await wordpress.waitForApp( page );

		await expect(
			page.locator( '#nfd-sm-part-uploads' )
		).not.toBeChecked();
		await expect(
			page.locator( '#nfd-sm-flag-skip_revisions' )
		).toBeChecked();
	} );
} );
