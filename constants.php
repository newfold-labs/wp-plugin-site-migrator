<?php

define( 'NFD_SM_VERSION', '0.1.0' );
define( 'NFD_SM_CIPHER_NAME', 'AES-256-CBC' );
define( 'NFD_SM_PLUGIN_NAME', 'nfd-site-migrator' );
define( 'NFD_SM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NFD_SM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// Build output is unversioned on purpose: a versioned path meant the plugin header,
// package.json and the built directory could drift and silently stop enqueueing.
// Cache busting comes from the content hash in nfd-site-migrator.asset.php.
define( 'NFD_SM_PLUGIN_BUILD_DIR', plugin_dir_path( __FILE__ ) . 'build' );
define( 'NFD_SM_PLUGIN_BUILD_URL', plugin_dir_url( __FILE__ ) . 'build' );

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

if ( ! defined( 'NFD_SM_PACKAGING_STATUS_OPTION' ) ) {
	define( 'NFD_SM_PACKAGING_STATUS_OPTION', 'nfd_site_migrator_packaging_status' );
}

if ( ! defined( 'NFD_SM_PACKAGING_SUCCESS_OPTION' ) ) {
	define( 'NFD_SM_PACKAGING_SUCCESS_OPTION', 'nfd_site_migrator_packaged_success' );
}

if ( ! defined( 'NFD_SM_PACKAGING_FAILED_OPTION' ) ) {
	define( 'NFD_SM_PACKAGING_FAILED_OPTION', 'nfd_site_migrator_packaged_failed' );
}

if ( ! defined( 'NFD_SM_OPTIONS_LIST' ) ) {
	define(
		'NFD_SM_OPTIONS_LIST',
		array(
			NFD_SM_OPTION_NAME,
			NFD_SM_PACKAGING_STATUS_OPTION,
			NFD_SM_PACKAGING_FAILED_OPTION,
			NFD_SM_PACKAGING_SUCCESS_OPTION,
		)
	);
}

if ( ! defined( 'NFD_SM_CAN_MIGRATE_TRANSIENT' ) ) {
	define( 'NFD_SM_CAN_MIGRATE_TRANSIENT', 'nfd_site_migrator_can_migrate' );
}
