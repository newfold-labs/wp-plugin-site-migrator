/**
 * WordPress authentication for the browser suite.
 *
 * Credentials come from the environment and belong to a throwaway install that this suite builds
 * and destroys. `WP_ADMIN_USERNAME` and `WP_ADMIN_PASSWORD` are the names wp-env uses, so the same
 * variables work whichever way the site under test was provisioned.
 */

/**
 * Whether a session is already established.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {Promise<boolean>} True when signed in.
 */
async function isLoggedIn( page ) {
	return page
		.locator( '#wpadminbar' )
		.isVisible()
		.catch( () => false );
}

/**
 * Sign in, unless already signed in.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 */
async function login( page ) {
	await page.goto( '/wp-login.php' );

	// A live session redirects straight past the form, so there is nothing to fill in.
	if ( ! ( await page.locator( '#user_login' ).isVisible().catch( () => false ) ) ) {
		return;
	}

	await page.fill( '#user_login', process.env.WP_ADMIN_USERNAME || 'admin' );
	await page.fill( '#user_pass', process.env.WP_ADMIN_PASSWORD || 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

/**
 * Sign in and open an admin page.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @param {string}                          path Admin path, e.g. `admin.php?page=…`.
 */
async function navigateToAdminPage( page, path ) {
	if ( ! ( await isLoggedIn( page ) ) ) {
		await login( page );
	}

	await page.goto( `/wp-admin/${ path }` );
}

export default { isLoggedIn, login, navigateToAdminPage };
