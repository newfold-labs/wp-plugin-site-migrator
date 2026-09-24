<?php
/**
 * Writes a package's zip parts without PHP's zip extension.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Package;

/**
 * One zip volume, written front to back with zlib.
 *
 * The counterpart to `ZipReader`, for a source on a host without `ZipArchive`. The format is
 * simple to produce: a local header per file, the file's bytes stored or deflated, and a central
 * directory at the end. zlib's `deflate_add()` does the compressing a piece at a time, so memory
 * stays flat whatever the file's size.
 *
 * It follows the rules `FileCollector` already lives by. A volume is created empty -- `wb`
 * truncates, which is `ZipArchive::OVERWRITE` -- filled once and closed once, so a step that dies
 * part way leaves a volume the next step simply writes again from the start. Unlike `ZipArchive`
 * the work happens as each file is added rather than in `close()`, which is why the collector
 * counts both when it sizes volumes.
 *
 * Each entry's size and CRC-32 are only known once its data has been written, and they are
 * patched into its local header rather than carried in a trailing data descriptor. That costs two
 * seeks per file on a local disk and keeps every header complete, which some readers need.
 *
 * Deliberately 32-bit: past 4GB in one volume or 65,535 entries it refuses rather than writing
 * zip64. Neither is reachable with the defaults -- volumes stop at 128MB and 20,000 entries, and
 * anything over 64MB travels loose -- and a refusal someone can see is better than an archive
 * nobody has tested.
 */
class ZipWriter {

	/**
	 * Bytes read from a source file per pass.
	 */
	const CHUNK = 1048576;

	/**
	 * Largest value a 32-bit field holds.
	 */
	const MAX_32 = 0xFFFFFFFF;

	/**
	 * Largest entry count the end record holds.
	 */
	const MAX_ENTRIES = 0xFFFF;

	/**
	 * Open volume.
	 *
	 * @var resource|null
	 */
	protected $handle = null;

	/**
	 * Absolute path of the volume.
	 *
	 * @var string
	 */
	protected $path = '';

	/**
	 * Bytes written so far, which is where the next entry begins.
	 *
	 * @var int
	 */
	protected $offset = 0;

	/**
	 * Central directory records, built as each entry is finished.
	 *
	 * @var string
	 */
	protected $directory = '';

	/**
	 * Entries written.
	 *
	 * @var int
	 */
	protected $count = 0;

	/**
	 * Use `create()`.
	 */
	protected function __construct() {}

	/**
	 * Release the handle if the volume was never closed. What is on disk is incomplete, and the
	 * next attempt at this volume truncates it.
	 */
	public function __destruct() {
		if ( \is_resource( $this->handle ) ) {
			\fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Which implementation will write parts on this server.
	 *
	 * `ZipArchive` whenever it exists. The filter forces this writer on a server that has both,
	 * which is how the round trip exercises it on a machine where the extension is compiled in.
	 *
	 * @return string `zip`, `zlib`, or empty when neither is available.
	 */
	public static function backend() {
		$zip  = \class_exists( '\\ZipArchive' );
		$zlib = \function_exists( 'deflate_init' ) && \function_exists( 'inflate_init' );

		if ( $zip && ( ! $zlib || ! \apply_filters( 'nfd_sm_native_zip', false ) ) ) {
			return 'zip';
		}

		return $zlib ? 'zlib' : '';
	}

	/**
	 * Start a volume, replacing anything already at the path.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return ZipWriter
	 *
	 * @throws \RuntimeException If the file cannot be created.
	 */
	public static function create( $path ) {
		$handle = \fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $handle ) {
			throw new \RuntimeException( 'Unable to open archive: ' . $path );
		}

		$writer         = new self();
		$writer->handle = $handle;
		$writer->path   = $path;

		return $writer;
	}

	/**
	 * Add one file.
	 *
	 * @param string $source Absolute path to read.
	 * @param string $name   Name inside the archive.
	 * @param bool   $store  Store rather than deflate, for files that are already compressed.
	 *
	 * @return bool False when the file could not be read, in which case nothing of it remains in
	 *              the volume -- the same outcome as `ZipArchive::addFile()` refusing it.
	 *
	 * @throws \RuntimeException When the volume outgrows what a 32-bit archive can describe, or
	 *                           the volume itself cannot be written.
	 */
	public function add_file( $source, $name, $store = false ) {
		$name = (string) $name;

		if ( null === $this->handle || '' === $name || \strlen( $name ) > 0xFFFF ) {
			return false;
		}

		if ( $this->count >= self::MAX_ENTRIES ) {
			throw new \RuntimeException( 'Too many files for one volume: ' . $this->path );
		}

		$in = @\fopen( $source, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions

		if ( false === $in ) {
			return false;
		}

		$start  = $this->offset;
		$method = $store ? 0 : 8;

		// Bit 11 says the name is UTF-8. Without it a reader is entitled to treat a non-ASCII name
		// as CP437, and libzip does exactly that -- `café` would arrive as `cafÃ©`. Set only when
		// the name really is UTF-8, which is also when libzip sets it.
		$flags = ( \preg_match( '/[\x80-\xFF]/', $name ) && \preg_match( '//u', $name ) ) ? 0x0800 : 0;

		// `fstat()` on the handle already open, not `filemtime()` on the path: packaging is bound
		// by the cost of touching each file, and a second lookup is that cost again.
		$stat          = \fstat( $in );
		list( $t, $d ) = self::dos_time( false === $stat ? \time() : (int) $stat['mtime'] );

		$header = "PK\x03\x04" . \pack( 'vvvvvVVVvv', 20, $flags, $method, $t, $d, 0, 0, 0, \strlen( $name ), 0 ) . $name;

		if ( ! $this->write( $header ) ) {
			\fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			return $this->undo( $start, true );
		}

		$context = $store ? null : \deflate_init( ZLIB_ENCODING_RAW, array( 'level' => -1 ) );

		if ( false === $context ) {
			\fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			return $this->undo( $start, true );
		}

		$crc        = \hash_init( 'crc32b' );
		$size       = 0;
		$compressed = 0;

		while ( ! \feof( $in ) ) {
			$chunk = \fread( $in, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false === $chunk ) {
				\fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions

				return $this->undo( $start, false );
			}

			if ( '' === $chunk ) {
				break;
			}

			$size += \strlen( $chunk );
			\hash_update( $crc, $chunk );

			$output = $store ? $chunk : \deflate_add( $context, $chunk, ZLIB_NO_FLUSH );

			if ( false === $output || ! $this->write( $output ) ) {
				\fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions

				return $this->undo( $start, true );
			}

			$compressed += \strlen( $output );
		}

		\fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $store ) {
			$output = \deflate_add( $context, '', ZLIB_FINISH );

			if ( false === $output || ! $this->write( $output ) ) {
				return $this->undo( $start, true );
			}

			$compressed += \strlen( $output );
		}

		if ( $size > self::MAX_32 || $compressed > self::MAX_32 || $start > self::MAX_32 || $this->offset > self::MAX_32 ) {
			$this->undo( $start, false );

			throw new \RuntimeException( 'A volume grew past 4GB, which this writer does not produce: ' . $this->path );
		}

		$crc_value = \unpack( 'N', \hash_final( $crc, true ) )[1];

		// Fourteen bytes in: signature, version, flags, method, time, date. Then the three fields
		// nobody could know until now.
		if ( 0 !== \fseek( $this->handle, $start + 14 )
			|| 12 !== \fwrite( $this->handle, \pack( 'VVV', $crc_value, $compressed, $size ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions
			|| 0 !== \fseek( $this->handle, 0, SEEK_END ) ) {
			throw new \RuntimeException( 'Unable to write archive: ' . $this->path );
		}

		$this->directory .= "PK\x01\x02" . \pack(
			'vvvvvvVVVvvvvvVV',
			0x0314,     // Made by: Unix, format 2.0, so a reader honours the permissions below.
			20,         // Needed to extract: 2.0, deflate.
			$flags,
			$method,
			$t,
			$d,
			$crc_value,
			$compressed,
			$size,
			\strlen( $name ),
			0,          // Extra field length.
			0,          // Comment length.
			0,          // Disk number.
			0,          // Internal attributes.
			0x81A40000, // A regular file, mode 0644.
			$start
		) . $name;

		++$this->count;

		return true;
	}

	/**
	 * Write the central directory and close the volume.
	 *
	 * A volume nothing was added to is removed rather than left as an empty archive, which is
	 * what `ZipArchive` does -- it never creates the file at all.
	 *
	 * @return bool
	 *
	 * @throws \RuntimeException If the directory cannot be written.
	 */
	public function close() {
		if ( null === $this->handle ) {
			return false;
		}

		if ( 0 === $this->count ) {
			\fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->handle = null;
			\unlink( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			return true;
		}

		$directory_offset = $this->offset;
		$directory_size   = \strlen( $this->directory );

		if ( $directory_offset > self::MAX_32 || $directory_offset + $directory_size > self::MAX_32 ) {
			throw new \RuntimeException( 'A volume grew past 4GB, which this writer does not produce: ' . $this->path );
		}

		$end = "PK\x05\x06" . \pack( 'vvvvVVv', 0, 0, $this->count, $this->count, $directory_size, $directory_offset, 0 );

		$written = $this->write( $this->directory ) && $this->write( $end ) && \fflush( $this->handle );

		\fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->handle    = null;
		$this->directory = '';

		if ( ! $written ) {
			throw new \RuntimeException( 'Unable to write archive: ' . $this->path );
		}

		return true;
	}

	/**
	 * Entries written so far.
	 *
	 * @return int
	 */
	public function count() {
		return $this->count;
	}

	/**
	 * Append to the volume.
	 *
	 * @param string $data Bytes.
	 *
	 * @return bool
	 */
	protected function write( $data ) {
		$length = \strlen( $data );

		if ( 0 === $length ) {
			return true;
		}

		$written = \fwrite( $this->handle, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $written ) {
			return false;
		}

		$this->offset += $written;

		return $written === $length;
	}

	/**
	 * Take a half-written entry back off the end of the volume.
	 *
	 * @param int  $start       Where the entry began.
	 * @param bool $write_error Whether the volume itself failed, rather than the source file.
	 *
	 * @return bool Always false, for the caller to return.
	 *
	 * @throws \RuntimeException When the volume could not be written or cut back -- a volume
	 *                           with a torn entry in it must not be closed as though it were whole.
	 */
	protected function undo( $start, $write_error ) {
		$cut = \ftruncate( $this->handle, $start ) && 0 === \fseek( $this->handle, $start );

		$this->offset = $start;

		if ( $write_error || ! $cut ) {
			throw new \RuntimeException( 'Unable to write archive: ' . $this->path );
		}

		return false;
	}

	/**
	 * MS-DOS time and date, which is what a zip header holds.
	 *
	 * @param int $timestamp Unix timestamp.
	 *
	 * @return array Time, then date.
	 */
	protected static function dos_time( $timestamp ) {
		$parts = \getdate( $timestamp );

		// The format starts in 1980. A file claiming to be older is recorded as the earliest
		// moment it can say.
		if ( $parts['year'] < 1980 ) {
			return array( 0, ( 1 << 5 ) | 1 );
		}

		return array(
			( $parts['hours'] << 11 ) | ( $parts['minutes'] << 5 ) | ( $parts['seconds'] >> 1 ),
			( ( $parts['year'] - 1980 ) << 9 ) | ( $parts['mon'] << 5 ) | $parts['mday'],
		);
	}
}
