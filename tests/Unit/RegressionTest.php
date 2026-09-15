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
use NewfoldLabs\WP\SiteMigrator\Core\Import\CodeCompatibility;
use NewfoldLabs\WP\SiteMigrator\Core\Import\Importer;
use NewfoldLabs\WP\SiteMigrator\Core\Import\PluginPresence;
use NewfoldLabs\WP\SiteMigrator\Core\Import\Upload;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Compatibility;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Pairing;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\SiteProfile;
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
	 * A rolled-back run does not block importing another package into the same directory.
	 *
	 * `is_complete()` guards the run that finished and has been neither undone nor kept, because
	 * its backup tables are the only copy of the site as it was. A settled one is finished
	 * business. Without the distinction the refusal fired after a rollback and said "roll it back
	 * first" -- advice the user had already taken -- and a linked pull always stages into the same
	 * directory, so the package path matched every time.
	 *
	 * @dataProvider settled_states
	 *
	 * @param string $key   Which decision was recorded.
	 * @param mixed  $value What it was recorded as.
	 */
	public function test_a_settled_import_does_not_refuse_the_next_package( $key, $value ) {
		$dir        = $this->dir . '/incoming';
		$checkpoint = new ImportCheckpoint();
		$state      = ImportCheckpoint::defaults();

		$state['stage']   = ImportCheckpoint::STAGE_DONE;
		$state['package'] = $dir;
		$checkpoint->save( $state );

		// Undecided, the same package is refused: this is the case the guard exists for.
		$this->assertTrue( ( new Importer( $dir ) )->is_complete() );

		$state[ $key ] = $value;
		$checkpoint->save( $state );

		$this->assertFalse( ( new Importer( $dir ) )->is_complete() );
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
	 * Every site, not the first hundred.
	 *
	 * Raised by the AI review, and it was right: `WP_Site_Query` defaults to `number = 100`, so
	 * `get_sites( array( 'fields' => 'ids' ) )` silently pages. Any network between 101 sites and
	 * `wp_is_large_network()` would have been purged down to its first hundred and left there --
	 * the exact half-purged state the large-network guard exists to avoid, reached through a
	 * default rather than a timeout.
	 *
	 * The unit suite could not have caught it either, which is the more useful half of the
	 * lesson: `Fixture`'s `get_sites()` returned everything it held whatever it was asked for, so
	 * the stub was more generous than the function it stood in for and the bug was invisible.
	 * It honours `number` now.
	 */
	public function test_a_network_past_the_default_page_is_purged_whole() {
		\Fixture::$sites = \range( 1, 150 );

		\nfd_sm_uninstall();

		$this->assertCount( 150, \Fixture::$switched );
		$this->assertSame( 150, \end( \Fixture::$switched ) );
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

		// Site 2 has a storage directory and nothing to protect, so the uninstall should take it.
		\switch_to_blog( 2 );
		$purged = \nfd_sm_storage_path();
		\restore_current_blog();

		\Fixture::$switched = array();

		\nfd_sm_uninstall();

		$this->assertSame( array( 1, 2 ), \Fixture::$switched );

		// The one mid-migration keeps its state, and the one that is not is actually gone --
		// tracking the switches alone would pass for a loop that visited every site and deleted
		// nothing.
		$this->assertDirectoryExists( $kept );

		\switch_to_blog( 2 );
		$this->assertDirectoryDoesNotExist( $purged );
		\restore_current_blog();
	}

	/**
	 * An empty blob dumped as `0x`, which is not a hex literal.
	 *
	 * A production import stopped at `INSERT INTO ... VALUES ('bannedURLs',0x,'yes')` with
	 * "Unknown column '0x' in 'field list'" -- MySQL reads a hex literal with no digits as an
	 * identifier. Wordfence keeps its configuration in a longblob and leaves unset settings
	 * empty, so a site running it dumps a row like this for every option it has never written,
	 * and the dump is refused at the first one. Nothing downstream could catch it: the package
	 * was hashed after the dump was written, so `verify` confirmed the broken bytes arrived
	 * intact.
	 */
	public function test_empty_blob_is_not_dumped_as_a_bare_hex_prefix() {
		$db = new \DumpValues();

		foreach ( array( 'blob', 'longblob', 'mediumblob', 'tinyblob', 'binary(16)', 'varbinary(255)' ) as $type ) {
			$this->assertSame( "''", $db->prepare( '', $type ), $type . ' with an empty value' );
		}

		// The non-empty case is the one that has always worked, and has to keep working.
		$this->assertSame( '0x00ff', $db->prepare( "\x00\xff", 'longblob' ) );

		// A NULL is still a NULL, not an empty string -- Wordfence's `val` is NOT NULL, but
		// other plugins' blobs are not, and collapsing the two loses a real distinction.
		$this->assertSame( 'NULL', $db->prepare( null, 'longblob' ) );
	}
	/**
	 * Build a manifest for a package whose dump hashes to `$sha`.
	 *
	 * @param string $sha Checksum the manifest declares for database.sql.
	 *
	 * @return array
	 */
	protected function package_manifest( $sha ) {
		return array(
			'schema_version' => 1,
			'created_at'     => '2026-09-11T14:45:56+00:00',
			'source'         => array( 'site_url' => 'https://source.test' ),
			'database'       => array(
				'file'   => 'database.sql',
				'bytes'  => 4096,
				'sha256' => $sha,
			),
			'parts'          => array(
				array(
					'file'   => 'parts/uploads.zip',
					'bytes'  => 2048,
					'sha256' => 'bb',
				),
			),
			'totals'         => array(
				'bytes' => 6144,
				'files' => 2,
			),
		);
	}

	/**
	 * Stage a package in the upload directory, as a part-finished upload leaves it.
	 *
	 * @param array $manifest Manifest to write.
	 *
	 * @return string The staging directory.
	 */
	protected function stage_upload( array $manifest ) {
		$dir = Upload::dir();

		\file_put_contents( $dir . '/manifest.json', \wp_json_encode( $manifest ) );
		\file_put_contents( $dir . '/database.sql', \str_repeat( 'a', 4096 ) );

		return $dir;
	}

	/**
	 * A re-upload resuming into the package already staged.
	 *
	 * An upload resumes from the bytes on disk, which is what survives a dropped connection. A
	 * package corrected in place is the case that breaks it: every file keeps its size, so each
	 * one is skipped as already complete and the corrected bytes are never sent, while
	 * `manifest.json` -- the one file whose size did move -- is appended to rather than
	 * replaced, leaving JSON that will not parse. That is what happened on a real destination,
	 * and the error it produced ("No manifest.json") named the only file that had actually
	 * arrived.
	 */
	public function test_uploading_a_different_package_does_not_resume_into_the_old_one() {
		$staged = $this->stage_upload( $this->package_manifest( 'aa' ) );

		// Same source, same moment, same totals -- a dump corrected in place moves nothing but
		// its checksum, so an identity built from how a package describes itself would miss it.
		$cleared = Upload::reconcile( $this->package_manifest( 'cc' ) );

		$this->assertGreaterThan( 0, $cleared, 'the stale bytes are reported as discarded' );
		$this->assertFileDoesNotExist( $staged . '/database.sql' );
		$this->assertFileDoesNotExist( $staged . '/manifest.json' );
	}

	/**
	 * And the same package still resumes, which is the whole point of staging bytes at all.
	 */
	public function test_the_same_package_still_resumes() {
		$manifest = $this->package_manifest( 'aa' );
		$staged   = $this->stage_upload( $manifest );

		$this->assertSame( 0, Upload::reconcile( $manifest ) );
		$this->assertFileExists( $staged . '/database.sql' );
		$this->assertSame( 4096, (int) \filesize( $staged . '/database.sql' ) );
	}

	/**
	 * A finished-but-undecided import is not something a new upload may quietly discard.
	 *
	 * The same refusal `Upload::discard()` and `Puller::reconcile()` make, against the same
	 * directory: its backup tables are the only copy of the site as it was.
	 */
	public function test_an_unsettled_import_blocks_the_clear() {
		$staged = $this->stage_upload( $this->package_manifest( 'aa' ) );

		$checkpoint = new ImportCheckpoint();
		$state      = ImportCheckpoint::defaults();

		$state['package'] = $staged;
		$state['stage']   = 'database';

		$checkpoint->save( $state );

		$this->expectException( \RuntimeException::class );

		Upload::reconcile( $this->package_manifest( 'cc' ) );
	}
	/**
	 * A 5.7 source refused by an 8.0 destination over a rename.
	 *
	 * MySQL 8.0 renamed the three-byte `utf8` character set to `utf8mb3` and stopped listing
	 * the old spellings in `SHOW COLLATION`, while still accepting them in DDL. So a source on
	 * 5.7 whose tables are `utf8_general_ci` looked, to a destination on 8.0, like it needed a
	 * collation that does not exist there -- and the gate blocks rather than warns, because a
	 * missing collation really does kill an import partway with "Unknown collation". A real
	 * localhost import was refused with "The destination does not support this site's text
	 * encoding" by a server that supports every one of them.
	 */
	public function test_utf8_and_utf8mb3_are_the_same_collation() {
		$source = new SiteProfile(
			array(
				'schema_version' => SiteProfile::SCHEMA,
				'database'       => array(
					'collations_used' => array(
						'utf8mb4_unicode_520_ci',
						'utf8_general_ci',
						'latin1_swedish_ci',
						'utf8mb4_general_ci',
					),
				),
			)
		);

		// What MySQL 8.0.35 actually answers: no `utf8_general_ci`, only the mb3 spelling.
		$destination = new SiteProfile(
			array(
				'schema_version' => SiteProfile::SCHEMA,
				'database'       => array(
					'collations' => array(
						'utf8mb4_unicode_520_ci',
						'utf8mb3_general_ci',
						'latin1_swedish_ci',
						'utf8mb4_general_ci',
						'utf8mb4_unicode_ci',
					),
				),
			)
		);

		// Asserted on the collation check alone rather than on the whole report: these profiles
		// carry nothing else, so every other gate is indeterminate, and indeterminate blocks.
		$compatibility = new Compatibility( $source, $destination );
		$collation     = $compatibility->check()->get( 'collation' );

		$this->assertSame( 'pass', $collation['status'], 'a rename is not an incompatibility' );

		// And the reverse move, which is the same rename read the other way round: an 8.0 source
		// whose tables report `utf8mb3_general_ci`, onto a 5.7 destination that only ever calls
		// it `utf8_general_ci`.
		$newer = new SiteProfile(
			array(
				'schema_version' => SiteProfile::SCHEMA,
				'database'       => array(
					'collations_used' => array( 'utf8mb3_general_ci' ),
				),
			)
		);

		$older = new SiteProfile(
			array(
				'schema_version' => SiteProfile::SCHEMA,
				'database'       => array(
					'collations' => array( 'utf8_general_ci', 'utf8mb4_general_ci' ),
				),
			)
		);

		$back = new Compatibility( $newer, $older );

		$this->assertSame( 'pass', $back->check()->get( 'collation' )['status'] );
	}

	/**
	 * A collation that is genuinely absent still blocks.
	 *
	 * The guard against curing the false refusal by never refusing at all.
	 */
	public function test_a_collation_that_really_is_missing_still_blocks() {
		$source = new SiteProfile(
			array(
				'schema_version' => SiteProfile::SCHEMA,
				'database'       => array(
					'collations_used' => array( 'latin2_general_ci' ),
				),
			)
		);

		$destination = new SiteProfile(
			array(
				'schema_version' => SiteProfile::SCHEMA,
				'database'       => array(
					'collations' => array( 'utf8mb4_general_ci', 'utf8mb3_general_ci' ),
				),
			)
		);

		$compatibility = new Compatibility( $source, $destination );
		$collation     = $compatibility->check()->get( 'collation' );

		$this->assertSame( 'block', $collation['status'] );
		$this->assertSame( array( 'latin2_general_ci' ), $collation['context']['missing'] );
	}

	/**
	 * A site on current MariaDB refused by a destination on the very same server.
	 *
	 * MariaDB 11.4.5 lists its UCA 14.0 collations in `SHOW COLLATION` as `uca1400_ai_ci`, with no
	 * character set, and 11.5 made `utf8mb4_uca1400_ai_ci` the server default. A fresh WordPress on
	 * MariaDB 12.3.3 had 65 tables in the two UCA 14.0 collations, and a second install on the same
	 * 12.3.3 refused its package with "The destination does not support this site's text encoding".
	 * The answers below are what that server gave.
	 */
	public function test_mariadb_uca1400_collations_are_listed_by_their_full_names() {
		\Fixture::$columns = array(
			'SHOW COLLATION'             => array( 'latin1_swedish_ci', 'utf8mb4_general_ci', 'utf8mb4_unicode_520_ci', 'uca1400_ai_ci' ),
			'SELECT FULL_COLLATION_NAME' => array( 'utf8mb3_uca1400_ai_ci', 'utf8mb4_uca1400_ai_ci', 'ucs2_uca1400_ai_ci' ),
		);

		$facts = ( new \ReflectionMethod( SiteProfile::class, 'database_facts' ) );
		$facts->setAccessible( true );
		$facts = $facts->invoke( null );

		$this->assertContains( 'utf8mb4_uca1400_ai_ci', $facts['collations'] );
		$this->assertContains( 'utf8mb3_uca1400_ai_ci', $facts['collations'] );
		$this->assertNotContains( 'ucs2_uca1400_ai_ci', $facts['collations'], 'still only the families WordPress uses' );

		$source = new SiteProfile(
			array(
				'schema_version' => SiteProfile::SCHEMA,
				'database'       => array(
					'collations_used' => array( 'utf8mb4_unicode_520_ci', 'utf8mb3_uca1400_ai_ci', 'utf8mb4_uca1400_ai_ci', 'latin1_swedish_ci' ),
				),
			)
		);

		$destination = new SiteProfile(
			array(
				'schema_version' => SiteProfile::SCHEMA,
				'database'       => array( 'collations' => $facts['collations'] ),
			)
		);

		$this->assertSame( 'pass', ( new Compatibility( $source, $destination ) )->check()->get( 'collation' )['status'] );

		// MySQL has no FULL_COLLATION_NAME column, so the second query fails and answers nothing;
		// the list is then exactly what it always was.
		\Fixture::$columns = array( 'SHOW COLLATION' => array( 'utf8mb4_0900_ai_ci', 'utf8mb4_general_ci' ) );

		$this->assertSame( array( 'utf8mb4_0900_ai_ci', 'utf8mb4_general_ci' ), $facts = ( function () {
			$method = new \ReflectionMethod( SiteProfile::class, 'database_facts' );
			$method->setAccessible( true );

			return $method->invoke( null )['collations'];
		} )() );
	}
	/**
	 * Reduce findings to the symbols they name.
	 *
	 * @param string $source  PHP source.
	 * @param string $version PHP version to judge against.
	 *
	 * @return array
	 */
	protected function removals( $source, $version = '8.0' ) {
		$checker  = new CodeCompatibility( '' );
		$symbols  = array();

		foreach ( $checker->inspect( '<?php ' . $source, $version ) as $finding ) {
			$symbols[] = $finding['symbol'];
		}

		\sort( $symbols );

		return $symbols;
	}

	/**
	 * The two lines that took a production site down after a successful migration.
	 *
	 * Both came across in a theme, which declares no `Requires PHP` for the header gate to
	 * read, and the version comparison called 7.4 to 8.5 a "heads up". The import verified,
	 * swapped, and left a site that fatals in `wp-settings.php` on every request -- including
	 * the REST call the screen offering to undo it depends on.
	 */
	public function test_the_two_removals_that_bricked_a_real_site() {
		$this->assertSame(
			array( 'create_function()' ),
			$this->removals( 'add_action( "widgets_init", create_function( "", "return 1;" ) );' )
		);

		// The constructor case does not name itself at runtime: the class simply inherits its
		// parent's constructor, and PHP blames `WP_Widget::__construct()` inside core.
		$this->assertSame(
			array( 'web_login::web_login() as a constructor' ),
			$this->removals( 'class web_login extends WP_Widget { function web_login() { parent::__construct( "a", "b" ); } }' )
		);
	}

	/**
	 * And neither is reported on the version that still has them.
	 */
	public function test_nothing_is_removed_on_the_version_that_has_it() {
		$this->assertSame(
			array(),
			$this->removals( 'add_action( "widgets_init", create_function( "", "return 1;" ) );', '7.4' )
		);

		$this->assertSame(
			array(),
			$this->removals( 'class web_login { function web_login() {} }', '7.4' )
		);
	}

	/**
	 * The false positives a regex pass produces, none of which are findings.
	 *
	 * Checked against a real theme first: matching `each` with a regex reported thirty-seven
	 * hits in one theme and every one was wrong -- `_.each(` is Underscore and `$.each(` is
	 * jQuery, both sitting inside PHP strings that render JavaScript templates, and a global
	 * helper function named after a class is not that class's constructor. A check that cries
	 * wolf on somebody's theme is worse than no check, because the habit it teaches is to press
	 * on regardless.
	 */
	public function test_what_looks_like_a_removal_and_is_not() {
		$cases = array(
			'underscore in a template'  => '?><# _.each( data.items, function ( i ) { #><li></li><# } ); #><?php ',
			'jquery in inline script'   => 'echo "<script>$.each( things, function () {} );</script>";',
			'a method of the same name' => 'class Basket { public function each( $fn ) {} } $b = new Basket(); $b->each( "trim" );',
			'a static of the same name' => 'Collection::each( $items );',
			'somebody declaring it'     => 'function each( $thing ) { return $thing; }',
			'a bare word, not a call'   => '$options = array( "each" => true ); echo $options["each"];',
			'a modern constructor'      => 'class Widget { function __construct() {} }',
			'a namespaced same name'    => 'namespace Vendor\\Pkg; class Thing { function Thing() {} }',
			'an anonymous class'        => '$x = new class { function nope() {} };',
		);

		foreach ( $cases as $why => $source ) {
			$this->assertSame( array(), $this->removals( $source ), $why );
		}
	}

	/**
	 * A same-named method in a plain class is still caught when it follows a namespaced one.
	 *
	 * The namespace check is per file and bails on the whole of it, which is right -- a file
	 * with a namespace declaration has no PHP 4 constructors anywhere in it.
	 */
	public function test_a_removed_call_is_still_found_in_a_namespaced_file() {
		$this->assertSame(
			array( 'create_function()' ),
			$this->removals( 'namespace A\\B; $f = create_function( "", "return 1;" );' )
		);
	}
	/**
	 * A package without the plugins leaves a database that still expects them.
	 *
	 * Observed on a real destination: the header rendered five banners stacked instead of one,
	 * and a page printed `[ninja_forms id=3]` as text. Nothing had failed -- `sidebars_widgets`
	 * really does list five widgets and the page really does contain that shortcode. What was
	 * missing was the code that reduces the first to one and turns the second into a form.
	 * `Fixups::drop_missing_plugins()` deactivated twenty-one entries without comment, and the
	 * review screen's only warning had been a part name.
	 */
	public function test_active_plugins_the_package_will_not_bring() {
		$active = array(
			'jetpack/jetpack.php',
			'ninja-forms/ninja-forms.php',
			'siteorigin-panels/siteorigin-panels.php',
			'jobget-s2s.php',
		);

		// The destination already has one of them, and the package carries another.
		$missing = PluginPresence::missing(
			$active,
			array( 'siteorigin-panels' ),
			array( 'ninja-forms', 'index.php' )
		);

		$this->assertSame(
			array( 'jetpack/jetpack.php', 'jobget-s2s.php' ),
			$missing
		);
	}

	/**
	 * A plugin that is one loose file is not skipped as malformed.
	 *
	 * `jobget-s2s.php` has no directory, and it is exactly the kind that cannot be fetched from
	 * wordpress.org afterwards -- so of the whole list it is the one that must not be dropped
	 * by a parser expecting `slug/file.php`.
	 */
	public function test_a_loose_single_file_plugin_still_counts() {
		$this->assertSame(
			array( 'jobget-s2s.php' ),
			PluginPresence::missing( array( 'jobget-s2s.php' ), array(), array() )
		);

		$this->assertSame(
			array(),
			PluginPresence::missing( array( 'jobget-s2s.php' ), array(), array( 'jobget-s2s.php' ) )
		);
	}

	/**
	 * The option is read out of the dump, where it is escaped for MySQL rather than for PHP.
	 *
	 * Unserializing would mean undoing that escaping exactly right first, on input from a
	 * package this site did not build. Reading the strings out is enough and cannot be made to
	 * do anything.
	 */
	public function test_reading_active_plugins_out_of_a_dump_line() {
		$line = 'INSERT INTO `wp_options` VALUES (99,\'active_plugins\',\'a:2:{i:0;s:19:\\"jetpack/jetpack.php\\";'
			. 'i:1;s:14:\\"jobget-s2s.php\\";}\',\'yes\');';

		$this->assertSame(
			array( 'jetpack/jetpack.php', 'jobget-s2s.php' ),
			PluginPresence::parse_serialized_strings( $line )
		);
	}
}
