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
		$mysql->set_table_where_query( \nfd_sm_table_prefix() . 'options', $this->options_exclusion() );

		if ( $deadline > 0 ) {
			$seconds = \max( 1, (int) \ceil( $deadline - \microtime( true ) ) );

			\add_filter(
				'nfd_sm_completed_timeout',
				function () use ( $seconds ) {
					return $seconds;
				}
			);
		}

		$complete = $mysql->export( $target, $query_offset, $table_index, $table_offset, $table_rows );

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

		foreach ( $rows as $table ) {
			if ( 0 === \strpos( $table, $prefix ) ) {
				$tables[] = $table;
			}
		}

		return $tables;
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
		$names = \defined( 'NFD_SM_OPTIONS_LIST' ) ? NFD_SM_OPTIONS_LIST : array();

		if ( empty( $names ) ) {
			return '1 = 1';
		}

		$quoted = array();

		foreach ( $names as $name ) {
			$quoted[] = "'" . \esc_sql( $name ) . "'";
		}

		return '`option_name` NOT IN (' . \implode( ', ', $quoted ) . ')';
	}
}
