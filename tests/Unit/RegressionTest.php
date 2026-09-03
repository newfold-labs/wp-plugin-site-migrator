<?php
/**
 * The bugs that actually happened.
 *
 * Every case here was found by running the plugin against real sites, not by review, and each one
 * cost real time. They are grouped together deliberately: a test named after the defect it
 * prevents is worth more to whoever reads it later than the same assertion filed under the class
 * it happens to touch.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use NewfoldLabs\WP\SiteMigrator\Core\Import\SearchReplace;
use NewfoldLabs\WP\SiteMigrator\Core\Package\Manifest;
use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageReader;
use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageWriter;
use NewfoldLabs\WP\SiteMigrator\Core\Import\ImportCheckpoint;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Pairing;
use PHPUnit\Framework\TestCase;

class RegressionTest extends TestCase {

	/**
	 * Temporary uploads directory for the current test.
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
	 * A package is complete only while its manifest is there, and a rebuild has to un-mark it.
	 *
	 * The manifest is written last precisely so its presence means "finished". Nothing removed it
	 * when a run started again, so for the whole of a re-export the previous manifest advertised a
	 * finished package whose parts were being overwritten underneath it — and `Offer` handed a
	 * destination sizes and checksums for files that no longer matched them.
	 */
	public function test_invalidate_marks_a_package_incomplete() {
		$package = $this->dir . '/package';
		$writer  = new PackageWriter( $package );

		$writer->prepare();
		$writer->finalize( new Manifest() );

		$reader = new PackageReader( $package );

		$this->assertTrue( $reader->is_complete(), 'A manifest was just written.' );

		$this->assertTrue( $writer->invalidate(), 'There was a manifest to remove.' );
		$this->assertFalse(
			( new PackageReader( $package ) )->is_complete(),
			'Removing the manifest must un-mark the package.'
		);

		$this->assertFalse( $writer->invalidate(), 'Nothing left to remove the second time.' );
	}

	/**
	 * A fresh run must not inherit the previous one's output.
	 *
	 * Overwriting by name is not enough: whatever the shorter run does not reach stays behind. In
	 * the real failure that meant a `database.sql` holding a complete dump followed by the middle
	 * of an older one, three bytes into an `INSERT`, which imported as `ERT INTO`.
	 */
	public function test_reset_clears_a_previous_runs_output() {
		$package = $this->dir . '/package';
		$writer  = new PackageWriter( $package );

		$writer->prepare();

		$stale = array(
			$writer->path( 'database.sql' ),
			$writer->path( PackageWriter::PARTS_DIR ) . '/plugins.099.zip',
			$writer->path( PackageWriter::LARGE_DIR ) . '/leftover.bin',
		);

		foreach ( $stale as $path ) {
			\file_put_contents( $path, 'from the previous run' );
		}

		$writer->finalize( new Manifest() );

		$writer->reset();

		foreach ( $stale as $path ) {
			$this->assertFileDoesNotExist( $path, 'reset() must not leave the previous run behind.' );
		}

		$this->assertFileDoesNotExist( $writer->path( Manifest::NAME ), 'And the package is no longer complete.' );
		$this->assertDirectoryExists( $writer->path( PackageWriter::PARTS_DIR ), 'But the layout is ready to use.' );
	}

	/**
	 * URLs pointing at the source under the *other* scheme have to be rewritten too.
	 *
	 * A real import left `yith_shippo_webhook_address` on `https://` because the source records
	 * itself as `http://` and only that form was searched for.
	 */
	public function test_search_replace_covers_both_schemes() {
		$pairs = SearchReplace::pairs(
			array(
				'site_url' => 'http://old.test',
				'home_url' => 'http://old.test',
			),
			array(
				'site_url' => 'http://new.test',
				'home_url' => 'http://new.test',
				'abspath'  => '/srv/new',
			)
		);

		$this->assertArrayHasKey( 'http://old.test', $pairs );
		$this->assertArrayHasKey( 'https://old.test', $pairs, 'The other scheme must be rewritten as well.' );
		$this->assertSame( 'http://new.test', $pairs['https://old.test'], 'Both become the destination as it really is.' );

		// The escaped forms matter for Gutenberg block attributes and JSON-encoded meta.
		$this->assertArrayHasKey( 'http:\\/\\/old.test', $pairs );
		$this->assertArrayHasKey( 'https:\\/\\/old.test', $pairs );
	}

	/**
	 * Nothing broader than the site's own address is ever rewritten.
	 *
	 * A protocol-relative `//host` or a bare hostname matches far more than this migration, and a
	 * replacement that is too eager corrupts content that had nothing to do with it.
	 */
	public function test_search_replace_does_not_invent_looser_patterns() {
		$pairs = SearchReplace::pairs(
			array(
				'site_url' => 'http://old.test',
				'home_url' => 'http://old.test',
			),
			array(
				'site_url' => 'http://new.test',
				'home_url' => 'http://new.test',
				'abspath'  => '/srv/new',
			)
		);

		foreach ( \array_keys( $pairs ) as $needle ) {
			$this->assertStringStartsWith(
				'http',
				$needle,
				'Only absolute http(s) URLs are replaced: ' . $needle
			);
		}

		$this->assertArrayNotHasKey( '//old.test', $pairs );
		$this->assertArrayNotHasKey( 'old.test', $pairs );
	}

	/**
	 * A migration that is not going anywhere produces no pairs at all.
	 *
	 * Replacing a URL with itself is a no-op that still costs a full pass over every table.
	 */
	public function test_search_replace_skips_an_unchanged_url() {
		$pairs = SearchReplace::pairs(
			array(
				'site_url' => 'http://same.test',
				'home_url' => 'http://same.test',
			),
			array(
				'site_url' => 'http://same.test',
				'home_url' => 'http://same.test',
				'abspath'  => '',
			)
		);

		$this->assertSame( array(), $pairs );
	}

	/**
	 * A destination that has gone away is reported, not papered over.
	 *
	 * Found live. The compatibility screen recomputes its verdict from facts the destination
	 * reported once, because a verdict about two sites goes stale and one of the two is this
	 * one. Nothing in that round trip touches the network -- so a source paired days ago, whose
	 * destination had since gone off the air, clicked Check again and got a clean "Ready to
	 * migrate" with no hint that the other end was unavailable.
	 */
	public function test_reach_reports_a_destination_that_does_not_answer() {
		$result = Pairing::reach( 'http://gone.test' );

		$this->assertFalse( $result['reachable'] );
		$this->assertNotSame( '', $result['error'] );
	}

	/**
	 * A response -- any response -- means something is there.
	 *
	 * 404 is what `pairing/profile` gives everybody without a code, deliberately, so that the
	 * endpoint is not an oracle for "a WordPress with this plugin lives here". It is still the
	 * answer this probe wants: the question is whether the address is serving, not whether we
	 * are allowed in.
	 */
	public function test_reach_counts_a_refusal_as_an_answer() {
		\Fixture::$http = array( array( 'response' => array( 'code' => 404 ) ) );

		$result = Pairing::reach( 'http://alive.test' );

		$this->assertTrue( $result['reachable'] );
		$this->assertSame( 404, $result['status'] );
	}

	/**
	 * One base failing is not the destination failing.
	 *
	 * `?rest_route=` is tried first and `/wp-json/` second, for the reason finding 3.17 records.
	 * Reporting the first transport error would call a site unreachable over this site's own
	 * choice of URL.
	 */
	public function test_reach_tries_the_second_base_before_giving_up() {
		\Fixture::$http = array(
			new \WP_Error( 'http_request_failed', 'nope' ),
			array( 'response' => array( 'code' => 200 ) ),
		);

		$result = Pairing::reach( 'http://alive.test' );

		$this->assertTrue( $result['reachable'] );
		$this->assertCount( 2, \Fixture::$requests );
		$this->assertStringContainsString( '?rest_route=', \Fixture::$requests[0]['url'] );
		$this->assertStringContainsString( '/wp-json/', \Fixture::$requests[1]['url'] );
	}

	/**
	 * The probe carries no code, and that is not an oversight.
	 *
	 * `PairingController::profile()` short-circuits an empty code before `Pairing::redeem()`, so
	 * a probe costs the destination nothing. Sending a junk code instead would spend one of the
	 * ten attempts in the rate-limit window -- protecting a code the user is very likely about
	 * to type for real.
	 */
	public function test_reach_does_not_spend_an_attempt_on_the_destination() {
		Pairing::reach( 'http://alive.test' );

		$this->assertNotEmpty( \Fixture::$requests );

		foreach ( \Fixture::$requests as $sent ) {
			$this->assertArrayNotHasKey( 'X-NFD-SM-Pairing', $sent['args']['headers'] );
			$this->assertTrue( $sent['args']['sslverify'] );
		}
	}

	/**
	 * Deactivating the plugin does not destroy the package.
	 *
	 * Found on a production site. `register_deactivation_hook` pointed at `nfd_sm_purge_all()`,
	 * which recursively deletes the storage directory -- so switching the plugin off, the routine
	 * "turn everything off and find the conflict" move, silently deleted a package that had taken
	 * an hour and several gigabytes to build. Removing data belongs in `uninstall.php`.
	 */
	public function test_deactivation_leaves_the_package_alone() {
		$package = \nfd_sm_package_path();

		\wp_mkdir_p( $package );
		\file_put_contents( $package . '/manifest.json', '{}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		\nfd_sm_flush_state();

		$this->assertFileExists( $package . '/manifest.json' );
		$this->assertDirectoryExists( \nfd_sm_storage_path() );

		// And the hook points at it. The function being harmless is only half of the fix; the
		// defect was which function the hook named, and nothing else here would notice it being
		// pointed back.
		$bootstrap = (string) \file_get_contents( __DIR__ . '/../../nfd-site-migrator.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertStringContainsString(
			"register_deactivation_hook( __FILE__, 'nfd_sm_flush_state' )",
			$bootstrap
		);
		$this->assertStringNotContainsString(
			"register_deactivation_hook( __FILE__, 'nfd_sm_purge_all' )",
			$bootstrap
		);
	}

	/**
	 * Uninstalling while an import is undecided is refused.
	 *
	 * The backup tables are the only copy of the site as it was, and `Importer::rollback()` reads
	 * the checkpoint on disk to know how to put them back. Delete the state directory and the
	 * tables are orphaned: a swapped site with no way home.
	 */
	public function test_an_undecided_import_is_not_purged() {
		$checkpoint = new ImportCheckpoint();
		$state      = ImportCheckpoint::defaults();

		$state['stage'] = ImportCheckpoint::STAGE_DONE;
		$checkpoint->save( $state );

		$this->assertTrue( \nfd_sm_import_unsettled() );
	}

	/**
	 * Nor is one still in flight, which may be a statement short of the swap.
	 */
	public function test_an_import_in_progress_is_not_purged() {
		$checkpoint = new ImportCheckpoint();

		$checkpoint->save( ImportCheckpoint::defaults() );

		$this->assertTrue( \nfd_sm_import_unsettled() );
	}

	/**
	 * A decided run holds nothing back.
	 *
	 * Rolled back or confirmed, it is a record of what happened rather than the only way out of
	 * it -- so deleting the plugin is free to take it.
	 *
	 * @dataProvider settled_states
	 *
	 * @param string $key Which decision was recorded.
	 */
	public function test_a_settled_import_is_purged( $key, $value ) {
		$checkpoint = new ImportCheckpoint();
		$state      = ImportCheckpoint::defaults();

		$state['stage'] = ImportCheckpoint::STAGE_DONE;
		$state[ $key ]  = $value;
		$checkpoint->save( $state );

		$this->assertFalse( \nfd_sm_import_unsettled() );
	}

	/**
	 * The two ways a finished import gets decided.
	 *
	 * @return array
	 */
	public function settled_states() {
		return array(
			'rolled back' => array( 'rolled_back', true ),
			'confirmed'   => array( 'confirmed_at', '2026-09-02T00:00:00+00:00' ),
		);
	}

	/**
	 * With no import at all there is nothing to protect.
	 */
	public function test_no_import_means_nothing_to_hold_back() {
		$this->assertFalse( \nfd_sm_import_unsettled() );
	}

	/**
	 * A network is purged site by site, because a network has one of everything per site.
	 *
	 * Raised by the AI review on the uninstall change. Migration is blocked at preflight on
	 * multisite, so what a network-activated plugin leaves behind is an empty protected directory
	 * and a row of defaults on each site rather than a package — but `wp_get_upload_dir()` follows
	 * `switch_to_blog()`, so deleting only the current site's is deleting one of however many.
	 */
	public function test_a_network_is_purged_site_by_site() {
		\Fixture::$sites = array( 1, 2, 3 );

		\nfd_sm_uninstall();

		$this->assertSame( array( 1, 2, 3 ), \Fixture::$switched );
	}

	/**
	 * A large network is left alone rather than half-purged.
	 *
	 * WordPress stops counting sites past `wp_is_large_network()`, and this stops deleting past it
	 * for the same reason: a loop long enough to exhaust the request leaves the uninstall
	 * half-done, which is a worse state than an untouched one.
	 */
	public function test_a_large_network_is_left_alone() {
		\Fixture::$sites         = array( 1, 2, 3 );
		\Fixture::$large_network = true;

		\nfd_sm_uninstall();

		$this->assertSame( array(), \Fixture::$switched );
	}

	/**
	 * And the refusal is asked per site, not once for the network.
	 *
	 * The checkpoint lives under a site's own uploads directory, so one site mid-migration must
	 * not stop the others being cleaned, and must not be cleaned itself.
	 */
	public function test_one_undecided_site_does_not_stop_the_others() {
		\Fixture::$sites = array( 1, 2 );

		// Give site 1 an unsettled import, then run the uninstall over both.
		\switch_to_blog( 1 );
		$checkpoint = new ImportCheckpoint();
		$checkpoint->save( ImportCheckpoint::defaults() );
		$kept = \nfd_sm_storage_path();
		\restore_current_blog();

		\Fixture::$switched = array();

		\nfd_sm_uninstall();

		$this->assertSame( array( 1, 2 ), \Fixture::$switched );
		$this->assertDirectoryExists( $kept );
	}
}
