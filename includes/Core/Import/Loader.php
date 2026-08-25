<?php
/**
 * Keeps the importer loaded across the moment it deactivates itself.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

/**
 * A temporary must-use plugin, written at import start and removed at the end.
 *
 * This is the answer to the second problem in §9.1. `active_plugins` is replaced wholesale by
 * the source's list, and the source correctly excluded this plugin from its own archive — so
 * the instant the swap lands, the importer is not in the active list. On the very next request
 * WordPress does not load it, the step endpoint 404s, and an import that was most of the way
 * through simply stops existing.
 *
 * `Fixups` puts the plugin back into `active_plugins`, but that runs *after* the swap, in the
 * same request, and only if that request survives. The mu-plugin covers the gap either way:
 * must-use plugins are loaded from disk with no reference to any option, so nothing the swap
 * does to the database can unload it.
 *
 * It is removed on completion, on rollback, and on cancel. A stale one is harmless — it only
 * loads the plugin that is installed anyway — but leaving litter in `mu-plugins` is not
 * acceptable, so its removal is part of every exit path rather than a cleanup step.
 */
class Loader {

	const NAME = 'nfd-site-migrator-import.php';

	/**
	 * Write the loader.
	 *
	 * @return bool Whether it is now in place.
	 */
	public static function install() {
		$dir = self::dir();

		if ( '' === $dir ) {
			return false;
		}

		if ( ! \is_dir( $dir ) && ! \wp_mkdir_p( $dir ) ) {
			return false;
		}

		$plugin = \nfd_sm_plugin_file();

		// The same file as WordPress addresses it, which differs from the line above whenever
		// the plugin directory is a symlink. Recorded now, while the plugin is loaded the
		// ordinary way and `plugin_basename()` can still be trusted.
		$linked = \rtrim( \WP_PLUGIN_DIR, '/\\' ) . '/' . \nfd_sm_plugin_basename();

		$body = "<?php\n"
			. "/**\n"
			. " * Plugin Name: Site Migrator (import in progress)\n"
			. " * Description: Written automatically while an import runs, and removed when it finishes.\n"
			. " *              Safe to delete if an import was abandoned.\n"
			. " */\n\n"
			. "// The import replaces active_plugins with the source site's list, which does not\n"
			. "// include the migrator. Without this file the importer stops being loaded at the\n"
			. "// exact moment it still has work to do.\n"
			. '$nfd_sm_plugin = ' . \var_export( $plugin, true ) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			. '$nfd_sm_linked = ' . \var_export( $linked, true ) . ";\n\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			. "if ( file_exists( \$nfd_sm_plugin ) && ! function_exists( 'nfd_sm_storage_path' ) ) {\n"
			. "\t// WordPress registers a plugin's symlink only for entries in active_plugins, and\n"
			. "\t// this one is not in it while the import runs. Without the mapping, the plugin's\n"
			. "\t// own directory cannot be expressed as a URL and every asset 404s -- a blank admin\n"
			. "\t// page on the screen where the migration is accepted or undone. Registered before\n"
			. "\t// the require, because constants.php computes the asset URLs as it loads.\n"
			. "\tif ( function_exists( 'wp_register_plugin_realpath' ) ) {\n"
			. "\t\twp_register_plugin_realpath( \$nfd_sm_linked );\n"
			. "\t}\n\n"
			. "\trequire_once \$nfd_sm_plugin;\n"
			. "}\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		return false !== \file_put_contents( self::path(), $body );
	}

	/**
	 * Remove the loader.
	 *
	 * @return void
	 */
	public static function remove() {
		$path = self::path();

		if ( '' !== $path && \file_exists( $path ) ) {
			\unlink( $path );
		}
	}

	/**
	 * Whether the loader is in place.
	 *
	 * @return bool
	 */
	public static function installed() {
		$path = self::path();

		return '' !== $path && \file_exists( $path );
	}

	/**
	 * The mu-plugins directory.
	 *
	 * @return string Empty when this install does not define one.
	 */
	protected static function dir() {
		if ( ! \defined( 'WPMU_PLUGIN_DIR' ) ) {
			return '';
		}

		return \rtrim( \WPMU_PLUGIN_DIR, '/\\' );
	}

	/**
	 * Absolute path to the loader.
	 *
	 * @return string
	 */
	protected static function path() {
		$dir = self::dir();

		return '' === $dir ? '' : $dir . DIRECTORY_SEPARATOR . self::NAME;
	}
}
