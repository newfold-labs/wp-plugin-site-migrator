<?php
/**
 * Packaging a part with the zlib writer, the way an export does.
 *
 * `ZipWriterTest` checks the archives the writer produces. This checks the writer inside
 * `FileCollector`, which is where it can go wrong in ways the writer alone cannot: volumes opened
 * and closed across batches, sizes and checksums recorded at close, and the read-back that stands
 * between a volume being written and a volume being trusted.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use NewfoldLabs\WP\SiteMigrator\Core\Export\FileCollector;
use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageWriter;
use NewfoldLabs\WP\SiteMigrator\Core\Package\PartSpec;
use NewfoldLabs\WP\SiteMigrator\Core\Package\ZipReader;
use NewfoldLabs\WP\SiteMigrator\Core\Package\ZipWriter;
use PHPUnit\Framework\TestCase;

class ZlibExportTest extends TestCase {

	/**
	 * Temporary uploads directory for the current test.
	 *
	 * @var string
	 */
	protected $dir = '';

	protected function setUp(): void {
		parent::setUp();

		$this->dir = \Fixture::reset();

		\add_filter(
			'nfd_sm_native_zip',
			function () {
				return true;
			}
		);
	}

	protected function tearDown(): void {
		\Fixture::rmdir( $this->dir );

		parent::tearDown();
	}

	public function test_a_part_packaged_with_zlib_holds_every_file_across_volumes() {
		$this->assertSame( 'zlib', ZipWriter::backend() );

		$root    = $this->dir . '/site/wp-content/uploads';
		$files   = $this->tree( $root );
		$package = new PackageWriter( $this->dir . '/package' );

		$package->prepare();

		$spec = new PartSpec( 'uploads', $root, 'wp-content/uploads' );

		// Counts read-backs, so the test fails if closing a volume ever stops checking it. The
		// read-back's own tests call it directly and would go on passing without that call.
		$collector = new class( $package, 67108864, 134217728 ) extends FileCollector {
			public $checked = 0;

			protected function read_back( PartSpec $spec, array $state, $expected ) {
				++$this->checked;
				parent::read_back( $spec, $state, $expected );
			}
		};

		$totals = $collector->prepare( $spec );

		$this->assertSame( \count( $files ), $totals['files'] );

		$state = array(
			'list_offset'  => 0,
			'volume'       => 1,
			'volume_bytes' => 0,
			'bytes_done'   => 0,
			'files_done'   => 0,
			'parts'        => array(),
			'large'        => array(),
			'large_file'   => '',
			'large_offset' => 0,
			'large_meta'   => array(),
		);

		$this->assertTrue( $collector->step( $spec, $state, 0 ) );
		$this->assertSame( \count( $files ), $state['files_done'] );

		// More files than one batch, against a first volume sized at a hundred entries: the writer
		// was closed and a second one started, which is the path a real export takes constantly.
		$this->assertGreaterThan( 1, \count( $state['parts'] ) );
		$this->assertSame( \count( $state['parts'] ), $collector->checked, 'every zlib volume was read back before it was recorded' );

		$found = array();

		foreach ( $state['parts'] as $relative => $part ) {
			$path = $this->dir . '/package/' . $relative;

			// Recorded at close, from the file as it is on disk -- what the manifest will carry.
			$this->assertSame( \filesize( $path ), $part['bytes'], $relative );
			$this->assertSame( \hash_file( 'sha256', $path ), $part['sha256'], $relative );

			$reader = ZipReader::open( $path, true );

			$this->assertSame( $part['files'], $reader->count(), $relative );

			for ( $i = 0; $i < $reader->count(); $i++ ) {
				$found[ $reader->name( $i ) ] = $reader->contents( $i );
			}

			$reader->close();

			if ( \class_exists( '\\ZipArchive' ) ) {
				$zip = new \ZipArchive();
				$this->assertTrue( $zip->open( $path, \ZipArchive::CHECKCONS ), $relative . ' passes libzip\'s consistency check' );
				$zip->close();
			}
		}

		\ksort( $files );
		\ksort( $found );

		$this->assertSame( \array_keys( $files ), \array_keys( $found ) );

		foreach ( $files as $name => $contents ) {
			$this->assertSame( $contents, $found[ $name ], $name );
		}
	}

	public function test_exporting_without_zip_is_a_warning_when_zlib_will_write() {
		$method = new \ReflectionMethod( \NewfoldLabs\WP\SiteMigrator\Core\Preflight\Checker::class, 'check_zip' );

		if ( \PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$report = new \NewfoldLabs\WP\SiteMigrator\Core\Preflight\Report();
		$method->invoke( null, $report );

		// A source used to be refused here. With the writer available it is told, not stopped.
		$this->assertSame( 'warn', $report->get( 'zip' )['status'] );
		$this->assertTrue( $report->to_array()['ok'] );
	}

	public function test_a_zlib_volume_that_does_not_read_back_is_refused() {
		list( $collector, $spec, $path ) = $this->one_volume();

		$bytes = (string) \file_get_contents( $path );
		$flip  = 30 + \strlen( 'wp-content/uploads/big.txt' ) + 500;

		$bytes[ $flip ] = \chr( \ord( $bytes[ $flip ] ) ^ 0xFF );
		\file_put_contents( $path, $bytes );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'does not read back intact' );

		$this->read_back( $collector, $spec, 1 );
	}

	public function test_a_zlib_volume_missing_an_entry_is_refused() {
		list( $collector, $spec ) = $this->one_volume();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'holds 1 entries rather than 2' );

		$this->read_back( $collector, $spec, 2 );
	}

	/**
	 * A package with one zlib volume holding one deflated file.
	 *
	 * @return array Collector, spec, and the volume's path.
	 */
	protected function one_volume() {
		$package = new PackageWriter( $this->dir . '/package' );
		$package->prepare();

		$source = $this->dir . '/big.txt';
		\file_put_contents( $source, \str_repeat( "the quick brown fox jumps over the lazy dog\n", 50000 ) );

		$path   = $package->part_path( 'uploads', 1 );
		$writer = ZipWriter::create( $path );

		$writer->add_file( $source, 'wp-content/uploads/big.txt' );
		$writer->close();

		return array(
			new FileCollector( $package, 67108864, 134217728 ),
			new PartSpec( 'uploads', $this->dir, 'wp-content/uploads' ),
			$path,
		);
	}

	/**
	 * Call the collector's read-back directly.
	 *
	 * @param FileCollector $collector Collector.
	 * @param PartSpec      $spec      Part.
	 * @param int           $expected  Entries the volume should hold.
	 *
	 * @return void
	 */
	protected function read_back( FileCollector $collector, PartSpec $spec, $expected ) {
		$method = new \ReflectionMethod( FileCollector::class, 'read_back' );

		if ( \PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$method->invoke( $collector, $spec, array( 'volume' => 1 ), $expected );
	}

	/**
	 * A small uploads directory: enough files to span volumes, one that deflates well, one
	 * stored, one with a name that is not ASCII.
	 *
	 * @param string $root Directory to create it in.
	 *
	 * @return array Contents, keyed by the name each file is stored under.
	 */
	protected function tree( $root ) {
		$files = array();

		for ( $i = 0; $i < 300; $i++ ) {
			$files[ \sprintf( '2026/%02d/file-%03d.txt', $i % 12 + 1, $i ) ] = "file $i\n" . \str_repeat( \chr( 65 + $i % 26 ), $i );
		}

		$files['2026/01/style.css'] = \str_repeat( "body{margin:0}\n", 150000 );
		$files['2026/02/photo.jpg'] = \random_bytes( 200000 );
		$files['café/naïve.txt']   = 'a name that is not ascii';

		$out = array();

		foreach ( $files as $relative => $contents ) {
			$path = $root . '/' . $relative;

			\wp_mkdir_p( \dirname( $path ) );
			\file_put_contents( $path, $contents );

			$out[ 'wp-content/uploads/' . $relative ] = $contents;
		}

		return $out;
	}
}
