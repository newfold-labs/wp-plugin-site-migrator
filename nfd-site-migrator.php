<?php
/**
 * Site Migrator
 *
 * @package           NewfoldLabs\WP\SiteMigrator
 * @author            Newfold Labs
 * @copyright         Copyright 2020-2026 Newfold Digital, Inc.
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Site Migrator
 * Plugin URI:        https://github.com/newfold-labs/wp-plugin-site-migrator
 * Description:       Move a WordPress site between hosts. Export this site to a package, or import one exported from elsewhere.
 * Version:           0.2.5
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * Author:            Newfold Labs
 * Author URI:        https://newfold.com/
 * Text Domain:       nfd-site-migrator
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

// Nothing below this is loaded on a PHP older than the header promises, and the check is
// deliberately the first statement in the file. WordPress enforces `Requires PHP` when a plugin is
// activated or updated through the admin, and nowhere else: a plugin that is *already* active is
// loaded on whatever PHP the server is running today, so a host downgrade, a restored backup, or
// files replaced over FTP or a file manager all put this code in front of a PHP it was never
// written for. The first line any of it cannot compile is then a fatal error on every request,
// wp-admin included -- which is a locked-out site with no clue on the screen, and the plugin cannot
// even be switched off from the plugins page to clear it. A refusal that names the version is
// recoverable; a white screen is not.
//
// Keep this block compilable by every PHP a WordPress has ever run on. It is the one piece of the
// plugin that has to parse on a version the rest of it does not support, so nothing here may use
// syntax newer than the floor it is checking for.
if ( PHP_VERSION_ID < 70400 ) {

	/**
	 * Say why the plugin did not load.
	 *
	 * Declared inside the guard because the file returns before anything else is defined, so this
	 * is the only thing the site gets from the plugin and it cannot collide with the real one.
	 *
	 * @return void
	 */
	function nfd_sm_php_version_notice() {
		echo '<div class="notice notice-error"><p><strong>Site Migrator</strong> '
			. 'needs PHP 7.4 or newer and this server is running PHP '
			. esc_html( PHP_VERSION ) . '. The plugin has not loaded; nothing else on the site is '
			. 'affected. Ask your host to update PHP, or deactivate the plugin.</p></div>';
	}

	add_action( 'admin_notices', 'nfd_sm_php_version_notice' );

	return;
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/constants.php';

// Check plugin requirements. These have to agree with the header above: WordPress enforces
// `Requires PHP` and `Requires at least` itself on activation, and this adds the extension check
// it has no equivalent for. `zip` is deliberately not on the list. It was, to stop a host without
// it activating cleanly and failing partway through the first export -- but this check
// *deactivates* the plugin on every visit to the Plugins screen, which also shut out a destination
// that never needed zip: `ZipReader` unpacks a package with zlib, and `ZipWriter` builds one with
// it. A host with neither is still refused by `Checker::check_zip()` before anything is written,
// which is the failure the list was there to prevent.
global $pagenow;
if ( 'plugins.php' === $pagenow ) {
	$plugin_check = new WP_Forge_Plugin_Check( __FILE__ );

	$plugin_check->min_php_version    = '7.4';
	$plugin_check->min_wp_version     = '5.8';
	$plugin_check->req_php_extensions = array( 'json', 'zlib' );

	$plugin_check->check_plugin_requirements();
}


// Include functions
require_once __DIR__ . DIRECTORY_SEPARATOR . 'functions.php';

// Deactivation is reversible, so it takes nothing with it but a transient. It used to call
// `nfd_sm_purge_all()`, which recursively deletes the storage directory -- switching the plugin
// off to see whether it was the cause destroyed the package the user had just built, and on a
// destination mid-import took the checkpoint `rollback()` needs along with it. Removing data is
// `uninstall.php`'s job, and it refuses while a migration is still undecided.
register_deactivation_hook( __FILE__, 'nfd_sm_flush_state' );

// Initialize the Admin page
new NewfoldLabs\WP\SiteMigrator\WP_Admin();

// Register the REST routes
NewfoldLabs\WP\SiteMigrator\Rest\Routes::register();

// Initialize options
NewfoldLabs\WP\SiteMigrator\Utils\Options::fetch();

// Register the WP-CLI harness. Second consumer of Core/, so the transport boundary is
// enforced by a real caller and not only by a lint rule.
NewfoldLabs\WP\SiteMigrator\Cli\Commands::register();

// persist options on shutdown
add_action( 'shutdown', array( 'NewfoldLabs\WP\SiteMigrator\Utils\Options', 'maybe_persist' ) );
