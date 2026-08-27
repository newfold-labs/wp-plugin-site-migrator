<?php
/**
 * The moment the destination becomes the source.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

/**
 * Verifies the staged tables and switches them in.
 *
 * The whole import is built around making this step instantaneous. Everything before it writes
 * only to tables the live site never reads, so a failure at any earlier point leaves the
 * destination exactly as it was and the user simply retries. This class is the only part that
 * touches a live table, and the change it makes is a single multi-table `RENAME`, which MySQL
 * executes atomically: there is no moment at which the site is half-migrated.
 *
 * The old tables are kept under a backup prefix, so rollback is a second rename.
 */
class Swap {

	/**
	 * Tables a WordPress site cannot boot without.
	 *
	 * @var array
	 */
	protected static $essential = array(
		'options',
		'posts',
		'postmeta',
		'users',
		'usermeta',
		'terms',
		'term_taxonomy',
		'term_relationships',
		'comments',
		'commentmeta',
	);

	/**
	 * Staged table prefix.
	 *
	 * @var string
	 */
	protected $stage;

	/**
	 * Live table prefix.
	 *
	 * @var string
	 */
	protected $live;

	/**
	 * Backup table prefix.
	 *
	 * @var string
	 */
	protected $backup;

	/**
	 * Constructor.
	 *
	 * @param string $stage  Staged prefix.
	 * @param string $live   Live prefix.
	 * @param string $backup Backup prefix.
	 */
	public function __construct( $stage, $live, $backup ) {
		$this->stage  = $stage;
		$this->live   = $live;
		$this->backup = $backup;
	}

	/**
	 * Check that the staged tables are worth switching to.
	 *
	 * @return array Problems found, empty when the staged database is sound.
	 */
	public function verify() {
		$problems = array();
		$staged   = $this->tables_with_prefix( $this->stage );

		if ( empty( $staged ) ) {
			return array( 'No staged tables were created, so there is nothing to swap in.' );
		}

		foreach ( self::$essential as $name ) {
			if ( ! \in_array( $this->stage . $name, $staged, true ) ) {
				$problems[] = \sprintf( 'The package is missing the %s table.', $name );
			}
		}

		$rows = $this->row_count( $this->stage . 'options' );

		if ( $rows < 1 ) {
			$problems[] = 'The staged options table is empty, which means the dump did not load.';
		}

		if ( $this->row_count( $this->stage . 'users' ) < 1 ) {
			$problems[] = 'The staged users table is empty, so nobody could sign in afterwards.';
		}

		return $problems;
	}

	/**
	 * Perform the swap.
	 *
	 * @param array $state Import state, modified in place.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the rename fails.
	 */
	public function execute( array &$state ) {
		$state['live_views'] = $this->drop_live_views();

		$staged = $this->tables_with_prefix( $this->stage );
		$live   = $this->tables_with_prefix( $this->live );

		$pairs = array();

		// Live out of the way first, staged into its place second. MySQL applies the pairs in
		// the order they are written, so a single statement can do both halves of a name swap.
		foreach ( $live as $table ) {
			$pairs[] = array( $table, $this->backup . \substr( $table, \strlen( $this->live ) ) );
		}

		foreach ( $staged as $table ) {
			$pairs[] = array( $table, $this->live . \substr( $table, \strlen( $this->stage ) ) );
		}

		if ( empty( $pairs ) ) {
			throw new \RuntimeException( 'Nothing to swap.' );
		}

		$this->rename( $pairs );

		$state['swapped']      = true;
		$state['swapped_at']   = \gmdate( 'c' );
		$state['backup_count'] = \count( $live );
	}

	/**
	 * Put the destination's own tables back.
	 *
	 * @param array $state Import state, modified in place.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the rename fails.
	 */
	public function rollback( array &$state ) {
		$backups  = $this->tables_with_prefix( $this->backup );
		$imported = $this->tables_with_prefix( $this->live );

		// Deliberately does not blame a clock. There is no longer one to blame — a backup ends
		// when its import is kept or when the next migration starts — and the old wording sent
		// somebody looking for an expiry that had not happened.
		if ( empty( $backups ) ) {
			throw new \RuntimeException(
				'There are no backup tables left to roll back to. This import was either already '
				. 'kept, or a later migration discarded them.'
			);
		}

		$pairs = array();

		foreach ( $imported as $table ) {
			$pairs[] = array( $table, $this->stage . \substr( $table, \strlen( $this->live ) ) );
		}

		foreach ( $backups as $table ) {
			$pairs[] = array( $table, $this->live . \substr( $table, \strlen( $this->backup ) ) );
		}

		$this->rename( $pairs );

		if ( ! empty( $state['live_views'] ) ) {
			$this->create_views( $state['live_views'], '', '' );
		}

		$state['swapped']     = false;
		$state['rolled_back'] = true;
	}

	/**
	 * Recreate the source's views against their final table names.
	 *
	 * A view body names the tables it selects from, so it can only be created once those tables
	 * exist under the names the source used — which is only true after the swap.
	 *
	 * @param array $views Map of source view name to its CREATE statement.
	 * @param array $state Import state.
	 *
	 * @return void
	 */
	public function recreate_views( array $views, array $state ) {
		if ( empty( $views ) ) {
			return;
		}

		$this->create_views( $views, $state['source_prefix'], $this->live );
	}

	/**
	 * Drop the backup tables.
	 *
	 * @return int How many were dropped.
	 */
	public function discard_backup() {
		global $wpdb;

		$tables  = $this->tables_with_prefix( $this->backup );
		$dropped = 0;

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'DROP TABLE IF EXISTS `' . \esc_sql( $table ) . '`' );
			++$dropped;
		}

		return $dropped;
	}

	/**
	 * Remove the staged tables without touching anything live.
	 *
	 * @return int How many were dropped.
	 */
	public function discard_staged() {
		global $wpdb;

		$tables  = $this->tables_with_prefix( $this->stage );
		$dropped = 0;

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'DROP TABLE IF EXISTS `' . \esc_sql( $table ) . '`' );
			++$dropped;
		}

		return $dropped;
	}

	/**
	 * Whether backup tables are still available to roll back to.
	 *
	 * @return bool
	 */
	public function has_backup() {
		return ! empty( $this->tables_with_prefix( $this->backup ) );
	}

	/**
	 * Run one multi-table rename.
	 *
	 * @param array $pairs List of `array( from, to )`.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the rename fails.
	 */
	protected function rename( array $pairs ) {
		global $wpdb;

		$clauses = array();

		foreach ( $pairs as $pair ) {
			$clauses[] = \sprintf( '`%s` TO `%s`', \esc_sql( $pair[0] ), \esc_sql( $pair[1] ) );
		}

		$sql = 'RENAME TABLE ' . \implode( ', ', $clauses );

		// Suppressed rather than hidden: hide_errors() still lets wpdb print the failure, and a
		// swap that fails is reported by the exception below in words the user can act on, not
		// as a 2KB RENAME statement dumped into the page.
		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->query( $sql );

		$wpdb->suppress_errors( $suppressed );

		if ( false === $result ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an exception message, not output: it reaches a terminal or a JSON field, never an HTML page.
				'The table swap failed and nothing was changed: ' . $wpdb->last_error
			);
		}
	}

	/**
	 * Drop the destination's own views, keeping their definitions for rollback.
	 *
	 * Renaming a view does not rewrite the tables its body names, so a view carried through the
	 * swap would silently start reading the backup tables.
	 *
	 * @return array Map of view name to its CREATE statement.
	 */
	protected function drop_live_views() {
		global $wpdb;

		$definitions = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$views = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s',
				$wpdb->dbname,
				$wpdb->esc_like( $this->live ) . '%'
			)
		);

		foreach ( (array) $views as $view ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( 'SHOW CREATE VIEW `' . \esc_sql( $view ) . '`', ARRAY_A );

			if ( isset( $row['Create View'] ) ) {
				$definitions[ $view ] = $row['Create View'];
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'DROP VIEW IF EXISTS `' . \esc_sql( $view ) . '`' );
		}

		return $definitions;
	}

	/**
	 * Create views, optionally rewriting a table prefix inside their definitions.
	 *
	 * @param array  $views Map of name to CREATE statement.
	 * @param string $from  Prefix to rewrite from, or empty to leave the definition alone.
	 * @param string $to    Prefix to rewrite to.
	 *
	 * @return void
	 */
	protected function create_views( array $views, $from, $to ) {
		global $wpdb;

		foreach ( $views as $name => $sql ) {
			if ( '' !== $from && $from !== $to ) {
				// Only backticked identifiers, so a literal in the view's WHERE clause is left
				// alone.
				$sql = \str_replace( '`' . $from, '`' . $to, $sql );
			}

			$target = '' !== $from && $from !== $to && 0 === \strpos( $name, $from )
				? $to . \substr( $name, \strlen( $from ) )
				: $name;

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'DROP VIEW IF EXISTS `' . \esc_sql( $target ) . '`' );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $sql );
		}
	}

	/**
	 * Base tables carrying a prefix.
	 *
	 * Matched on the prefix as a literal string rather than with LIKE, whose wildcards would
	 * make a prefix containing an underscore — which is to say every WordPress prefix — match
	 * far more than it should.
	 *
	 * @param string $prefix Prefix.
	 *
	 * @return array
	 */
	public function tables_with_prefix( $prefix ) {
		global $wpdb;

		// An empty prefix matches every table in the database, and two of this class's callers
		// go on to DROP what it returns. Refuse rather than trust that no caller ever passes
		// state that has not been through precheck.
		if ( '' === (string) $prefix ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$all = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s'
				. " AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME ASC",
				$wpdb->dbname
			)
		);

		$found = array();

		foreach ( (array) $all as $table ) {
			if ( 0 === \strpos( $table, $prefix ) ) {
				$found[] = $table;
			}
		}

		return $found;
	}

	/**
	 * Row count for a table.
	 *
	 * @param string $table Table name.
	 *
	 * @return int
	 */
	protected function row_count( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . \esc_sql( $table ) . '`' );
	}
}
