<?php
/**
 * Where a package's files land on this site.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

use NewfoldLabs\WP\SiteMigrator\Core\Export\PartSpecs;

/**
 * Translates a path recorded in a package into an absolute path on this install.
 *
 * The source recorded every path relative to its own WordPress root, including its own
 * `wp-content` directory name. The destination's may differ — Bedrock moves it, some hosts
 * rename it, and multiple theme roots are ordinary. Extracting entries straight into ABSPATH
 * works only for two installs laid out identically, so instead each part is mapped by name
 * onto wherever this site keeps that kind of file.
 *
 * The destination layout comes from PartSpecs, the same class the export side uses to decide
 * what to collect, so the two halves cannot drift apart.
 */
class PathMap {

	/**
	 * Part name to absolute destination directory.
	 *
	 * @var array
	 */
	protected $roots = array();

	/**
	 * Absolute paths this import must never write to, normalised.
	 *
	 * @var array
	 */
	protected $protected = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		foreach ( PartSpecs::all() as $spec ) {
			$this->roots[ $spec->name() ] = $spec->root();
		}

		$this->protected = $this->protected_paths();
	}

	/**
	 * Absolute destination directory for a part, and the source prefix to strip.
	 *
	 * A part this site has no equivalent for — a second theme root that does not exist here —
	 * falls back to the WordPress root with the source's own path kept intact. The files are
	 * carried rather than dropped, and they land where the source had them.
	 *
	 * @param string $name   Part name from the manifest.
	 * @param string $prefix Source-relative prefix from the manifest.
	 *
	 * @return array `root` and `strip`.
	 */
	public function target( $name, $prefix ) {
		$prefix = \trim( (string) $prefix, '/' );

		if ( isset( $this->roots[ $name ] ) ) {
			return array(
				'root'  => $this->roots[ $name ],
				'strip' => $prefix,
			);
		}

		return array(
			'root'  => \rtrim( ABSPATH, '/\\' ),
			'strip' => '',
		);
	}

	/**
	 * Every destination root this map knows.
	 *
	 * @return array
	 */
	public function roots() {
		return $this->roots;
	}

	/**
	 * Whether a path is one this import must refuse to write.
	 *
	 * The export excludes the migrator from its own package, so in the ordinary case nothing
	 * ever matches. This is the destination refusing to depend on that: the package is
	 * untrusted input, it may have been built by an older version, and what it would overwrite
	 * here is the code that is running the import. The failure is not a broken plugin
	 * afterwards — it is the importer's own files changing underneath it, mid-request.
	 *
	 * Three things are refused, and the first two are not the same path. A working copy is
	 * often symlinked into the plugins directory, so the running plugin has an address inside
	 * the site and a real one outside it, and a package can name either. The third is this
	 * plugin under the name it is distributed as, which would install a *second* copy beside
	 * the running one — two sets of the same classes and functions, which is a fatal error on
	 * the next request rather than a tidiness problem.
	 *
	 * String comparison only. This runs for every entry in the package, and the per-file cost
	 * of the restore is the thing that decides whether a large site finishes.
	 *
	 * @param string $path Absolute, normalised destination path.
	 *
	 * @return bool
	 */
	public function is_protected( $path ) {
		foreach ( $this->protected as $prefix ) {
			if ( $path === $prefix || 0 === \strpos( $path, $prefix . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the refusal list.
	 *
	 * @return array
	 */
	protected function protected_paths() {
		$plugins = \rtrim( (string) \nfd_sm_plugins_dir(), '/\\' );
		$paths   = array(
			// The running plugin as the site addresses it, and as the filesystem does.
			$plugins . '/' . \nfd_sm_plugin_dirname(),
			\rtrim( \NFD_SM_PLUGIN_DIR, '/\\' ),
			// A second copy, under the name this plugin ships as.
			$plugins . '/' . NFD_SM_PLUGIN_NAME,
		);

		if ( \defined( 'WPMU_PLUGIN_DIR' ) ) {
			// A package built from a site that was itself mid-import carries that site's import
			// loader. Restored here it would keep loading a plugin from the *source's* path,
			// for good, and on two machines laid out alike that path resolves.
			$paths[] = \rtrim( \WPMU_PLUGIN_DIR, '/\\' ) . '/' . Loader::NAME;
		}

		$out = array();

		foreach ( $paths as $path ) {
			$path = self::normalise( $path );

			if ( '' !== $path && ! \in_array( $path, $out, true ) ) {
				$out[] = $path;
			}
		}

		return $out;
	}

	/**
	 * Turn a path recorded in the package into an absolute path under a root.
	 *
	 * This is the plugin's primary security surface: a package is untrusted input, and a
	 * crafted entry name is the cheapest way to write a PHP file anywhere the web server can
	 * reach. Everything suspicious is refused rather than sanitised, because a sanitiser that
	 * turns a hostile path into a plausible one still writes a file.
	 *
	 * @param string $root  Absolute destination root.
	 * @param string $entry Path as recorded in the package.
	 * @param string $strip Leading prefix to remove from the entry.
	 *
	 * @return string Absolute path, or an empty string when the entry must be refused.
	 */
	public static function safe_path( $root, $entry, $strip = '' ) {
		$entry = (string) $entry;

		// A null byte truncates the path at the filesystem layer while PHP still sees the
		// whole string, so every check above it can be made to pass.
		if ( false !== \strpos( $entry, "\0" ) ) {
			return '';
		}

		$entry = \str_replace( '\\', '/', $entry );

		// Directory entries carry no content and are created implicitly by their children.
		if ( '' === $entry || '/' === \substr( $entry, -1 ) ) {
			return '';
		}

		// Absolute, either POSIX or with a Windows drive letter.
		if ( 0 === \strpos( $entry, '/' ) || \preg_match( '#^[a-zA-Z]:#', $entry ) ) {
			return '';
		}

		$segments = \explode( '/', $entry );

		foreach ( $segments as $segment ) {
			if ( '..' === $segment ) {
				return '';
			}
		}

		if ( '' !== $strip ) {
			if ( 0 !== \strpos( $entry, $strip . '/' ) ) {
				// Outside the prefix this part declared. Refusing is right: the alternative is
				// guessing where it belongs.
				return '';
			}

			$entry = \substr( $entry, \strlen( $strip ) + 1 );
		}

		if ( '' === $entry ) {
			return '';
		}

		$root = \rtrim( $root, '/\\' );
		$path = $root . '/' . $entry;

		// Belt and braces against anything the segment walk missed — a normalised path must
		// still start at the root it was built from.
		$normalised = self::normalise( $path );

		if ( 0 !== \strpos( $normalised, self::normalise( $root ) . '/' ) ) {
			return '';
		}

		return $normalised;
	}

	/**
	 * Collapse `.` and `..` textually, without touching the filesystem.
	 *
	 * `realpath()` is no use here: the path being checked does not exist yet.
	 *
	 * @param string $path Path.
	 *
	 * @return string
	 */
	public static function normalise( $path ) {
		$path     = \str_replace( '\\', '/', $path );
		$absolute = 0 === \strpos( $path, '/' );
		$out      = array();

		foreach ( \explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				\array_pop( $out );

				continue;
			}

			$out[] = $segment;
		}

		return ( $absolute ? '/' : '' ) . \implode( '/', $out );
	}
}
