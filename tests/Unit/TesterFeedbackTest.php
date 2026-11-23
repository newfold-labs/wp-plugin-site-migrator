<?php
/**
 * The defects an external test run found in 0.2.6, by the IDs in `docs/bug-fixes.md`.
 *
 * Named for what went wrong rather than for the classes involved, like `RegressionTest`: these
 * are the ones a reader six months from now will want to find by symptom.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use NewfoldLabs\WP\SiteMigrator\Core\Export\PartSpecs;
use NewfoldLabs\WP\SiteMigrator\Core\Export\TableOwners;
use NewfoldLabs\WP\SiteMigrator\Core\Transfer\Link;
use NewfoldLabs\WP\SiteMigrator\Core\Transfer\Puller;
use NewfoldLabs\WP\SiteMigrator\Core\Transfer\TransferKey;
use PHPUnit\Framework\TestCase;

class TesterFeedbackTest extends TestCase {

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

	protected function call( $object, $method, ...$args ) {
		$reflection = new \ReflectionMethod( $object, $method );

		if ( \PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		return $reflection->invoke( \is_object( $object ) ? $object : null, ...$args );
	}

	protected function staging() {
		$dir = $this->dir . '/incoming';
		\mkdir( $dir );

		return $dir;
	}

	/**
	 * T1: the staged manifest, not only the stored record, decides whether a package is the same.
	 */
	public function test_a_staged_package_is_recognised_without_the_source_record() {
		$dir      = $this->staging();
		$manifest = '{"created_at":"2026-11-20T10:00:00+00:00"}';
		\file_put_contents( $dir . '/manifest.json', $manifest );
		\file_put_contents( $dir . '/part-001.zip', \str_repeat( 'x', 4096 ) );

		$puller  = new Puller( $dir );
		$summary = array(
			'files' => array(
				array(
					'file'   => 'manifest.json',
					'sha256' => \hash( 'sha256', $manifest ),
				),
			),
		);

		$this->assertTrue( $this->call( $puller, 'staged_is', $summary ) );
		$this->assertSame( 0, $this->call( $puller, 'reconcile', $summary ) );
		$this->assertFileExists( $dir . '/part-001.zip' );

		$summary['files'][0]['sha256'] = \hash( 'sha256', 'a different package' );

		$this->assertFalse( $this->call( $puller, 'staged_is', $summary ) );
	}

	/**
	 * T2: the protection files are not "a different package".
	 */
	public function test_protection_files_are_not_counted_as_staged_bytes() {
		$dir = $this->staging();
		\nfd_sm_protect_directory( $dir );

		$this->assertSame( 0, $this->call( new Puller( $dir ), 'staged_bytes' ) );

		\file_put_contents( $dir . '/part-001.zip', 'abc' );

		$this->assertSame( 3, $this->call( new Puller( $dir ), 'staged_bytes' ) );
	}

	/**
	 * T4: desktop metadata is not site content.
	 */
	public function test_os_metadata_is_recognised() {
		foreach ( array( '.DS_Store', '._akismet', 'Thumbs.db', 'desktop.ini' ) as $name ) {
			$this->assertTrue( PartSpecs::is_os_metadata( $name ), $name );
		}

		foreach ( array( 'akismet', 'hello.php', '.htaccess', 'index.php' ) as $name ) {
			$this->assertFalse( PartSpecs::is_os_metadata( $name ), $name );
		}
	}

	/**
	 * T7: a new pairing and a new offer each retire the previous transfer key.
	 */
	public function test_a_new_pairing_or_offer_retires_the_old_key() {
		TransferKey::issue();
		Link::issue( 'https://destination.test' );

		$this->assertFalse( TransferKey::status()['active'] );

		TransferKey::issue();
		$this->assertTrue( Link::offer() );

		$this->assertFalse( TransferKey::status()['active'] );
	}

	/**
	 * T10: a table one plugin only refers to is not offered as that plugin's.
	 */
	public function test_a_plugin_with_its_own_namespace_lets_go_of_foreign_tables() {
		$settled = TableOwners::arbitrate(
			array(
				'plugins/jetpack-boost' => array(
					'tables'  => array( 'wp_jetpack_boost_cache', 'wp_wc_order_stats' ),
					'options' => array(),
				),
				'plugins/wordfence'     => array(
					'tables'  => array( 'wp_wfconfig' ),
					'options' => array(),
				),
			),
			'wp_'
		);

		$this->assertSame( array( 'wp_jetpack_boost_cache' ), $settled['claims']['plugins/jetpack-boost']['tables'] );

		// No evidence either way, so Wordfence keeps its oddly named table.
		$this->assertSame( array( 'wp_wfconfig' ), $settled['claims']['plugins/wordfence']['tables'] );
	}

	/**
	 * T10: of several claimants, the one whose name it carries owns it; the longest match wins.
	 */
	public function test_the_plugin_a_table_is_named_after_owns_it() {
		$settled = TableOwners::arbitrate(
			array(
				'plugins/woocommerce'   => array(
					'tables'  => array( 'wp_woocommerce_api_keys' ),
					'options' => array(),
				),
				'plugins/optinmonster'  => array(
					'tables'  => array( 'wp_woocommerce_api_keys' ),
					'options' => array(),
				),
				'plugins/jetpack'       => array(
					'tables'  => array( 'wp_jetpack_boost_cache' ),
					'options' => array(),
				),
				'plugins/jetpack-boost' => array(
					'tables'  => array( 'wp_jetpack_boost_cache' ),
					'options' => array(),
				),
			),
			'wp_'
		);

		$this->assertSame( array( 'wp_woocommerce_api_keys' ), $settled['claims']['plugins/woocommerce']['tables'] );
		$this->assertArrayNotHasKey( 'plugins/optinmonster', $settled['claims'] );
		$this->assertSame( array( 'wp_jetpack_boost_cache' ), $settled['claims']['plugins/jetpack-boost']['tables'] );
		$this->assertArrayNotHasKey( 'plugins/jetpack', $settled['claims'] );
		$this->assertSame( array(), $settled['shared'] );
	}

	/**
	 * T11: a setting neither claimant is named after stays with both, and is marked shared.
	 */
	public function test_a_setting_two_plugins_read_is_marked_shared() {
		$settled = TableOwners::arbitrate(
			array(
				'plugins/bluehost-wordpress-plugin' => array(
					'tables'  => array(),
					'options' => array( 'mm_brand' ),
				),
				'plugins/wp-plugin-hostgator'       => array(
					'tables'  => array(),
					'options' => array( 'mm_brand' ),
				),
			)
		);

		$this->assertSame(
			array( 'plugins/bluehost-wordpress-plugin', 'plugins/wp-plugin-hostgator' ),
			$settled['shared']['mm_brand']
		);
		$this->assertSame( array( 'mm_brand' ), $settled['claims']['plugins/wp-plugin-hostgator']['options'] );
	}
}
