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
	 * Swap a named few tables and leave the rest of the database where it is.
	 *
	 * The whole-site swap moves everything with the live prefix, which is right when the package
	 * *is* the site. A package that carries a plugin's own tables is not that: the destination
	 * keeps its posts, its users and its settings, and what changes is the handful of tables the
	 * plugin owns. Same statement, same atomicity, a shorter list -- and the same backup, so the
	 * undo is the same rename in reverse.
	 *
	 * A name with no live table is an arrival rather than a replacement: nothing goes to the
	 * backup for it, and rolling back drops it.
	 *
	 * @param array $state Import state, modified in place.
	 * @param array $names Table names without any prefix.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If there is nothing to move, or the rename fails.
	 */
	public function execute_only( array &$state, array $names ) {
		$pairs    = array();
		$replaced = array();
		$added    = array();

		foreach ( $names as $name ) {
			$name = (string) $name;

			if ( '' === $name ) {
				continue;
			}

			if ( ! $this->table_exists( $this->stage . $name ) ) {
				continue;
			}

			if ( $this->table_exists( $this->live . $name ) ) {
				$pairs[]    = array( $this->live . $name, $this->backup . $name );
				$replaced[] = $name;
			} else {
				$added[] = $name;
			}

			$pairs[] = array( $this->stage . $name, $this->live . $name );
		}

		if ( empty( $pairs ) ) {
			throw new \RuntimeException( 'Nothing to swap.' );
		}

		$this->rename( $pairs );

		$state['swapped']       = true;
		$state['swapped_at']    = \gmdate( 'c' );
		$state['backup_count']  = \count( $replaced );
		$state['swapped_names'] = \array_values( \array_merge( $replaced, $added ) );
		$state['added_names']   = \array_values( $added );
	}

	/**
	 * Undo a swap of a named few tables.
	 *
	 * The mirror of `execute_only()`, and it has one case the whole-site rollback does not: a
	 * table the destination never had is not put back, because there is nothing to put back. It
	 * goes to the staging prefix with the others and is dropped from there.
	 *
	 * @param array $state Import state, modified in place.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the rename fails.
	 */
	public function rollback_only( array &$state ) {
		$names = isset( $state['swapped_names'] ) ? (array) $state['swapped_names'] : array();
		$pairs = array();

		foreach ( $names as $name ) {
			$name = (string) $name;

			if ( '' === $name || ! $this->table_exists( $this->live . $name ) ) {
				continue;
			}

			$pairs[] = array( $this->live . $name, $this->stage . $name );

			if ( $this->table_exists( $this->backup . $name ) ) {
				$pairs[] = array( $this->backup . $name, $this->live . $name );
			}
		}

		if ( ! empty( $pairs ) ) {
			$this->rename( $pairs );
		}

		$state['swapped']     = false;
		$state['rolled_back'] = true;
	}

	/**
	 * Take a named few backup tables out of the way before a partial swap needs their names.
	 *
	 * @param array $names Table names without any prefix.
	 *
	 * @return array The full names that were dropped.
	 */
	public function clear_backup_names( array $names ) {
		global $wpdb;

		$dropped = array();

		foreach ( $names as $name ) {
			$table = $this->backup . (string) $name;

			if ( ! $this->table_exists( $table ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'DROP TABLE IF EXISTS `' . \esc_sql( $table ) . '`' );

			$dropped[] = $table;
		}

		return $dropped;
	}

	/**
	 * Whether one table is there.
	 *
	 * @param string $table Full table name.
	 *
	 * @return bool
	 */
	public function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);

		return null !== $found;
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
	 * @return int How many actually went.
	 */
	public function discard_backup() {
		return $this->drop_prefixed( $this->backup );
	}

	/**
	 * Drop every table carrying a prefix, and report what really happened.
	 *
	 * One statement, not one per table. A foreign key between two of them makes the parent
	 * undroppable while the child is still there, so dropping them one at a time succeeds or fails
	 * by luck of the alphabet -- and the old loop counted every table as dropped without asking,
	 * so a refusal was reported as a success. That is how a site came to be left holding one
	 * `nfdold_wp_users` that no import would clear and that the review screen then read as an
	 * undecided migration.
	 *
	 * The count is taken by looking again rather than by trusting the statement.
	 *
	 * @param string $prefix Table prefix.
	 *
	 * @return int How many tables are gone.
	 */
	protected function drop_prefixed( $prefix ) {
		global $wpdb;

		$tables = $this->tables_with_prefix( $prefix );

		if ( empty( $tables ) ) {
			return 0;
		}

		$quoted = array();

		foreach ( $tables as $table ) {
			$quoted[] = '`' . \esc_sql( $table ) . '`';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . \implode( ', ', $quoted ) );

		return \count( $tables ) - \count( $this->tables_with_prefix( $prefix ) );
	}

	/**
	 * Tables that are still there under a prefix.
	 *
	 * @param string $prefix Table prefix.
	 *
	 * @return array
	 */
	public function remaining( $prefix ) {
		return $this->tables_with_prefix( $prefix );
	}

	/**
	 * The prefix replaced tables are kept under.
	 *
	 * @return string
	 */
	public function backup_prefix() {
		return $this->backup;
	}

	/**
	 * Cut the foreign keys that now point into the backup tables.
	 *
	 * `RENAME TABLE` carries a foreign key with it: InnoDB rewrites the reference so it still
	 * names the table it was given, under its new name. For the tables the package brought that is
	 * exactly right -- child and parent move together. For a table this site already had and the
	 * package does not carry, it is not: its parent was renamed out from under it, so a constraint
	 * that meant `wp_users` now means `nfdold_wp_users`, pointing at the backup instead of at the
	 * site. Found on a real destination, where WP Defender's quarantine table was left referencing
	 * the replaced users table -- which also made that table refuse to be dropped.
	 *
	 * Dropping the constraint is a schema change and touches no rows. Repointing it at the live
	 * table is the tempting alternative and is wrong: the merge can renumber user IDs, so the
	 * rows underneath may no longer satisfy it, and a migration must not fail on somebody else's
	 * plugin's integrity rule. WordPress itself defines no foreign keys.
	 *
	 * @return array Each `table.constraint` that was cut.
	 */
	public function detach_backup_references() {
		global $wpdb;

		$like = $wpdb->esc_like( $this->backup ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS child, CONSTRAINT_NAME AS name FROM information_schema.REFERENTIAL_CONSTRAINTS'
				. ' WHERE CONSTRAINT_SCHEMA = %s AND REFERENCED_TABLE_NAME LIKE %s AND TABLE_NAME NOT LIKE %s',
				$wpdb->dbname,
				$like,
				$like
			),
			ARRAY_A
		);

		$cut = array();

		foreach ( (array) $rows as $row ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$done = $wpdb->query(
				'ALTER TABLE `' . \esc_sql( $row['child'] ) . '` DROP FOREIGN KEY `' . \esc_sql( $row['name'] ) . '`'
			);

			if ( false !== $done ) {
				$cut[] = $row['child'] . '.' . $row['name'];
			}
		}

		return $cut;
	}

	/**
	 * Remove the staged tables without touching anything live.
	 *
	 * @return int How many were dropped.
	 */
	public function discard_staged() {
		return $this->drop_prefixed( $this->stage );
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
