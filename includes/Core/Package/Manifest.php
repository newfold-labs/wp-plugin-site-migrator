<?php
/**
 * Package manifest: schema, read, write.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Package;

/**
 * The index of a package.
 *
 * Written last, once every part is finalised, so its presence is what makes a package complete.
 * An interrupted export leaves a checkpoint and no manifest.
 */
class Manifest {

	const SCHEMA = 1;
	const NAME   = 'manifest.json';

	/**
	 * Manifest data.
	 *
	 * @var array
	 */
	protected $data;

	/**
	 * Constructor.
	 *
	 * @param array $data Manifest data.
	 */
	public function __construct( array $data = array() ) {
		$this->data = $data;
	}

	/**
	 * Build a manifest describing the current site.
	 *
	 * @return Manifest
	 */
	public static function for_this_site() {
		global $wpdb, $wp_version, $wp_db_version;

		return new self(
			array(
				'schema_version' => self::SCHEMA,
				'generator'      => array(
					'plugin'  => 'nfd-site-migrator',
					'version' => \defined( 'NFD_SM_VERSION' ) ? NFD_SM_VERSION : 'dev',
				),
				'created_at'     => \gmdate( 'c' ),
				'source'         => array(
					'site_url'     => \get_site_url(),
					'home_url'     => \get_home_url(),
					'wp_version'   => isset( $wp_version ) ? $wp_version : '',
					'db_version'   => isset( $wp_db_version ) ? (int) $wp_db_version : 0,
					'php_version'  => PHP_VERSION,
					'table_prefix' => isset( $wpdb ) ? $wpdb->prefix : '',
					'content_dir'  => \defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
					'is_multisite' => \is_multisite(),
					'locale'       => \get_locale(),
				),
				'database'       => array(),
				'parts'          => array(),
				'large'          => array(),
				'totals'         => array(
					'bytes' => 0,
					'files' => 0,
				),
			)
		);
	}

	/**
	 * Read a manifest from a package directory.
	 *
	 * @param string $dir Package directory.
	 *
	 * @return Manifest|null Null when absent or unreadable.
	 */
	public static function read( $dir ) {
		$path = \rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . self::NAME;

		if ( ! \is_readable( $path ) ) {
			return null;
		}

		$decoded = \json_decode( \file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! \is_array( $decoded ) ) {
			return null;
		}

		return new self( $decoded );
	}

	/**
	 * Write the manifest into a package directory.
	 *
	 * @param string $dir Package directory.
	 *
	 * @return string Absolute path written.
	 */
	public function write( $dir ) {
		$path = \rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . self::NAME;

		\file_put_contents( $path, \wp_json_encode( $this->data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $path;
	}

	/**
	 * Whether this manifest's schema is one we understand.
	 *
	 * A reader must refuse a version it does not know rather than guess at it.
	 *
	 * @return bool
	 */
	public function is_supported() {
		return isset( $this->data['schema_version'] ) && self::SCHEMA === (int) $this->data['schema_version'];
	}

	/**
	 * Record the database dump.
	 *
	 * @param string $file   Path relative to the package.
	 * @param int    $bytes  Size in bytes.
	 * @param string $sha256 Checksum.
	 *
	 * @return void
	 */
	public function set_database( $file, $bytes, $sha256 ) {
		$this->data['database'] = array(
			'file'   => $file,
			'bytes'  => (int) $bytes,
			'sha256' => $sha256,
		);
	}

	/**
	 * Record one archive part.
	 *
	 * @param array $part Part descriptor.
	 *
	 * @return void
	 */
	public function add_part( array $part ) {
		$this->data['parts'][] = $part;
	}

	/**
	 * Record one loosely stored large file.
	 *
	 * @param array $file File descriptor.
	 *
	 * @return void
	 */
	public function add_large( array $file ) {
		$this->data['large'][] = $file;
	}

	/**
	 * Recompute the totals from the recorded parts.
	 *
	 * @return void
	 */
	public function recalculate_totals() {
		$bytes = isset( $this->data['database']['bytes'] ) ? (int) $this->data['database']['bytes'] : 0;
		$files = 0;

		foreach ( $this->data['parts'] as $part ) {
			$bytes += isset( $part['bytes'] ) ? (int) $part['bytes'] : 0;
			$files += isset( $part['files'] ) ? (int) $part['files'] : 0;
		}

		foreach ( $this->data['large'] as $large ) {
			$bytes += isset( $large['bytes'] ) ? (int) $large['bytes'] : 0;
			++$files;
		}

		$this->data['totals'] = array(
			'bytes' => $bytes,
			'files' => $files,
		);
	}

	/**
	 * Manifest data.
	 *
	 * @return array
	 */
	public function to_array() {
		return $this->data;
	}

	/**
	 * Read a value using dot notation.
	 *
	 * @param string $key           Dot-delimited key.
	 * @param mixed  $default_value Returned when the key is absent.
	 *
	 * @return mixed
	 */
	public function get( $key, $default_value = null ) {
		return \nfd_sm_data_get( $this->data, $key, $default_value );
	}
}
