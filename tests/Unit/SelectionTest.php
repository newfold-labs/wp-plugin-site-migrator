<?php
/**
 * Choosing what goes into a package.
 *
 * Two kinds of assertion live here. The first is about refusal: a selection arrives from a REST
 * route, so the class that reads it has to be the thing that says no — to a path climbing out of
 * its part, and to leaving out a table WordPress cannot start without.
 *
 * The second is about the shape of the part list, and it is the reason this file exists at all.
 * `content-other` collects everything under `wp-content` that no other part claims, which it
 * works out from the other parts' roots. Narrow that list first and the exclusion goes with it —
 * so "leave out the media library" would have packaged the media library, through `content-other`,
 * with nothing anywhere saying so.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use NewfoldLabs\WP\SiteMigrator\Core\Export\DatabaseExporter;
use NewfoldLabs\WP\SiteMigrator\Core\Export\Exporter;
use NewfoldLabs\WP\SiteMigrator\Core\Export\PartSpecs;
use NewfoldLabs\WP\SiteMigrator\Core\Export\Selection;
use NewfoldLabs\WP\SiteMigrator\Core\Package\Checkpoint;
use NewfoldLabs\WP\SiteMigrator\Core\Package\Manifest;
use PHPUnit\Framework\TestCase;

class SelectionTest extends TestCase {

	/**
	 * Temporary uploads directory for the current test.
	 *
	 * @var string
	 */
	protected $dir = '';

	protected function setUp(): void {
		parent::setUp();

		$this->dir = \Fixture::reset();

		// A site laid out the way a real one is, so the parts derive their prefixes from
		// somewhere real and `content-other` has something to exclude.
		foreach ( array( 'plugins/akismet', 'plugins/hello', 'themes/twentytwenty', 'mu-plugins', 'uploads/2024', 'languages' ) as $path ) {
			\wp_mkdir_p( WP_CONTENT_DIR . '/' . $path );
		}

		\Fixture::$uploads = WP_CONTENT_DIR . '/uploads';
	}

	protected function tearDown(): void {
		\Fixture::rmdir( $this->dir );
		\Fixture::rmdir( \rtrim( ABSPATH, '/' ) );

		parent::tearDown();
	}

	/**
	 * The names of the parts a selection produces.
	 *
	 * @param Selection $selection Selection.
	 *
	 * @return array
	 */
	protected function part_names( Selection $selection ) {
		$names = array();

		foreach ( PartSpecs::all( $selection ) as $spec ) {
			$names[] = $spec->name();
		}

		return $names;
	}

	/**
	 * One part out of a list, by name.
	 *
	 * @param Selection $selection Selection.
	 * @param string    $name      Part name.
	 *
	 * @return \NewfoldLabs\WP\SiteMigrator\Core\Package\PartSpec|null
	 */
	protected function part( Selection $selection, $name ) {
		foreach ( PartSpecs::all( $selection ) as $spec ) {
			if ( $name === $spec->name() ) {
				return $spec;
			}
		}

		return null;
	}

	/**
	 * Nothing chosen means the whole site, which is what an export has always been.
	 */
	public function test_an_empty_selection_carries_everything() {
		$selection = Selection::everything();

		$this->assertTrue( $selection->is_everything() );
		$this->assertSame( array(), $selection->describe() );
		$this->assertContains( 'uploads', $this->part_names( $selection ) );
	}

	/**
	 * A deselected part is gone from the list.
	 */
	public function test_a_refused_part_is_not_exported() {
		$names = $this->part_names( new Selection( array( 'parts' => array( 'uploads' => false ) ) ) );

		$this->assertNotContains( 'uploads', $names );
		$this->assertContains( 'plugins', $names, 'Only the refused part goes.' );
	}

	/**
	 * And `content-other` still refuses to collect it.
	 *
	 * The bug this guards is invisible from the part list: `uploads` is correctly absent, and the
	 * files are packaged anyway by the part that sweeps up whatever the others left.
	 */
	public function test_refusing_uploads_does_not_hand_them_to_content_other() {
		$other = $this->part( new Selection( array( 'parts' => array( 'uploads' => false ) ) ), 'content-other' );

		$this->assertNotNull( $other );
		$this->assertContains(
			'uploads',
			$other->excluded_dirs(),
			'content-other must keep excluding a part that was turned off, or the files come back through it.'
		);
	}

	/**
	 * An item inside a part is refused by path, in both lists.
	 */
	public function test_a_refused_item_is_excluded_from_its_part() {
		$plugins = $this->part(
			new Selection( array( 'paths' => array( 'plugins' => array( 'akismet' ) ) ) ),
			'plugins'
		);

		$this->assertNotNull( $plugins );
		$this->assertContains( 'akismet', $plugins->excluded_dirs() );
		$this->assertContains( 'akismet', $plugins->excluded_files() );
	}

	/**
	 * Refusing every item of an allowlisted part drops the part.
	 *
	 * `PartSpec::only( array() )` leaves the spec looking like a plain shallow walk, and for
	 * `root-extras` that walk is the WordPress root — where `wp-config.php` lives. Finding 2.4
	 * again, reached from a direction that did not exist when it was fixed.
	 */
	public function test_emptying_an_allowlist_removes_the_part_rather_than_widening_it() {
		$selection = new Selection(
			array( 'paths' => array( 'root-extras' => PartSpecs::root_allowlist() ) )
		);

		$this->assertNotContains( 'root-extras', $this->part_names( $selection ) );
	}

	/**
	 * A path may not climb out of the part it belongs to.
	 */
	public function test_paths_that_escape_are_dropped() {
		$clean = Selection::sanitize(
			array(
				'paths' => array(
					'plugins' => array( '../../wp-config.php', '/etc/passwd', 'C:/windows', '', 'akismet' ),
				),
			)
		);

		$this->assertSame( array( 'plugins' => array( 'etc/passwd', 'akismet' ) ), $clean['paths'] );
	}

	/**
	 * The tables WordPress cannot run without are not skippable, whatever the prefix.
	 */
	public function test_required_tables_cannot_be_skipped() {
		$clean = Selection::sanitize(
			array(
				'database' => array(
					'skip_tables' => array( 'wp_posts', 'wp_options', 'wp_actionscheduler_logs' ),
				),
			)
		);

		$this->assertSame( array( 'wp_actionscheduler_logs' ), $clean['database']['skip_tables'] );
		$this->assertTrue( Selection::is_required_table( 'wp_users' ) );
		$this->assertFalse( Selection::is_required_table( 'wp_yoast_indexable' ) );
	}

	/**
	 * Only refusals are stored, so a part added in a later version is carried by an old selection.
	 */
	public function test_only_refusals_are_stored() {
		$clean = Selection::sanitize(
			array( 'parts' => array( 'plugins' => true, 'uploads' => false ) )
		);

		$this->assertSame( array( 'uploads' => false ), $clean['parts'] );
	}

	/**
	 * A run keeps the selection it started with, whatever the site's saved one says now.
	 *
	 * `part_index` is a position in the part list, so a list that changed underneath a resume
	 * would pick up the wrong part and finish a package whose manifest describes something else.
	 * The checkpoint therefore owns the answer for the length of a run, and the site's saved
	 * selection only decides where the next one begins.
	 */
	public function test_a_running_export_keeps_its_own_selection() {
		$package = $this->dir . '/package';

		\wp_mkdir_p( $package );

		// The site now says "carry everything"...
		Selection::forget();

		// ...while the run in progress was started with the media library left out.
		\file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
			$package . '/' . Checkpoint::NAME,
			\wp_json_encode(
				\array_merge(
					Checkpoint::defaults(),
					array( 'selection' => array( 'parts' => array( 'uploads' => false ) ) )
				)
			)
		);

		$snapshot = ( new Exporter( $package ) )->snapshot();

		$this->assertNotContains( 'uploads', $snapshot['parts'] );

		// And a directory with no checkpoint in it starts from what the site has saved.
		Selection::store( array( 'parts' => array( 'plugins' => false ) ) );

		$fresh = ( new Exporter( $this->dir . '/fresh' ) )->snapshot();

		$this->assertNotContains( 'plugins', $fresh['parts'] );
		$this->assertContains( 'uploads', $fresh['parts'] );
	}

	/**
	 * What was left out travels in the manifest, and reads as "everything" when nothing was.
	 */
	public function test_the_manifest_records_what_was_left_out() {
		$selection = new Selection(
			array(
				'parts'    => array( 'uploads' => false ),
				'database' => array( 'skip_revisions' => true ),
			)
		);

		$manifest = new Manifest();
		$manifest->set_contents( $selection->to_array(), array( 'plugins' ), array( 'uploads' ) );

		$this->assertFalse( $manifest->get( 'contents.everything' ) );
		$this->assertSame( array( 'uploads' ), $manifest->get( 'contents.parts.excluded' ) );

		$read = new Selection( (array) $manifest->get( 'contents.selection', array() ) );

		$this->assertNotEmpty( $read->describe() );
		$this->assertTrue( $read->skips( 'skip_revisions' ) );

		$whole = new Manifest();
		$whole->set_contents( Selection::everything()->to_array(), array( 'plugins' ), array() );

		$this->assertTrue( $whole->get( 'contents.everything' ) );
	}

	/**
	 * A skipped table is named with the prefix or without it, and both mean the same table.
	 *
	 * The two surfaces disagree, which is why this matters. The picker lists what `SHOW TABLE
	 * STATUS` returned, so it sends `wp_acme_log`; somebody writing `--set` by hand reads the
	 * prefix off their own site and types `acme_log`. Matching the raw string honoured the first
	 * and silently ignored the second — a refusal that packaged the table anyway, against a README
	 * promising either form would do.
	 *
	 * @dataProvider skip_table_forms
	 *
	 * @param string $stored What the selection was given.
	 * @param string $asked  The full table name the dump asks about.
	 */
	public function test_a_skipped_table_is_matched_with_or_without_the_prefix( $stored, $asked ) {
		$selection = new Selection(
			array( 'database' => array( 'skip_tables' => array( $stored ) ) )
		);

		$this->assertTrue(
			$selection->skips_table( $asked ),
			\sprintf( 'stored as "%s", asked about "%s"', $stored, $asked )
		);
	}

	/**
	 * Both spellings of a skip, against both spellings of the question.
	 *
	 * @return array
	 */
	public function skip_table_forms() {
		return array(
			'prefixed stored, prefixed asked'     => array( 'wp_acme_log', 'wp_acme_log' ),
			'unprefixed stored, prefixed asked'   => array( 'acme_log', 'wp_acme_log' ),
			'prefixed stored, unprefixed asked'   => array( 'wp_acme_log', 'acme_log' ),
			'unprefixed stored, unprefixed asked' => array( 'acme_log', 'acme_log' ),
		);
	}

	/**
	 * And a table nobody skipped is not dragged in by the normalising.
	 */
	public function test_normalising_does_not_skip_a_table_nobody_named() {
		$selection = new Selection(
			array( 'database' => array( 'skip_tables' => array( 'acme_log' ) ) )
		);

		$this->assertFalse( $selection->skips_table( 'wp_acme_logs' ) );
		$this->assertFalse( $selection->skips_table( 'wp_acme' ) );
		$this->assertFalse( $selection->skips_table( 'wp_other' ) );
		$this->assertFalse( $selection->skips_table( '' ) );
	}

	/**
	 * The stored form is what was typed, because a selection travels in the manifest.
	 *
	 * Normalising on the way in would write this site's prefix into data a destination reads back,
	 * and the destination's prefix is the one thing about it that is guaranteed to differ.
	 */
	public function test_a_skipped_table_is_stored_as_it_was_given() {
		$selection = new Selection(
			array( 'database' => array( 'skip_tables' => array( 'acme_log' ) ) )
		);

		$this->assertSame(
			array( 'acme_log' ),
			$selection->to_array()['database']['skip_tables']
		);
	}

	/**
	 * A required table is refused whichever way it is spelled.
	 */
	public function test_a_required_table_is_refused_in_either_form() {
		$selection = new Selection(
			array(
				'database' => array(
					'skip_tables' => array( 'wp_options', 'posts', 'wp_acme_log' ),
				),
			)
		);

		$this->assertSame(
			array( 'wp_acme_log' ),
			$selection->to_array()['database']['skip_tables']
		);
	}

	/**
	 * And the dump's table list actually drops it, whichever form was stored.
	 *
	 * This is the assertion that spans the two halves: `SHOW TABLES` always answers with the
	 * prefix on, so a selection holding the bare name has to be matched against the dressed one
	 * here or the table is packaged despite being refused.
	 *
	 * @dataProvider skipped_table_spellings
	 *
	 * @param string $stored What the selection was given.
	 */
	public function test_the_dump_drops_a_skipped_table_however_it_was_named( $stored ) {
		\Fixture::$tables = array(
			'wp_posts',
			'wp_options',
			'wp_acme_log',
			'other_acme_log',
		);

		$selection = new Selection(
			array( 'database' => array( 'skip_tables' => array( $stored ) ) )
		);

		$tables = ( new DatabaseExporter( $selection ) )->tables();

		$this->assertNotContains( 'wp_acme_log', $tables );
		$this->assertContains( 'wp_posts', $tables );
		$this->assertContains( 'wp_options', $tables );

		// Another install sharing the database is not this site and never was.
		$this->assertNotContains( 'other_acme_log', $tables );
	}

	/**
	 * @return array
	 */
	public function skipped_table_spellings() {
		return array(
			'as the picker sends it'      => array( 'wp_acme_log' ),
			'as somebody types it by hand' => array( 'acme_log' ),
		);
	}

	/**
	 * With nothing skipped, every table of this site's is dumped.
	 */
	public function test_the_dump_carries_everything_by_default() {
		\Fixture::$tables = array( 'wp_posts', 'wp_acme_log', 'other_acme_log' );

		$tables = ( new DatabaseExporter( Selection::everything() ) )->tables();

		$this->assertSame( array( 'wp_posts', 'wp_acme_log' ), $tables );
	}
}
