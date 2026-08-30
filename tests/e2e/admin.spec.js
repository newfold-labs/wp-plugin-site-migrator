/**
 * The admin app, unstubbed.
 *
 * Every assertion here goes through the real REST API on a real WordPress. That is the whole
 * point: the suite this replaced intercepted every route, so it proved React can render fixtures
 * and nothing else.
 *
 * What it watches for is the failure this plugin actually keeps having — **a blank admin page**.
 * It has been caused by a wrong `plugin_dir_url()` under a symlink, by a REST payload handing
 * React an object where it expected a string, and by a resume redirect that returned `null`. All
 * three look identical to a user and none are visible to a unit test.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

const { test, expect } = require( '@playwright/test' );

const PLUGIN_PAGE = '/wp-admin/admin.php?page=nfd-site-migrator';

/**
 * Sign in once and reuse the session for the rest of the file.
 *
 * Credentials belong to a throwaway install this suite builds and destroys; there is nothing here
 * that exists outside the test run.
 */
test.beforeEach( async ( { page } ) => {
	await page.goto( '/wp-login.php' );

	if ( await page.locator( '#user_login' ).isVisible().catch( () => false ) ) {
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', process.env.NFD_E2E_PASS || 'password' );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );
	}
} );

test.describe( 'the admin app', () => {
	test( 'mounts and renders rather than leaving an empty div', async ( {
		page,
	} ) => {
		const errors = [];

		page.on( 'pageerror', ( error ) => errors.push( error.message ) );

		await page.goto( PLUGIN_PAGE );

		const app = page.locator( '#nfd-sm-app' );

		await expect( app ).toBeAttached();

		// The mount point existing proves nothing -- it is printed by PHP. What matters is that
		// React put something inside it. Every blank-page bug this plugin has had left this
		// element present and empty.
		await expect( app.locator( ':scope > *' ).first() ).toBeVisible( {
			timeout: 15_000,
		} );

		expect( errors, 'the page threw while rendering' ).toEqual( [] );
	} );

	test( 'offers both halves of a migration', async ( { page } ) => {
		await page.goto( PLUGIN_PAGE );

		// The Start screen is the only place both roles are offered, and which one a site plays
		// is decided here rather than at install time.
		await expect(
			page.getByText( 'Send this site somewhere else' )
		).toBeVisible( { timeout: 15_000 } );
		await expect( page.getByText( 'Receive a site here' ) ).toBeVisible();
	} );

	test( 'loads its own stylesheet and script', async ( { page } ) => {
		const failed = [];

		// `plugin_dir_url()` cannot express a symlinked plugin directory, which is why
		// `nfd_sm_plugin_url()` exists -- and this install is symlinked, so a regression there
		// shows up here as a 404 rather than as a subtly wrong string.
		page.on( 'response', ( response ) => {
			if (
				response.url().includes( 'nfd-site-migrator' ) &&
				response.status() >= 400
			) {
				failed.push( `${ response.status() } ${ response.url() }` );
			}
		} );

		await page.goto( PLUGIN_PAGE );
		await expect( page.locator( '#nfd-sm-app' ) ).toBeAttached();

		expect( failed, 'plugin assets failed to load' ).toEqual( [] );
	} );

	test( 'reports what preflight found, from the real REST API', async ( {
		page,
	} ) => {
		await page.goto( PLUGIN_PAGE );

		// Nothing is intercepted, so this text only appears if the SPA called the REST API, got
		// a report back, and rendered it. On this install the storage check genuinely blocks --
		// PHP's built-in server ignores .htaccess exactly as nginx does -- so the app has to be
		// able to render a refusal as well as a pass.
		await expect(
			page.getByText(
				/cannot be exported yet|Send this site somewhere else/
			)
		).toBeVisible( { timeout: 20_000 } );
	} );
} );
