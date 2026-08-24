<?php
/**
 * Collects one part of a package.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Export;

use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageWriter;
use NewfoldLabs\WP\SiteMigrator\Core\Package\PartSpec;

/**
 * One resumable implementation, driven by a PartSpec.
 *
 * This replaces PluginsArchiver, ThemesArchiver, UploadsArchiver, MuPluginsArchiver,
 * DropinsArchiver and RootArchiver, which were near-identical copies of each other
 * (finding 4.1) and each carried its own copy of the same offset-tracking bugs.
 *
 * Two things make it resumable without a custom archive format:
 *
 * - Files are added to the zip in small batches and the archive is closed after each one.
 *   ZipArchive does its compression work in close(), so batching bounds how much work a single
 *   step can be committed to before it can check the clock again.
 * - Files at or above the loose threshold are copied to `large/` as plain files instead. A zip
 *   entry cannot be appended to across steps; a plain copy resumes from a byte offset.
 */
class FileCollector {

	/**
	 * Files added to the archive before it is flushed and the clock re-checked.
	 */
	const BATCH = 64;

	/**
	 * Bytes copied per read when streaming a large file.
	 */
	const COPY_CHUNK = 4194304;

	/**
	 * Package writer.
	 *
	 * @var PackageWriter
	 */
	protected $package;

	/**
	 * Size at or above which a file is stored loose rather than zipped.
	 *
	 * @var int
	 */
	protected $loose_threshold;

	/**
	 * Size at which the current volume is closed and the next started.
	 *
	 * @var int
	 */
	protected $volume_limit;

	/**
	 * Constructor.
	 *
	 * @param PackageWriter $package         Package writer.
	 * @param int           $loose_threshold Loose-storage threshold in bytes.
	 * @param int           $volume_limit    Volume limit in bytes.
	 */
	public function __construct( PackageWriter $package, $loose_threshold, $volume_limit ) {
		$this->package         = $package;
		$this->loose_threshold = (int) $loose_threshold;
		$this->volume_limit    = (int) $volume_limit;
	}

	/**
	 * Enumerate everything a part will collect, into a list file.
	 *
	 * Done once per part, before any archiving, so that progress has a denominator and the
	 * walk is not repeated on every resume.
	 *
	 * @param PartSpec $spec Part description.
	 *
	 * @return array Totals as `files` and `bytes`.
	 */
	public function prepare( PartSpec $spec ) {
		$list_path = $this->package->list_path( $spec->name() );
		$handle    = \fopen( $list_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $handle ) {
			return array(
				'files' => 0,
				'bytes' => 0,
			);
		}

		$files = 0;
		$bytes = 0;

		foreach ( $this->walk( $spec ) as $entry ) {
			\fwrite( $handle, $entry['relative'] . "\t" . $entry['size'] . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			++$files;
			$bytes += $entry['size'];
		}

		\fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return array(
			'files' => $files,
			'bytes' => $bytes,
		);
	}

	/**
	 * Enumerate the files a part covers.
	 *
	 * @param PartSpec $spec Part description.
	 *
	 * @return array List of entries with `path`, `relative` and `size`.
	 */
	protected function walk( PartSpec $spec ) {
		$root = $spec->root();

		if ( ! \is_dir( $root ) ) {
			return array();
		}

		if ( $spec->is_allowlisted() ) {
			return $this->walk_allowlist( $spec );
		}

		if ( ! $spec->is_recursive() ) {
			return $this->walk_shallow( $spec );
		}

		return $this->walk_recursive( $spec );
	}

	/**
	 * Collect an explicit set of basenames from the top level of the root.
	 *
	 * @param PartSpec $spec Part description.
	 *
	 * @return array
	 */
	protected function walk_allowlist( PartSpec $spec ) {
		$entries = array();

		foreach ( $spec->allowlist() as $name ) {
			$path = $spec->root() . DIRECTORY_SEPARATOR . $name;

			if ( \is_file( $path ) && \is_readable( $path ) ) {
				$entries[] = $this->entry( $path, $name, $spec );
			}
		}

		return $entries;
	}

	/**
	 * Collect the files directly inside the root, without descending.
	 *
	 * @param PartSpec $spec Part description.
	 *
	 * @return array
	 */
	protected function walk_shallow( PartSpec $spec ) {
		$entries = array();
		$items   = \scandir( $spec->root() );

		if ( false === $items ) {
			return $entries;
		}

		foreach ( $items as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$path = $spec->root() . DIRECTORY_SEPARATOR . $name;

			if ( \is_file( $path ) && \is_readable( $path ) ) {
				$entries[] = $this->entry( $path, $name, $spec );
			}
		}

		return $entries;
	}

	/**
	 * Collect the whole tree below the root, skipping excluded directories.
	 *
	 * @param PartSpec $spec Part description.
	 *
	 * @return array
	 */
	protected function walk_recursive( PartSpec $spec ) {
		$entries  = array();
		$root     = $spec->root();
		$excluded = $spec->excluded_dirs();
		$skip     = $spec->excluded_files();

		$flags    = \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS;
		$dir_iter = new \RecursiveDirectoryIterator( $root, $flags );

		$filter = new \RecursiveCallbackFilterIterator(
			$dir_iter,
			function ( $current ) use ( $root, $excluded, $skip ) {
				$relative = \nfd_sm_relative_path( $root, $current->getPathname() );

				if ( $current->isDir() ) {
					// Returning false for a directory blocks descent into it, which is the
					// intended behaviour here and was the accidental behaviour that made the
					// old RootArchiver collect nothing but top-level files.
					return ! \in_array( $relative, $excluded, true );
				}

				if ( \in_array( $relative, $skip, true ) ) {
					return false;
				}

				return $current->isFile() && $current->isReadable();
			}
		);

		$iterator = new \RecursiveIteratorIterator( $filter, \RecursiveIteratorIterator::LEAVES_ONLY );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$entries[] = $this->entry(
				$file->getPathname(),
				\nfd_sm_relative_path( $root, $file->getPathname() ),
				$spec
			);
		}

		return $entries;
	}

	/**
	 * Build one list entry.
	 *
	 * @param string   $path     Absolute path.
	 * @param string   $relative Path relative to the part root.
	 * @param PartSpec $spec     Part description.
	 *
	 * @return array
	 */
	protected function entry( $path, $relative, PartSpec $spec ) {
		$prefix = $spec->prefix();

		return array(
			'path'     => $path,
			'relative' => '' === $prefix ? $relative : $prefix . '/' . $relative,
			'size'     => (int) \filesize( $path ),
		);
	}

	/**
	 * Archive as much of a part as the deadline allows.
	 *
	 * @param PartSpec $spec     Part description.
	 * @param array    $state    Run state, modified in place.
	 * @param float    $deadline Unix timestamp to stop by, or 0 for no limit.
	 *
	 * @return bool True when the part is complete.
	 *
	 * @throws \RuntimeException If the part's file list cannot be read.
	 */
	public function step( PartSpec $spec, array &$state, $deadline ) {
		$list_path = $this->package->list_path( $spec->name() );
		$handle    = \fopen( $list_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $handle ) {
			throw new \RuntimeException( \esc_html( 'Unable to read the file list for part: ' . $spec->name() ) );
		}

		if ( $state['list_offset'] > 0 ) {
			\fseek( $handle, $state['list_offset'] );
		}

		$complete = false;

		while ( true ) {
			$batch = $this->read_batch( $handle, $spec, $state, $deadline );

			// Flush before acting on `exhausted`. The batch that reaches the end of the list
			// still holds files, and a part smaller than one batch reaches the end on its
			// first read — breaking out first would discard every file it collected.
			if ( ! empty( $batch['files'] ) ) {
				$this->flush_batch( $spec, $batch['files'], $state );
			}

			// The checkpoint advances only now, after the archive has been closed and the
			// bytes are on disk.
			$state['list_offset'] = $batch['offset'];

			if ( $batch['exhausted'] ) {
				$complete = true;
				break;
			}

			if ( $batch['out_of_time'] ) {
				break;
			}
		}

		\fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $complete;
	}

	/**
	 * Read up to one batch of entries, handling large files inline.
	 *
	 * @param resource $handle   Open list-file handle.
	 * @param PartSpec $spec     Part description.
	 * @param array    $state    Run state, modified in place.
	 * @param float    $deadline Unix timestamp to stop by, or 0 for no limit.
	 *
	 * @return array
	 */
	protected function read_batch( $handle, PartSpec $spec, array &$state, $deadline ) {
		$files       = array();
		$offset      = \ftell( $handle );
		$exhausted   = false;
		$out_of_time = false;
		$collected   = 0;

		while ( $collected < self::BATCH ) {
			if ( $this->past( $deadline ) ) {
				$out_of_time = true;
				break;
			}

			$position = \ftell( $handle );
			$line     = \fgets( $handle );

			if ( false === $line ) {
				$exhausted = true;
				$offset    = $position;
				break;
			}

			$line = \rtrim( $line, "\r\n" );

			if ( '' === $line ) {
				$offset = \ftell( $handle );
				continue;
			}

			$parts    = \explode( "\t", $line );
			$relative = $parts[0];
			$size     = isset( $parts[1] ) ? (int) $parts[1] : 0;
			$source   = $this->source_path( $spec, $relative );

			if ( ! \is_readable( $source ) ) {
				// A file that vanished between the walk and now is skipped, not fatal.
				$offset = \ftell( $handle );
				continue;
			}

			if ( $size >= $this->loose_threshold ) {
				$done = $this->copy_large( $source, $relative, $state, $deadline );

				if ( ! $done ) {
					// Mid-file: leave the list offset pointing at this same entry so the copy
					// resumes here, and stop.
					return array(
						'files'       => $files,
						'offset'      => $position,
						'exhausted'   => false,
						'out_of_time' => true,
					);
				}

				$state['large'][]    = $relative;
				$state['bytes_done'] = (int) $state['bytes_done'] + $size;
				++$state['files_done'];
				$offset = \ftell( $handle );
				continue;
			}

			$files[] = array(
				'source'   => $source,
				'relative' => $relative,
				'size'     => $size,
			);
			++$collected;
			$offset = \ftell( $handle );
		}

		return array(
			'files'       => $files,
			'offset'      => $offset,
			'exhausted'   => $exhausted,
			'out_of_time' => $out_of_time,
		);
	}

	/**
	 * Add a batch of files to the current volume and flush it.
	 *
	 * @param PartSpec $spec  Part description.
	 * @param array    $files Batch of files.
	 * @param array    $state Run state, modified in place.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the archive cannot be opened.
	 */
	protected function flush_batch( PartSpec $spec, array $files, array &$state ) {
		$path = $this->package->part_path( $spec->name(), (int) $state['volume'] );
		$zip  = new \ZipArchive();

		if ( true !== $zip->open( $path, \ZipArchive::CREATE ) ) {
			throw new \RuntimeException( \esc_html( 'Unable to open archive: ' . $path ) );
		}

		$added = 0;

		foreach ( $files as $file ) {
			if ( $zip->addFile( $file['source'], $file['relative'] ) ) {
				++$added;
				$state['volume_bytes'] = (int) $state['volume_bytes'] + $file['size'];
				$state['bytes_done']   = (int) $state['bytes_done'] + $file['size'];
				++$state['files_done'];
			}
		}

		$zip->close();

		if ( $added > 0 ) {
			$relative = $this->package->part_relative( $spec->name(), (int) $state['volume'] );

			// Keyed by volume path, but carrying the part it belongs to. Import needs the
			// part name and the source prefix to decide where the files land; deriving them
			// from the file name loses both, since `plugins.002.zip` is still the plugins part.
			if ( ! isset( $state['parts'][ $relative ] ) ) {
				$state['parts'][ $relative ] = array(
					'name'   => $spec->name(),
					'prefix' => $spec->prefix(),
					'files'  => 0,
				);
			}

			$state['parts'][ $relative ]['files'] += $added;
		}

		if ( $state['volume_bytes'] >= $this->volume_limit ) {
			++$state['volume'];
			$state['volume_bytes'] = 0;
		}
	}

	/**
	 * Copy a large file into the package, resuming from the recorded offset.
	 *
	 * @param string $source   Absolute source path.
	 * @param string $relative Path relative to the WordPress install.
	 * @param array  $state    Run state, modified in place.
	 * @param float  $deadline Unix timestamp to stop by, or 0 for no limit.
	 *
	 * @return bool True when the copy finished.
	 */
	protected function copy_large( $source, $relative, array &$state, $deadline ) {
		$target = $this->package->large_path( $relative );
		$dir    = \dirname( $target );

		if ( ! \is_dir( $dir ) ) {
			\wp_mkdir_p( $dir );
		}

		$offset = ( $state['large_file'] === $relative ) ? (int) $state['large_offset'] : 0;

		$state['large_file']   = $relative;
		$state['large_offset'] = $offset;

		$in = \fopen( $source, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $in ) {
			return true;
		}

		$out = \fopen( $target, 0 === $offset ? 'wb' : 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $out ) {
			\fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			return true;
		}

		if ( $offset > 0 ) {
			\fseek( $in, $offset );
		}

		$done = false;

		while ( ! \feof( $in ) ) {
			$chunk = \fread( $in, self::COPY_CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			\fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$offset               += \strlen( $chunk );
			$state['large_offset'] = $offset;

			if ( $this->past( $deadline ) && ! \feof( $in ) ) {
				break;
			}
		}

		if ( \feof( $in ) ) {
			$done                  = true;
			$state['large_file']   = '';
			$state['large_offset'] = 0;
		}

		\fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		\fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $done;
	}

	/**
	 * Absolute source path for a list entry.
	 *
	 * @param PartSpec $spec     Part description.
	 * @param string   $relative Path as recorded in the list, including the part prefix.
	 *
	 * @return string
	 */
	protected function source_path( PartSpec $spec, $relative ) {
		$prefix = $spec->prefix();

		if ( '' !== $prefix && 0 === \strpos( $relative, $prefix . '/' ) ) {
			$relative = \substr( $relative, \strlen( $prefix ) + 1 );
		}

		return $spec->root() . DIRECTORY_SEPARATOR . \str_replace( '/', DIRECTORY_SEPARATOR, $relative );
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
