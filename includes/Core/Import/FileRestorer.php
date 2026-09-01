<?php
/**
 * Unpacks a package's files onto this site.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

use NewfoldLabs\WP\SiteMigrator\Core\Package\Manifest;

/**
 * Restores the file half of a package, one bounded step at a time.
 *
 * Entry by entry rather than `ZipArchive::extractTo()`, for two reasons. Extraction has to be
 * resumable — a 3GB uploads archive will not finish inside one PHP request — and every path
 * has to pass through PathMap before anything is written. `extractTo()` gives neither: it runs
 * to completion or not at all, and it writes whatever path the archive names.
 */
class FileRestorer {

	/**
	 * Bytes copied per read while streaming one entry.
	 */
	const CHUNK = 262144;

	/**
	 * Absolute package directory.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Package manifest.
	 *
	 * @var Manifest
	 */
	protected $manifest;

	/**
	 * Destination layout.
	 *
	 * @var PathMap
	 */
	protected $map;

	/**
	 * Paths refused during this run.
	 *
	 * @var array
	 */
	protected $refused = array();

	/**
	 * Constructor.
	 *
	 * @param string   $dir      Absolute package directory.
	 * @param Manifest $manifest Package manifest.
	 */
	public function __construct( $dir, Manifest $manifest ) {
		$this->dir      = \rtrim( $dir, '/\\' );
		$this->manifest = $manifest;
		$this->map      = new PathMap();
	}

	/**
	 * Restore as much as the deadline allows.
	 *
	 * @param array $state    Import state, modified in place.
	 * @param float $deadline Unix timestamp to stop by, or 0 for no limit.
	 *
	 * @return bool True when every part and every loose file has been written.
	 *
	 * @throws \RuntimeException If an archive cannot be opened.
	 */
	public function step( array &$state, $deadline ) {
		$parts = (array) $this->manifest->get( 'parts', array() );
		$count = \count( $parts );

		while ( $state['part_index'] < $count ) {
			$part = $parts[ $state['part_index'] ];

			if ( ! $this->restore_part( $part, $state, $deadline ) ) {
				return false;
			}

			++$state['part_index'];
			$state['entry_index'] = 0;

			if ( $this->past( $deadline ) ) {
				return false;
			}
		}

		return $this->restore_large( $state, $deadline );
	}

	/**
	 * Paths the package asked for that were refused.
	 *
	 * @return array
	 */
	public function refused() {
		return $this->refused;
	}

	/**
	 * Extract one archive, continuing from the recorded entry index.
	 *
	 * @param array $part     Manifest part entry.
	 * @param array $state    Import state, modified in place.
	 * @param float $deadline Unix timestamp to stop by.
	 *
	 * @return bool True when the archive is fully extracted.
	 *
	 * @throws \RuntimeException If the archive cannot be opened.
	 */
	protected function restore_part( array $part, array &$state, $deadline ) {
		$path = $this->dir . '/' . \ltrim( isset( $part['file'] ) ? $part['file'] : '', '/' );

		if ( ! \is_readable( $path ) ) {
			throw new \RuntimeException(
				'Missing archive: ' . $path
			);
		}

		$target = $this->map->target(
			isset( $part['name'] ) ? $part['name'] : '',
			isset( $part['prefix'] ) ? $part['prefix'] : ''
		);

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $path ) ) {
			throw new \RuntimeException(
				'Unable to open archive: ' . $path
			);
		}

		$total = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		while ( $state['entry_index'] < $total ) {
			$name = $zip->getNameIndex( (int) $state['entry_index'] );

			if ( false !== $name ) {
				$this->write_entry( $zip, $name, $target, $state );
			}

			++$state['entry_index'];

			// Checked every entry rather than every batch: one entry can be a 200MB theme
			// zip inside uploads, and a budget that is only consulted between batches is not
			// a budget.
			if ( $this->past( $deadline ) ) {
				$zip->close();

				return false;
			}
		}

		$zip->close();

		return true;
	}

	/**
	 * Write one archive entry to disk.
	 *
	 * @param \ZipArchive $zip    Open archive.
	 * @param string      $name   Entry name.
	 * @param array       $target Root and prefix to strip.
	 * @param array       $state  Import state, modified in place.
	 *
	 * @return void
	 */
	protected function write_entry( \ZipArchive $zip, $name, array $target, array &$state ) {
		$path = PathMap::safe_path( $target['root'], $name, $target['strip'] );

		if ( '' === $path ) {
			// Directory entries land here too, which is why this is not treated as an error.
			if ( '/' !== \substr( $name, -1 ) ) {
				$this->refused[] = $name;
			}

			return;
		}

		// Not `refused`: nothing is wrong with the package, and the user is told separately.
		if ( $this->map->is_protected( $path ) ) {
			++$state['migrator_skipped'];

			return;
		}

		if ( ! $this->prepare_parent( $target['root'], $path ) ) {
			$this->refused[] = $name;

			return;
		}

		// A symlink at the leaf is the same trick one level down. Replace the link rather than
		// write to whatever it points at.
		if ( \is_link( $path ) ) {
			\unlink( $path );
		}

		$stream = $zip->getStream( $name );

		if ( ! \is_resource( $stream ) ) {
			$this->refused[] = $name;

			return;
		}

		$handle = \fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $handle ) {
			\fclose( $stream );
			$this->refused[] = $name;

			return;
		}

		$bytes = 0;

		while ( ! \feof( $stream ) ) {
			$chunk = \fread( $stream, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			\fwrite( $handle, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$bytes += \strlen( $chunk );
		}

		\fclose( $handle );
		\fclose( $stream );

		++$state['files_done'];
		$state['bytes_done'] += $bytes;
	}

	/**
	 * Copy the loosely stored large files, resuming mid-file.
	 *
	 * @param array $state    Import state, modified in place.
	 * @param float $deadline Unix timestamp to stop by.
	 *
	 * @return bool True when every large file has been copied.
	 */
	protected function restore_large( array &$state, $deadline ) {
		$large = (array) $this->manifest->get( 'large', array() );
		$root  = \rtrim( ABSPATH, '/\\' );
		$count = \count( $large );

		while ( $state['large_index'] < $count ) {
			$entry  = $large[ $state['large_index'] ];
			$source = $this->dir . '/' . \ltrim( isset( $entry['file'] ) ? $entry['file'] : '', '/' );
			$path   = PathMap::safe_path( $root, isset( $entry['path'] ) ? $entry['path'] : '' );

			if ( '' === $path || ! \is_readable( $source ) ) {
				$this->refused[] = isset( $entry['path'] ) ? $entry['path'] : '(unnamed)';
				++$state['large_index'];
				$state['large_offset'] = 0;

				continue;
			}

			if ( $this->map->is_protected( $path ) ) {
				++$state['migrator_skipped'];
				++$state['large_index'];
				$state['large_offset'] = 0;

				continue;
			}

			if ( ! $this->copy_resumable( $root, $source, $path, $state, $deadline ) ) {
				return false;
			}

			++$state['files_done'];
			++$state['large_index'];
			$state['large_offset'] = 0;

			if ( $this->past( $deadline ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Copy a file from a byte offset, stopping at the deadline.
	 *
	 * @param string $root     Absolute destination root.
	 * @param string $source   Absolute source path.
	 * @param string $path     Absolute destination path.
	 * @param array  $state    Import state, modified in place.
	 * @param float  $deadline Unix timestamp to stop by.
	 *
	 * @return bool True when the copy finished.
	 */
	protected function copy_resumable( $root, $source, $path, array &$state, $deadline ) {
		if ( ! $this->prepare_parent( $root, $path ) ) {
			$this->refused[] = $path;

			return true;
		}

		if ( \is_link( $path ) ) {
			\unlink( $path );
		}

		$offset = (int) $state['large_offset'];
		$in     = \fopen( $source, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out    = \fopen( $path, 0 === $offset ? 'wb' : 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $in || false === $out ) {
			if ( \is_resource( $in ) ) {
				\fclose( $in );
			}

			if ( \is_resource( $out ) ) {
				\fclose( $out );
			}

			$this->refused[] = $path;

			return true;
		}

		\fseek( $in, $offset );
		\fseek( $out, $offset );

		$complete = true;

		while ( ! \feof( $in ) ) {
			$chunk = \fread( $in, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			\fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			$offset              += \strlen( $chunk );
			$state['bytes_done'] += \strlen( $chunk );

			if ( $this->past( $deadline ) ) {
				$complete = false;

				break;
			}
		}

		// The offset is recorded after the bytes are on disk, never before, so a resume can
		// only ever repeat work rather than skip it.
		$state['large_offset'] = $offset;

		\fclose( $in );
		\fclose( $out );

		return $complete;
	}

	/**
	 * Make sure a file's directory exists and genuinely sits inside the root.
	 *
	 * `safe_path()` proves the *name* stays inside the root. It cannot prove the filesystem
	 * agrees: if any directory along the way is a symlink pointing elsewhere, a name that looks
	 * entirely innocent writes outside the site. `wp-content/plugins/cache/x.php` is a clean
	 * path right up until `cache` turns out to be a link to `/`.
	 *
	 * So the deepest ancestor that actually exists is resolved with `realpath()`, which follows
	 * every link, and the answer has to still be under the root.
	 *
	 * @param string $root Absolute destination root.
	 * @param string $path Absolute file path.
	 *
	 * @return bool
	 */
	protected function prepare_parent( $root, $path ) {
		$real_root = \realpath( $root );

		if ( false === $real_root ) {
			return false;
		}

		$parent = \dirname( $path );
		$walk   = $parent;

		while ( ! \file_exists( $walk ) && \strlen( $walk ) > 1 ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops
			$walk = \dirname( $walk );
		}

		$resolved = \realpath( $walk );

		if ( false === $resolved ) {
			return false;
		}

		$real_root = \rtrim( $real_root, '/' );

		if ( $resolved !== $real_root && 0 !== \strpos( $resolved . '/', $real_root . '/' ) ) {
			return false;
		}

		if ( \is_dir( $parent ) ) {
			return true;
		}

		return (bool) \wp_mkdir_p( $parent );
	}

	/**
	 * Whether the deadline has passed.
	 *
	 * @param float $deadline Unix timestamp, or 0 for no limit.
	 *
	 * @return bool
	 */
	protected function past( $deadline ) {
		return $deadline > 0 && \microtime( true ) >= $deadline;
	}
}
