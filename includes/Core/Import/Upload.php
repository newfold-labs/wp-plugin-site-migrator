<?php
/**
 * Receives a package through the browser, a piece at a time.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageReader;
use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageWriter;

/**
 * Chunked, resumable upload into a staging directory.
 *
 * A package is routinely gigabytes and the hosts this plugin exists for cap a single upload at
 * 8MB. So the browser slices each file and appends the slices here, and because every write
 * declares the offset it belongs at, a refused or repeated chunk is an error rather than
 * silent corruption.
 *
 * Nothing here trusts the client about what it is sending. Paths go through PathMap, offsets
 * must match the bytes already on disk, and completeness is judged by re-verifying the whole
 * directory against the manifest's own checksums — not against the sizes the uploader claimed.
 */
class Upload {

	const DIR = 'incoming';

	/**
	 * Largest chunk to ask a client for, whatever the server would allow.
	 */
	const MAX_CHUNK = 8388608;

	/**
	 * Smallest chunk worth using.
	 */
	const MIN_CHUNK = 262144;

	/**
	 * Where uploaded pieces are assembled.
	 *
	 * @return string
	 */
	public static function dir() {
		$dir = \rtrim( \nfd_sm_storage_path(), '/\\' ) . DIRECTORY_SEPARATOR . self::DIR;

		if ( ! \is_dir( $dir ) ) {
			\wp_mkdir_p( $dir );
		}

		// The same protection the package directory gets. This one holds an entire site too,
		// and for longer.
		$writer = new PackageWriter( $dir );
		$writer->prepare();

		return $dir;
	}

	/**
	 * The largest chunk this server will actually accept.
	 *
	 * Derived rather than guessed, because getting it wrong is the difference between an upload
	 * that works and a 413 the user cannot interpret. The margin covers the rest of the request:
	 * headers, the route, and whatever the host's proxy adds.
	 *
	 * @return int Bytes.
	 */
	public static function chunk_size() {
		$limits = array();

		foreach ( array( 'post_max_size', 'upload_max_filesize' ) as $setting ) {
			$value = self::bytes( \ini_get( $setting ) );

			if ( $value > 0 ) {
				$limits[] = $value;
			}
		}

		$limit = empty( $limits ) ? self::MAX_CHUNK : \min( $limits );
		$size  = (int) \floor( $limit * 0.8 );

		$size = \min( $size, self::MAX_CHUNK );
		$size = \max( $size, self::MIN_CHUNK );

		/**
		 * Filter the chunk size the client is told to use.
		 *
		 * @param int $size Bytes.
		 */
		return (int) \apply_filters( 'nfd_sm_upload_chunk_size', $size );
	}

	/**
	 * Start over: remove anything previously uploaded.
	 *
	 * @return void
	 */
	public static function reset() {
		\nfd_sm_delete_directory( self::dir() );
	}

	/**
	 * How much of each declared file is already here.
	 *
	 * The client resumes from this rather than from anything it remembers, so a reload, a
	 * different tab, or a different machine all continue the same upload.
	 *
	 * @param array $files Relative paths the client intends to send.
	 *
	 * @return array Map of relative path to bytes received.
	 */
	public static function received( array $files ) {
		$dir  = self::dir();
		$have = array();

		foreach ( $files as $relative ) {
			$path = PathMap::safe_path( $dir, $relative );

			$have[ $relative ] = ( '' !== $path && \is_readable( $path ) ) ? (int) \filesize( $path ) : 0;
		}

		return $have;
	}

	/**
	 * Append one chunk.
	 *
	 * @param string $relative Path within the package.
	 * @param int    $offset   Byte offset this chunk starts at.
	 * @param string $bytes    The chunk.
	 *
	 * @return int Total bytes now on disk for that file.
	 *
	 * @throws \RuntimeException If the path is refused or the offset does not line up.
	 */
	public static function chunk( $relative, $offset, $bytes ) {
		$dir  = self::dir();
		$path = PathMap::safe_path( $dir, $relative );

		if ( '' === $path ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an exception message, not output: it reaches a terminal or a JSON field, never an HTML page.
			throw new \RuntimeException( 'That is not a path inside a package: ' . $relative );
		}

		$parent = \dirname( $path );

		if ( ! \is_dir( $parent ) && ! \wp_mkdir_p( $parent ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an exception message, not output: it reaches a terminal or a JSON field, never an HTML page.
			throw new \RuntimeException( 'Could not create a directory for ' . $relative );
		}

		$have = \is_readable( $path ) ? (int) \filesize( $path ) : 0;

		// A chunk that is not the next one is refused rather than written somewhere plausible.
		// Writing at a declared offset would let a lost chunk leave a hole full of zero bytes,
		// and the checksum would then fail at the end of a multi-gigabyte upload instead of
		// here.
		if ( (int) $offset !== $have ) {
			throw new \RuntimeException(
				\sprintf(
					'Chunk out of order for %s: it starts at %d but %d bytes are here.',
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an exception message, not output: it reaches a terminal or a JSON field, never an HTML page.
					$relative,
					(int) $offset,
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an exception message, not output: it reaches a terminal or a JSON field, never an HTML page.
					$have
				)
			);
		}

		$handle = \fopen( $path, 0 === $have ? 'wb' : 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $handle ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an exception message, not output: it reaches a terminal or a JSON field, never an HTML page.
			throw new \RuntimeException( 'Could not open ' . $relative . ' for writing.' );
		}

		// LOCK_EX so two tabs uploading the same package cannot interleave writes.
		\flock( $handle, LOCK_EX );
		$written = \fwrite( $handle, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		\fflush( $handle );
		\flock( $handle, LOCK_UN );
		\fclose( $handle );

		if ( false === $written || \strlen( $bytes ) !== $written ) {
			throw new \RuntimeException(
				'Only part of that chunk could be written. The server may be out of disk space.'
			);
		}

		return $have + $written;
	}

	/**
	 * Check that what arrived is a complete, undamaged package.
	 *
	 * Judged against the manifest's own checksums rather than the sizes the uploader declared,
	 * because the uploader is the thing being checked.
	 *
	 * @return array Problems found, empty when the package is sound.
	 */
	public static function verify() {
		$dir = self::dir();

		if ( ! \is_readable( $dir . DIRECTORY_SEPARATOR . 'manifest.json' ) ) {
			return array( 'manifest.json has not arrived yet, so there is nothing to check against.' );
		}

		$reader = new PackageReader( $dir );

		return $reader->verify();
	}

	/**
	 * Package directories already sitting on this server.
	 *
	 * The escape hatch for a site too large to push through a browser: put the package on the
	 * server by FTP, SFTP, or the host's file manager, and the import finds it. Looked for in
	 * the few places somebody would actually put it, one level deep, rather than by walking the
	 * whole install.
	 *
	 * @return array List of `path`, `label`, `source`, `created_at`, `bytes`.
	 */
	public static function discover() {
		$roots = array(
			\rtrim( \nfd_sm_storage_path(), '/\\' ),
			\rtrim( (string) \nfd_sm_uploads_dir(), '/\\' ),
			\rtrim( \WP_CONTENT_DIR, '/\\' ),
			\rtrim( ABSPATH, '/\\' ),
		);

		$found = array();
		$seen  = array();

		foreach ( \array_unique( \array_filter( $roots ) ) as $root ) {
			foreach ( self::candidates( $root ) as $candidate ) {
				$real = \realpath( $candidate );

				if ( false === $real || isset( $seen[ $real ] ) ) {
					continue;
				}

				$seen[ $real ] = true;

				$reader = new PackageReader( $candidate );

				if ( ! $reader->is_complete() ) {
					continue;
				}

				$info = $reader->inspect();

				$found[] = array(
					'path'       => $candidate,
					'label'      => \basename( $candidate ),
					'source'     => \nfd_sm_data_get( $info, 'source.site_url', '' ),
					'created_at' => \nfd_sm_data_get( $info, 'created_at', '' ),
					'bytes'      => (int) \nfd_sm_data_get( $info, 'totals.bytes', 0 ),
				);
			}
		}

		return $found;
	}

	/**
	 * Delete a package this site is offering as a source.
	 *
	 * A package is a whole site on disk — the two on the test install were 2.2GB each — and
	 * until now the only way to reclaim that was a shell. The screen that lists them is the
	 * place to remove them from, because it is the only place they are visible.
	 *
	 * The path is not taken on trust. It is matched, by `realpath()`, against what `discover()`
	 * itself reports: an endpoint that deletes whatever directory it is handed is an
	 * arbitrary-deletion endpoint, and `manage_options` is not a good enough reason for that to
	 * exist. Everything else here is a refusal to delete something still being relied on.
	 *
	 * @param string $path Absolute package directory.
	 *
	 * @return int Bytes the package recorded for itself.
	 *
	 * @throws \RuntimeException If the path is not a package this site offers, or is in use.
	 */
	public static function discard( $path ) {
		$path = \rtrim( (string) $path, '/\\' );

		// `realpath('')` is the working directory, not nothing. Under PHP-FPM that can be the
		// WordPress root, which `discover()` does look at — so an empty parameter must be
		// refused before it is resolved, not compared after.
		if ( '' === $path ) {
			throw new \RuntimeException( 'No package was named, so nothing was deleted.' );
		}

		$real  = \realpath( $path );
		$bytes = -1;

		foreach ( self::discover() as $found ) {
			if ( false !== $real && \realpath( $found['path'] ) === $real ) {
				$bytes = (int) $found['bytes'];

				break;
			}
		}

		if ( $bytes < 0 ) {
			throw new \RuntimeException(
				'That is not a package this site is offering, so nothing was deleted.'
			);
		}

		// `discover()` looks at each root itself as well as its children, so a package could in
		// principle be found at a path that also holds this site's own import state. Deleting it
		// would take the checkpoint with it, and with the checkpoint goes the ability to roll
		// back at all.
		$state_dir = \realpath( ImportCheckpoint::state_dir() );

		if ( false !== $state_dir && ( $state_dir === $real || 0 === \strpos( $state_dir, $real . '/' ) ) ) {
			throw new \RuntimeException(
				'That directory holds this site\'s own import state, so it was left alone.'
			);
		}

		$checkpoint = new ImportCheckpoint();
		$state      = $checkpoint->load();
		$recorded   = \rtrim( (string) $state['package'], '/\\' );
		$in_use     = '' === $recorded ? false : \realpath( $recorded );

		if ( false !== $in_use && $in_use === $real && ! ImportCheckpoint::is_settled( $state ) ) {
			throw new \RuntimeException(
				'This is the package the current import is using. Finish it, undo it, or cancel it first.'
			);
		}

		\nfd_sm_delete_directory( $real );

		return $bytes;
	}

	/**
	 * Directories one level under a root that might hold a package.
	 *
	 * @param string $root Absolute directory.
	 *
	 * @return array Absolute paths, including the root itself.
	 */
	protected static function candidates( $root ) {
		$paths = array( $root );

		if ( ! \is_dir( $root ) || ! \is_readable( $root ) ) {
			return array();
		}

		$entries = @\scandir( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		foreach ( (array) $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $root . DIRECTORY_SEPARATOR . $entry;

			if ( \is_dir( $path ) ) {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	/**
	 * Turn a php.ini size into bytes.
	 *
	 * @param string $value Value such as `8M`.
	 *
	 * @return int
	 */
	protected static function bytes( $value ) {
		$value = \trim( (string) $value );

		if ( '' === $value ) {
			return 0;
		}

		$unit   = \strtolower( \substr( $value, -1 ) );
		$number = (int) $value;

		switch ( $unit ) {
			case 'g':
				return $number * 1024 * 1024 * 1024;
			case 'm':
				return $number * 1024 * 1024;
			case 'k':
				return $number * 1024;
			default:
				return $number;
		}
	}
}
