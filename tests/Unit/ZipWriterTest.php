<?php
/**
 * Building a package on a server without PHP's zip extension.
 *
 * Everything `ZipWriter` produces is read back by something that is not `ZipWriter`: `ZipArchive`,
 * which is what most destinations will use; this plugin's zlib reader, which checks every CRC;
 * and Info-ZIP's `unzip -t` where it is installed, which shares no code with either. A writer
 * checked only against its own idea of the format would pass for the wrong reason.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use NewfoldLabs\WP\SiteMigrator\Core\Package\ZipReader;
use NewfoldLabs\WP\SiteMigrator\Core\Package\ZipWriter;
use PHPUnit\Framework\TestCase;

class ZipWriterTest extends TestCase {

	/**
	 * Temporary uploads directory for the current test.
	 *
	 * @var string
	 */
	protected $dir = '';

	/**
	 * Source files by the name they are stored under.
	 *
	 * @var array
	 */
	protected $files = array();

	protected function setUp(): void {
		parent::setUp();

		$this->dir = \Fixture::reset();

		\add_filter(
			'nfd_sm_native_zip',
			function () {
				return true;
			}
		);

		$this->files = array(
			'wp-content/uploads/readme.txt'      => "plain text\n",
			'wp-content/uploads/empty.txt'       => '',
			// Incompressible and larger than a read.
			'wp-content/uploads/2026/noise.bin'  => \random_bytes( 1500000 ),
			// Several reads that deflate to almost nothing.
			'wp-content/uploads/2026/style.css'  => \str_repeat( "body{margin:0}\n", 150000 ),
			'wp-content/uploads/photo.jpg'       => \random_bytes( 300000 ),
			'wp-content/uploads/café/naïve.txt' => 'a name that is not ascii',
		);
	}

	protected function tearDown(): void {
		\Fixture::rmdir( $this->dir );

		parent::tearDown();
	}

	public function test_ziparchive_reads_exactly_what_zlib_wrote() {
		if ( ! \class_exists( '\\ZipArchive' ) ) {
			$this->markTestSkipped( 'Needs ZipArchive to read with.' );
		}

		$path = $this->write_volume();
		$zip  = new \ZipArchive();

		// CHECKCONS makes libzip compare every local header with the central directory, which is
		// exactly where a patched-in size or CRC would disagree.
		$this->assertTrue( $zip->open( $path, \ZipArchive::CHECKCONS ), 'libzip consistency check' );
		$this->assertSame( \count( $this->files ), $zip->numFiles );

		$seen = array();

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );

			$this->assertArrayHasKey( $name, $this->files, 'names survive, including the non-ASCII one' );
			$this->assertSame( $this->files[ $name ], $zip->getFromIndex( $i ), $name );

			$seen[ $name ] = $zip->statIndex( $i )['comp_method'];
		}

		$this->assertSame( \ZipArchive::CM_STORE, $seen['wp-content/uploads/photo.jpg'] );
		$this->assertSame( \ZipArchive::CM_DEFLATE, $seen['wp-content/uploads/2026/style.css'] );

		$zip->close();
	}

	public function test_a_name_that_is_not_ascii_is_marked_utf8() {
		$path  = $this->write_volume();
		$bytes = (string) \file_get_contents( $path );
		$end   = \strrpos( $bytes, "PK\x05\x06" );
		$at    = \unpack( 'V', \substr( $bytes, $end + 16, 4 ) )[1];
		$utf8  = array();

		// Read straight out of the central directory. Reading the name back through ZipArchive
		// proves nothing: libzip treats any valid UTF-8 name as UTF-8 with or without the flag.
		// Python's zipfile, Windows' own extractor and older unzips do not -- an unflagged name
		// is CP437 to them, and `café` arrives as `cafÃ©`.
		while ( "PK\x01\x02" === \substr( $bytes, $at, 4 ) ) {
			$flags   = \unpack( 'v', \substr( $bytes, $at + 8, 2 ) )[1];
			$lengths = \unpack( 'vname/vextra/vcomment', \substr( $bytes, $at + 28, 6 ) );
			$name    = \substr( $bytes, $at + 46, $lengths['name'] );

			$utf8[ $name ] = 0 !== ( $flags & 0x0800 );
			$at           += 46 + $lengths['name'] + $lengths['extra'] + $lengths['comment'];
		}

		$this->assertCount( \count( $this->files ), $utf8 );
		$this->assertTrue( $utf8['wp-content/uploads/café/naïve.txt'] );
		$this->assertFalse( $utf8['wp-content/uploads/readme.txt'], 'and only where it is needed' );
	}

	public function test_the_zlib_reader_verifies_every_entry_zlib_wrote() {
		$path   = $this->write_volume();
		$reader = ZipReader::open( $path, true );

		$this->assertSame( \count( $this->files ), $reader->count() );

		for ( $i = 0; $i < $reader->count(); $i++ ) {
			$name = $reader->name( $i );

			$this->assertTrue( $reader->verify( $i ), $name );
			$this->assertSame( $this->files[ $name ], $reader->contents( $i ), $name );
			$this->assertSame( \strlen( $this->files[ $name ] ), $reader->size( $i ), $name );
		}

		// And deflating really happened: two megabytes of repeated CSS is not stored.
		$this->assertLessThan( \strlen( $this->files['wp-content/uploads/2026/style.css'] ), \filesize( $path ) - 1500000 - 300000 );
	}

	public function test_info_zip_agrees() {
		$unzip = \trim( (string) \shell_exec( 'command -v unzip 2>/dev/null' ) );

		if ( '' === $unzip ) {
			$this->markTestSkipped( 'Info-ZIP is not installed.' );
		}

		$path = $this->write_volume();

		\exec( \escapeshellarg( $unzip ) . ' -tqq ' . \escapeshellarg( $path ) . ' 2>&1', $output, $code );

		$this->assertSame( 0, $code, \implode( "\n", $output ) );
	}

	public function test_an_unreadable_file_leaves_nothing_behind() {
		$good = $this->dir . '/good.txt';
		\file_put_contents( $good, 'kept' );

		$path   = $this->dir . '/volume.zip';
		$writer = ZipWriter::create( $path );

		$this->assertFalse( $writer->add_file( $this->dir . '/does-not-exist.txt', 'missing.txt' ) );
		$this->assertTrue( $writer->add_file( $good, 'good.txt' ) );
		$this->assertTrue( $writer->close() );

		$reader = ZipReader::open( $path, true );

		$this->assertSame( 1, $reader->count() );
		$this->assertSame( 'good.txt', $reader->name( 0 ) );
		$this->assertSame( 'kept', $reader->contents( 0 ) );
	}

	public function test_a_volume_nothing_went_into_is_not_left_on_disk() {
		$path   = $this->dir . '/empty.zip';
		$writer = ZipWriter::create( $path );

		$writer->add_file( $this->dir . '/does-not-exist.txt', 'missing.txt' );
		$writer->close();

		// ZipArchive never creates the file in this case, and an archive of nothing sitting in
		// the parts directory is a file no manifest names.
		$this->assertFalse( \file_exists( $path ) );
	}

	public function test_creating_a_volume_replaces_what_was_there() {
		$path = $this->dir . '/volume.zip';
		\file_put_contents( $path, \str_repeat( 'the previous run', 100000 ) );

		$source = $this->dir . '/one.txt';
		\file_put_contents( $source, 'one' );

		$writer = ZipWriter::create( $path );
		$writer->add_file( $source, 'one.txt' );
		$writer->close();

		// The re-export bug that packaged 296MB of site as 1.3GB came from an archive that was
		// added to rather than replaced.
		$reader = ZipReader::open( $path, true );

		$this->assertSame( 1, $reader->count() );
		$this->assertLessThan( 1024, \filesize( $path ) );
	}

	/**
	 * Write every source file into one volume.
	 *
	 * @return string Path.
	 */
	protected function write_volume() {
		$path   = $this->dir . '/volume.zip';
		$writer = ZipWriter::create( $path );

		foreach ( $this->files as $name => $contents ) {
			$source = $this->dir . '/src-' . \md5( $name );
			\file_put_contents( $source, $contents );

			$this->assertTrue( $writer->add_file( $source, $name, 'jpg' === \pathinfo( $name, PATHINFO_EXTENSION ) ), $name );
		}

		$this->assertTrue( $writer->close() );

		return $path;
	}
}
