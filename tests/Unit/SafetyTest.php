<?php
/**
 * The boundaries that stop a package doing harm.
 *
 * `PathMap::safe_path()` is the security boundary for untrusted archive input: everything the
 * import writes goes through it, and a package is a file somebody else built. `resolve_range()`
 * decides what bytes an unauthenticated-until-keyed request gets to read. Both are small, pure and
 * exactly the kind of thing that breaks quietly when someone tidies it.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use NewfoldLabs\WP\SiteMigrator\Core\Import\PathMap;
use NewfoldLabs\WP\SiteMigrator\Rest\Controller;
use PHPUnit\Framework\TestCase;

class SafetyTest extends TestCase {

	/**
	 * Temporary root for the current test.
	 *
	 * @var string
	 */
	protected $dir = '';

	protected function setUp(): void {
		parent::setUp();

		$this->dir = \Fixture::reset();
	}

	protected function tearDown(): void {
		\Fixture::rmdir( $this->dir );

		parent::tearDown();
	}

	/**
	 * Entries that try to leave the root are refused.
	 *
	 * @dataProvider hostile_paths
	 *
	 * @param string $entry What the archive claims the file is called.
	 */
	public function test_safe_path_refuses_hostile_entries( $entry ) {
		$this->assertSame(
			'',
			PathMap::safe_path( $this->dir, $entry ),
			'Refused rather than resolved: ' . $entry
		);
	}

	/**
	 * Names a hostile or malformed archive can carry.
	 *
	 * @return array
	 */
	public function hostile_paths() {
		return array(
			'parent traversal'            => array( '../escaped.txt' ),
			'nested traversal'            => array( 'wp-content/../../escaped.txt' ),
			'absolute unix path'          => array( '/etc/passwd' ),
			'absolute windows path'       => array( 'C:\\\\Windows\\\\system.ini' ),
			'backslash traversal'         => array( '..\\\\escaped.txt' ),
			'traversal after a good part' => array( 'plugins/../../../escaped.txt' ),
			'empty'                       => array( '' ),
			'just a dot-dot'              => array( '..' ),
		);
	}

	/**
	 * An ordinary entry resolves to somewhere inside the root.
	 */
	public function test_safe_path_allows_an_ordinary_entry() {
		$path = PathMap::safe_path( $this->dir, 'wp-content/plugins/thing/thing.php' );

		$this->assertNotSame( '', $path );
		$this->assertStringStartsWith( \rtrim( $this->dir, '/' ), $path );
		$this->assertStringEndsWith( 'wp-content/plugins/thing/thing.php', $path );
	}

	/**
	 * A range that names no bytes gets the whole file.
	 */
	public function test_no_range_header_is_the_whole_file() {
		$resolved = Controller::resolve_range( 1000, '' );

		$this->assertSame( 0, $resolved['start'] );
		$this->assertSame( 999, $resolved['end'] );
		$this->assertSame( 200, $resolved['status'] );
	}

	/**
	 * Ranges are resolved the way the HTTP spec says, including the awkward ones.
	 *
	 * @dataProvider ranges
	 *
	 * @param int    $size   File size.
	 * @param string $header Range header.
	 * @param int    $start  Expected first byte.
	 * @param int    $end    Expected last byte.
	 * @param int    $status Expected status.
	 */
	public function test_ranges_resolve( $size, $header, $start, $end, $status ) {
		$resolved = Controller::resolve_range( $size, $header );

		$this->assertSame( $status, $resolved['status'], $header );

		if ( 416 === $status ) {
			return;
		}

		$this->assertSame( $start, $resolved['start'], $header );
		$this->assertSame( $end, $resolved['end'], $header );
	}

	/**
	 * @return array
	 */
	public function ranges() {
		return array(
			'closed'              => array( 1000, 'bytes=0-99', 0, 99, 206 ),
			'open ended'          => array( 1000, 'bytes=500-', 500, 999, 206 ),
			'suffix'              => array( 1000, 'bytes=-100', 900, 999, 206 ),
			'single byte'         => array( 1000, 'bytes=0-0', 0, 0, 206 ),
			'end past the file'   => array( 1000, 'bytes=900-5000', 900, 999, 206 ),
			'start past the file' => array( 1000, 'bytes=1000-', 0, 0, 416 ),
			'whole file'          => array( 1000, 'bytes=0-999', 0, 999, 206 ),
		);
	}

	/**
	 * A range beyond the end is unsatisfiable rather than clamped to something.
	 *
	 * Worth its own test: returning 206 with nothing in it would have the destination write zero
	 * bytes and count the file as progressing, which is a transfer that never finishes.
	 */
	public function test_a_range_past_the_end_is_unsatisfiable() {
		$this->assertSame( 416, Controller::resolve_range( 100, 'bytes=100-200' )['status'] );
		$this->assertSame( 416, Controller::resolve_range( 100, 'bytes=999-' )['status'] );
	}
}
