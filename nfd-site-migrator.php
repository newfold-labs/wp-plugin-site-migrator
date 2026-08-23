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
 * Version:           0.1.0
 * Requires PHP:      5.6
 * Requires at least: 4.7
 * Author:            Newfold Labs
 * Author URI:        https://newfold.com/
 * Text Domain:       nfd-site-migrator
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/constants.php';

// Check plugin requirements
global $pagenow;
if ( 'plugins.php' === $pagenow ) {
	$plugin_check = new WP_Forge_Plugin_Check( __FILE__ );

	$plugin_check->min_php_version    = '5.6';
	$plugin_check->min_wp_version     = '4.7';
	$plugin_check->req_php_extensions = array( 'json', 'zlib' );

	$plugin_check->check_plugin_requirements();
}


// Include functions
require_once __DIR__ . DIRECTORY_SEPARATOR . 'functions.php';

register_deactivation_hook( __FILE__, 'nfd_sm_purge_all' );

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
