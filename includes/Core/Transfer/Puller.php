<?php
/**
 * Pulls a package from a paired source, a range at a time.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Transfer;

use NewfoldLabs\WP\SiteMigrator\Core\Import\ImportCheckpoint;
use NewfoldLabs\WP\SiteMigrator\Core\Import\PathMap;
use NewfoldLabs\WP\SiteMigrator\Core\Import\Upload;

/**
 * The v2 transport: the destination fetches the package itself, and nothing downstream notices.
 *
 * The plan called for `PackageReader` to grow a remote backend. It cannot: the parts are zip
 * archives and `ZipArchive` reads a local file, so an import driven straight off HTTP would mean
 * reimplementing zip. It is also unnecessary — the import already needs the package on disk, so
 * putting it there is not a cost the direct transfer adds. What this replaces is the *carrying*:
 * the bytes land in exactly the directory the browser upload fills, and verification, preview,
 * `Importer`, `PathMap` and rollback are the code that already worked.
 *
 * **Pull, not push.** The work happens inside the destination's own request, so the site doing the
 * writing is the site reporting the progress — the same contract as every other step loop here,
 * and the reason the progress bar cannot lie. It also means the person authorising the overwrite
 * is standing at the machine being overwritten.
 *
 * **Progress is the bytes on disk, not a number this class keeps.** Sizes come from the manifest
 * the source declared; how far along each file is comes from `filesize()`. So there is no
 * checkpoint to fall out of step with the files, a step never writes a record, and a browser
 * closed mid-transfer resumes by asking the disk what it already has.
 *
 * **Every completed file is hashed as it lands.** The upload path verifies the whole package in
 * one request at the end, which for a 2.2GB package is a minute that cannot be split — and it
 * finds a damaged first part only after the last one has arrived. Hashing each file at the moment
 * it completes costs the same total reading, spread across the transfer, and a part that arrives
 * wrong is refetched immediately rather than ten gigabytes later.
 */
class Puller {

	/**
	 * Range size to open with, before anything has been measured.
	 */
	const CHUNK = 8388608;

	/**
	 * Range size floor and ceiling.
	 */
	const MIN_CHUNK = 1048576;
	const MAX_CHUNK = 33554432;

	/**
	 * Seconds a single range request should aim to take.
	 */
	const TARGET = 5.0;

	/**
	 * Seconds before a single range request is given up on.
	 */
	const TIMEOUT = 120;

	/**
	 * How long a run lock is trusted before it is treated as abandoned.
	 */
	const LOCK_SECONDS = 300;

	/**
	 * Times one file may be refetched after arriving damaged.
	 */
	const MAX_RETRY = 2;

	/**
	 * Where the pieces are assembled.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Constructor.
	 *
	 * @param string $dir Staging directory. Defaults to the one the browser upload uses.
	 */
	public function __construct( $dir = '' ) {
		$this->dir = '' === $dir ? Upload::dir() : \rtrim( $dir, '/\\' );
	}

	/**
	 * Speak to a source, and remember it if it answers.
	 *
	 * The two URL shapes are tried here and the winner is stored, so the hundreds of requests
	 * that follow go straight to the one that works.
	 *
	 * @param string $url Source site URL.
	 * @param string $key Transfer key issued there.
	 *
	 * @return array `summary` and `cleared` bytes, or `error` describing why not.
	 */
	public function connect( $url, $key ) {
		$key = TransferKey::normalise( $key );

		if ( '' === $key ) {
			return array( 'error' => 'That does not look like a transfer key. It is 48 characters long.' );
		}

		$bases = \nfd_sm_rest_bases( $url );

		if ( empty( $bases ) ) {
			return array( 'error' => 'Give me the source\'s address.' );
		}

		$last = array( 'error' => 'The source did not answer.' );

		foreach ( $bases as $base ) {
			$attempt = $this->ask( $base, $key );

			if ( isset( $attempt['summary'] ) ) {
				try {
					$cleared = $this->reconcile( $attempt['summary'] );
				} catch ( \RuntimeException $e ) {
					return array( 'error' => $e->getMessage() );
				}

				Source::connect( $url, $key, $base, $attempt['summary'] );

				return array(
					'summary' => $attempt['summary'],
					'cleared' => $cleared,
				);
			}

			if ( ! empty( $attempt['final'] ) ) {
				unset( $attempt['final'] );

				return $attempt;
			}

			$last = $attempt;
		}

		unset( $last['final'] );

		return $last;
	}

	/**
	 * Stop pulling and forget the source, keeping whatever has already arrived.
	 *
	 * The bytes are the expensive part and they are still valid; a user who wants them gone has
	 * `Upload::reset()` on the same screen. Forgetting the source is what makes the key stop
	 * being held here.
	 *
	 * @return void
	 */
	public function disconnect() {
		Source::forget();
		$this->clear_retries();
	}

	/**
	 * Fetch as much as fits in the budget.
	 *
	 * @param float $budget Seconds, or 0 for no limit.
	 *
	 * @return array The same shape `snapshot()` returns.
	 *
	 * @throws \RuntimeException If no source is connected, or the source stops cooperating.
	 */
	public function step( $budget = 0 ) {
		$state = Source::load();

		if ( empty( $state ) ) {
			throw new \RuntimeException( 'No source is connected, so there is nothing to fetch.' );
		}

		if ( $this->is_running() ) {
			// Two tabs appending to the same file at the same offset is silent corruption that
			// only the closing checksum would catch, so a second pull waits its turn rather
			// than helping.
			return \array_merge( $this->snapshot(), array( 'running' => true ) );
		}

		$this->claim();

		$notes    = array();
		$deadline = $budget > 0 ? ( \microtime( true ) + $budget ) : 0;
		$chunk    = self::CHUNK;

		// A step must always make one attempt before the budget can stop it. Without this, a
		// budget that is spent before the loop reaches an incomplete file — which a large
		// package's walk over its finished parts can do on its own, on a host with a tight one
		// — returns having fetched nothing, and the caller loops forever against a number that
		// never moves. "As much as fits" has a floor of one.
		$fetched = 0;

		try {
			foreach ( (array) $state['files'] as $file ) {
				$relative = (string) \nfd_sm_data_get( $file, 'file', '' );
				$total    = (int) \nfd_sm_data_get( $file, 'bytes', 0 );
				$target   = PathMap::safe_path( $this->dir, $relative );

				if ( '' === $target ) {
					throw new \RuntimeException( 'The source named a file that is not a path inside a package: ' . $relative );
				}

				$this->prepare( $target );
				$have = $this->have( $target );

				if ( $have > $total ) {
					// Longer than the manifest says it should be. Whatever produced that, none
					// of it can be trusted, and appending to it would only bury the problem.
					$this->discard( $target );
					$notes[] = \sprintf( '%s was larger than the source says it is, so it is being fetched again.', $relative );
					$have    = 0;
				}

				if ( 0 === $total && ! \is_file( $target ) ) {
					$this->touch( $target );

					continue;
				}

				while ( $have < $total ) {
					if ( $fetched > 0 && $deadline > 0 && \microtime( true ) >= $deadline ) {
						$this->release();

						return $this->report( $notes );
					}

					$started = \microtime( true );
					$got     = $this->fetch( $state, $relative, $have, \min( $chunk, $total - $have ), $total, $target );
					$have   += $got;
					$chunk   = $this->adapt( $chunk, $got, \microtime( true ) - $started );
					++$fetched;
				}

				$damaged = $this->settle( $file, $target );

				if ( '' !== $damaged ) {
					$notes[] = $damaged;
				}
			}
		} catch ( \Exception $e ) {
			$this->release();

			throw new \RuntimeException( $e->getMessage() );
		}

		$this->release();

		$report = $this->report( $notes );

		if ( $report['done'] ) {
			Source::complete();
			$this->clear_retries();
		}

		return $report;
	}

	/**
	 * Where the transfer has got to, without moving it along.
	 *
	 * A reloaded tab draws the transfer it is joining before asking for more of it — the same
	 * reason `Exporter::snapshot()` exists.
	 *
	 * @return array
	 */
	public function snapshot() {
		return \array_merge(
			$this->report( array() ),
			array( 'running' => $this->is_running() )
		);
	}

	/**
	 * Count what is on disk against what the source declared.
	 *
	 * @param array $notes Notes to carry back.
	 *
	 * @return array
	 */
	protected function report( array $notes ) {
		$state = Source::load();

		$report = array(
			'connected'   => ! empty( $state ),
			'done'        => false,
			'running'     => false,
			'source'      => empty( $state ) ? '' : $state['site_url'],
			'created_at'  => empty( $state ) ? '' : $state['created_at'],
			'files_done'  => 0,
			'files_total' => 0,
			'bytes_done'  => 0,
			'bytes_total' => empty( $state ) ? 0 : (int) $state['bytes'],
			'current'     => '',
			'notes'       => $notes,
		);

		if ( empty( $state ) ) {
			return $report;
		}

		foreach ( (array) $state['files'] as $file ) {
			$relative = (string) \nfd_sm_data_get( $file, 'file', '' );
			$total    = (int) \nfd_sm_data_get( $file, 'bytes', 0 );
			$target   = PathMap::safe_path( $this->dir, $relative );
			$have     = '' === $target ? 0 : \min( $this->have( $target ), $total );

			++$report['files_total'];
			$report['bytes_done'] += $have;

			if ( $have >= $total && ( 0 !== $total || \is_file( $target ) ) ) {
				++$report['files_done'];

				continue;
			}

			if ( '' === $report['current'] ) {
				$report['current'] = $relative;
			}
		}

		$report['done'] = $report['files_total'] > 0 && $report['files_done'] === $report['files_total'];

		return $report;
	}

	/**
	 * Ask one base for the package listing.
	 *
	 * An answer in JSON came from the REST API, so it is the source's real answer and no other
	 * URL will do better — those are marked `final`, exactly as the pairing handshake does.
	 *
	 * @param string $base REST base to try.
	 * @param string $key  Transfer key.
	 *
	 * @return array `summary`, or `error` plus `final`.
	 */
	protected function ask( $base, $key ) {
		$response = \wp_remote_get(
			\nfd_sm_rest_url( $base, 'transfer/package' ),
			array(
				'timeout'   => 30,
				'sslverify' => true,
				'headers'   => $this->headers( $key ),
			)
		);

		if ( \is_wp_error( $response ) ) {
			return array(
				'error' => 'Could not reach the source: ' . $response->get_error_message(),
				'final' => true,
			);
		}

		$status = (int) \wp_remote_retrieve_response_code( $response );
		$json   = false !== \strpos( (string) \wp_remote_retrieve_header( $response, 'content-type' ), 'json' );

		if ( 404 === $status ) {
			return array(
				'error' => 'The source did not accept that key. It may have expired, been withdrawn, or already been claimed by another site — generate a new one there.',
				'final' => $json,
			);
		}

		if ( 409 === $status ) {
			return array(
				'error' => 'The source has not finished packaging its site yet.',
				'final' => true,
			);
		}

		if ( 200 !== $status ) {
			return array(
				'error' => \sprintf( 'The source answered with status %d.', $status ),
				'final' => $json,
			);
		}

		$body = \json_decode( \wp_remote_retrieve_body( $response ), true );

		if ( ! \is_array( $body ) || empty( $body['files'] ) ) {
			return array(
				'error' => 'The source answered, but not with a package. Is the plugin installed and up to date there?',
				'final' => false,
			);
		}

		return array( 'summary' => $body );
	}

	/**
	 * Fetch one range and append it.
	 *
	 * @param array  $state    Source record.
	 * @param string $relative Path within the package.
	 * @param int    $offset   Bytes already here.
	 * @param int    $length   Bytes wanted.
	 * @param int    $total    The file's full size.
	 * @param string $target   Absolute path to append to.
	 *
	 * @return int Bytes appended.
	 *
	 * @throws \RuntimeException If the source refuses, or answers with something else.
	 */
	protected function fetch( array $state, $relative, $offset, $length, $total, $target ) {
		$temp = $target . '.part';

		$this->discard( $temp );

		$response = \wp_remote_get(
			\nfd_sm_rest_url( $state['base'], 'transfer/file', array( 'file' => $relative ) ),
			array(
				'timeout'   => self::TIMEOUT,
				// Always, and never tied to this site's own scheme: an HTTP destination that
				// accepted any certificate would be pulling its entire new database over a
				// connection anyone on the path could rewrite (finding 2.5).
				'sslverify' => true,
				// Streamed to a file rather than returned, because the body is megabytes and a
				// shared host's memory limit is the whole reason this plugin steps at all.
				'stream'    => true,
				'filename'  => $temp,
				'headers'   => \array_merge(
					$this->headers( $state['key'] ),
					array( 'Range' => \sprintf( 'bytes=%d-%d', $offset, ( $offset + $length ) - 1 ) )
				),
			)
		);

		if ( \is_wp_error( $response ) ) {
			$this->discard( $temp );

			throw new \RuntimeException( 'The source stopped responding: ' . $response->get_error_message() );
		}

		$status = (int) \wp_remote_retrieve_response_code( $response );

		if ( 404 === $status ) {
			$this->discard( $temp );

			throw new \RuntimeException( 'The source is no longer accepting this transfer key. Generate a new one there and reconnect — nothing already fetched is lost.' );
		}

		// 200 means the source ignored the range and sent the file from the beginning, which
		// only helps if that is where we are.
		if ( 206 !== $status && ! ( 200 === $status && 0 === $offset ) ) {
			$this->discard( $temp );

			throw new \RuntimeException( \sprintf( 'The source answered with status %d while sending %s.', $status, $relative ) );
		}

		$size = $this->have( $temp );
		$room = $total - $offset;

		if ( $size <= 0 ) {
			$this->discard( $temp );

			throw new \RuntimeException( \sprintf( 'The source sent nothing for %s.', $relative ) );
		}

		// A source that sends more than the manifest leaves room for is either broken or
		// hostile, and either way the answer is to stop rather than to keep writing.
		if ( $size > $room ) {
			$this->discard( $temp );

			throw new \RuntimeException( \sprintf( 'The source sent more of %s than it said the file contains.', $relative ) );
		}

		$this->append( $temp, $target, $offset );

		return $size;
	}

	/**
	 * Move a fetched range into place.
	 *
	 * At offset zero the temp file *is* the file so far, so it is renamed rather than copied —
	 * which for the manifest, the database dump, and every small part is the whole transfer.
	 *
	 * @param string $temp   Fetched range.
	 * @param string $target Destination file.
	 * @param int    $offset Where the range belongs.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the append cannot be completed.
	 */
	protected function append( $temp, $target, $offset ) {
		if ( 0 === $offset && ! \is_file( $target ) ) {
			if ( ! \rename( $temp, $target ) ) {
				$this->discard( $temp );

				throw new \RuntimeException( 'Could not put ' . \basename( $target ) . ' into place.' );
			}

			return;
		}

		$in  = \fopen( $temp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out = \fopen( $target, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $in || false === $out ) {
			if ( false !== $in ) {
				\fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}

			if ( false !== $out ) {
				\fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}

			$this->discard( $temp );

			throw new \RuntimeException( 'Could not append to ' . \basename( $target ) . '.' );
		}

		\flock( $out, LOCK_EX );

		while ( ! \feof( $in ) ) {
			$block = \fread( $in, 1048576 ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false === $block || '' === $block ) {
				break;
			}

			if ( \strlen( $block ) !== \fwrite( $out, $block ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				\flock( $out, LOCK_UN );
				\fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				\fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$this->discard( $temp );

				throw new \RuntimeException( 'Only part of that range could be written. The server may be out of disk space.' );
			}
		}

		\fflush( $out );
		\flock( $out, LOCK_UN );
		\fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		\fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->discard( $temp );
	}

	/**
	 * Check a file that has just reached its full size.
	 *
	 * @param array  $file   Manifest entry.
	 * @param string $target Absolute path.
	 *
	 * @return string A note, when there is something to say.
	 *
	 * @throws \RuntimeException If the same file keeps arriving damaged.
	 */
	protected function settle( array $file, $target ) {
		$expected = (string) \nfd_sm_data_get( $file, 'sha256', '' );
		$relative = (string) \nfd_sm_data_get( $file, 'file', '' );

		if ( '' === $expected || ! \is_file( $target ) ) {
			return '';
		}

		if ( \hash_file( 'sha256', $target ) === $expected ) {
			return '';
		}

		$retries = $this->retries();
		$done    = isset( $retries[ $relative ] ) ? (int) $retries[ $relative ] : 0;

		if ( $done >= self::MAX_RETRY ) {
			throw new \RuntimeException(
				\sprintf(
					'%s keeps arriving damaged after %d attempts. Something between the two sites is altering it — a proxy or a security filter is the usual cause.',
					$relative,
					$done + 1
				)
			);
		}

		$retries[ $relative ] = $done + 1;
		$this->save_retries( $retries );
		$this->discard( $target );

		return \sprintf( '%s arrived damaged and is being fetched again.', $relative );
	}

	/**
	 * Decide what a new connection means for what is already staged.
	 *
	 * Reconnecting to the same package resumes it. Pointing at a different one clears the way,
	 * because half of each of two sites in one directory is a package whose checksums fail at
	 * the very end of a transfer that never had a chance of working.
	 *
	 * @param array $summary What the source reported.
	 *
	 * @return int Bytes cleared.
	 *
	 * @throws \RuntimeException If an unsettled import is using what is staged here.
	 */
	protected function reconcile( array $summary ) {
		if ( Source::matches( $summary ) ) {
			return 0;
		}

		$existing = $this->staged_bytes();

		if ( $existing <= 0 ) {
			return 0;
		}

		// The same refusal `Upload::discard()` makes, for the same reason and against the same
		// directory: an import that has run but has not been kept or undone is still the only
		// account of what this site looked like, and its package is what the checkpoint names.
		// Rollback itself reads the checkpoint and the backup tables rather than the package, so
		// what this protects is narrower than data loss -- but it is somebody's staged gigabytes,
		// and connecting to a different source is not consent to throw them away.
		$state = $this->import_state();

		if ( ! empty( $state ) && ! ImportCheckpoint::is_settled( $state ) ) {
			throw new \RuntimeException(
				'An import from the package staged here has not been kept or undone yet. Finish it, undo it, or cancel it before pulling a different site.'
			);
		}

		Upload::reset();
		$this->clear_retries();

		return (int) $existing;
	}

	/**
	 * The import checkpoint, when one names the directory this pull would clear.
	 *
	 * Anything pointing somewhere else is not this transfer's business.
	 *
	 * @return array Checkpoint state, or empty.
	 */
	protected function import_state() {
		$checkpoint = new ImportCheckpoint();
		$state      = $checkpoint->load();
		$recorded   = \rtrim( (string) \nfd_sm_data_get( $state, 'package', '' ), '/\\' );

		if ( '' === $recorded ) {
			return array();
		}

		$here = \realpath( $this->dir );
		$them = \realpath( $recorded );

		return ( false !== $here && $here === $them ) ? (array) $state : array();
	}

	/**
	 * How much is sitting in the staging directory right now.
	 *
	 * Walked directly rather than through `nfd_sm_get_dir_size()`, which caches its answer in a
	 * transient: this number decides whether a directory gets deleted, and a cached size that
	 * belongs to a previous transfer would make that decision on stale evidence. A staging
	 * directory holds tens of files, so there is nothing to save by caching it anyway.
	 *
	 * @return int Bytes.
	 */
	protected function staged_bytes() {
		if ( ! \is_dir( $this->dir ) ) {
			return 0;
		}

		$bytes = 0;

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $files as $file ) {
			if ( $file->isFile() && ! $file->isLink() ) {
				$bytes += (int) $file->getSize();
			}
		}

		return $bytes;
	}

	/**
	 * Headers every request to the source carries.
	 *
	 * @param string $key Transfer key.
	 *
	 * @return array
	 */
	protected function headers( $key ) {
		return array(
			TransferKey::HEADER => $key,
			'X-NFD-SM-From'     => \get_site_url(),
		);
	}

	/**
	 * Grow or shrink the range size towards one that takes about `TARGET` seconds.
	 *
	 * Measured on bytes per second rather than on a fixed size, because the link between two
	 * hosts is the one thing here that cannot be guessed from either end: the same 8MB request
	 * is a blink between two datacentres and half a step budget over a domestic connection.
	 * Moved halfway towards the new estimate rather than straight to it, so one slow response
	 * does not collapse the transfer to its floor.
	 *
	 * @param int   $chunk   Current size.
	 * @param int   $bytes   Bytes the last request returned.
	 * @param float $seconds How long it took.
	 *
	 * @return int
	 */
	protected function adapt( $chunk, $bytes, $seconds ) {
		if ( $seconds <= 0.01 || $bytes <= 0 ) {
			return (int) \min( $chunk * 2, self::MAX_CHUNK );
		}

		$wanted = (int) ( ( $bytes / $seconds ) * self::TARGET );
		$next   = (int) ( ( $chunk + $wanted ) / 2 );

		return (int) \max( self::MIN_CHUNK, \min( self::MAX_CHUNK, $next ) );
	}

	/**
	 * Bytes of a file already here.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return int
	 */
	protected function have( $path ) {
		\clearstatcache( true, $path );

		return \is_readable( $path ) ? (int) \filesize( $path ) : 0;
	}

	/**
	 * Make sure a file's directory exists.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the directory cannot be created.
	 */
	protected function prepare( $path ) {
		$parent = \dirname( $path );

		if ( ! \is_dir( $parent ) && ! \wp_mkdir_p( $parent ) ) {
			throw new \RuntimeException( 'Could not create a directory for ' . \basename( $path ) . '.' );
		}
	}

	/**
	 * Create an empty file.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return void
	 */
	protected function touch( $path ) {
		$handle = \fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false !== $handle ) {
			\fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Remove a file if it is there.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return void
	 */
	protected function discard( $path ) {
		if ( \is_file( $path ) ) {
			\unlink( $path );
		}
	}

	/**
	 * Refetch counts, kept on disk.
	 *
	 * On disk rather than in an option for the reason every other piece of run state here is:
	 * a step runs for minutes, and the plugin's option array is written back whole on shutdown.
	 * Outside the staging directory, so clearing the transfer does not also clear the record of
	 * how many times it has been tried.
	 *
	 * @return array
	 */
	protected function retries() {
		$path = $this->retries_path();

		if ( ! \is_readable( $path ) ) {
			return array();
		}

		$data = \json_decode( (string) \file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return \is_array( $data ) ? $data : array();
	}

	/**
	 * Write the refetch counts back.
	 *
	 * @param array $retries Counts by relative path.
	 *
	 * @return void
	 */
	protected function save_retries( array $retries ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		\file_put_contents( $this->retries_path(), \wp_json_encode( $retries ), LOCK_EX );
	}

	/**
	 * Forget the refetch counts.
	 *
	 * @return void
	 */
	protected function clear_retries() {
		$this->discard( $this->retries_path() );
	}

	/**
	 * Where the refetch counts live.
	 *
	 * @return string
	 */
	protected function retries_path() {
		return \rtrim( \nfd_sm_storage_path(), '/\\' ) . DIRECTORY_SEPARATOR . 'transfer-retries.json';
	}

	/**
	 * Take the run lock, if nothing else holds it.
	 *
	 * @return void
	 */
	protected function claim() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		\file_put_contents( $this->lock_path(), (string) \time(), LOCK_EX );
	}

	/**
	 * Whether a pull is running right now.
	 *
	 * A stale lock is taken over rather than waited on: its holder is a request the host killed
	 * or a tab that went away, and nothing would ever release it.
	 *
	 * @return bool
	 */
	public function is_running() {
		$path = $this->lock_path();

		if ( ! \is_readable( $path ) ) {
			return false;
		}

		$held = (int) \file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $held > 0 && ( \time() - $held ) < self::LOCK_SECONDS;
	}

	/**
	 * Give the run lock back.
	 *
	 * @return void
	 */
	protected function release() {
		$this->discard( $this->lock_path() );
	}

	/**
	 * Where the run lock lives.
	 *
	 * @return string
	 */
	protected function lock_path() {
		return \rtrim( \nfd_sm_storage_path(), '/\\' ) . DIRECTORY_SEPARATOR . 'transfer.lock';
	}
}
