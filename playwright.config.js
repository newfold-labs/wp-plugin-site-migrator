/**
 * Playwright, driving a real WordPress with the real plugin and the real REST API.
 *
 * The suite this replaced stubbed every route with `cy.intercept`, so it asserted that React can
 * render fixtures. That cannot fail for any reason the plugin is responsible for — and by the end
 * it was clicking element IDs that had not existed in `src/` since the rework. Nothing here is
 * mocked: `globalSetup` installs WordPress, `webServer` serves it, and the tests click.
 *
 * The site is provisioned by a shell script rather than by wp-env, for the same reason the round
 * trip is: this project already needs `wp` and a database, and adding Docker on top buys nothing.
 */

const { defineConfig, devices } = require( '@playwright/test' );
const path = require( 'path' );
const os = require( 'os' );

const PORT = process.env.NFD_E2E_PORT || 8781;

// Deliberately outside the repository. The plugin is symlinked into the site, so a site built
// *inside* the repo makes the plugin directory contain the site that contains the plugin — the
// asset URLs come out recursive, and the exporter would happily package a WordPress install into
// its own test fixture.
const SITE =
	process.env.NFD_E2E_SITE ||
	path.join( os.tmpdir(), 'nfd-sm-e2e', 'site' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	testMatch: '**/*.spec.js',

	// One worker. These tests share a single WordPress install, and a migration plugin is all
	// global state — an export lock, a checkpoint on disk, one options row.
	workers: 1,
	fullyParallel: false,

	// Failing loudly in CI and not at all locally is how a suite rots.
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,

	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : [ [ 'list' ] ],

	use: {
		baseURL: `http://127.0.0.1:${ PORT }`,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],

	// PHP's built-in server is enough: WordPress runs on it, and it is one process to start and
	// stop rather than a container to keep healthy. It also ignores `.htaccess`, which is a useful
	// accident — it is how `Checker::check_storage_reachable()` gets exercised the way nginx would.
	//
	// The command provisions before it serves, because Playwright starts `webServer` *before*
	// `globalSetup`: a global setup that builds the site would run after the server had already
	// failed to find one.
	webServer: {
		command: `bash ${ path.join( __dirname, 'tests', 'e2e', 'serve.sh' ) } ${ SITE }`,
		url: `http://127.0.0.1:${ PORT }/wp-login.php`,
		reuseExistingServer: ! process.env.CI,
		timeout: 60_000,
		stdout: 'ignore',
		stderr: 'pipe',
	},
} );
