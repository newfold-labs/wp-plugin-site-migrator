<?php
/**
 * Receives a package through the browser, a piece at a time.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

use NewfoldLabs\WP\SiteMigrator\Core\Package\Manifest;
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
		self::clear_stamp();
	}

	/**
	 * What identifies the package a staging directory holds.
	 *
	 * Built from the manifest's declared contents rather than from how it describes itself,
	 * because the case this exists for is two packages that describe themselves identically. A
	 * re-exported site keeps its `site_url`, and a package corrected in place keeps its
	 * `created_at` and its `totals` -- what moves is a checksum. So every declared file's path,
	 * size and hash goes in, sorted, so that the order the writer happened to emit them in is
	 * not part of the identity.
	 *
	 * @param array $manifest Parsed manifest.
	 *
	 * @return string A hash, or '' when the manifest declares nothing to identify it by.
	 */
	public static function fingerprint( array $manifest ) {
		$entries = array();

		$declared = \array_merge(
			array( \nfd_sm_data_get( $manifest, 'database', array() ) ),
			(array) \nfd_sm_data_get( $manifest, 'parts', array() ),
			(array) \nfd_sm_data_get( $manifest, 'large', array() )
		);

		foreach ( $declared as $entry ) {
			if ( ! \is_array( $entry ) || empty( $entry['file'] ) ) {
				continue;
			}

			$entries[] = \sprintf(
				'%s:%d:%s',
				(string) $entry['file'],
				isset( $entry['bytes'] ) ? (int) $entry['bytes'] : 0,
				isset( $entry['sha256'] ) ? (string) $entry['sha256'] : ''
			);
		}

		if ( empty( $entries ) ) {
			return '';
		}

		\sort( $entries );

		\array_unshift(
			$entries,
			(string) \nfd_sm_data_get( $manifest, 'source.site_url', '' ),
			(string) \nfd_sm_data_get( $manifest, 'created_at', '' )
		);

		return \hash( 'sha256', \implode( "\n", $entries ) );
	}

	/**
	 * Make sure what is staged belongs to the package about to be sent.
	 *
	 * An upload resumes from the bytes already on disk -- that is what makes a dropped
	 * connection survivable, and it is also what silently merges two packages when the thing
	 * being uploaded has changed since. A file whose size did not move is skipped outright, so
	 * a package corrected in place is the worst case: the corrected bytes are never sent, and
	 * the manifest that would have caught it is itself appended to rather than replaced.
	 *
	 * `Puller::reconcile()` has made this check on the transfer side from the start. This is
	 * the same rule for the same directory, arrived at from the other door.
	 *
	 * @param array $manifest The manifest of the package about to be uploaded.
	 *
	 * @return int Bytes discarded, 0 when nothing needed clearing.
	 *
	 * @throws \RuntimeException If an unsettled import is staged here.
	 */
	public static function reconcile( array $manifest ) {
		$incoming = self::fingerprint( $manifest );

		if ( '' === $incoming ) {
			return 0;
		}

		$staged = self::staged_fingerprint();

		if ( $staged === $incoming ) {
			return 0;
		}

		$existing = self::staged_bytes();

		if ( $existing <= 0 ) {
			self::write_stamp( $incoming );

			return 0;
		}

		// The same refusal `Upload::discard()` and `Puller::reconcile()` make, for the same
		// reason: an import that has run and has not been kept or undone is still the only
		// account of what this site looked like, and its package is what the checkpoint names.
		$checkpoint = new ImportCheckpoint();
		$state      = $checkpoint->load();
		$recorded   = \realpath( \rtrim( (string) \nfd_sm_data_get( $state, 'package', '' ), '/\\' ) );
		$here       = \realpath( self::dir() );

		if ( false !== $here && $here === $recorded && ! ImportCheckpoint::is_settled( $state ) ) {
			throw new \RuntimeException(
				'An import from the package staged here has not been kept or undone yet. Finish it, undo it, or cancel it before uploading a different package.'
			);
		}

		self::reset();
		self::write_stamp( $incoming );

		return (int) $existing;
	}

	/**
	 * The identity of what is staged now.
	 *
	 * The stamp is the answer when there is one. Falling back to the staged manifest matters
	 * more than it looks: `expectedFiles()` sends `manifest.json` first, so an upload
	 * interrupted anywhere after its first few kilobytes has a readable one, and an upload that
	 * finished certainly does. What is left -- an upload cut off inside the manifest itself, or
	 * one begun by a version that did not stamp -- is a few kilobytes in, and starting those
	 * again costs nothing worth protecting.
	 *
	 * @return string A hash, or '' when nothing here can be identified.
	 */
	protected static function staged_fingerprint() {
		$stamped = self::read_stamp();

		if ( '' !== $stamped ) {
			return $stamped;
		}

		$manifest = Manifest::read( self::dir() );

		return null === $manifest ? '' : self::fingerprint( $manifest->to_array() );
	}

	/**
	 * How much is sitting in the staging directory.
	 *
	 * @return int Bytes.
	 */
	protected static function staged_bytes() {
		$dir = self::dir();

		if ( ! \is_dir( $dir ) ) {
			return 0;
		}

		$bytes = 0;

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $files as $file ) {
			if ( $file->isFile() ) {
				$bytes += (int) $file->getSize();
			}
		}

		return $bytes;
	}

	/**
	 * Where the staged package's identity is recorded.
	 *
	 * Beside the staging directory rather than inside it, like the transfer's refetch counts:
	 * `reset()` deletes that directory whole, and a marker that says what used to be there
	 * cannot live in the thing being removed. It is also not a file a package contains, and
	 * `incoming/` becomes a package the moment the last byte lands.
	 *
	 * @return string
	 */
	protected static function stamp_path() {
		return \rtrim( \nfd_sm_storage_path(), '/\\' ) . DIRECTORY_SEPARATOR . 'upload-stamp.json';
	}

	/**
	 * Record which package the staging directory is being filled with.
	 *
	 * @param string $fingerprint Identity of that package.
	 *
	 * @return void
	 */
	protected static function write_stamp( $fingerprint ) {
		\file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::stamp_path(),
			(string) \wp_json_encode( array( 'fingerprint' => (string) $fingerprint ) ),
			LOCK_EX
		);
	}

	/**
	 * The recorded identity, if there is one.
	 *
	 * @return string
	 */
	protected static function read_stamp() {
		$path = self::stamp_path();

		if ( ! \is_readable( $path ) ) {
			return '';
		}

		$decoded = \json_decode( (string) \file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return \is_array( $decoded ) && isset( $decoded['fingerprint'] )
			? (string) $decoded['fingerprint']
			: '';
	}

	/**
	 * Forget which package was being staged.
	 *
	 * @return void
	 */
	protected static function clear_stamp() {
		$path = self::stamp_path();

		if ( \is_readable( $path ) ) {
			\unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
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
			throw new \RuntimeException( 'That is not a path inside a package: ' . $relative );
		}

		$parent = \dirname( $path );

		if ( ! \is_dir( $parent ) && ! \wp_mkdir_p( $parent ) ) {
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
					$relative,
					(int) $offset,
					$have
				)
			);
		}

		$handle = \fopen( $path, 0 === $have ? 'wb' : 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $handle ) {
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
