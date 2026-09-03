<?php
/**
 * What deleting this plugin takes with it.
 *
 * WordPress runs this file when the plugin is deleted, which is the only moment a plugin is
 * entitled to remove a user's data. Deactivation used to do it -- `register_deactivation_hook`
 * pointed straight at `nfd_sm_purge_all()`, so switching the plugin off to check whether it was
 * causing something recursively deleted `uploads/nfd-site-migrator/`, taking with it a package
 * that may have been an hour and several gigabytes in the making. There was no warning and no way
 * back.
 *
 * The work is in `nfd_sm_uninstall()` so that it can be tested and so the multisite branch has
 * somewhere to live: on a network every site has its own uploads directory and its own options
 * row, and a network-activated plugin leaves one of each per site rather than one per network.
 *
 * The one thing this still refuses to do is delete the state of a migration nobody has decided
 * about yet. A finished-but-undecided import's `nfdold_` backup tables are the only copy of the
 * site as it was, and `Importer::rollback()` reads the checkpoint on disk to know how to put them
 * back; a run still in flight may be one statement short of the swap. In either case the files
 * here are what stands between the site and being stuck as it is. Options and the transient go
 * either way -- they are small, and nothing reads them once the code is gone.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

// Called by WordPress, and by nothing else.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$nfd_sm_autoload = __DIR__ . '/vendor/autoload.php';

// A checkout without `composer install` has no autoloader, and a plugin being deleted is a poor
// place to fatal. Without it the guard below cannot read the checkpoint, so this stops rather
// than deleting on an assumption it could not check.
if ( ! is_readable( $nfd_sm_autoload ) ) {
	return;
}

require_once $nfd_sm_autoload;
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/functions.php';

nfd_sm_uninstall();
