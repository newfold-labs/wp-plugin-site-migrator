<?php
/**
 * Database load step.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

use NewfoldLabs\WP\SiteMigrator\Database\DatabaseMysqli;

/**
 * Loads the dump into staging tables.
 *
 * The mirror of DatabaseExporter, and just as thin: the statement handling lives in
 * DatabaseBase alongside the export it has to agree with. Keeping the two halves in one file
 * is what stops the reader drifting from the writer.
 */
class DatabaseImporter {

	const FILE = 'database.sql';

	/**
	 * Collation rewrites applied during the load.
	 *
	 * @var array
	 */
	protected $collations = array();

	/**
	 * Whether any rewrite lost characters.
	 *
	 * @var bool
	 */
	protected $lossy = false;

	/**
	 * Load as much of the dump as the deadline allows.
	 *
	 * @param string $source   Absolute path to the dump.
	 * @param array  $state    Import state, modified in place.
	 * @param float  $deadline Unix timestamp to stop by, or 0 for no limit.
	 *
	 * @return bool True when the whole dump has been loaded.
	 */
	public function step( $source, array &$state, $deadline ) {
		global $wpdb;

		$mysql = new DatabaseMysqli( $wpdb );

		// Tables whose rows must all land in one request. Options is the one that matters: a
		// half-written options table is a site that cannot boot, and it is small enough that
		// finishing it is never the thing that blows a request budget.
		$mysql->set_atomic_tables( array( $state['source_prefix'] . 'options' ) );

		$budget = null;

		if ( $deadline > 0 ) {
			$seconds = \max( 1, (int) \ceil( $deadline - \microtime( true ) ) );

			$budget = function () use ( $seconds ) {
				return $seconds;
			};

			\add_filter( 'nfd_sm_completed_timeout', $budget );
		}

		$complete = $mysql->import( $source, $state );

		// Removed rather than left in place: a CLI run calls step() hundreds of times in one
		// process, and a filter added per call and never taken off is a closure per step, each
		// still holding the deadline from a step that finished long ago.
		if ( null !== $budget ) {
			\remove_filter( 'nfd_sm_completed_timeout', $budget );
		}

		$this->collations = $mysql->get_collations_replaced();
		$this->lossy      = $mysql->has_lossy_collation_change();

		return (bool) $complete;
	}

	/**
	 * Collation rewrites applied.
	 *
	 * @return array
	 */
	public function collations() {
		return $this->collations;
	}

	/**
	 * Whether a rewrite narrowed the character set.
	 *
	 * @return bool
	 */
	public function is_lossy() {
		return $this->lossy;
	}
}
