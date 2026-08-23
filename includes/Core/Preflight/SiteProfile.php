<?php
/**
 * Facts about a WordPress install.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Preflight;

/**
 * Gathers, serialises and parses the compatibility profile.
 *
 * The profile is what lets a source decide, before packaging anything, whether a destination
 * can take the site. It is facts about a server: versions, limits, free space. No credentials,
 * no salts, no content.
 *
 * It travels one of two ways — fetched live over a paired connection, or pasted by hand when
 * the destination cannot be reached. Both produce the same structure.
 */
class SiteProfile {

	const SCHEMA  = 1;
	const MAX_AGE = 604800;

	/**
	 * Profile data.
	 *
	 * @var array
	 */
	protected $data;

	/**
	 * Constructor.
	 *
	 * @param array $data Profile data.
	 */
	public function __construct( array $data = array() ) {
		$this->data = $data;
	}

	/**
	 * Gather the facts for this install.
	 *
	 * @param bool $as_source Include the extra facts only the site being moved needs to report.
	 *
	 * @return SiteProfile
	 */
	public static function gather( $as_source = false ) {
		global $wpdb, $wp_version, $wp_db_version;

		$content_dir = \defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH;

		$profile = new self(
			array(
				'schema_version' => self::SCHEMA,
				'minted_at'      => \time(),
				'profile_id'     => \wp_generate_password( 16, false ),
				'site_url'       => \get_site_url(),
				'wordpress'      => array(
					'version'      => isset( $wp_version ) ? $wp_version : '',
					'db_version'   => isset( $wp_db_version ) ? (int) $wp_db_version : 0,
					'is_multisite' => \is_multisite(),
					'locale'       => \get_locale(),
					'prefix'       => isset( $wpdb ) ? $wpdb->prefix : '',
					'content_dir'  => $content_dir,
					'content_std'  => \rtrim( $content_dir, '/\\' ) === \rtrim( ABSPATH, '/\\' ) . '/wp-content',
				),
				'php'            => array(
					'version'           => PHP_VERSION,
					'extensions'        => self::extensions(),
					'memory_limit'      => \ini_get( 'memory_limit' ),
					'max_execution'     => (int) \ini_get( 'max_execution_time' ),
					'upload_max'        => self::bytes( \ini_get( 'upload_max_filesize' ) ),
					'post_max'          => self::bytes( \ini_get( 'post_max_size' ) ),
					'disabled'          => \array_filter( \array_map( 'trim', \explode( ',', (string) \ini_get( 'disable_functions' ) ) ) ),
					'open_basedir'      => (string) \ini_get( 'open_basedir' ),
					'zip'               => \class_exists( 'ZipArchive' ),
				),
				'database'       => self::database_facts(),
				'host'           => array(
					'free_bytes' => self::free_space( $content_dir ),
					'software'   => isset( $_SERVER['SERVER_SOFTWARE'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
					'is_ssl'     => \is_ssl(),
				),
			)
		);

		// Source-only facts. They cost real work — a table scan and a directory walk — so the
		// destination, which only ever has them read *about* it, does not pay for them.
		if ( $as_source ) {
			$profile->add_source_facts();
		}

		return $profile;
	}

	/**
	 * Add the facts only the site being moved needs to report.
	 *
	 * @return void
	 */
	protected function add_source_facts() {
		$this->data['database']['collations_used'] = self::collations_in_use();
		$this->data['php']['requires']             = self::required_php();
		$this->data['estimate']                    = self::size_estimate();
	}

	/**
	 * Which collations this site's own tables actually use.
	 *
	 * The destination having *a* collation is not the question; the question is whether it has
	 * the ones this site's tables are declared with.
	 *
	 * @return array
	 */
	protected static function collations_in_use() {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return array();
		}

		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT DISTINCT TABLE_COLLATION FROM information_schema.TABLES'
				. ' WHERE TABLE_SCHEMA = %s AND TABLE_COLLATION IS NOT NULL',
				$wpdb->dbname
			)
		);

		return \is_array( $rows ) ? \array_values( \array_filter( $rows ) ) : array();
	}

	/**
	 * The highest PHP version any active plugin or the active theme declares it needs.
	 *
	 * Read from the headers rather than assumed from the running version: a site on PHP 8.3
	 * whose plugins all declare 7.4 can move to a 7.4 host perfectly well.
	 *
	 * @return string Empty when nothing declares a requirement.
	 */
	protected static function required_php() {
		if ( ! \function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$required = '';
		$active   = (array) \get_option( 'active_plugins', array() );
		$all      = \get_plugins();

		foreach ( $active as $file ) {
			if ( ! isset( $all[ $file ]['RequiresPHP'] ) ) {
				continue;
			}

			$needs = (string) $all[ $file ]['RequiresPHP'];

			if ( '' !== $needs && ( '' === $required || \version_compare( $needs, $required, '>' ) ) ) {
				$required = $needs;
			}
		}

		$theme = \wp_get_theme();

		if ( $theme instanceof \WP_Theme ) {
			$needs = (string) $theme->get( 'RequiresPHP' );

			if ( '' !== $needs && ( '' === $required || \version_compare( $needs, $required, '>' ) ) ) {
				$required = $needs;
			}
		}

		return $required;
	}

	/**
	 * Roughly how big a package will be.
	 *
	 * Used for the destination's free-space gate. Approximate on purpose: an exact figure would
	 * mean walking the whole site, and the gate has a wide margin anyway.
	 *
	 * @return array
	 */
	protected static function size_estimate() {
		global $wpdb;

		$database = 0;

		if ( isset( $wpdb ) ) {
			$size = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s',
					$wpdb->dbname
				)
			);

			$database = (int) $size;
		}

		$content  = 0;
		$complete = true;

		if ( \function_exists( 'nfd_sm_measure_dir' ) && \defined( 'WP_CONTENT_DIR' ) ) {
			$measured = \nfd_sm_measure_dir( WP_CONTENT_DIR, 3.0 );
			$content  = (int) $measured['bytes'];
			$complete = (bool) $measured['complete'];
		}

		return array(
			'database_bytes' => $database,
			'content_bytes'  => $content,
			'total_bytes'    => $database + $content,
			// False when the walk ran out of budget, so the number is a floor rather than a
			// total. Consumers must not treat a floor as grounds to block.
			'complete'       => $complete,
		);
	}

	/**
	 * Extensions worth reporting.
	 *
	 * The full list is long and mostly noise; these are the ones a WordPress site's plugins
	 * actually depend on.
	 *
	 * @return array
	 */
	protected static function extensions() {
		$interesting = array( 'zip', 'gd', 'imagick', 'intl', 'mbstring', 'curl', 'soap', 'bcmath', 'exif', 'openssl', 'mysqli', 'zlib' );
		$present     = array();

		foreach ( $interesting as $ext ) {
			if ( \extension_loaded( $ext ) ) {
				$present[] = $ext;
			}
		}

		return $present;
	}

	/**
	 * Database facts, including the collations a dump might need.
	 *
	 * @return array
	 */
	protected static function database_facts() {
		global $wpdb;

		$facts = array(
			'version'            => '',
			'is_mariadb'         => false,
			'max_allowed_packet' => 0,
			'collations'         => array(),
			'can_rename'         => null,
		);

		if ( ! isset( $wpdb ) ) {
			return $facts;
		}

		$server = $wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$facts['version']    = (string) $server;
		$facts['is_mariadb'] = false !== \stripos( (string) $server, 'mariadb' );

		$packet = $wpdb->get_row( "SHOW VARIABLES LIKE 'max_allowed_packet'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( \is_array( $packet ) && isset( $packet['Value'] ) ) {
			$facts['max_allowed_packet'] = (int) $packet['Value'];
		}

		$collations = $wpdb->get_col( 'SHOW COLLATION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( \is_array( $collations ) ) {
			// Only the families a WordPress table is ever declared with. The full list runs to
			// roughly 290 entries and dominates the profile, which matters because the profile
			// is sometimes copied and pasted by hand.
			$facts['collations'] = \array_values(
				\array_filter(
					$collations,
					function ( $collation ) {
						return (bool) \preg_match( '/^(utf8|utf8mb3|utf8mb4|latin1|ascii|binary)/', $collation );
					}
				)
			);
		}

		$facts['can_rename'] = self::probe_rename();

		return $facts;
	}

	/**
	 * Find out whether RENAME TABLE actually works here.
	 *
	 * Probed with a scratch table rather than inferred from the grant tables, because managed
	 * hosts routinely grant a privilege and then block the statement. The atomic swap the
	 * importer depends on is only available if this succeeds.
	 *
	 * @return bool|null Null when the probe itself could not run.
	 */
	protected static function probe_rename() {
		global $wpdb;

		$a = $wpdb->prefix . 'nfd_sm_probe';
		$b = $wpdb->prefix . 'nfd_sm_probe2';

		$wpdb->query( "DROP TABLE IF EXISTS `{$a}`, `{$b}`" ); // phpcs:ignore WordPress.DB

		if ( false === $wpdb->query( "CREATE TABLE `{$a}` ( id INT )" ) ) { // phpcs:ignore WordPress.DB
			return null;
		}

		$renamed = $wpdb->query( "RENAME TABLE `{$a}` TO `{$b}`" ); // phpcs:ignore WordPress.DB
		$ok      = ( false !== $renamed );

		$wpdb->query( "DROP TABLE IF EXISTS `{$a}`, `{$b}`" ); // phpcs:ignore WordPress.DB

		return $ok;
	}

	/**
	 * Free disk space, or null when it cannot be determined.
	 *
	 * @param string $path Directory to measure.
	 *
	 * @return int|null
	 */
	protected static function free_space( $path ) {
		if ( ! \function_exists( 'disk_free_space' ) ) {
			return null;
		}

		$free = @\disk_free_space( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return false === $free ? null : (int) $free;
	}

	/**
	 * Turn a php.ini shorthand size into bytes.
	 *
	 * @param string $value Shorthand value such as `64M`.
	 *
	 * @return int
	 */
	public static function bytes( $value ) {
		$value = \trim( (string) $value );

		if ( '' === $value ) {
			return 0;
		}

		$unit   = \strtolower( \substr( $value, -1 ) );
		$number = (int) $value;

		switch ( $unit ) {
			case 'g':
				return $number * 1073741824;
			case 'm':
				return $number * 1048576;
			case 'k':
				return $number * 1024;
			default:
				return $number;
		}
	}

	/**
	 * Encode for pasting: base64 of the JSON, prefixed so it is recognisable.
	 *
	 * @return string
	 */
	public function encode() {
		return 'NFDSM1-' . \base64_encode( \wp_json_encode( $this->data ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Decode a pasted profile.
	 *
	 * @param string $blob Pasted text.
	 *
	 * @return SiteProfile|null Null when it cannot be read.
	 */
	public static function decode( $blob ) {
		$blob = \trim( (string) $blob );

		if ( 0 === \strpos( $blob, 'NFDSM1-' ) ) {
			$blob = \substr( $blob, 7 );
		}

		$json = \base64_decode( $blob, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		if ( false === $json ) {
			return null;
		}

		$data = \json_decode( $json, true );

		if ( ! \is_array( $data ) || ! isset( $data['schema_version'] ) || self::SCHEMA !== (int) $data['schema_version'] ) {
			return null;
		}

		return new self( $data );
	}

	/**
	 * Whether a pasted profile is too old to trust.
	 *
	 * Only applies to the pasted route; a live paired fetch is current by definition.
	 *
	 * @return bool
	 */
	public function is_stale() {
		$minted = isset( $this->data['minted_at'] ) ? (int) $this->data['minted_at'] : 0;

		return ( \time() - $minted ) > self::MAX_AGE;
	}

	/**
	 * Read a value using dot notation.
	 *
	 * @param string $key           Dot-delimited key.
	 * @param mixed  $default_value Returned when absent.
	 *
	 * @return mixed
	 */
	public function get( $key, $default_value = null ) {
		return \nfd_sm_data_get( $this->data, $key, $default_value );
	}

	/**
	 * Profile data.
	 *
	 * @return array
	 */
	public function to_array() {
		return $this->data;
	}
}
