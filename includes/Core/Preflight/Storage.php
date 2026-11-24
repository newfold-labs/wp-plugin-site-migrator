<?php
/**
 * Where the plugin keeps its files.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Preflight;

/**
 * The storage directory, and the places it can move to when uploads is not writable.
 *
 * Everything the plugin writes -- the package, the export and import checkpoints, an upload in
 * flight, a transfer's staging directory -- lives under one directory, by default
 * `uploads/nfd-site-migrator`. On a host where uploads is read-only to PHP nothing can start,
 * and somebody who only has wp-admin has no way to fix the permissions. So two alternates are
 * offered: a directory beside the WordPress folder, and the server's temporary directory.
 *
 * **The choice is recorded by the directory existing, never in an option.** The import replaces
 * `wp_options` partway through, and the import checkpoint lives in this directory: a location
 * read from the database would point somewhere else the moment the swap ran, and the run would
 * lose the record of what to roll back. A directory survives the swap. An adopted alternate
 * therefore wins whenever it exists, and deleting the plugin removes it, which is what returns a
 * site to the default.
 *
 * **An alternate is only trusted if it is plainly ours.** The temporary directory is shared with
 * every other account on a shared host, and a package holds the whole database. So an alternate
 * is created `0700`, and one found already there counts only if it is a real directory (not a
 * link), closed to group and world, and writable by this process -- which, for a `0700`
 * directory, only its owner can be. Its name carries a hash of `AUTH_KEY` so it cannot be
 * squatted in advance by somebody who knows only the site's path.
 */
class Storage {

	/**
	 * The default location, under uploads.
	 */
	const UPLOADS = 'uploads';

	/**
	 * Beside the WordPress folder, one level up from `ABSPATH`.
	 */
	const ABOVE_ROOT = 'above-root';

	/**
	 * The server's temporary directory.
	 */
	const TEMP = 'temp';

	/**
	 * Where the plugin's files live: an adopted alternate if there is one, otherwise uploads.
	 *
	 * Nothing is created here; `nfd_sm_storage_path()` does that for the default.
	 *
	 * @return string Absolute path with a trailing separator.
	 */
	public static function resolve() {
		foreach ( self::alternates() as $alternate ) {
			if ( self::is_ours( $alternate['path'] ) ) {
				return $alternate['path'];
			}
		}

		return self::default_path();
	}

	/**
	 * The default location.
	 *
	 * @return string Absolute path with a trailing separator.
	 */
	public static function default_path() {
		$uploads = \wp_get_upload_dir();

		return $uploads['basedir'] . DIRECTORY_SEPARATOR . 'nfd-site-migrator' . DIRECTORY_SEPARATOR;
	}

	/**
	 * Which location a path is: one of the constants, or `custom` when a filter put it elsewhere.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return string
	 */
	public static function kind( $path ) {
		$path = \rtrim( (string) $path, '/\\' );

		if ( \rtrim( self::default_path(), '/\\' ) === $path ) {
			return self::UPLOADS;
		}

		foreach ( self::alternates() as $alternate ) {
			if ( \rtrim( $alternate['path'], '/\\' ) === $path ) {
				return $alternate['id'];
			}
		}

		return 'custom';
	}

	/**
	 * The alternate locations, in order of preference.
	 *
	 * Beside WordPress comes first: on most shared hosts that is the account's home directory,
	 * outside the web root, persistent and on the same disk. The temporary directory is the last
	 * resort, because hosts clear it.
	 *
	 * @return array List of `id`, `path`.
	 */
	public static function alternates() {
		$name = 'nfd-site-migrator-' . self::key();
		$list = array();

		$above = \dirname( \rtrim( ABSPATH, '/\\' ) );

		if ( '' !== $above && '.' !== $above ) {
			$list[ \rtrim( $above, '/\\' ) . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR ] = self::ABOVE_ROOT;
		}

		$temp = \defined( 'WP_TEMP_DIR' ) && '' !== (string) WP_TEMP_DIR ? (string) WP_TEMP_DIR : (string) \sys_get_temp_dir();

		if ( '' !== $temp ) {
			$path = \rtrim( $temp, '/\\' ) . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR;

			// Keyed by path, so a temp directory that is the folder above WordPress is offered
			// once, under the name that describes it better.
			if ( ! isset( $list[ $path ] ) ) {
				$list[ $path ] = self::TEMP;
			}
		}

		$alternates = array();

		foreach ( $list as $path => $id ) {
			$alternates[] = array(
				'id'   => $id,
				'path' => $path,
			);
		}

		return $alternates;
	}

	/**
	 * The alternates that could be adopted right now, described for the preflight screen.
	 *
	 * Tried rather than inferred: `is_writable()` is wrong often enough under ACLs, quotas and
	 * `open_basedir` that the only honest answer is a directory actually created and removed.
	 *
	 * @return array List of `id`, `path`, `label`, `detail`.
	 */
	public static function options() {
		$options = array();

		foreach ( self::alternates() as $alternate ) {
			if ( ! self::can_create( $alternate['path'] ) ) {
				continue;
			}

			$options[] = array(
				'id'     => $alternate['id'],
				'path'   => \rtrim( $alternate['path'], '/\\' ),
				'label'  => self::TEMP === $alternate['id']
					? 'The server\'s temporary directory'
					: 'Beside the WordPress folder',
				'detail' => self::TEMP === $alternate['id']
					? 'Hosts clear this from time to time, so send or download the package soon after it is built.'
					: 'Usually outside the web root, and kept until the plugin is deleted.',
			);
		}

		return $options;
	}

	/**
	 * Move storage to an alternate, because the current location cannot be written.
	 *
	 * Refused while the current location works. Storage moving under a run that has started --
	 * an export part way through, an import with a checkpoint -- would strand it, so the only time
	 * a move is allowed is when nothing can have been written yet.
	 *
	 * @param string $id One of the alternate ids.
	 *
	 * @return string|\WP_Error The new location.
	 */
	public static function adopt( $id ) {
		// Through `nfd_sm_storage_path()`, which creates the default: asked of `resolve()` alone,
		// an uploads directory that is writable but not yet made would look like one that is not.
		if ( self::is_writable( \nfd_sm_storage_path() ) ) {
			return new \WP_Error(
				'nfd_sm_storage_usable',
				'The current storage directory is writable, so there is nothing to move.',
				array( 'status' => 409 )
			);
		}

		$path = '';

		foreach ( self::alternates() as $alternate ) {
			if ( $alternate['id'] === $id ) {
				$path = $alternate['path'];
			}
		}

		if ( '' === $path ) {
			return new \WP_Error( 'nfd_sm_storage_unknown', 'That is not a place this site can keep its files.', array( 'status' => 400 ) );
		}

		if ( ! \file_exists( $path ) ) {
			@\mkdir( $path, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		if ( ! self::is_ours( $path ) ) {
			return new \WP_Error(
				'nfd_sm_storage_failed',
				'That directory could not be created, or something else already owns it.',
				array(
					'status' => 409,
					'path'   => \rtrim( $path, '/\\' ),
				)
			);
		}

		\nfd_sm_protect_directory( $path );

		return $path;
	}

	/**
	 * Every location that exists, for purging.
	 *
	 * @return array Absolute paths with trailing separators.
	 */
	public static function existing() {
		$paths = array();

		foreach ( \array_merge( array( self::default_path() ), \array_column( self::alternates(), 'path' ) ) as $path ) {
			if ( @\is_dir( $path ) && ! \is_link( \rtrim( $path, '/\\' ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$paths[] = $path;
			}
		}

		return $paths;
	}

	/**
	 * Whether files can actually be written in a directory.
	 *
	 * @param string $path Absolute directory.
	 *
	 * @return bool
	 */
	public static function is_writable( $path ) {
		if ( ! @\is_dir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}

		$probe  = \rtrim( $path, '/\\' ) . DIRECTORY_SEPARATOR . 'write-probe-' . \wp_generate_password( 8, false );
		$wrote  = @\file_put_contents( $probe, 'probe' ); // phpcs:ignore
		$exists = \is_readable( $probe );

		if ( $exists ) {
			@\unlink( $probe ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		return false !== $wrote && $exists;
	}

	/**
	 * The address a location is served at, if it is inside what this site serves.
	 *
	 * Three answers, which `Checker` treats differently: a URL to probe, `''` for a directory
	 * outside every path this site maps to a URL, and `null` when the uploads URL that should have
	 * been there could not be worked out.
	 *
	 * @param string $path Absolute directory.
	 *
	 * @return string|null
	 */
	public static function url( $path ) {
		$path    = \rtrim( (string) $path, '/\\' );
		$uploads = \wp_get_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? \rtrim( (string) $uploads['basedir'], '/\\' ) : '';
		$baseurl = isset( $uploads['baseurl'] ) ? \rtrim( (string) $uploads['baseurl'], '/' ) : '';

		$relative = self::below( $path, $basedir );

		if ( null !== $relative ) {
			return '' === $baseurl ? null : $baseurl . '/' . $relative;
		}

		$root    = \rtrim( ABSPATH, '/\\' );
		$siteurl = \rtrim( (string) \get_site_url(), '/' );

		$relative = self::below( $path, $root );

		if ( null !== $relative ) {
			return $siteurl . '/' . $relative;
		}

		// Beside WordPress is served only when WordPress lives in a subdirectory of the web
		// root, which is exactly when the site's own address has a path to step back out of.
		$relative = self::below( $path, \dirname( $root ) );
		$sitepath = (string) \wp_parse_url( $siteurl, PHP_URL_PATH );

		if ( null !== $relative && '' !== \trim( $sitepath, '/' ) ) {
			return \substr( $siteurl, 0, -\strlen( \rtrim( $sitepath, '/' ) ) ) . \rtrim( \dirname( $sitepath ), '/\\' ) . '/' . $relative;
		}

		return '';
	}

	/**
	 * A path relative to a root it sits under, with forward slashes.
	 *
	 * @param string $path Absolute path, no trailing separator.
	 * @param string $root Absolute root, no trailing separator.
	 *
	 * @return string|null Null when the path is not below the root.
	 */
	protected static function below( $path, $root ) {
		if ( '' === $root || 0 !== \strpos( $path, $root . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		return \str_replace( '\\', '/', \substr( $path, \strlen( $root ) + 1 ) );
	}

	/**
	 * Whether an alternate exists and belongs to this site.
	 *
	 * @param string $path Absolute directory.
	 *
	 * @return bool
	 */
	protected static function is_ours( $path ) {
		$bare = \rtrim( $path, '/\\' );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors -- open_basedir warns on paths it forbids.
		if ( ! @\is_dir( $bare ) || \is_link( $bare ) ) {
			return false;
		}

		$perms = @\fileperms( $bare ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		// Windows reports no group or world bits worth reading, and has no shared /tmp to defend.
		if ( '\\' !== DIRECTORY_SEPARATOR && ( false === $perms || 0 !== ( $perms & 0077 ) ) ) {
			return false;
		}

		return \is_writable( $bare );
	}

	/**
	 * Whether an alternate could be created, or is already ours.
	 *
	 * @param string $path Absolute directory.
	 *
	 * @return bool
	 */
	protected static function can_create( $path ) {
		if ( self::is_ours( $path ) ) {
			return true;
		}

		$bare = \rtrim( $path, '/\\' );

		if ( @\file_exists( $bare ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}

		$probe = $bare . '-probe-' . \wp_generate_password( 8, false );

		if ( ! @\mkdir( $probe, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}

		@\rmdir( $probe ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return true;
	}

	/**
	 * What makes this site's alternate directory name its own.
	 *
	 * `AUTH_KEY` lives in wp-config.php, which an import never touches, so the name holds across
	 * the swap; the blog id keeps sites on one network apart, as their uploads directories do.
	 *
	 * @return string
	 */
	protected static function key() {
		$blog   = \function_exists( 'get_current_blog_id' ) ? (int) \get_current_blog_id() : 1;
		$secret = \defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';

		return \substr( \md5( ABSPATH . '|' . $blog . '|' . $secret ), 0, 12 );
	}
}
