<?php
/**
 * Shapes that other people depend on.
 *
 * A manifest is read by a destination that may have been built months later, and `--format=json`
 * is read by somebody's script. Neither can be changed quietly. These tests exist to make a change
 * *loud* rather than to forbid it: if a key here moves, the failure tells you to bump the schema
 * and say so in the changelog.
 *
 * Phase 4a supplied the argument. Renaming one key inside the site profile broke every
 * compatibility gate, and nothing caught it until a package built before the change was fed to a
 * destination built after it.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use NewfoldLabs\WP\SiteMigrator\Cli\Output;
use NewfoldLabs\WP\SiteMigrator\Core\Package\Manifest;
use PHPUnit\Framework\TestCase;

class ContractTest extends TestCase {

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
	 * The manifest's top-level shape.
	 *
	 * Adding a key is fine and does not fail this test. Removing or renaming one does, because a
	 * destination reading an older package looks for exactly these.
	 */
	public function test_manifest_carries_its_documented_keys() {
		$manifest = Manifest::for_this_site();
		$data     = $manifest->to_array();

		foreach ( array( 'schema_version', 'generator', 'created_at', 'source' ) as $key ) {
			$this->assertArrayHasKey( $key, $data, "The manifest must keep its `{$key}` key." );
		}

		foreach ( array( 'site_url', 'home_url', 'wp_version', 'php_version', 'table_prefix', 'abspath' ) as $key ) {
			$this->assertArrayHasKey( $key, $data['source'], "The manifest's source must keep `{$key}`." );
		}
	}

	/**
	 * The source records which PHP measured it.
	 *
	 * `PHP_VERSION` is the binary running the export, which under WP-CLI is not the one serving
	 * the site — a real pair reported 8.5.9 for a site serving 8.4.18. The number stays because it
	 * is the best available; the SAPI travels with it so a comparison can say how much to trust it.
	 */
	public function test_manifest_records_the_sapi_beside_the_php_version() {
		$data = Manifest::for_this_site()->to_array();

		$this->assertArrayHasKey( 'php_sapi', $data['source'] );
		$this->assertSame( \PHP_SAPI, $data['source']['php_sapi'] );
	}

	/**
	 * A manifest with nothing in it totals to zero rather than warning.
	 */
	public function test_an_empty_manifest_totals_to_nothing() {
		$manifest = new Manifest();

		$manifest->recalculate_totals();

		$data = $manifest->to_array();

		$this->assertSame( 0, (int) $data['totals']['files'] );
		$this->assertSame( 0, (int) $data['totals']['bytes'] );
	}

	/**
	 * A manifest written to disk reads back the same.
	 */
	public function test_manifest_round_trips_through_disk() {
		$manifest = Manifest::for_this_site();
		$manifest->recalculate_totals();

		$path = $manifest->write( $this->dir );

		$this->assertFileExists( $path );

		$read = \json_decode( (string) \file_get_contents( $path ), true );

		$this->assertSame( $manifest->to_array(), $read, 'What was written is what comes back.' );
	}

	/**
	 * The exit codes are a published interface; their numbers cannot drift.
	 *
	 * A wrapper script branches on these. Changing one silently turns "stopped early, run me
	 * again" into something a caller treats as a failed migration.
	 */
	public function test_exit_codes_are_fixed() {
		$this->assertSame( 0, Output::EXIT_OK );
		$this->assertSame( 1, Output::EXIT_FAILURE );
		$this->assertSame( 2, Output::EXIT_INCOMPATIBLE );
		$this->assertSame( 3, Output::EXIT_RESUMABLE );
		$this->assertSame( 4, Output::EXIT_INVALID_PACKAGE );
	}

	/**
	 * Only formats the CLI actually renders are accepted, and the default is human-readable.
	 */
	public function test_format_resolution() {
		$this->assertSame( 'table', Output::format( array() ), 'No --format means a table.' );
		$this->assertSame( 'json', Output::format( array( 'format' => 'json' ) ) );
		$this->assertSame( 'json', Output::format( array( 'format' => 'JSON' ) ), 'Case does not matter.' );
		$this->assertSame(
			'table',
			Output::format( array( 'format' => 'nonsense' ) ),
			'An unknown format falls back rather than failing.'
		);
	}

	/**
	 * Which formats mean "a program is reading this".
	 *
	 * This is what decides whether a confirmation prompt is a question or an error, so it has to
	 * include every machine format and exclude the human one.
	 */
	public function test_machine_formats_are_recognised() {
		$this->assertFalse( Output::is_machine( array() ) );
		$this->assertFalse( Output::is_machine( array( 'format' => 'table' ) ) );

		foreach ( array( 'json', 'csv', 'yaml' ) as $format ) {
			$this->assertTrue( Output::is_machine( array( 'format' => $format ) ), $format . ' is machine-readable.' );
		}
	}

	/**
	 * A compatibility report flattens with the blocking checks first.
	 */
	public function test_report_rows_put_blockers_first() {
		$rows = Output::report_rows(
			array(
				'passed'   => array( array( 'id' => 'zip', 'status' => 'pass', 'label' => 'Fine.' ) ),
				'warnings' => array( array( 'id' => 'disk', 'status' => 'warn', 'label' => 'Tight.' ) ),
				'blocking' => array( array( 'id' => 'multisite', 'status' => 'block', 'label' => 'No.' ) ),
			)
		);

		$this->assertCount( 3, $rows );
		$this->assertSame( 'multisite', $rows[0]['check'], 'The reason somebody ran this comes first.' );
		$this->assertSame( 'disk', $rows[1]['check'] );
		$this->assertSame( 'zip', $rows[2]['check'] );
	}

	/**
	 * An empty report flattens to nothing rather than to a row of blanks.
	 */
	public function test_report_rows_of_nothing_is_nothing() {
		$this->assertSame( array(), Output::report_rows( array() ) );
	}
}
