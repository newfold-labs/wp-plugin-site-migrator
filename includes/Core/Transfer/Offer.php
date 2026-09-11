<?php
/**
 * What a package offers to a destination pulling it.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Transfer;

use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageReader;

/**
 * Turns a package on disk into the list of files a transfer may read, and nothing else.
 *
 * The list comes from the manifest rather than from the directory, and the file endpoint serves
 * only what is on it. That is a stronger guard than the download endpoint's "anything inside the
 * package directory", and it costs one array lookup: a package directory can also hold an export
 * checkpoint, and a stray file somebody dropped in there is not part of the site either way.
 *
 * `manifest.json` leads the list because the destination needs it before it can know what else
 * to ask for — and once it has been pulled, the destination reads its own copy and stops asking
 * the source what the package contains.
 */
class Offer {

	/**
	 * Absolute package directory.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * The package, once opened.
	 *
	 * @var PackageReader
	 */
	protected $reader;

	/**
	 * Constructor.
	 *
	 * @param string $dir Absolute package directory. Defaults to this site's own package.
	 */
	public function __construct( $dir = '' ) {
		$this->dir    = '' === $dir ? \nfd_sm_package_path() : \rtrim( $dir, '/\\' );
		$this->reader = new PackageReader( $this->dir );
	}

	/**
	 * Whether there is a finished package here to offer.
	 *
	 * @return bool
	 */
	public function is_ready() {
		return $this->reader->is_complete();
	}

	/**
	 * The package directory.
	 *
	 * @return string
	 */
	public function dir() {
		return $this->dir;
	}

	/**
	 * Everything a destination has to fetch, in the order it should fetch it.
	 *
	 * Sizes and checksums come along so the destination can resume against them and verify at
	 * the end without a second conversation.
	 *
	 * @return array List of `file`, `bytes`, `sha256`.
	 */
	public function files() {
		if ( ! $this->is_ready() ) {
			return array();
		}

		$manifest = $this->dir . DIRECTORY_SEPARATOR . 'manifest.json';

		$files = array(
			array(
				'file'   => 'manifest.json',
				'bytes'  => (int) \filesize( $manifest ),
				'sha256' => \hash_file( 'sha256', $manifest ),
			),
		);

		$reader   = $this->reader->manifest();
		$database = $reader->get( 'database' );

		if ( \is_array( $database ) && ! empty( $database['file'] ) ) {
			$files[] = $this->entry( $database );
		}

		foreach ( (array) $reader->get( 'parts', array() ) as $part ) {
			$files[] = $this->entry( $part );
		}

		foreach ( (array) $reader->get( 'large', array() ) as $large ) {
			$files[] = $this->entry( $large );
		}

		return \array_values( \array_filter( $files ) );
	}

	/**
	 * What the source chose to leave out of this package.
	 *
	 * Offered before the transfer starts rather than discovered from the manifest afterwards: the
	 * destination is about to spend hours fetching, and "this package has no media in it" is
	 * worth knowing at the start of that rather than the end.
	 *
	 * @return array Empty for a package that carries everything, or was built before this existed.
	 */
	public function contents() {
		return (array) \nfd_sm_data_get( $this->reader->inspect(), 'contents', array() );
	}

	/**
	 * How the transfer describes itself before a single byte moves.
	 *
	 * @return array
	 */
	public function summary() {
		$files = $this->files();
		$bytes = 0;

		foreach ( $files as $file ) {
			$bytes += $file['bytes'];
		}

		return array(
			'site_url'   => (string) \nfd_sm_data_get( $this->reader->inspect(), 'source.site_url', '' ),
			'created_at' => (string) \nfd_sm_data_get( $this->reader->inspect(), 'created_at', '' ),
			'bytes'      => $bytes,
			'files'      => $files,
		);
	}

	/**
	 * Resolve a requested path to a file this package actually offers.
	 *
	 * Two independent checks, because either alone has been wrong somewhere before. The name
	 * must appear in the manifest, and the resolved path must sit inside the package directory
	 * — a manifest is written by this plugin, but a package can be handed to a site by anyone,
	 * and `parts/../../../wp-config.php` should not become a working request just because
	 * somebody wrote it into a JSON file.
	 *
	 * @param string $relative Requested path within the package.
	 *
	 * @return string Absolute path, or empty when it is not on offer.
	 */
	public function resolve( $relative ) {
		$relative = \ltrim( \str_replace( '\\', '/', (string) $relative ), '/' );

		if ( '' === $relative ) {
			return '';
		}

		$known = false;

		foreach ( $this->files() as $file ) {
			if ( $file['file'] === $relative ) {
				$known = true;

				break;
			}
		}

		if ( ! $known ) {
			return '';
		}

		$root = \realpath( $this->dir );
		$path = \realpath( $root . DIRECTORY_SEPARATOR . $relative );

		if ( false === $root || false === $path || ! \is_file( $path ) ) {
			return '';
		}

		return 0 === \strpos( $path, $root . DIRECTORY_SEPARATOR ) ? $path : '';
	}

	/**
	 * One manifest entry, reduced to what a transfer needs.
	 *
	 * @param array $entry Manifest entry.
	 *
	 * @return array|null Null when it names no file.
	 */
	protected function entry( array $entry ) {
		if ( empty( $entry['file'] ) ) {
			return null;
		}

		return array(
			'file'   => (string) $entry['file'],
			'bytes'  => isset( $entry['bytes'] ) ? (int) $entry['bytes'] : 0,
			'sha256' => isset( $entry['sha256'] ) ? (string) $entry['sha256'] : '',
		);
	}
}
