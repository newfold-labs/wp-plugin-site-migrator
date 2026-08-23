<?php
/**
 * The set of parts a WordPress site is packaged into.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Export;

use NewfoldLabs\WP\SiteMigrator\Core\Package\PartSpec;

/**
 * Builds the part list. The one place that knows WordPress's directory layout.
 *
 * Every prefix is derived from where a directory actually is, relative to the WordPress root,
 * rather than assumed to be `wp-content/<name>`. Plugins, themes and uploads can all be
 * relocated, and a package that records the wrong path restores files to the wrong place.
 */
class PartSpecs {

	/**
	 * Top-level files worth carrying, as a closed allowlist.
	 *
	 * WordPress core is deliberately absent: the destination already has it. So is
	 * `wp-config.php`, which must never leave the server — and now cannot reach a package at
	 * all, because this is an allowlist rather than a walk with exclusions (finding 2.4).
	 *
	 * @var array
	 */
	protected static $root_allowlist = array(
		'.htaccess',
		'robots.txt',
		'ads.txt',
		'app-ads.txt',
		'favicon.ico',
		'browserconfig.xml',
		'BingSiteAuth.xml',
		'google-site-verification.html',
	);

	/**
	 * Drop-ins WordPress recognises in wp-content.
	 *
	 * @var array
	 */
	protected static $dropins = array(
		'advanced-cache.php',
		'db.php',
		'db-error.php',
		'install.php',
		'maintenance.php',
		'object-cache.php',
		'php-error.php',
		'fatal-error-handler.php',
	);

	/**
	 * Build the ordered list of parts.
	 *
	 * @return array List of PartSpec.
	 */
	public static function all() {
		$content = \rtrim( WP_CONTENT_DIR, '/\\' );
		$specs   = array();
		$covered = array();

		$plugins = self::first_dir( \nfd_sm_plugins_dir() );

		if ( '' !== $plugins ) {
			$spec = new PartSpec( 'plugins', $plugins, self::prefix_for( $plugins ) );
			// The migrator excludes itself: a package that restores this plugin would overwrite
			// the running importer mid-import.
			$spec->exclude_dirs( array( NFD_SM_PLUGIN_NAME ) );
			$specs[]   = $spec;
			$covered[] = $plugins;
		}

		// WordPress supports more than one theme root via register_theme_directory(), so this
		// is an array. Taking only the first would silently drop themes.
		$theme_roots = \nfd_sm_themes_dir();
		$theme_roots = \is_array( $theme_roots ) ? $theme_roots : array( $theme_roots );
		$index       = 0;

		foreach ( $theme_roots as $root ) {
			$root = \rtrim( (string) $root, '/\\' );

			if ( '' === $root || ! \is_dir( $root ) ) {
				continue;
			}

			++$index;
			$name      = 1 === $index ? 'themes' : 'themes-' . $index;
			$specs[]   = new PartSpec( $name, $root, self::prefix_for( $root ) );
			$covered[] = $root;
		}

		$mu = self::first_dir( \nfd_sm_mu_plugins_dir() );

		if ( '' !== $mu ) {
			$specs[]   = new PartSpec( 'mu-plugins', $mu, self::prefix_for( $mu ) );
			$covered[] = $mu;
		}

		$uploads = self::first_dir( \nfd_sm_uploads_dir() );

		if ( '' !== $uploads ) {
			$specs[]   = new PartSpec( 'uploads', $uploads, self::prefix_for( $uploads ) );
			$covered[] = $uploads;
		}

		$dropins = new PartSpec( 'dropins', $content, self::prefix_for( $content ) );
		$dropins->only( self::$dropins );
		$specs[] = $dropins;

		// Everything under wp-content that no other part claims. Without this,
		// wp-content/languages/ and any custom directory are silently dropped.
		$other = new PartSpec( 'content-other', $content, self::prefix_for( $content ) );
		$other->exclude_dirs( self::relative_children( $content, $covered ) );
		// Drop-ins have their own part; without this they are collected twice and stored twice.
		$other->exclude_files( self::$dropins );
		$specs[] = $other;

		$root_extras = new PartSpec( 'root-extras', \rtrim( ABSPATH, '/\\' ), '' );
		$root_extras->only( self::$root_allowlist );
		$specs[] = $root_extras;

		/**
		 * Filter the parts a package is built from.
		 *
		 * @param array $specs List of PartSpec.
		 */
		return \apply_filters( 'nfd_sm_part_specs', $specs );
	}

	/**
	 * Normalise a helper's return value to a single existing directory.
	 *
	 * The directory helpers return a string, an array, or null depending on which one and
	 * whether the directory exists.
	 *
	 * @param mixed $value Helper return value.
	 *
	 * @return string Empty when there is no usable directory.
	 */
	protected static function first_dir( $value ) {
		if ( \is_array( $value ) ) {
			$value = \reset( $value );
		}

		$value = \rtrim( (string) $value, '/\\' );

		return ( '' !== $value && \is_dir( $value ) ) ? $value : '';
	}

	/**
	 * Where a directory sits relative to the WordPress root.
	 *
	 * @param string $dir Absolute directory.
	 *
	 * @return string Relative path with forward slashes, empty for the root itself.
	 */
	protected static function prefix_for( $dir ) {
		$abspath = \rtrim( ABSPATH, '/\\' );

		if ( $dir === $abspath ) {
			return '';
		}

		$relative = \nfd_sm_relative_path( $abspath, $dir );

		// A directory outside the WordPress root cannot be expressed relative to it. Fall back
		// to its own name so the files are still carried and land somewhere predictable.
		if ( '' === $relative || false !== \strpos( $relative, '..' ) ) {
			return \basename( $dir );
		}

		return $relative;
	}

	/**
	 * Which of the given directories are immediate children of a parent.
	 *
	 * Used to tell `content-other` what another part already covers. A directory that lives
	 * outside wp-content is not excluded here, because it cannot collide.
	 *
	 * @param string $base     Absolute parent directory.
	 * @param array  $children Absolute directories.
	 *
	 * @return array Names relative to the parent.
	 */
	protected static function relative_children( $base, array $children ) {
		$names   = array();
		$storage = \rtrim( \nfd_sm_storage_path(), '/\\' );

		foreach ( \array_merge( $children, array( $storage ) ) as $child ) {
			$child = \rtrim( (string) $child, '/\\' );

			if ( '' === $child ) {
				continue;
			}

			$relative = \nfd_sm_relative_path( $base, $child );

			if ( '' === $relative || false !== \strpos( $relative, '..' ) || $child === $relative ) {
				continue;
			}

			$names[] = $relative;
		}

		return \array_values( \array_unique( $names ) );
	}

	/**
	 * The root allowlist.
	 *
	 * @return array
	 */
	public static function root_allowlist() {
		return self::$root_allowlist;
	}
}
