<?php
/**
 * Reading a package on a server without PHP's zip extension.
 *
 * Every case forces the zlib reader with the filter a real host would not need, because the PHP
 * this suite runs on has `ZipArchive` compiled in. Where an archive is built for a test, it is
 * built by `ZipArchive` -- the same writer that produces real packages -- and read back by the
 * other implementation, so the two are checked against each other rather than against what this
 * file believes a zip looks like. The zip64 case reads a fixture written by Info-ZIP instead, so
 * it runs where `ZipArchive` does not exist.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use NewfoldLabs\WP\SiteMigrator\Core\Import\FileRestorer;
use NewfoldLabs\WP\SiteMigrator\Core\Import\PathMap;
use NewfoldLabs\WP\SiteMigrator\Core\Package\Manifest;
use NewfoldLabs\WP\SiteMigrator\Core\Package\ZipReader;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Compatibility;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\SiteProfile;
use PHPUnit\Framework\TestCase;

class ZipReaderTest extends TestCase {

	/**
	 * Temporary uploads directory for the current test.
	 *
	 * @var string
	 */
	protected $dir = '';

	protected function setUp(): void {
		parent::setUp();

		$this->dir = \Fixture::reset();

		// After reset(), which clears filters.
		\add_filter(
			'nfd_sm_native_unzip',
			function () {
				return true;
			}
		);
	}

	protected function tearDown(): void {
		\Fixture::rmdir( $this->dir );

		parent::tearDown();
	}

	public function test_zlib_reads_exactly_what_ziparchive_reads() {
		$this->needs_ziparchive();

		$path = $this->build(
			array(
				'wp-content/uploads/readme.txt'      => "plain text\n",
				'wp-content/uploads/empty.txt'       => '',
				'wp-content/uploads/2026/'           => null,
				// Incompressible and larger than a read: deflate falls back to stored blocks.
				'wp-content/uploads/2026/noise.bin'  => \random_bytes( 700000 ),
				// 2MB that deflates to a few KB, so one read inflates into many times its size.
				'wp-content/uploads/2026/style.css'  => \str_repeat( "body{margin:0}\n", 150000 ),
				'wp-content/uploads/photo.jpg'       => \random_bytes( 300000 ),
				'wp-content/uploads/café/naïve.txt' => 'a name that is not ascii',
			),
			array( 'wp-content/uploads/photo.jpg' )
		);

		$this->assertSame( 'zlib', ZipReader::backend() );

		$zip = new \ZipArchive();
		$zip->open( $path );

		// The case is only worth something if both methods are really in the archive.
		$methods = array();

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$methods[ $zip->statIndex( $i )['comp_method'] ] = true;
		}

		$this->assertArrayHasKey( \ZipArchive::CM_DEFLATE, $methods );
		$this->assertArrayHasKey( \ZipArchive::CM_STORE, $methods );

		$native = ZipReader::open( $path );

		$this->assertSame( $zip->numFiles, $native->count() );

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name     = $zip->getNameIndex( $i );
			$expected = $zip->getFromIndex( $i );

			$this->assertSame( $name, $native->name( $i ) );
			$this->assertSame( $zip->statIndex( $i )['size'], $native->size( $i ), $name );
			$this->assertSame( $expected, $native->contents( $i ), $name );

			$out = \fopen( 'php://temp', 'w+b' );
			$this->assertSame( \strlen( $expected ), $native->copy( $i, $out ), $name );
			\rewind( $out );
			$this->assertSame( \hash( 'sha256', $expected ), \hash( 'sha256', \stream_get_contents( $out ) ), $name );
			\fclose( $out );
		}

		$this->assertFalse( $native->name( $zip->numFiles ) );

		$zip->close();
		$native->close();
	}

	public function test_zlib_is_only_used_when_asked_while_ziparchive_exists() {
		$this->needs_ziparchive();

		\Fixture::$filters = array();

		$this->assertSame( 'zip', ZipReader::backend() );
		$this->assertTrue( ZipReader::available() );

		\add_filter(
			'nfd_sm_native_unzip',
			function () {
				return true;
			}
		);

		$this->assertSame( 'zlib', ZipReader::backend() );
	}

	public function test_zip64_written_by_a_different_tool() {
		$path = \dirname( __DIR__ ) . '/fixtures/zip64-infozip.zip';

		// Guard the fixture: replaced by an ordinary archive, this would pass without reading a
		// single zip64 structure.
		$bytes = (string) \file_get_contents( $path );
		$this->assertStringContainsString( "PK\x06\x06", $bytes, 'zip64 end of central directory record' );
		$this->assertStringContainsString( "PK\x06\x07", $bytes, 'zip64 locator' );

		$reader = ZipReader::open( $path );
		$names  = array();

		for ( $i = 0; $i < $reader->count(); $i++ ) {
			$names[] = $reader->name( $i );
		}

		$this->assertSame( array( 'hello.txt', 'dir/repeat.txt', 'empty.txt' ), $names );
		$this->assertSame( "hello from info-zip\n", $reader->contents( 0 ) );
		$this->assertSame( \str_repeat( 'abcdefgh', 4096 ), $reader->contents( 1 ) );
		$this->assertSame( 32768, $reader->size( 1 ) );
		$this->assertSame( '', $reader->contents( 2 ) );
	}

	public function test_a_damaged_entry_is_refused_rather_than_returned_short() {
		$this->needs_ziparchive();

		foreach ( array( 'deflated', 'stored' ) as $kind ) {
			$path = $this->build(
				array(
					'first.txt'  => \str_repeat( "the quick brown fox jumps over the lazy dog\n", 20000 ),
					'second.txt' => 'untouched',
				),
				'stored' === $kind ? array( 'first.txt' ) : array()
			);

			// The first entry's data starts right after its local header.
			$bytes  = (string) \file_get_contents( $path );
			$header = \unpack( 'vname/vextra', \substr( $bytes, 26, 4 ) );
			$start  = 30 + $header['name'] + $header['extra'];
			$flip   = $start + 200;

			$bytes[ $flip ] = \chr( \ord( $bytes[ $flip ] ) ^ 0xFF );
			\file_put_contents( $path, $bytes );

			$reader = ZipReader::open( $path );

			$this->assertFalse( $reader->contents( 0 ), $kind );
			$this->assertSame( 'untouched', $reader->contents( 1 ), $kind . ': one bad entry does not spoil the rest' );
		}
	}

	public function test_an_entry_that_inflates_past_its_recorded_size_is_refused() {
		$this->needs_ziparchive();

		$path  = $this->build( array( 'bomb.txt' => \str_repeat( 'x', 1048576 ) ) );
		$bytes = (string) \file_get_contents( $path );

		// Rewrite the directory's uncompressed size to ten bytes: offset 24 into its record.
		$end       = \strrpos( $bytes, "PK\x05\x06" );
		$directory = \unpack( 'V', \substr( $bytes, $end + 16, 4 ) )[1];
		$bytes     = \substr_replace( $bytes, \pack( 'V', 10 ), $directory + 24, 4 );
		\file_put_contents( $path, $bytes );

		$out = \fopen( 'php://temp', 'w+b' );

		$this->assertFalse( ZipReader::open( $path )->copy( 0, $out ) );

		// And it stopped at the promise rather than writing the megabyte first.
		$this->assertLessThanOrEqual( 10, \ftell( $out ) );
		\fclose( $out );
	}

	public function test_what_is_not_a_readable_archive_throws() {
		$this->needs_ziparchive();

		$good  = (string) \file_get_contents( $this->build( array( 'a.txt' => 'a' ) ) );
		$end   = \strrpos( $good, "PK\x05\x06" );
		$cases = array(
			'not a zip'                  => 'this is not an archive at all, just some text',
			'too short to be one'        => 'PK',
			'cut off before its end'     => \substr( $good, 0, -10 ),
			'directory past end of file' => \substr_replace( $good, \pack( 'V', 0x7FFFFFF0 ), $end + 16, 4 ),
			'split across disks'         => \substr_replace( $good, \pack( 'v', 1 ), $end + 4, 2 ),
		);

		foreach ( $cases as $label => $bytes ) {
			$path = $this->dir . '/case.zip';
			\file_put_contents( $path, $bytes );

			try {
				ZipReader::open( $path );
				$this->fail( $label . ' was opened' );
			} catch ( \RuntimeException $e ) {
				$this->assertNotSame( '', $e->getMessage(), $label );
			}
		}
	}

	public function test_an_import_unpacks_files_without_ziparchive() {
		$this->needs_ziparchive();

		$package = $this->dir . '/package';
		\wp_mkdir_p( $package . '/parts' );

		$part = $this->build(
			array(
				'wp-content/uploads/broken.php'       => \str_repeat( "<?php echo 'a truncated file is a fatal';\n", 5000 ),
				'wp-content/uploads/2026/a.txt'       => 'alpha',
				'wp-content/uploads/../../escape.txt' => 'must not land',
			)
		);

		// Damage the first entry, so the restore meets a bad entry among good ones.
		$bytes  = (string) \file_get_contents( $part );
		$header = \unpack( 'vname/vextra', \substr( $bytes, 26, 4 ) );
		$flip   = 30 + $header['name'] + $header['extra'] + 100;

		$bytes[ $flip ] = \chr( \ord( $bytes[ $flip ] ) ^ 0xFF );
		\file_put_contents( $package . '/parts/uploads.zip', $bytes );

		$manifest = new Manifest(
			array(
				'parts' => array(
					array(
						'name'   => 'uploads',
						'prefix' => 'wp-content/uploads',
						'file'   => 'parts/uploads.zip',
					),
				),
				'large' => array(),
			)
		);

		$state = array(
			'part_index'       => 0,
			'entry_index'      => 0,
			'files_done'       => 0,
			'bytes_done'       => 0,
			'migrator_skipped' => 0,
			'large_index'      => 0,
			'large_offset'     => 0,
		);

		$restorer = new FileRestorer( $package, $manifest );

		$this->assertTrue( $restorer->step( $state, 0 ) );

		$map  = new PathMap();
		$root = $map->target( 'uploads', 'wp-content/uploads' )['root'];

		$this->assertSame( 'alpha', \file_get_contents( $root . '/2026/a.txt' ) );
		$this->assertSame( 1, $state['files_done'] );
		$this->assertSame( 5, $state['bytes_done'] );

		$this->assertFalse( \file_exists( $root . '/broken.php' ), 'a damaged entry leaves nothing behind, not half a file' );
		$this->assertContains( 'wp-content/uploads/broken.php', $restorer->refused() );

		$this->assertFalse( \file_exists( \dirname( $root, 2 ) . '/escape.txt' ) );
		$this->assertContains( 'wp-content/uploads/../../escape.txt', $restorer->refused() );
	}

	public function test_a_destination_without_zip_is_warned_not_refused() {
		$source = new SiteProfile(
			array(
				'schema_version' => SiteProfile::SCHEMA,
				'php'            => array( 'extensions' => array( 'zip', 'zlib', 'mysqli' ) ),
			)
		);

		$reader = new SiteProfile(
			array(
				'schema_version' => SiteProfile::SCHEMA,
				'php'            => array(
					'extensions' => array( 'zlib', 'mysqli' ),
					'unzip'      => true,
				),
			)
		);

		$this->assertSame( 'warn', ( new Compatibility( $source, $reader ) )->check()->get( 'zip' )['status'] );

		// A destination running a plugin from before the zlib reader sends no `unzip`, and that one
		// really cannot unpack without the extension.
		$older = new SiteProfile(
			array(
				'schema_version' => SiteProfile::SCHEMA,
				'php'            => array( 'extensions' => array( 'zlib', 'mysqli' ) ),
			)
		);

		$this->assertSame( 'block', ( new Compatibility( $source, $older ) )->check()->get( 'zip' )['status'] );

		$equipped = new SiteProfile(
			array(
				'schema_version' => SiteProfile::SCHEMA,
				'php'            => array( 'extensions' => array( 'zip', 'zlib', 'mysqli' ) ),
			)
		);

		$this->assertNull( ( new Compatibility( $source, $equipped ) )->check()->get( 'zip' ) );
	}

	/**
	 * Write an archive the way packages are written.
	 *
	 * @param array $entries Name => contents, or null for a directory.
	 * @param array $stored  Names to store rather than deflate.
	 *
	 * @return string Path.
	 */
	protected function build( array $entries, array $stored = array() ) {
		static $n = 0;

		$path = $this->dir . '/built-' . ( ++$n ) . '.zip';
		$zip  = new \ZipArchive();

		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );

		foreach ( $entries as $name => $contents ) {
			if ( null === $contents ) {
				$zip->addEmptyDir( \rtrim( $name, '/' ) );
				continue;
			}

			$zip->addFromString( $name, $contents );

			if ( \in_array( $name, $stored, true ) ) {
				$zip->setCompressionName( $name, \ZipArchive::CM_STORE );
			}
		}

		$zip->close();

		return $path;
	}

	/**
	 * Skip where the archive for a case cannot be built.
	 *
	 * @return void
	 */
	protected function needs_ziparchive() {
		if ( ! \class_exists( '\\ZipArchive' ) ) {
			$this->markTestSkipped( 'Building this archive needs ZipArchive; the Info-ZIP fixture still covers reading without it.' );
		}
	}
}
