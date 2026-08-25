<?php

define( 'NFD_SM_VERSION', '0.1.0' );
define( 'NFD_SM_PLUGIN_NAME', 'nfd-site-migrator' );
define( 'NFD_SM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
// Build output is unversioned on purpose: a versioned path meant the plugin header,
// package.json and the built directory could drift and silently stop enqueueing.
// Cache busting comes from the content hash in nfd-site-migrator.asset.php.
define( 'NFD_SM_PLUGIN_BUILD_DIR', plugin_dir_path( __FILE__ ) . 'build' );

// There is deliberately no URL constant to match. A URL for this plugin's own directory cannot
// be computed while it is loading: `plugin_dir_url()` resolves a symlinked plugin directory
// only through a mapping wp-settings.php registers later, and when the import's mu-plugin
// loader is what included us, that mapping never arrives at all. Use `nfd_sm_plugin_url()`,
// which answers at the moment it is asked.

if ( ! defined( 'NFD_SM_MAX_TRANSACTION_QUERIES' ) ) {
	define( 'NFD_SM_MAX_TRANSACTION_QUERIES', 1000 );
}

if ( ! defined( 'NFD_SM_SELECT_RECORDS' ) ) {
	define( 'NFD_SM_SELECT_RECORDS', 1000 );
}

if ( ! defined( 'NFD_SM_STAGE_DATABASE' ) ) {
	define( 'NFD_SM_STAGE_DATABASE', 'database' );
}

if ( ! defined( 'NFD_SM_OPTION_NAME' ) ) {
	define( 'NFD_SM_OPTION_NAME', 'nfd_site_migrator' );
}

// Deliberately its own option rather than a key inside NFD_SM_OPTION_NAME. That one is read
// once per request and written back whole on shutdown, so a long export step — which is exactly
// when somebody presses Pause — would persist a copy of the array it read minutes earlier and
// undo the pause it never saw.
if ( ! defined( 'NFD_SM_PAUSED_OPTION' ) ) {
	define( 'NFD_SM_PAUSED_OPTION', 'nfd_site_migrator_paused' );
}

if ( ! defined( 'NFD_SM_OPTIONS_LIST' ) ) {
	define(
		'NFD_SM_OPTIONS_LIST',
		array(
			NFD_SM_OPTION_NAME,
			NFD_SM_PAUSED_OPTION,
		)
	);
}

if ( ! defined( 'NFD_SM_CAN_MIGRATE_TRANSIENT' ) ) {
	define( 'NFD_SM_CAN_MIGRATE_TRANSIENT', 'nfd_site_migrator_can_migrate' );
}
