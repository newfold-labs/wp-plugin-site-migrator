<?php
/**
 * Opens and validates a package.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Package;

/**
 * Reads a package produced by PackageWriter.
 *
 * Verification exists in phase 2, before any importer does, because "the checksum for part 3
 * does not match" is a usable failure and "the import broke" is not.
 */
class PackageReader {

	/**
	 * Absolute package directory.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Manifest, once read.
	 *
	 * @var Manifest|null
	 */
	protected $manifest;

	/**
	 * Constructor.
	 *
	 * @param string $dir Absolute package directory.
	 */
	public function __construct( $dir ) {
		$this->dir      = \rtrim( $dir, '/\\' );
		$this->manifest = Manifest::read( $this->dir );
	}

	/**
	 * Whether a complete package is present.
	 *
	 * A directory with a checkpoint and no manifest is an interrupted export, not a package.
	 *
	 * @return bool
	 */
	public function is_complete() {
		return null !== $this->manifest;
	}

	/**
	 * The manifest.
	 *
	 * @return Manifest|null
	 */
	public function manifest() {
		return $this->manifest;
	}

	/**
	 * Check the package against its manifest.
	 *
	 * @return array Problems found, empty when the package is sound.
	 */
	public function verify() {
		$problems = array();

		if ( null === $this->manifest ) {
			$problems[] = 'No manifest.json: this directory does not hold a finished package.';

			return $problems;
		}

		if ( ! $this->manifest->is_supported() ) {
			$problems[] = \sprintf(
				'Package schema version %s is not supported by this plugin.',
				(string) $this->manifest->get( 'schema_version', 'unknown' )
			);

			return $problems;
		}

		$entries = array();

		$database = $this->manifest->get( 'database' );

		if ( \is_array( $database ) && isset( $database['file'] ) ) {
			$entries[] = $database;
		}

		foreach ( (array) $this->manifest->get( 'parts', array() ) as $part ) {
			$entries[] = $part;
		}

		foreach ( (array) $this->manifest->get( 'large', array() ) as $large ) {
			$entries[] = $large;
		}

		foreach ( $entries as $entry ) {
			$problem = $this->verify_entry( $entry );

			if ( '' !== $problem ) {
				$problems[] = $problem;
			}
		}

		return $problems;
	}

	/**
	 * Check one manifest entry against the file on disk.
	 *
	 * @param array $entry Manifest entry.
	 *
	 * @return string Empty when sound.
	 */
	protected function verify_entry( array $entry ) {
		$relative = isset( $entry['file'] ) ? $entry['file'] : '';

		if ( '' === $relative ) {
			return 'Manifest entry with no file path.';
		}

		$path = $this->dir . DIRECTORY_SEPARATOR . $relative;

		if ( ! \is_readable( $path ) ) {
			return \sprintf( 'Missing: %s', $relative );
		}

		$expected_bytes = isset( $entry['bytes'] ) ? (int) $entry['bytes'] : 0;
		$actual_bytes   = (int) \filesize( $path );

		if ( $expected_bytes !== $actual_bytes ) {
			return \sprintf(
				'Wrong size: %s is %d bytes, the manifest says %d.',
				$relative,
				$actual_bytes,
				$expected_bytes
			);
		}

		$expected_hash = isset( $entry['sha256'] ) ? $entry['sha256'] : '';

		if ( '' !== $expected_hash && \hash_file( 'sha256', $path ) !== $expected_hash ) {
			return \sprintf( 'Checksum mismatch: %s', $relative );
		}

		return '';
	}

	/**
	 * Summarise a package for display.
	 *
	 * @return array
	 */
	public function inspect() {
		if ( null === $this->manifest ) {
			return array();
		}

		$parts = array();

		foreach ( (array) $this->manifest->get( 'parts', array() ) as $part ) {
			$parts[] = array(
				'name'  => isset( $part['name'] ) ? $part['name'] : '',
				'file'  => isset( $part['file'] ) ? $part['file'] : '',
				'bytes' => isset( $part['bytes'] ) ? (int) $part['bytes'] : 0,
				'files' => isset( $part['files'] ) ? (int) $part['files'] : 0,
			);
		}

		return array(
			'schema_version' => $this->manifest->get( 'schema_version' ),
			'created_at'     => $this->manifest->get( 'created_at' ),
			'source'         => $this->manifest->get( 'source', array() ),
			'database'       => $this->manifest->get( 'database', array() ),
			'parts'          => $parts,
			'large'          => (array) $this->manifest->get( 'large', array() ),
			'totals'         => $this->manifest->get( 'totals', array() ),
		);
	}
}
