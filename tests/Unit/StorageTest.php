<?php
/**
 * Keeping the migration files outside uploads.
 *
 * A production site whose uploads directory PHP cannot write could not export at all, and the
 * person asking had wp-admin and nothing else. These pin the way out: the alternates offered,
 * adopting one, and the rules that keep a package -- the whole database -- from landing in a
 * directory somebody else owns.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Checker;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Report;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Storage;
use PHPUnit\Framework\TestCase;

class StorageTest extends TestCase {

	/**
	 * Uploads root for the current test.
	 *
	 * @var string
	 */
	protected $dir = '';

	protected function setUp(): void {
		parent::setUp();

		$this->dir = \Fixture::reset();
		$this->clear_alternates();
	}

	protected function tearDown(): void {
		@\chmod( $this->dir, 0755 ); // phpcs:ignore
		\Fixture::rmdir( $this->dir );
		$this->clear_alternates();

		parent::tearDown();
	}

	/**
	 * Alternates live outside the fixture, keyed by ABSPATH, so one left behind would move the
	 * storage of every later test in the suite.
	 */
	protected function clear_alternates() {
		foreach ( Storage::alternates() as $alternate ) {
			@\chmod( $alternate['path'], 0700 ); // phpcs:ignore
			\Fixture::rmdir( \rtrim( $alternate['path'], '/' ) );
		}
	}

	protected function lock_uploads() {
		if ( 0 === \posix_geteuid() ) {
			$this->markTestSkipped( 'Root writes through permissions.' );
		}

		\chmod( $this->dir, 0555 );
	}

	protected function check( $name ) {
		$method = new \ReflectionMethod( Checker::class, $name );

		if ( \PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$report = new Report();
		$method->invoke( null, $report );

		return $report;
	}

	public function test_storage_stays_under_uploads_by_default() {
		$this->assertSame( Storage::default_path(), \nfd_sm_storage_path() );
		$this->assertSame( 'pass', $this->check( 'check_storage' )->get( 'storage' )['status'] );
	}

	public function test_nothing_moves_while_uploads_is_writable() {
		$moved = Storage::adopt( Storage::ABOVE_ROOT );

		$this->assertInstanceOf( \WP_Error::class, $moved );
		$this->assertSame( Storage::default_path(), \nfd_sm_storage_path() );
	}

	public function test_read_only_uploads_offers_somewhere_else_and_moves_there() {
		$this->lock_uploads();

		$check = $this->check( 'check_storage' )->get( 'storage' );

		$this->assertSame( 'block', $check['status'] );
		$this->assertNotEmpty( $check['context']['alternatives'] );

		$id    = $check['context']['alternatives'][0]['id'];
		$moved = Storage::adopt( $id );

		$this->assertIsString( $moved );
		$this->assertSame( $moved, \nfd_sm_storage_path() );
		$this->assertSame( 0700, \fileperms( $moved ) & 0777 );
		$this->assertFileExists( $moved . 'index.php' );
		$this->assertNotSame( 'block', $this->check( 'check_storage' )->get( 'storage' )['status'] );

		// Outside every folder the site serves, so there is no address to fetch it from.
		$this->assertSame( '', Storage::url( $moved ) );
		$this->assertSame( 'pass', $this->check( 'check_storage_reachable' )->get( 'storage_reachable' )['status'] );
	}

	public function test_an_adopted_location_holds_once_uploads_is_writable_again() {
		$this->lock_uploads();
		$moved = Storage::adopt( Storage::ABOVE_ROOT );
		\chmod( $this->dir, 0755 );

		// An import's checkpoint lives here; storage moving back under it would strand the run.
		$this->assertSame( $moved, \nfd_sm_storage_path() );
	}

	public function test_a_directory_open_to_others_is_never_adopted() {
		$this->lock_uploads();

		$path = Storage::alternates()[0]['path'];
		\mkdir( $path, 0700 );
		\chmod( $path, 0777 );

		$this->assertSame( Storage::default_path(), \nfd_sm_storage_path() );
		$this->assertInstanceOf( \WP_Error::class, Storage::adopt( Storage::alternates()[0]['id'] ) );
	}

	public function test_an_unknown_location_is_refused() {
		$this->lock_uploads();

		$this->assertInstanceOf( \WP_Error::class, Storage::adopt( '../../etc' ) );
	}

	public function test_beside_wordpress_is_served_when_wordpress_is_in_a_subdirectory() {
		\Fixture::$site_url = 'https://example.test/wp';

		$above = \dirname( \rtrim( ABSPATH, '/' ) ) . '/nfd-site-migrator-abc';

		$this->assertSame( 'https://example.test/nfd-site-migrator-abc', Storage::url( $above ) );
		$this->assertSame( 'https://example.test/wp/wp-content/x', Storage::url( ABSPATH . 'wp-content/x' ) );
	}

	public function test_purging_removes_an_adopted_location() {
		$this->lock_uploads();
		$moved = Storage::adopt( Storage::ABOVE_ROOT );
		\chmod( $this->dir, 0755 );

		\nfd_sm_purge_all();

		$this->assertDirectoryDoesNotExist( $moved );
	}
}
