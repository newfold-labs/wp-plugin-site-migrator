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
	 * Directory names that are never site content, wherever they appear.
	 *
	 * Version control metadata is the big one and the reason this list exists: a plugin
	 * installed by checking it out, or a vendored dependency that shipped its history, brings
	 * a `.git` directory whose pack files can be larger than the entire rest of the site — one
	 * observed at 130MB inside a single module. It restores to nothing useful.
	 *
	 * `node_modules` is here on the same grounds. It is a build-time dependency tree, never
	 * read by PHP at runtime, and it is the usual reason a WordPress install has a hundred
	 * thousand files in it. The failure mode of leaving it out is a developer running `npm
	 * install` on the other side; the failure mode of carrying it is hours of packaging and a
	 * package several times the size of the site.
	 *
	 * Everything left out this way is listed in the manifest. Filter `nfd_sm_excluded_names`
	 * to change it.
	 *
	 * @var array
	 */
	protected static $excluded_names = array(
		'.git',
		'.svn',
		'.hg',
		'.bzr',
		'CVS',
		'node_modules',
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
			// the running importer mid-import. By the directory it is actually in, which is not
			// the slug for a checkout or a symlinked working copy.
			$spec->exclude_dirs( array( \nfd_sm_plugin_dirname() ) );
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
		// Plus core's own update scratch space, which is temporary by definition and is
		// sometimes left behind holding a full copy of a plugin mid-upgrade.
		$other->exclude_dirs(
			\array_merge(
				self::relative_children( $content, $covered ),
				array( 'upgrade', 'upgrade-temp-backup' )
			)
		);
		// Drop-ins have their own part; without this they are collected twice and stored twice.
		$other->exclude_files( self::$dropins );
		$specs[] = $other;

		$root_extras = new PartSpec( 'root-extras', \rtrim( ABSPATH, '/\\' ), '' );
		$root_extras->only( self::$root_allowlist );
		$specs[] = $root_extras;

		/**
		 * Filter the directory names left out of every part.
		 *
		 * @param array $names Directory basenames.
		 */
		$names = (array) \apply_filters( 'nfd_sm_excluded_names', self::$excluded_names );

		foreach ( $specs as $spec ) {
			$spec->exclude_names( $names );
			$spec->exclude_dirs(
				\array_values(
					\array_unique(
						\array_merge( $spec->excluded_dirs(), self::storage_within( $spec->root() ) )
					)
				)
			);
		}

		/**
		 * Filter the parts a package is built from.
		 *
		 * @param array $specs List of PartSpec.
		 */
		return \apply_filters( 'nfd_sm_part_specs', $specs );
	}

	/**
	 * This plugin's own storage directory, expressed relative to a part's root.
	 *
	 * **The export must never package the package.** The storage directory lives inside
	 * uploads, and the uploads part had no exclusions at all, so a run swept up whatever
	 * volumes it had already written — and, because it walks as it goes, the ones it wrote
	 * while walking. A 1GB site produced a 2.2GB package containing a copy of itself at
	 * `wp-content/uploads/nfd-site-migrator/package/…`, doubling the export and every
	 * subsequent upload.
	 *
	 * `content-other` already excluded it, which is why this went unnoticed: the exclusion was
	 * written against the one part that happened not to need it, since `uploads` excludes the
	 * whole directory from `content-other` anyway. Computed per part rather than named once,
	 * because `nfd_sm_storage_path()` is filterable and need not stay under uploads.
	 *
	 * @param string $root Absolute part root.
	 *
	 * @return array Empty when the storage directory is not below this root.
	 */
	protected static function storage_within( $root ) {
		$root    = \rtrim( (string) $root, '/\\' );
		$storage = \rtrim( \nfd_sm_storage_path(), '/\\' );

		if ( '' === $root || '' === $storage ) {
			return array();
		}

		$relative = \nfd_sm_relative_path( $root, $storage );

		if ( '' === $relative || $relative === $storage || false !== \strpos( $relative, '..' ) ) {
			return array();
		}

		return array( $relative );
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

	/**
	 * The drop-in filenames WordPress recognises in wp-content.
	 *
	 * @return array
	 */
	public static function dropins() {
		return self::$dropins;
	}
}
