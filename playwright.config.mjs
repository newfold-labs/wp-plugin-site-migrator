/*
 * Playwright config.
 * @see https://playwright.dev/docs/test-configuration
 */
import { defineConfig, devices } from '@playwright/test';
import { existsSync, readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, resolve } from 'path';

const __filename = fileURLToPath( import.meta.url );
const __dirname = dirname( __filename );

// The port wp-env serves on, and the one the local fallback binds to.
const wpEnv = existsSync( './.wp-env.json' )
	? JSON.parse( readFileSync( './.wp-env.json', 'utf8' ) )
	: {};
const port = process.env.NFD_E2E_PORT || wpEnv.port || 10004;

const projects = JSON.parse(
	readFileSync( resolve( __dirname, './tests/playwright/playwright-projects.json' ), 'utf8' )
);

process.env.PLUGIN_DIR = __dirname;
process.env.PLUGIN_ID = 'site-migrator';
process.env.WP_ADMIN_USERNAME = process.env.WP_ADMIN_USERNAME || 'admin';
process.env.WP_ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD || 'password';

/**
 * How the site under test gets served.
 *
 * In CI the workflow starts wp-env itself and Playwright just connects, which is how the other
 * plugins in this org do it — starting a container from inside the test runner makes a failure to
 * start look like a test failure.
 *
 * Locally, `NFD_E2E_SERVER=builtin` provisions with WP-CLI and serves with PHP's own server
 * instead. That exists because wp-env needs Docker, and because PHP's built-in server ignores
 * `.htaccess` exactly as nginx does — which is the only way `Checker::check_storage_reachable()`
 * gets exercised the way it behaves on a real nginx host.
 */
function webServer() {
	if ( process.env.CI ) {
		return undefined;
	}

	if ( 'builtin' === process.env.NFD_E2E_SERVER ) {
		return {
			command: `bash ${ resolve( __dirname, './tests/playwright/serve.sh' ) }`,
			port: Number( port ),
			reuseExistingServer: true,
			timeout: 120 * 1000,
		};
	}

	return {
		command: 'npx wp-env start',
		port: Number( port ),
		reuseExistingServer: true,
		timeout: 120 * 1000,
	};
}

export default defineConfig( {
	globalSetup: resolve( __dirname, './tests/playwright/global-setup.js' ),
	projects,
	use: {
		...devices[ 'Desktop Chrome' ],
		headless: true,
		viewport: { width: 1200, height: 800 },
		baseURL: `http://localhost:${ port }`,
		ignoreHTTPSErrors: true,
		locale: 'en-US',
		contextOptions: {
			reducedMotion: 'reduce',
			strictSelectors: true,
		},
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	webServer: webServer(),
	timeout: 30 * 1000,
	expect: {
		timeout: 10 * 1000,
	},
	// One worker. These tests share a WordPress install, and a migration plugin is all global
	// state — an export lock, a checkpoint on disk, one options row.
	retries: 1,
	workers: 1,
	outputDir: 'tests/playwright/test-results',
	reporter: [ [ 'list', { printSteps: true } ] ],
} );
