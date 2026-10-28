<?php
/**
 * Database dump step.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Export;

use NewfoldLabs\WP\SiteMigrator\Database\DatabaseMysqli;

/**
 * Thin wrapper over the retained dumper.
 *
 * `DatabaseBase::export()` already resumes correctly — it takes its four offsets by reference
 * and returns whether it finished. That is the one piece of the original packaging pipeline
 * whose resumability worked, so it is kept and simply given a caller that respects it.
 */
class DatabaseExporter {

	const FILE = 'database.sql';

	/**
	 * What the user chose to leave out.
	 *
	 * @var Selection
	 */
	protected $selection;

	/**
	 * Constructor.
	 *
	 * @param Selection $selection What to leave out, or null for the whole database.
	 */
	public function __construct( $selection = null ) {
		$this->selection = ( $selection instanceof Selection ) ? $selection : Selection::everything();
	}

	/**
	 * Dump as much of the database as the deadline allows.
	 *
	 * @param string $target   Absolute path to write to.
	 * @param array  $offsets  Export offsets, modified in place.
	 * @param float  $deadline Unix timestamp to stop by, or 0 for no limit.
	 *
	 * @return bool True when the dump is complete.
	 */
	public function step( $target, array &$offsets, $deadline ) {
		global $wpdb;

		$query_offset = isset( $offsets['query_offset'] ) ? (int) $offsets['query_offset'] : 0;
		$table_index  = isset( $offsets['table_index'] ) ? (int) $offsets['table_index'] : 0;
		$table_offset = isset( $offsets['table_offset'] ) ? (int) $offsets['table_offset'] : 0;
		$table_rows   = isset( $offsets['table_rows'] ) ? (int) $offsets['table_rows'] : 0;

		$mysql = new DatabaseMysqli( $wpdb );
		$mysql->set_tables( $this->tables() );

		foreach ( $this->where_clauses() as $table => $where ) {
			$mysql->set_table_where_query( $table, $where );
		}

		$budget = null;

		if ( $deadline > 0 ) {
			$seconds = \max( 1, (int) \ceil( $deadline - \microtime( true ) ) );

			$budget = function () use ( $seconds ) {
				return $seconds;
			};

			\add_filter( 'nfd_sm_completed_timeout', $budget );
		}

		$complete = $mysql->export( $target, $query_offset, $table_index, $table_offset, $table_rows );

		// Taken off again: a CLI run calls step() repeatedly in one process, and a filter added
		// per call accumulates a closure per step, each holding a deadline that has passed.
		if ( null !== $budget ) {
			\remove_filter( 'nfd_sm_completed_timeout', $budget );
		}

		$offsets['query_offset'] = $query_offset;
		$offsets['table_index']  = $table_index;
		$offsets['table_offset'] = $table_offset;
		$offsets['table_rows']   = $table_rows;

		return (bool) $complete;
	}

	/**
	 * Tables belonging to this site.
	 *
	 * @return array
	 */
	public function tables() {
		global $wpdb;

		$tables = array();
		$prefix = \nfd_sm_table_prefix();
		$rows   = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! \is_array( $rows ) ) {
			return $tables;
		}

		// A partial dump is the other way round: an allowlist rather than a site minus refusals.
		// It exists to carry a chosen plugin's own tables into a destination that keeps its
		// database, so anything not on the list is not merely skipped -- it is somebody else's
		// data that this package has no business moving.
		if ( $this->selection->is_partial_database() ) {
			return $this->carried( $rows, $prefix );
		}

		foreach ( $rows as $table ) {
			if ( 0 !== \strpos( $table, $prefix ) ) {
				continue;
			}

			// `Selection` has already refused to skip anything the site cannot run without, so
			// this list only ever names extras: a plugin's log table, an analytics archive. It
			// answers the question itself rather than being compared to, because a name may or
			// may not carry the prefix depending on which surface sent it.
			if ( $this->selection->skips_table( $table ) ) {
				continue;
			}

			$tables[] = $table;
		}

		return $tables;
	}

	/**
	 * The tables a partial dump carries: the chosen ones, plus `options` when settings travel.
	 *
	 * `wp_options` is in the dump but never in the swap. It arrives as a staging table, the rows
	 * the source chose and nothing else, and the import copies those rows into the destination's
	 * own options table one at a time. That is the only way a plugin's settings can travel without
	 * the destination's identity -- its address, its permalinks, its active plugins -- travelling
	 * with them.
	 *
	 * @param array  $rows   Every table in the database.
	 * @param string $prefix This site's prefix.
	 *
	 * @return array
	 */
	protected function carried( array $rows, $prefix ) {
		$wanted = array();

		foreach ( $this->selection->carried_tables() as $table ) {
			$name = 0 === \strpos( (string) $table, $prefix ) ? (string) $table : $prefix . $table;

			$wanted[ $name ] = true;
		}

		if ( ! empty( $this->selection->carried_options() ) ) {
			$wanted[ $prefix . 'options' ] = true;
		}

		$tables = array();

		foreach ( $rows as $table ) {
			if ( isset( $wanted[ $table ] ) ) {
				$tables[] = $table;
			}
		}

		return $tables;
	}

	/**
	 * The row filters this dump runs with, keyed by table.
	 *
	 * Each is dropped into the dumper's `WHERE %s`, so they have to be complete expressions. The
	 * rows they remove are the ones nobody misses and everybody carries: revisions of posts that
	 * exist anyway, comments already marked as junk, and cache entries whose whole purpose is to
	 * be rebuilt. On a long-lived site they are routinely most of the dump.
	 *
	 * @return array Table name => SQL condition.
	 */
	protected function where_clauses() {
		$prefix  = \nfd_sm_table_prefix();
		$clauses = array( $prefix . 'options' => $this->options_exclusion() );

		if ( $this->selection->skips( 'skip_revisions' ) ) {
			$clauses[ $prefix . 'posts' ] = "`post_type` != 'revision'";
		}

		if ( $this->selection->skips( 'skip_spam' ) ) {
			$clauses[ $prefix . 'comments' ] = "`comment_approved` NOT IN ( 'spam', 'trash' )";
		}

		return $clauses;
	}

	/**
	 * A WHERE clause excluding this plugin's own options from the dump.
	 *
	 * Built by escaping the option list rather than by counting placeholders into a format
	 * string, which is what made the previous version break whenever the list length changed
	 * (finding 3.6).
	 *
	 * @return string
	 */
	protected function options_exclusion() {
		$carried = $this->selection->carried_options();

		// In a partial dump the options table is not the site's settings, it is the chosen
		// plugins' settings: an allowlist of names, and no row that is not on it.
		if ( ! empty( $carried ) ) {
			$quoted = array();

			foreach ( $carried as $name ) {
				$quoted[] = "'" . \esc_sql( $name ) . "'";
			}

			return '`option_name` IN (' . \implode( ', ', $quoted ) . ')';
		}

		$names  = \defined( 'NFD_SM_OPTIONS_LIST' ) ? NFD_SM_OPTIONS_LIST : array();
		$where  = array();
		$quoted = array();

		foreach ( $names as $name ) {
			$quoted[] = "'" . \esc_sql( $name ) . "'";
		}

		if ( ! empty( $quoted ) ) {
			$where[] = '`option_name` NOT IN (' . \implode( ', ', $quoted ) . ')';
		}

		if ( $this->selection->skips( 'skip_transients' ) ) {
			// The underscore is a single-character wildcard in LIKE, so both of these escape it:
			// unescaped, `_transient_%` also matches `xtransientyfoo`, and a pattern that looks
			// this specific quietly dropping option names nobody named is the worst shape this
			// could fail in. Two patterns rather than four, because a timeout row is named
			// `_transient_timeout_<key>` and the first already matches it.
			foreach ( array( '\_transient\_%', '\_site\_transient\_%' ) as $pattern ) {
				$where[] = "`option_name` NOT LIKE '" . $pattern . "'";
			}
		}

		return empty( $where ) ? '1 = 1' : \implode( ' AND ', $where );
	}
}
