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

		// Both values exist to be carried to another machine, and for a while the only way to
		// take them was to select the text by hand -- on the one screen whose whole job is
		// handing two strings over.
		await expect( page.locator( '#nfd-sm-copy-url' ) ).toBeVisible();
		await expect( page.locator( '#nfd-sm-copy-code' ) ).toBeVisible();
	} );

	test( 'copies on an origin with no clipboard API', async ( { page } ) => {
		// `navigator.clipboard` exists only in a secure context. A WordPress site being migrated
		// is very often neither HTTPS nor localhost -- `http://something.local` under Local, a
		// staging box on plain HTTP -- and there the property is `undefined`, so the call threw
		// before any permission was considered and the `catch` around it stayed quiet. Both copy
		// buttons did nothing at all, on a real site, while passing here.
		//
		// They passed here because the site under test is served at `http://localhost:8888`, and
		// localhost *is* a secure context: the one origin where the broken path cannot happen.
		// So the API is removed on purpose, which is the only way this suite can stand where the
		// user was standing.
		await page.addInitScript( () => {
			Object.defineProperty( window.navigator, 'clipboard', {
				value: undefined,
				configurable: true,
			} );
		} );

		await auth.navigateToAdminPage( page, RECEIVE );
		await wordpress.waitForApp( page );

		expect(
			await page.evaluate( () => typeof window.navigator.clipboard )
		).toBe( 'undefined' );

		await page.locator( '#nfd-sm-issue-code' ).click();
		await expect( page.locator( '#nfd-sm-pairing-code' ) ).not.toBeEmpty();

		// "Copied" is the button reporting that the fallback returned true. The label is the
		// whole point: before this the button had no state to move to, in either direction.
		await page.locator( '#nfd-sm-copy-code' ).click();

		await expect( page.locator( '#nfd-sm-copy-code' ) ).toHaveText(
			'Copied'
		);
	} );

	test( 'can write its own profile down for a source that cannot reach it', async ( {
		page,
	} ) => {
		await auth.navigateToAdminPage( page, RECEIVE );
		await wordpress.waitForApp( page );

		// The source's pairing screen has always offered "paste a profile instead" for a
		// destination behind a firewall, and told the user to copy it from this screen. This
		// screen showed nothing to copy: `SiteProfile::encode()` had no caller anywhere, so the
		// one way through for an unreachable destination was an instruction pointing at a
		// control that did not exist.
		const fallback = page.locator( '#nfd-sm-profile-fallback' );

		await expect( fallback ).toBeVisible();

		await fallback.evaluate( ( node ) => {
			node.open = true;
		} );

		await page.locator( '#nfd-sm-show-profile' ).click();

		const blob = page.locator( '#nfd-sm-profile-blob' );

		await expect( blob ).toBeVisible();
		await expect( blob ).toHaveText( /^NFDSM1-/ );

		// And it is the thing the other half actually takes. Asserted through the REST API the
		// source would call, because a blob this site cannot read back is the same bug in a new
		// place.
		const text = ( await blob.innerText() ).trim();

		const accepted = await page.evaluate(
			( profile ) =>
				window.wp.apiFetch( {
					path: '/nfd-site-migrator/v1/preflight/compare',
					method: 'POST',
					data: { profile },
				} ),
			text
		);

		expect( accepted.ok, accepted.error || '' ).not.toBe( false );
	} );
} );
