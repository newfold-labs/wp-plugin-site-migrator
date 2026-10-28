<?php
/**
 * Plugins the database expects and the package does not carry.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

use NewfoldLabs\WP\SiteMigrator\Core\Package\Manifest;
use NewfoldLabs\WP\SiteMigrator\Core\Package\ZipReader;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Report;

/**
 * Reconciles `active_plugins` against the code that will actually be on disk.
 *
 * Leaving the plugins out of a package is a legitimate choice -- a destination that already has
 * them does not need them carried -- but the database comes across whole either way, and it is
 * the database that decides what a page is made of. A widget area still lists five widgets, a
 * page still contains `[ninja_forms id=3]`, a layout still lives in `panels_data`. With the code
 * gone, `Fixups::drop_missing_plugins()` quietly deactivates the entries and WordPress renders
 * what is left: every widget at once, a shortcode as literal text, a page as bare paragraphs.
 *
 * Nothing failed. The site is simply not the site any more, and the only warning was a line on
 * the review screen saying which parts were left out -- which does not connect to what the
 * reader is about to lose.
 *
 * So this says it in the terms that matter: these plugins are switched on over there, and they
 * will not be here.
 */
class PluginPresence {

	/**
	 * Names to report before saying "and more".
	 */
	const MAX_REPORTED = 30;

	/**
	 * Package directory.
	 *
	 * @var string
	 */
	protected $dir = '';

	/**
	 * The package's manifest.
	 *
	 * @var Manifest|null
	 */
	protected $manifest = null;

	/**
	 * Constructor.
	 *
	 * @param string        $dir      Package directory.
	 * @param Manifest|null $manifest Its manifest.
	 */
	public function __construct( $dir, $manifest = null ) {
		$this->dir      = \rtrim( (string) $dir, '/\\' );
		$this->manifest = $manifest;
	}

	/**
	 * Add what will be missing to the report.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	public function check( Report $report ) {
		$active = $this->active_plugins();

		if ( empty( $active ) ) {
			return;
		}

		$missing = self::missing( $active, $this->carried(), $this->installed() );

		if ( empty( $missing ) ) {
			$report->pass( 'plugins_present', 'Every plugin the site has switched on will be here.' );

			return;
		}

		// A warning rather than a block. Excluding the plugins can be exactly what somebody
		// means to do, and refusing it would make a supported choice impossible -- but they
		// should be told in plugin names rather than in part names.
		$report->warn(
			'plugins_present',
			\sprintf(
				'%d plugin(s) are active on the site being moved and will not be on this server.',
				\count( $missing )
			),
			array(
				'missing' => \array_slice( $missing, 0, self::MAX_REPORTED ),
				'detail'  => 'The database still refers to them, so anything they render -- forms, sliders, page '
					. 'layouts, widget visibility -- will come out wrong rather than merely absent. Installing them '
					. 'again afterwards is not always possible: premium and bespoke plugins are not on wordpress.org, '
					. 'and a fresh copy is a different version from the one this data was written by.',
				'fix'     => 'Export again with the plugins included, or install them on this server first.',
			)
		);
	}

	/**
	 * Which of the active plugins will be absent.
	 *
	 * @param array $active    Plugin files from `active_plugins`.
	 * @param array $carried   Plugin directories and files the package holds.
	 * @param array $installed Plugin directories and files already on this server.
	 *
	 * @return array Plugin files, sorted.
	 */
	public static function missing( array $active, array $carried, array $installed ) {
		$have    = \array_merge( $carried, $installed );
		$missing = array();
		// The helper reads `NFD_SM_PLUGIN_DIR`, so both have to be there. This plugin is in
		// `active_plugins` on the source like any other and would otherwise report itself as
		// about to go missing, which is the one entry that is always wrong.
		$self = ( \function_exists( 'nfd_sm_plugin_basename' ) && \defined( 'NFD_SM_PLUGIN_DIR' ) )
			? \nfd_sm_plugin_basename()
			: '';

		foreach ( $active as $plugin ) {
			$plugin = (string) $plugin;

			if ( '' === $plugin || $plugin === $self ) {
				continue;
			}

			// `active_plugins` holds `slug/file.php`, or a bare `file.php` for a plugin that is
			// one loose file in the plugins directory -- and a loose file is the case most
			// likely to be unobtainable anywhere else, so it must not be skipped as malformed.
			$key = \strpos( $plugin, '/' ) === false ? $plugin : \substr( $plugin, 0, \strpos( $plugin, '/' ) );

			if ( ! \in_array( $key, $have, true ) ) {
				$missing[] = $plugin;
			}
		}

		\sort( $missing );

		return $missing;
	}

	/**
	 * `active_plugins` as the package's dump records it.
	 *
	 * Read out of the dump rather than out of the manifest, so this works on a package built
	 * before the check existed -- including one already sitting on a server. The file is read a
	 * line at a time and abandoned at the match: the dump writes one statement per line, and
	 * this option is in `wp_options`, which is near the start.
	 *
	 * @return array Plugin files.
	 */
	protected function active_plugins() {
		$path = $this->dir . DIRECTORY_SEPARATOR . DatabaseImporter::FILE;

		if ( ! \is_readable( $path ) ) {
			return array();
		}

		$prefix = null === $this->manifest ? '' : (string) $this->manifest->get( 'source.table_prefix', '' );
		$table  = '' === $prefix ? '' : \sprintf( 'INSERT INTO `%soptions` VALUES (', $prefix );

		// The row leads with `option_id`, so the name is not the first value -- and the comma
		// and quote in front of it are what keep this from matching
		// `jetpack_connection_active_plugins`, which ends with the same word.
		$needle = ",'active_plugins',";

		$handle = \fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $handle ) {
			return array();
		}

		$found = '';

		while ( ! \feof( $handle ) ) {
			$line = \fgets( $handle );

			if ( false === $line ) {
				break;
			}

			if ( false === \strpos( $line, $needle ) ) {
				continue;
			}

			if ( '' !== $table && 0 !== \strpos( $line, $table ) ) {
				continue;
			}

			$found = $line;
			break;
		}

		\fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return '' === $found ? array() : self::parse_serialized_strings( $found );
	}

	/**
	 * The strings inside a serialized array, without unserializing it.
	 *
	 * The value is a dump literal, so its quotes and backslashes are escaped for MySQL rather
	 * than for PHP; unserializing would mean undoing that escaping exactly right first, on
	 * input from a package this site did not build. Reading the strings out is enough -- the
	 * shape of `active_plugins` is a flat list of paths -- and it cannot be made to do anything.
	 *
	 * @param string $serialized A serialized array as it appears in the dump.
	 *
	 * @return array
	 */
	public static function parse_serialized_strings( $serialized ) {
		$found = array();

		if ( ! \preg_match( '/a:\d+:\{.*\}/s', (string) $serialized, $array ) ) {
			return $found;
		}

		if ( ! \preg_match_all( '/s:\d+:\\\\?"([^"\\\\]*)\\\\?"/', $array[0], $matches ) ) {
			return $found;
		}

		foreach ( $matches[1] as $value ) {
			// Values, not the integer keys a list has -- and a plugin file always ends `.php`.
			if ( '' !== $value && \substr( $value, -4 ) === '.php' ) {
				$found[] = $value;
			}
		}

		return $found;
	}

	/**
	 * Plugin directories and loose files the package carries.
	 *
	 * @return array
	 */
	protected function carried() {
		if ( null === $this->manifest || ! ZipReader::available() ) {
			return array();
		}

		$names = array();

		foreach ( (array) $this->manifest->get( 'parts', array() ) as $part ) {
			$file = isset( $part['file'] ) ? (string) $part['file'] : '';

			if ( '' === $file || 0 !== \strpos( \basename( $file ), 'plugins' ) ) {
				continue;
			}

			$path = $this->dir . DIRECTORY_SEPARATOR . $file;

			if ( ! \is_readable( $path ) ) {
				continue;
			}

			try {
				$zip = ZipReader::open( $path );
			} catch ( \RuntimeException $e ) {
				continue;
			}

			$entries = $zip->count();

			for ( $i = 0; $i < $entries; $i++ ) {
				$entry = $zip->name( $i );

				if ( false === $entry || ! \preg_match( '#(?:^|/)plugins/([^/]+)#', $entry, $match ) ) {
					continue;
				}

				$names[ $match[1] ] = true;
			}

			$zip->close();
		}

		return \array_keys( $names );
	}

	/**
	 * Plugin directories and loose files already on this server.
	 *
	 * @return array
	 */
	protected function installed() {
		if ( ! \defined( 'WP_PLUGIN_DIR' ) || ! \is_dir( \WP_PLUGIN_DIR ) ) {
			return array();
		}

		$names = array();

		foreach ( (array) @\scandir( \WP_PLUGIN_DIR ) as $entry ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( '.' === $entry || '..' === $entry || 'index.php' === $entry ) {
				continue;
			}

			$names[] = $entry;
		}

		return $names;
	}
}
