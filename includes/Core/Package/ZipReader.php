<?php
/**
 * Reads a package's zip parts, with or without PHP's zip extension.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Package;

/**
 * One zip part, opened for reading.
 *
 * Hosts do ship PHP without the zip extension, and for a destination that used to be the end of
 * the road: every file in a package travels inside a zip part, so an import that cannot open one
 * cannot carry a single file. Building a package still needs `ZipArchive` -- writing is where
 * libzip earns its keep -- but reading one does not. zlib, which the plugin already requires,
 * can inflate a stream a piece at a time, and the rest of the format is a directory at the end
 * of the file.
 *
 * So when `ZipArchive` is missing this reads the central directory itself and streams each entry
 * through `inflate_add()`. Memory stays flat whatever the entry's size, which is the reason for
 * not using the PclZip copy WordPress ships: PclZip holds a whole entry in memory, compressed
 * and inflated at once. Every entry is checked against the size and CRC-32 the directory
 * records -- more than `ZipArchive::getStream()` does -- so damage is a refusal rather than a
 * quietly short file.
 *
 * Parts are only ever written by this plugin: stored or deflated, never encrypted, never split.
 * That is all this reads. Anything else is refused per entry rather than guessed at, and
 * everything the archive says about offsets and lengths is checked against the file before it is
 * believed, because a package is untrusted input.
 */
class ZipReader {

	/**
	 * Bytes read per pass when an entry is stored.
	 */
	const CHUNK = 262144;

	/**
	 * Bytes read per pass when an entry is deflated.
	 *
	 * Smaller, because what comes out is bounded by what goes in times deflate's worst ratio --
	 * about a thousand to one. A 16KB read cannot produce more than ~16MB in one piece; a 256KB
	 * read of a crafted entry could produce a quarter of a gigabyte before any check runs.
	 */
	const DEFLATE_CHUNK = 16384;

	/**
	 * Largest central directory read into memory.
	 */
	const MAX_DIRECTORY = 67108864;

	/**
	 * Open archive, when the extension is doing the reading.
	 *
	 * @var \ZipArchive|null
	 */
	protected $zip = null;

	/**
	 * File handle, when this class is doing the reading.
	 *
	 * @var resource|null
	 */
	protected $handle = null;

	/**
	 * Entry names, by index.
	 *
	 * @var array
	 */
	protected $names = array();

	/**
	 * Fixed-width records, one per entry, packed.
	 *
	 * A volume can hold tens of thousands of entries, and an array of arrays costs several
	 * hundred bytes each before a single name is stored. Thirty-two bytes apiece does not.
	 *
	 * @var string
	 */
	protected $records = '';

	/**
	 * Where entry data has to stop: the start of the central directory.
	 *
	 * @var int
	 */
	protected $end = 0;

	/**
	 * Use `open()`.
	 */
	protected function __construct() {}

	/**
	 * Close on the way out, so a caller that throws does not leak a handle per part.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Which implementation will read parts on this server.
	 *
	 * `ZipArchive` when it exists, because it is the path every earlier release took. The filter
	 * forces the zlib reader on a server that has both, which is how the round trip exercises it
	 * on a machine where the extension cannot be removed.
	 *
	 * @return string `zip`, `zlib`, or empty when neither is available.
	 */
	public static function backend() {
		$zip  = \class_exists( '\\ZipArchive' );
		$zlib = \function_exists( 'inflate_init' );

		if ( $zip && ( ! $zlib || ! \apply_filters( 'nfd_sm_native_unzip', false ) ) ) {
			return 'zip';
		}

		return $zlib ? 'zlib' : '';
	}

	/**
	 * Whether this server can read a package at all.
	 *
	 * @return bool
	 */
	public static function available() {
		return '' !== self::backend();
	}

	/**
	 * Open a part.
	 *
	 * @param string $path       Absolute path.
	 * @param bool   $force_zlib Read with zlib even where `ZipArchive` exists. The export uses it
	 *                           to check what `ZipWriter` produced with an implementation that
	 *                           verifies every entry's CRC.
	 *
	 * @return ZipReader
	 *
	 * @throws \RuntimeException If it cannot be read as a zip archive.
	 */
	public static function open( $path, $force_zlib = false ) {
		$backend = $force_zlib && \function_exists( 'inflate_init' ) ? 'zlib' : self::backend();

		if ( '' === $backend ) {
			throw new \RuntimeException(
				'PHP here has neither the zip extension nor zlib, so a package cannot be read. Ask the host to enable either.'
			);
		}

		$reader = new self();

		if ( 'zip' === $backend ) {
			$zip = new \ZipArchive();

			if ( true !== $zip->open( $path ) ) {
				throw new \RuntimeException( 'Unable to open archive: ' . $path );
			}

			$reader->zip = $zip;

			return $reader;
		}

		$reader->read_directory( $path );

		return $reader;
	}

	/**
	 * How many entries the archive holds.
	 *
	 * @return int
	 */
	public function count() {
		if ( null !== $this->zip ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive's own property.
			return (int) $this->zip->numFiles;
		}

		return \count( $this->names );
	}

	/**
	 * An entry's name.
	 *
	 * @param int $index Entry index.
	 *
	 * @return string|false
	 */
	public function name( $index ) {
		if ( null !== $this->zip ) {
			return $this->zip->getNameIndex( (int) $index );
		}

		return isset( $this->names[ $index ] ) ? $this->names[ $index ] : false;
	}

	/**
	 * An entry's size once unpacked.
	 *
	 * @param int $index Entry index.
	 *
	 * @return int
	 */
	public function size( $index ) {
		if ( null !== $this->zip ) {
			$stat = $this->zip->statIndex( (int) $index );

			return false === $stat ? 0 : (int) $stat['size'];
		}

		$record = $this->record( $index );

		return null === $record ? 0 : $record['size'];
	}

	/**
	 * Unpack an entry into an open handle.
	 *
	 * @param int      $index  Entry index.
	 * @param resource $handle Writable handle.
	 *
	 * @return int|false Bytes written, or false when the entry could not be read intact. A false
	 *                   may follow a partial write; the caller owns the handle and what is in it.
	 */
	public function copy( $index, $handle ) {
		if ( null === $this->zip ) {
			return $this->inflate( $index, $handle );
		}

		$name = $this->zip->getNameIndex( (int) $index );

		if ( false === $name ) {
			return false;
		}

		$stream = $this->zip->getStream( $name );

		if ( ! \is_resource( $stream ) ) {
			return false;
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

		\fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $bytes;
	}

	/**
	 * An entry's contents as a string.
	 *
	 * For the small files a preview reads -- PHP source, mostly. Anything large belongs in
	 * `copy()`.
	 *
	 * @param int $index Entry index.
	 *
	 * @return string|false
	 */
	public function contents( $index ) {
		if ( null !== $this->zip ) {
			return $this->zip->getFromIndex( (int) $index );
		}

		$buffer = \fopen( 'php://temp', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $buffer ) {
			return false;
		}

		$contents = false;

		if ( false !== $this->inflate( $index, $buffer ) ) {
			\rewind( $buffer );
			$contents = \stream_get_contents( $buffer );
		}

		\fclose( $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $contents;
	}

	/**
	 * Whether an entry reads back whole, without keeping what it holds.
	 *
	 * With zlib this is the full check: every byte inflated, the size and CRC-32 compared. With
	 * `ZipArchive` it reads the entry through to the end, which is where libzip reports a bad CRC.
	 *
	 * @param int $index Entry index.
	 *
	 * @return bool
	 */
	public function verify( $index ) {
		if ( null === $this->zip ) {
			return false !== $this->inflate( $index, null );
		}

		$name   = $this->zip->getNameIndex( (int) $index );
		$stream = false === $name ? false : $this->zip->getStream( $name );

		if ( ! \is_resource( $stream ) ) {
			return false;
		}

		$whole = true;

		while ( ! \feof( $stream ) ) {
			if ( false === \fread( $stream, self::CHUNK ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				$whole = false;
				break;
			}
		}

		\fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $whole;
	}

	/**
	 * Release the archive. Safe to call twice.
	 *
	 * @return void
	 */
	public function close() {
		if ( null !== $this->zip ) {
			$this->zip->close();
			$this->zip = null;
		}

		if ( \is_resource( $this->handle ) ) {
			\fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$this->handle = null;
	}

	/**
	 * Read the end-of-central-directory record and the directory it points at.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the file is not a zip archive this can read.
	 */
	protected function read_directory( $path ) {
		$handle = \is_readable( $path ) ? \fopen( $path, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $handle ) {
			throw new \RuntimeException( 'Unable to open archive: ' . $path );
		}

		$this->handle = $handle;

		$stat = \fstat( $handle );
		$size = false === $stat ? 0 : (int) $stat['size'];

		if ( $size < 22 ) {
			throw new \RuntimeException( 'Not a zip archive: ' . $path );
		}

		// The record is 22 bytes followed by a comment of up to 65,535. Parts carry no comment,
		// but the search allows for one rather than assuming the record is the last 22 bytes.
		$tail_start = \max( 0, $size - 65557 );
		$tail       = $this->read_at( $tail_start, $size - $tail_start );
		$at         = \strrpos( $tail, "PK\x05\x06" );

		if ( false === $at || \strlen( $tail ) - $at < 22 ) {
			throw new \RuntimeException( 'Not a zip archive: ' . $path );
		}

		$record  = \unpack( 'vdisk/vcd_disk/vdisk_entries/ventries/Vcd_size/Vcd_offset', \substr( $tail, $at + 4, 16 ) );
		$limit   = $tail_start + $at;
		$entries = $record['entries'];
		$cd_size = $record['cd_size'];
		$offset  = $record['cd_offset'];
		$disks   = $record['disk'] + $record['cd_disk'];

		// Past 65,535 entries or 4GB the real numbers live in a zip64 record, which a locator
		// immediately before this one points at. A volume of small files can reach the first.
		if ( 0xFFFF === $entries || 0xFFFFFFFF === $cd_size || 0xFFFFFFFF === $offset ) {
			if ( $limit < 20 ) {
				throw new \RuntimeException( 'The zip64 record is missing: ' . $path );
			}

			$locator = $this->read_at( $limit - 20, 20 );

			if ( "PK\x06\x07" !== \substr( $locator, 0, 4 ) ) {
				throw new \RuntimeException( 'The zip64 record is missing: ' . $path );
			}

			$located = \unpack( 'Vdisk/Poffset', \substr( $locator, 4, 12 ) );

			if ( $located['offset'] < 0 || $located['offset'] + 56 > $limit - 20 ) {
				throw new \RuntimeException( 'The zip64 record is damaged: ' . $path );
			}

			$zip64 = $this->read_at( $located['offset'], 56 );

			if ( "PK\x06\x06" !== \substr( $zip64, 0, 4 ) ) {
				throw new \RuntimeException( 'The zip64 record is damaged: ' . $path );
			}

			$record  = \unpack( 'Psize/vmade/vneeded/Vdisk/Vcd_disk/Pdisk_entries/Pentries/Pcd_size/Pcd_offset', \substr( $zip64, 4, 52 ) );
			$limit   = $located['offset'];
			$entries = $record['entries'];
			$cd_size = $record['cd_size'];
			$offset  = $record['cd_offset'];
			$disks   = $record['disk'] + $record['cd_disk'] + $located['disk'];
		}

		if ( 0 !== $disks ) {
			throw new \RuntimeException( 'Split zip archives are not supported: ' . $path );
		}

		if ( $offset < 0 || $cd_size < 0 || $cd_size > self::MAX_DIRECTORY || $offset + $cd_size > $limit || $entries < 0 || $entries * 46 > $cd_size ) {
			throw new \RuntimeException( 'The archive\'s directory is damaged: ' . $path );
		}

		$directory = $cd_size > 0 ? $this->read_at( $offset, $cd_size ) : '';
		$pos       = 0;

		for ( $n = 0; $n < $entries; $n++ ) {
			if ( $pos + 46 > $cd_size || "PK\x01\x02" !== \substr( $directory, $pos, 4 ) ) {
				throw new \RuntimeException( 'The archive\'s directory is damaged: ' . $path );
			}

			$entry = \unpack(
				'vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset',
				\substr( $directory, $pos + 4, 42 )
			);

			$next = $pos + 46 + $entry['name'] + $entry['extra'] + $entry['comment'];

			if ( $next > $cd_size ) {
				throw new \RuntimeException( 'The archive\'s directory is damaged: ' . $path );
			}

			if ( 0xFFFFFFFF === $entry['size'] || 0xFFFFFFFF === $entry['compressed'] || 0xFFFFFFFF === $entry['offset'] ) {
				if ( ! self::zip64_extra( \substr( $directory, $pos + 46 + $entry['name'], $entry['extra'] ), $entry ) ) {
					throw new \RuntimeException( 'The archive\'s directory is damaged: ' . $path );
				}
			}

			$this->names[]  = (string) \substr( $directory, $pos + 46, $entry['name'] );
			$this->records .= \pack( 'vvVPPP', $entry['flags'], $entry['method'], $entry['crc'], $entry['compressed'], $entry['size'], $entry['offset'] );

			$pos = $next;
		}

		$this->end = $offset;
	}

	/**
	 * Replace 32-bit placeholders with the values in an entry's zip64 extra field.
	 *
	 * @param string $extra Extra field bytes.
	 * @param array  $entry Directory entry, modified in place.
	 *
	 * @return bool False when a placeholder has no value to replace it.
	 */
	protected static function zip64_extra( $extra, array &$entry ) {
		$at     = 0;
		$length = \strlen( $extra );

		while ( $at + 4 <= $length ) {
			$field = \unpack( 'vid/vsize', \substr( $extra, $at, 4 ) );

			if ( 0x0001 !== $field['id'] ) {
				$at += 4 + $field['size'];
				continue;
			}

			$data = (string) \substr( $extra, $at + 4, $field['size'] );
			$read = 0;

			// The order is fixed by the format, and only the fields that overflowed are present.
			foreach ( array( 'size', 'compressed', 'offset' ) as $key ) {
				if ( 0xFFFFFFFF !== $entry[ $key ] ) {
					continue;
				}

				if ( $read + 8 > \strlen( $data ) ) {
					return false;
				}

				$value         = \unpack( 'P', \substr( $data, $read, 8 ) );
				$entry[ $key ] = $value[1];
				$read         += 8;
			}

			return true;
		}

		return false;
	}

	/**
	 * One entry's packed record.
	 *
	 * @param int $index Entry index.
	 *
	 * @return array|null
	 */
	protected function record( $index ) {
		$index = (int) $index;

		if ( $index < 0 || ! isset( $this->names[ $index ] ) ) {
			return null;
		}

		return \unpack( 'vflags/vmethod/Vcrc/Pcompressed/Psize/Poffset', \substr( $this->records, $index * 32, 32 ) );
	}

	/**
	 * Stream one entry out of the archive, checking it as it goes.
	 *
	 * @param int      $index  Entry index.
	 * @param resource $handle Writable handle.
	 *
	 * @return int|false Bytes written, or false when the entry is not intact.
	 */
	protected function inflate( $index, $handle ) {
		$entry = $this->record( $index );

		// Encrypted, or compressed by something this plugin never writes.
		if ( null === $entry || ( $entry['flags'] & 1 ) || ( 0 !== $entry['method'] && 8 !== $entry['method'] ) ) {
			return false;
		}

		if ( $entry['offset'] < 0 || $entry['compressed'] < 0 || $entry['size'] < 0 || $entry['offset'] + 30 > $this->end ) {
			return false;
		}

		// The local header repeats the name and carries its own extra field, whose length can
		// differ from the directory's. Only its lengths are used; the sizes come from the
		// directory, which is where a writer streaming its output has to put them.
		$local = $this->read_at( $entry['offset'], 30, false );

		if ( 30 !== \strlen( $local ) || "PK\x03\x04" !== \substr( $local, 0, 4 ) ) {
			return false;
		}

		$lengths = \unpack( 'vname/vextra', \substr( $local, 26, 4 ) );
		$start   = $entry['offset'] + 30 + $lengths['name'] + $lengths['extra'];

		if ( $start + $entry['compressed'] > $this->end || 0 !== \fseek( $this->handle, $start ) ) {
			return false;
		}

		$deflated = 8 === $entry['method'];
		$context  = $deflated ? \inflate_init( ZLIB_ENCODING_RAW ) : null;

		if ( false === $context ) {
			return false;
		}

		$crc   = \hash_init( 'crc32b' );
		$left  = $entry['compressed'];
		$bytes = 0;
		$chunk = $deflated ? self::DEFLATE_CHUNK : self::CHUNK;

		while ( $left > 0 ) {
			$input = \fread( $this->handle, (int) \min( $chunk, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false === $input || '' === $input ) {
				return false;
			}

			$left  -= \strlen( $input );
			$output = $deflated ? @\inflate_add( $context, $input, ZLIB_SYNC_FLUSH ) : $input; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === $output || ! $this->emit( $handle, $crc, $output, $bytes, $entry['size'] ) ) {
				return false;
			}
		}

		if ( $deflated ) {
			$output = @\inflate_add( $context, '', ZLIB_FINISH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === $output || ! $this->emit( $handle, $crc, $output, $bytes, $entry['size'] ) ) {
				return false;
			}
		}

		if ( $bytes !== $entry['size'] || \hash_final( $crc ) !== \sprintf( '%08x', $entry['crc'] ) ) {
			return false;
		}

		return $bytes;
	}

	/**
	 * Write inflated bytes, refusing more than the directory promised.
	 *
	 * @param resource|null $handle Writable handle, or null to check without keeping anything.
	 * @param resource      $crc    Running CRC-32 context.
	 * @param string        $data   Bytes to write.
	 * @param int           $bytes  Bytes written so far, updated in place.
	 * @param int           $limit  The entry's recorded size.
	 *
	 * @return bool
	 */
	protected function emit( $handle, $crc, $data, &$bytes, $limit ) {
		$length = \strlen( $data );

		if ( 0 === $length ) {
			return true;
		}

		$bytes += $length;

		if ( $bytes > $limit ) {
			return false;
		}

		\hash_update( $crc, $data );

		if ( null === $handle ) {
			return true;
		}

		return \fwrite( $handle, $data ) === $length; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Read a stretch of the archive.
	 *
	 * @param int  $offset Where to start.
	 * @param int  $length How much.
	 * @param bool $strict Throw on a short read rather than return what there was.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException On a short read, when strict.
	 */
	protected function read_at( $offset, $length, $strict = true ) {
		$data = '';
		$have = 0;

		if ( 0 === \fseek( $this->handle, $offset ) ) {
			while ( $have < $length ) {
				$piece = \fread( $this->handle, $length - $have ); // phpcs:ignore WordPress.WP.AlternativeFunctions

				if ( false === $piece || '' === $piece ) {
					break;
				}

				$data .= $piece;
				$have += \strlen( $piece );
			}
		}

		if ( $strict && $have !== $length ) {
			throw new \RuntimeException( 'The archive ended early.' );
		}

		return $data;
	}
}
