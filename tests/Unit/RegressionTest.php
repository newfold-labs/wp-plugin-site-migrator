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
}
