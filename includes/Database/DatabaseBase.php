<?php
/**
 * Derived from All-in-One WP Migration by ServMask, Inc. (https://servmask.com/),
 * licensed GPL-2.0-or-later. Modified for this plugin. See CREDITS.md.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Database;

use NewfoldLabs\WP\SiteMigrator\Utils\DatabaseUtility;

/**
 * The base database class for the db interactions
 */
abstract class DatabaseBase {
	/**
	 * WordPress database handler
	 *
	 * @var object
	 */
	protected $wpdb = null;

	/**
	 * WordPress database base tables
	 *
	 * @var array
	 */
	protected $base_tables = null;

	/**
	 * WordPress database views
	 *
	 * @var array
	 */
	protected $views = null;

	/**
	 * WordPress database tables
	 *
	 * @var array
	 */
	protected $tables = null;

	/**
	 * Table where query
	 *
	 * @var array
	 */
	protected $table_where_query = array();

	/**
	 * Table select columns
	 *
	 * @var array
	 */
	protected $table_select_columns = array();

	/**
	 * Table prefix columns
	 *
	 * @var array
	 */
	protected $table_prefix_columns = array();

	/**
	 * Table prefix filters
	 *
	 * @var array
	 */
	protected $table_prefix_filters = array();

	/**
	 * List all tables that should not be affected by the timeout of the current request
	 *
	 * @var array
	 */
	protected $atomic_tables = array();

	/**
	 * Visual Composer
	 *
	 * @var boolean
	 */
	protected $visual_composer = false;

	/**
	 * Oxygen Builder
	 *
	 * @var boolean
	 */
	protected $oxygen_builder = false;

	/**
	 * BeTheme Responsive
	 *
	 * @var boolean
	 */
	protected $betheme_responsive = false;

	/**
	 * Optimize Press
	 *
	 * @var boolean
	 */
	protected $optimize_press = false;

	/**
	 * Avada Fusion Builder
	 *
	 * @var boolean
	 */
	protected $avada_fusion_builder = false;

	/**
	 * Collation rewrites this server needs, built once per instance
	 *
	 * @var array|null
	 */
	protected $collation_map = null;

	/**
	 * Collation rewrites actually applied, for the import report
	 *
	 * @var array
	 */
	protected $collations_replaced = array();

	/**
	 * Constructor
	 *
	 * @param object $wpdb WPDB instance
	 *
	 * @throws \Exception When we cannot connect to SQL.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;

		// Check Microsoft SQL Server support
		if ( is_resource( $this->wpdb->dbh ) ) {
			if ( get_resource_type( $this->wpdb->dbh ) === 'SQL Server Connection' ) {
				throw new \Exception(
					(
						'Your WordPress installation uses Microsoft SQL Server. ' .
						'To use Site Migrator, please change your installation to MySQL and try again. '
					)
				);
			}
		}

		// Set database host (HyberDB)
		if ( empty( $this->wpdb->dbhost ) ) {
			if ( isset( $this->wpdb->last_used_server['host'] ) ) {
				$this->wpdb->dbhost = $this->wpdb->last_used_server['host'];
			}
		}

		// Set database name (HyperDB)
		if ( empty( $this->wpdb->dbname ) ) {
			if ( isset( $this->wpdb->last_used_server['name'] ) ) {
				$this->wpdb->dbname = $this->wpdb->last_used_server['name'];
			}
		}
	}

	/**
	 * Set table where query
	 *
	 * @param  string $table_name  Table name
	 * @param  array  $where_query Table query
	 * @return object
	 */
	public function set_table_where_query( $table_name, $where_query ) {
		$this->table_where_query[ strtolower( $table_name ) ] = $where_query;

		return $this;
	}

	/**
	 * Get table where query
	 *
	 * @param  string $table_name Table name
	 * @return string
	 */
	public function get_table_where_query( $table_name ) {
		if ( isset( $this->table_where_query[ strtolower( $table_name ) ] ) ) {
			return $this->table_where_query[ strtolower( $table_name ) ];
		}
	}

	/**
	 * Set table select columns
	 *
	 * @param  string $table_name   Table name
	 * @param  array  $column_names Column names
	 * @return object
	 */
	public function set_table_select_columns( $table_name, $column_names ) {
		foreach ( $column_names as $column_name => $column_expression ) {
			$this->table_select_columns[ strtolower( $table_name ) ][ strtolower( $column_name ) ] = $column_expression;
		}

		return $this;
	}

	/**
	 * Get table select columns
	 *
	 * @param  string $table_name Table name
	 * @return array
	 */
	public function get_table_select_columns( $table_name ) {
		if ( isset( $this->table_select_columns[ strtolower( $table_name ) ] ) ) {
			return $this->table_select_columns[ strtolower( $table_name ) ];
		}
	}

	/**
	 * Set table prefix columns
	 *
	 * @param  string $table_name   Table name
	 * @param  array  $column_names Column names
	 * @return object
	 */
	public function set_table_prefix_columns( $table_name, $column_names ) {
		foreach ( $column_names as $column_name ) {
			$this->table_prefix_columns[ strtolower( $table_name ) ][ strtolower( $column_name ) ] = true;
		}

		return $this;
	}

	/**
	 * Get table prefix columns
	 *
	 * @param  string $table_name Table name
	 * @return array
	 */
	public function get_table_prefix_columns( $table_name ) {
		if ( isset( $this->table_prefix_columns[ strtolower( $table_name ) ] ) ) {
			return $this->table_prefix_columns[ strtolower( $table_name ) ];
		}
	}

	/**
	 * Add table prefix filter
	 *
	 * @param  string $table_prefix   Table prefix
	 * @param  string $exclude_prefix Exclude prefix
	 * @return object
	 */
	public function add_table_prefix_filter( $table_prefix, $exclude_prefix = null ) {
		$this->table_prefix_filters[] = array( $table_prefix, $exclude_prefix );

		return $this;
	}

	/**
	 * Get table prefix filter
	 *
	 * @return array
	 */
	public function get_table_prefix_filters() {
		return $this->table_prefix_filters;
	}

	/**
	 * Set atomic tables
	 *
	 * @param  array $tables List of tables
	 * @return object
	 */
	public function set_atomic_tables( $tables ) {
		$this->atomic_tables = $tables;

		return $this;
	}

	/**
	 * Get atomic tables
	 *
	 * @return array
	 */
	public function get_atomic_tables() {
		return $this->atomic_tables;
	}

	/**
	 * Set Visual Composer
	 *
	 * @param  boolean $active Is Visual Composer Active?
	 * @return object
	 */
	public function set_visual_composer( $active ) {
		$this->visual_composer = $active;

		return $this;
	}

	/**
	 * Get Visual Composer
	 *
	 * @return boolean
	 */
	public function get_visual_composer() {
		return $this->visual_composer;
	}

	/**
	 * Set Oxygen Builder
	 *
	 * @param  boolean $active Is Oxygen Builder Active?
	 * @return object
	 */
	public function set_oxygen_builder( $active ) {
		$this->oxygen_builder = $active;

		return $this;
	}

	/**
	 * Get Oxygen Builder
	 *
	 * @return boolean
	 */
	public function get_oxygen_builder() {
		return $this->oxygen_builder;
	}

	/**
	 * Set BeTheme Responsive
	 *
	 * @param  boolean $active Is BeTheme Responsive Active?
	 * @return object
	 */
	public function set_betheme_responsive( $active ) {
		$this->betheme_responsive = $active;

		return $this;
	}

	/**
	 * Get BeTheme Responsive
	 *
	 * @return boolean
	 */
	public function get_betheme_responsive() {
		return $this->betheme_responsive;
	}

	/**
	 * Set Optimize Press
	 *
	 * @param  boolean $active Is Optimize Press Active?
	 * @return object
	 */
	public function set_optimize_press( $active ) {
		$this->optimize_press = $active;

		return $this;
	}

	/**
	 * Get Optimize Press
	 *
	 * @return boolean
	 */
	public function get_optimize_press() {
		return $this->optimize_press;
	}

	/**
	 * Set Avada Fusion Builder
	 *
	 * @param  boolean $active Is Avada Fusion Builder Active?
	 * @return object
	 */
	public function set_avada_fusion_builder( $active ) {
		$this->avada_fusion_builder = $active;

		return $this;
	}

	/**
	 * Get Avada Fusion Builder
	 *
	 * @return boolean
	 */
	public function get_avada_fusion_builder() {
		return $this->avada_fusion_builder;
	}

	/**
	 * Get views
	 *
	 * @return array
	 */
	protected function get_views() {
		if ( is_null( $this->views ) ) {
			$where_query = array();

			// Get lower case table names
			$lower_case_table_names = $this->get_lower_case_table_names();

			// Loop over table prefixes
			if ( $this->get_table_prefix_filters() ) {
				foreach ( $this->get_table_prefix_filters() as $prefix_filter ) {
					if ( isset( $prefix_filter[0], $prefix_filter[1] ) ) {
						if ( $lower_case_table_names ) {
							$where_query[] = sprintf(
								"(`Tables_in_%s` REGEXP '^%s' AND `Tables_in_%s` NOT REGEXP '^%s')",
								$this->wpdb->dbname,
								$prefix_filter[0],
								$this->wpdb->dbname,
								$prefix_filter[1]
							);
						} else {
							$where_query[] = sprintf(
								"(CAST(`Tables_in_%s` AS BINARY) REGEXP BINARY '^%s' AND CAST(`Tables_in_%s` AS BINARY) NOT REGEXP BINARY '^%s')",
								$this->wpdb->dbname,
								$prefix_filter[0],
								$this->wpdb->dbname,
								$prefix_filter[1]
							);
						}
					} elseif ( $lower_case_table_names ) {
							$where_query[] = sprintf(
								"`Tables_in_%s` REGEXP '^%s'",
								$this->wpdb->dbname,
								$prefix_filter[0]
							);
					} else {
						$where_query[] = sprintf(
							"CAST(`Tables_in_%s` AS BINARY) REGEXP BINARY '^%s'",
							$this->wpdb->dbname,
							$prefix_filter[0]
						);
					}
				}
			} else {
				$where_query[] = 1;
			}

			$this->views = array();

			// Loop over views
			$result = $this->query(
				sprintf(
					"SHOW FULL TABLES FROM `%s` WHERE `Table_type` = 'VIEW' AND (%s)",
					$this->wpdb->dbname,
					implode( ' OR ', $where_query )
				)
			);
			// phpcs:ignore
			while ( $row = $this->fetch_row( $result ) ) {
				if ( isset( $row[0] ) ) {
					$this->views[] = $row[0];
				}
			}

			// Close result cursor
			$this->free_result( $result );
		}

		return $this->views;
	}

	/**
	 * Get base tables
	 *
	 * @return array
	 */
	protected function get_base_tables() {
		if ( is_null( $this->base_tables ) ) {
			$where_query = array();

			// Get lower case table names
			$lower_case_table_names = $this->get_lower_case_table_names();

			// Loop over table prefixes
			if ( $this->get_table_prefix_filters() ) {
				foreach ( $this->get_table_prefix_filters() as $prefix_filter ) {
					if ( isset( $prefix_filter[0], $prefix_filter[1] ) ) {
						if ( $lower_case_table_names ) {
							$where_query[] = sprintf(
								"(`Tables_in_%s` REGEXP '^%s' AND `Tables_in_%s` NOT REGEXP '^%s')",
								$this->wpdb->dbname,
								$prefix_filter[0],
								$this->wpdb->dbname,
								$prefix_filter[1]
							);
						} else {
							$where_query[] = sprintf(
								"(CAST(`Tables_in_%s` AS BINARY) REGEXP BINARY '^%s' AND CAST(`Tables_in_%s` AS BINARY) NOT REGEXP BINARY '^%s')",
								$this->wpdb->dbname,
								$prefix_filter[0],
								$this->wpdb->dbname,
								$prefix_filter[1]
							);
						}
					} elseif ( $lower_case_table_names ) {
							$where_query[] = sprintf(
								"`Tables_in_%s` REGEXP '^%s'",
								$this->wpdb->dbname,
								$prefix_filter[0]
							);
					} else {
						$where_query[] = sprintf(
							"CAST(`Tables_in_%s` AS BINARY) REGEXP BINARY '^%s'",
							$this->wpdb->dbname,
							$prefix_filter[0]
						);
					}
				}
			} else {
				$where_query[] = 1;
			}

			$this->base_tables = array();

			// Loop over base tables
			$result = $this->query(
				sprintf(
					"SHOW FULL TABLES FROM `%s` WHERE `Table_type` = 'BASE TABLE' AND (%s)",
					$this->wpdb->dbname,
					implode( ' OR ', $where_query )
				)
			);
			// phpcs:ignore
			while ( $row = $this->fetch_row( $result ) ) {
				if ( isset( $row[0] ) ) {
					$this->base_tables[] = $row[0];
				}
			}

			// Close result cursor
			$this->free_result( $result );
		}

		return $this->base_tables;
	}

	/**
	 * Set tables
	 *
	 * @param  array $tables List of tables
	 * @return object
	 */
	public function set_tables( $tables ) {
		$this->tables = $tables;

		return $this;
	}

	/**
	 * Get tables
	 *
	 * @return array
	 */
	public function get_tables() {
		if ( is_null( $this->tables ) ) {
			return array_merge( $this->get_base_tables(), $this->get_views() );
		}

		return $this->tables;
	}

	/**
	 * Export database into a file
	 *
	 * @param  string  $file_name    File name
	 * @param  integer $query_offset Query offset
	 * @param  integer $table_index  Table index
	 * @param  integer $table_offset Table offset
	 * @param  integer $table_rows   Table rows
	 * @return boolean
	 */
	public function export( $file_name, &$query_offset = 0, &$table_index = 0, &$table_offset = 0, &$table_rows = 0 ) {
		// Set file handler
		$file_handler = nfd_sm_open( $file_name, 'cb' );

		// Start time
		$start = microtime( true );

		// Flag to hold if all tables have been processed
		$completed = true;

		// Set SQL mode
		$this->query( "SET SESSION sql_mode = ''" );

		// Get tables
		$tables = $this->get_tables();

		// Get views
		$views = $this->get_views();

		// Set file pointer at the query offset
		if ( fseek( $file_handler, $query_offset ) !== -1 ) {

			// Write headers
			if ( 0 === $query_offset ) {
				nfd_sm_write( $file_handler, $this->get_header() );
			}

			$tables_count = count( $tables );

			// Export tables
			while ( $table_index < $tables_count ) {

				// Get table name
				$table_name = $tables[ $table_index ];

				// Loop over tables and views
				if ( in_array( $table_name, $views, true ) ) {

					// Get create view statement
					if ( 0 === $table_offset ) {

						// Write view drop statement
						$drop_view = "\nDROP VIEW IF EXISTS `{$table_name}`;\n";

						// Write drop view statement
						nfd_sm_write( $file_handler, $drop_view );

						// Get create view statement
						$create_view = $this->get_create_view( $table_name );

						// Replace create view name
						$create_view = $this->replace_view_name( $create_view, $table_name, $table_name );

						// Replace create view options
						$create_view = $this->replace_view_options( $create_view );

						// Write create view statement
						nfd_sm_write( $file_handler, $create_view );

						// Write end of statement
						nfd_sm_write( $file_handler, ";\n\n" );
					}

					// Set curent table index
					++$table_index;

					// Set current table offset
					$table_offset = 0;

				} else {

					// Get create table statement
					if ( 0 === $table_offset ) {

						// Write table drop statement
						$drop_table = "\nDROP TABLE IF EXISTS `{$table_name}`;\n";

						// Write table statement
						nfd_sm_write( $file_handler, $drop_table );

						// Get create table statement
						$create_table = $this->get_create_table( $table_name );

						// Replace create table name
						$create_table = $this->replace_table_name( $create_table, $table_name, $table_name );

						// Replace create table options
						$create_table = $this->replace_table_options( $create_table );

						// Write create table statement
						nfd_sm_write( $file_handler, $create_table );

						// Write end of statement
						nfd_sm_write( $file_handler, ";\n\n" );
					}

					// Get primary keys
					$primary_keys = $this->get_primary_keys( $table_name );

					// Get column types
					$column_types = $this->get_column_types( $table_name );

					do {

						// Set query
						if ( $primary_keys ) {

							// Set table keys
							$table_keys = array();
							foreach ( $primary_keys as $key ) {
								$table_keys[] = sprintf( '`%s`', $key );
							}

							$table_keys = implode( ', ', $table_keys );

							// Set table where query
							$table_where = $this->get_table_where_query( $table_name );
							if ( ! ( $table_where ) ) {
								$table_where = 1;
							}

							// Set table select columns
							$select_columns = $this->get_table_select_columns( $table_name );
							if ( ! ( $select_columns ) ) {
								$select_columns = array( 't1.*' );
							}

							$select_columns = implode( ', ', $select_columns );

							// Set query with offset and rows count
							$query = sprintf( 'SELECT %s FROM `%s` AS t1 JOIN (SELECT %s FROM `%s` WHERE %s ORDER BY %s LIMIT %d, %d) AS t2 USING (%s)', $select_columns, $table_name, $table_keys, $table_name, $table_where, $table_keys, $table_offset, NFD_SM_SELECT_RECORDS, $table_keys );

						} else {

							$table_keys = 1;

							// Set table where query
							$table_where = $this->get_table_where_query( $table_name );
							if ( ! ( $table_where ) ) {
								$table_where = 1;
							}

							// Set table select columns
							$select_columns = $this->get_table_select_columns( $table_name );
							if ( ! ( $select_columns ) ) {
								$select_columns = array( '*' );
							}

							$select_columns = implode( ', ', $select_columns );

							// Set query with offset and rows count
							$query = sprintf( 'SELECT %s FROM `%s` WHERE %s ORDER BY %s LIMIT %d, %d', $select_columns, $table_name, $table_where, $table_keys, $table_offset, NFD_SM_SELECT_RECORDS );
						}

						// Run SQL query
						$result = $this->query( $query );

						// Repair table data
						if ( 1194 === $this->errno() ) {

							// Current table is marked as crashed and should be repaired
							$this->repair_table( $table_name );

							// Run SQL query
							$result = $this->query( $query );
						}

						// Generate insert statements
						$num_rows = $this->num_rows( $result );
						if ( $num_rows ) {

							// Loop over table rows
							$row = $this->fetch_assoc( $result );
							while ( $row ) {

								// Write start transaction
								if ( 0 === $table_offset % NFD_SM_MAX_TRANSACTION_QUERIES ) {
									nfd_sm_write( $file_handler, "START TRANSACTION;\n" );
								}

								$items = array();
								foreach ( $row as $key => $value ) {
									$items[] = $this->prepare_table_values( $value, $column_types[ strtolower( $key ) ] );
								}

								// Set table values
								$table_values = implode( ',', $items );

								// Set insert statement
								$table_insert = "INSERT INTO `{$table_name}` VALUES ({$table_values});\n";

								// Write insert statement
								nfd_sm_write( $file_handler, $table_insert );

								// Set current table offset
								++$table_offset;

								// Set current table rows
								++$table_rows;

								// Write end of transaction
								if ( 0 === $table_offset % NFD_SM_MAX_TRANSACTION_QUERIES ) {
									nfd_sm_write( $file_handler, "COMMIT;\n" );
								}

								$row = $this->fetch_assoc( $result );
							}
						} else {

							// Write end of transaction
							if ( 0 !== $table_offset % NFD_SM_MAX_TRANSACTION_QUERIES ) {
								nfd_sm_write( $file_handler, "COMMIT;\n" );
							}

							// Set curent table index
							++$table_index;

							// Set current table offset
							$table_offset = 0;
						}

						// Close result cursor
						$this->free_result( $result );

						// Time elapsed
						$timeout = apply_filters( 'nfd_sm_completed_timeout', 10 );
						if ( $timeout ) {
							if ( ( microtime( true ) - $start ) > $timeout ) {
								$completed = false;
								break 2;
							}
						}
					} while ( $num_rows > 0 );
				}
			}
		}

		// Restore the session settings the header changed, once there is nothing left to write.
		if ( $completed ) {
			nfd_sm_write( $file_handler, $this->get_footer() );
		}

		// Set query offset
		$query_offset = ftell( $file_handler );

		// Close file handler
		fclose( $file_handler );

		return $completed;
	}

	/**
	 * Load a dump produced by export() into tables under a staging prefix
	 *
	 * Nothing here writes to a live table. Every table identifier in the stream is rewritten
	 * from the source prefix to the staging prefix, so the destination site keeps serving from
	 * its own tables until the swap renames them in one instant.
	 *
	 * Resumable through $state['query_offset'], which only ever advances past a statement that
	 * has been executed and committed.
	 *
	 * @param  string $file_name File name
	 * @param  array  $state     Import state, modified in place
	 * @return boolean           True when the whole dump has been loaded
	 *
	 * @throws \Exception If a statement fails or the dump ends mid-transaction.
	 */
	public function import( $file_name, array &$state ) {
		$file_handler = nfd_sm_open( $file_name, 'r' );

		// Start time
		$start = microtime( true );

		// Flag to hold if the whole file has been read
		$completed = true;

		// Each step runs on a fresh MySQL session, so the SET statements the dump writes into
		// its own header only ever apply to whichever step happened to read them. A resumed
		// import starts past the header and would meet `DEFAULT '0000-00-00 00:00:00'` under
		// strict mode. Set the session up here instead, every time.
		$this->prepare_import_session();

		$max_packet = (int) $this->get_max_allowed_packet();

		// Rows are committed in batches by the dump. Breaking for time inside one of those
		// batches would roll the batch back while the offset had already moved past it, which
		// loses rows silently, so a break is only ever taken between transactions.
		$in_transaction = false;

		if ( fseek( $file_handler, (int) $state['query_offset'] ) !== -1 ) {

			$query = '';

			// The read stays in the condition. Hoisting it above the loop body means the next
			// line has already been consumed by the time a statement is executed, which moves
			// ftell() one line past it — and ftell() is the resume offset, so a resumed import
			// would skip a line of SQL.
			// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			while ( ( $line = fgets( $file_handler ) ) !== false ) {

				$query .= $line;

				if ( ! $this->is_complete_statement( $query ) ) {
					continue;
				}

				$statement = trim( $query );
				$query     = '';

				if ( '' === $statement ) {
					continue;
				}

				if ( $max_packet > 0 && strlen( $statement ) > $max_packet ) {
					throw new \Exception(
						sprintf(
							'A single row of the source database is %d bytes, which is larger than this '
							. "server's max_allowed_packet of %d bytes. Raise max_allowed_packet on the "
							. 'destination and run the import again.',
							// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- exception messages, not output.
							strlen( $statement ),
							$max_packet
							// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
						)
					);
				}

				if ( $this->is_start_transaction_query( $statement ) ) {
					$this->import_query( $statement );
					$in_transaction = true;
					continue;
				}

				if ( $this->is_commit_query( $statement ) ) {
					$this->import_query( $statement );
					$in_transaction        = false;
					$state['query_offset'] = ftell( $file_handler );
				} else {
					$this->import_statement( $statement, $state );

					if ( ! $in_transaction ) {
						$state['query_offset'] = ftell( $file_handler );
					}
				}

				// Time elapsed. An atomic statement, or a table declared atomic, carries on
				// regardless: stopping in the middle of one leaves work that cannot be resumed.
				if ( $in_transaction || $this->is_atomic_query( $statement ) ) {
					continue;
				}

				$timeout = apply_filters( 'nfd_sm_completed_timeout', 10 );

				if ( $timeout && ( microtime( true ) - $start ) > $timeout ) {
					$completed = false;
					break;
				}
			}
		}

		fclose( $file_handler );

		if ( $completed && $in_transaction ) {
			throw new \Exception( 'The database dump ends in the middle of a transaction, so it is incomplete.' );
		}

		return $completed;
	}

	/**
	 * Prepare the session for loading a dump
	 *
	 * @return void
	 */
	protected function prepare_import_session() {
		// WordPress schemas are full of `DEFAULT '0000-00-00 00:00:00'`, which strict mode
		// rejects outright, and tables arrive in alphabetical rather than dependency order.
		$this->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO,ALLOW_INVALID_DATES'" );
		$this->query( 'SET SESSION foreign_key_checks = 0' );
		$this->query( 'SET SESSION unique_checks = 0' );
	}

	/**
	 * Run one statement, treating any error as fatal
	 *
	 * query() is deliberately forgiving because an export can survive a missing row. An import
	 * cannot: a CREATE TABLE that quietly failed produces a half-populated database that looks
	 * finished. Every failure has to stop the run.
	 *
	 * @param  string $input SQL statement
	 * @return void
	 *
	 * @throws \Exception If the statement fails.
	 */
	protected function import_query( $input ) {
		$this->query( $input );

		if ( $this->errno() ) {
			throw new \Exception(
				sprintf(
					'Database error %d while importing: %s (statement began: %s)',
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- exception messages, not output.
					$this->errno(),
					$this->error(),
					substr( $input, 0, 200 )
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				)
			);
		}
	}

	/**
	 * Route one statement from the dump
	 *
	 * Table identifiers are rewritten only where the grammar puts them, never by searching the
	 * whole statement. A post whose content quotes `wp_posts` in a code block would otherwise
	 * have its own text rewritten along with the identifier.
	 *
	 * @param  string $statement SQL statement
	 * @param  array  $state     Import state, modified in place
	 * @return void
	 */
	protected function import_statement( $statement, array &$state ) {
		// Session settings are re-applied per step by prepare_import_session(). Running the
		// dump's own copies would restore strict mode from a user variable that a resumed
		// step never set, which evaluates to NULL and fails.
		if ( stripos( $statement, 'SET ' ) === 0 ) {
			return;
		}

		// A view body names the tables it selects from by their live identifiers, which do not
		// exist under those names until the swap. Capture the definition and create it after.
		if ( preg_match( '/^DROP\s+VIEW\s+IF\s+EXISTS\s+`([^`]+)`/i', $statement ) ) {
			return;
		}

		if ( preg_match( '/^CREATE\s+VIEW\s+`([^`]+)`/i', $statement, $matches ) ) {
			$state['views'][ $matches[1] ] = $statement;

			return;
		}

		if ( preg_match( '/^DROP\s+TABLE\s+IF\s+EXISTS\s+`([^`]+)`/i', $statement, $matches ) ) {
			$staged = $this->stage_table_name( $matches[1], $state );

			$this->import_query( $this->replace_table_name( $statement, $matches[1], $staged ) );

			return;
		}

		if ( $this->is_create_table_query( $statement ) && preg_match( '/^CREATE\s+TABLE\s+`([^`]+)`/i', $statement, $matches ) ) {
			$staged = $this->stage_table_name( $matches[1], $state );

			$sql = $this->replace_table_name( $statement, $matches[1], $staged );
			$sql = $this->replace_table_collations( $sql );

			$this->import_query( $sql );

			$state['tables'][ $matches[1] ] = $staged;

			return;
		}

		if ( preg_match( '/^INSERT\s+INTO\s+`([^`]+)`/i', $statement, $matches ) ) {
			if ( $this->is_skippable_row( $statement, $matches[1], $state ) ) {
				return;
			}

			$staged = $this->stage_table_name( $matches[1], $state );

			$this->import_query( $this->replace_table_name( $statement, $matches[1], $staged ) );

			++$state['statements'];

			return;
		}

		$this->import_query( $statement );
	}

	/**
	 * The staging name for a table named in the dump
	 *
	 * @param  string $table_name Table name as the source wrote it
	 * @param  array  $state      Import state
	 * @return string
	 */
	protected function stage_table_name( $table_name, array $state ) {
		$source = isset( $state['source_prefix'] ) ? $state['source_prefix'] : '';
		$stage  = isset( $state['stage_prefix'] ) ? $state['stage_prefix'] : '';

		if ( '' !== $source && strpos( $table_name, $source ) === 0 ) {
			return $stage . substr( $table_name, strlen( $source ) );
		}

		return $stage . $table_name;
	}

	/**
	 * Whether a row is cache rather than content, and can be dropped on the way in
	 *
	 * Restricted to the tables that actually hold cache rows. Applied to every INSERT it would
	 * also drop a post whose body happens to contain the string `'_transient_`.
	 *
	 * @param  string $statement  SQL statement
	 * @param  string $table_name Table the row belongs to
	 * @param  array  $state      Import state
	 * @return boolean
	 */
	protected function is_skippable_row( $statement, $table_name, array $state ) {
		$source = isset( $state['source_prefix'] ) ? $state['source_prefix'] : '';

		$cache_tables = array(
			$source . 'options',
			$source . 'sitemeta',
			$source . 'woocommerce_sessions',
		);

		if ( ! in_array( strtolower( $table_name ), $cache_tables, true ) ) {
			return false;
		}

		return $this->is_cache_query( $statement );
	}

	/**
	 * Whether an accumulated buffer holds a whole statement
	 *
	 * Values are escaped on the way out, so a real newline never survives inside a quoted
	 * string and a line ending in a semicolon is a statement boundary. The quote count is
	 * checked anyway: getting this wrong truncates SQL silently, which is the one failure mode
	 * an importer must never have.
	 *
	 * @param  string $buffer Accumulated lines
	 * @return boolean
	 */
	protected function is_complete_statement( $buffer ) {
		$trimmed = rtrim( $buffer );

		if ( '' === $trimmed || substr( $trimmed, -1 ) !== ';' ) {
			return false;
		}

		$stripped = str_replace( array( '\\\\', "\\'" ), '', $trimmed );

		return 0 === substr_count( $stripped, "'" ) % 2;
	}

	/**
	 * Get MySQL version
	 *
	 * @return string
	 */
	protected function get_version() {
		$result = $this->query( "SHOW VARIABLES LIKE 'version'" );
		$row    = $this->fetch_assoc( $result );

		// Close result cursor
		$this->free_result( $result );

		// Get version
		if ( isset( $row['Value'] ) ) {
			return $row['Value'];
		}
	}

	/**
	 * Get MySQL max allowed packet
	 *
	 * @return integer
	 */
	protected function get_max_allowed_packet() {
		$result = $this->query( "SHOW VARIABLES LIKE 'max_allowed_packet'" );
		$row    = $this->fetch_assoc( $result );

		// Close result cursor
		$this->free_result( $result );

		// Get max allowed packet
		if ( isset( $row['Value'] ) ) {
			return $row['Value'];
		}
	}

	/**
	 * Get MySQL lower case table names
	 *
	 * @return integer
	 */
	protected function get_lower_case_table_names() {
		$result = $this->query( "SHOW VARIABLES LIKE 'lower_case_table_names'" );
		$row    = $this->fetch_assoc( $result );

		// Close result cursor
		$this->free_result( $result );

		// Get lower case table names
		if ( isset( $row['Value'] ) ) {
			return $row['Value'];
		}
	}

	/**
	 * Get MySQL collation name
	 *
	 * @param  string $collation_name Collation name
	 * @return string
	 */
	protected function get_collation( $collation_name ) {
		$result = $this->query( "SHOW COLLATION LIKE '{$collation_name}'" );
		$row    = $this->fetch_assoc( $result );

		// Close result cursor
		$this->free_result( $result );

		// Get collation name
		if ( isset( $row['Collation'] ) ) {
			return $row['Collation'];
		}
	}

	/**
	 * Get MySQL create view
	 *
	 * @param  string $view_name View name
	 * @return string
	 */
	protected function get_create_view( $view_name ) {
		$result = $this->query( "SHOW CREATE VIEW `{$view_name}`" );
		$row    = $this->fetch_assoc( $result );

		// Close result cursor
		$this->free_result( $result );

		// Get create view
		if ( isset( $row['Create View'] ) ) {
			return $row['Create View'];
		}
	}

	/**
	 * Get MySQL create table
	 *
	 * @param  string $table_name Table name
	 * @return string
	 */
	protected function get_create_table( $table_name ) {
		$result = $this->query( "SHOW CREATE TABLE `{$table_name}`" );
		$row    = $this->fetch_assoc( $result );

		// Close result cursor
		$this->free_result( $result );

		// Get create table
		if ( isset( $row['Create Table'] ) ) {
			return $row['Create Table'];
		}
	}

	/**
	 * Repair MySQL table
	 *
	 * @param  string $table_name Table name
	 * @return void
	 */
	protected function repair_table( $table_name ) {
		$this->query( "REPAIR TABLE `{$table_name}`" );
	}

	/**
	 * Get MySQL primary keys
	 *
	 * @param  string $table_name Table name
	 * @return array
	 */
	protected function get_primary_keys( $table_name ) {
		$primary_keys = array();

		// Get primary keys
		$result = $this->query( "SHOW KEYS FROM `{$table_name}` WHERE `Key_name` = 'PRIMARY'" );
		$row    = $this->fetch_assoc( $result );
		while ( $row ) {
			if ( isset( $row['Column_name'] ) ) {
				$primary_keys[] = $row['Column_name'];
			}
			$row = $this->fetch_assoc( $result );
		}

		// Close result cursor
		$this->free_result( $result );

		return $primary_keys;
	}

	/**
	 * Get MySQL unique keys
	 *
	 * @param  string $table_name Table name
	 * @return array
	 */
	protected function get_unique_keys( $table_name ) {
		$unique_keys = array();

		// Get unique keys
		$result = $this->query( "SHOW KEYS FROM `{$table_name}` WHERE `Non_unique` = 0" );
		$row    = $this->fetch_assoc( $result );
		while ( $row ) {
			if ( isset( $row['Column_name'] ) ) {
				$unique_keys[] = $row['Column_name'];
			}
			$row = $this->fetch_assoc( $result );
		}

		// Close result cursor
		$this->free_result( $result );

		return $unique_keys;
	}

	/**
	 * Get MySQL column types
	 *
	 * @param  string $table_name Table name
	 * @return array
	 */
	protected function get_column_types( $table_name ) {
		$column_types = array();

		// Get column types
		$result = $this->query( "SHOW COLUMNS FROM `{$table_name}`" );
		$row    = $this->fetch_assoc( $result );
		while ( $row ) {
			if ( isset( $row['Field'] ) ) {
				$column_types[ strtolower( $row['Field'] ) ] = $row['Type'];
			}
			$row = $this->fetch_assoc( $result );
		}

		// Close result cursor
		$this->free_result( $result );

		return $column_types;
	}

	/**
	 * Get MySQL column names
	 *
	 * @param  string $table_name Table name
	 * @return array
	 */
	public function get_column_names( $table_name ) {
		$column_names = array();

		// Get column types
		$result = $this->query( "SHOW COLUMNS FROM `{$table_name}`" );
		$row    = $this->fetch_assoc( $result );
		while ( $row ) {
			if ( isset( $row['Field'] ) ) {
				$column_names[ strtolower( $row['Field'] ) ] = $row['Field'];
			}
			$row = $this->fetch_assoc( $result );
		}

		// Close result cursor
		$this->free_result( $result );

		return $column_names;
	}

	/**
	 * Replace table name
	 *
	 * @param  string $input          Table value
	 * @param  string $old_table_name Old table name
	 * @param  string $new_table_name New table name
	 * @return string
	 */
	protected function replace_table_name( $input, $old_table_name, $new_table_name ) {
		$position = stripos( $input, "`$old_table_name`" );
		if ( false !== $position ) {
			$input = substr_replace( $input, "`$new_table_name`", $position, strlen( "`$old_table_name`" ) );
		}

		return $input;
	}

	/**
	 * Replace view name
	 *
	 * @param  string $input         View value
	 * @param  string $old_view_name Old view name
	 * @param  string $new_view_name New view name
	 * @return string
	 */
	protected function replace_view_name( $input, $old_view_name, $new_view_name ) {
		$position = stripos( $input, "`$old_view_name`" );
		if ( false !== $position ) {
			$input = substr_replace( $input, "`$new_view_name`", $position, strlen( "`$old_view_name`" ) );
		}

		return $input;
	}

	/**
	 * Replace view options
	 *
	 * @param  string $input Table value
	 * @return string
	 */
	protected function replace_view_options( $input ) {
		return preg_replace( '/CREATE(.+?)VIEW/i', 'CREATE VIEW', $input );
	}

	/**
	 * Replace table values
	 *
	 * @param  string $input Table value
	 * @return string
	 */
	protected function replace_table_values( $input ) {
		// Replace base64 encoded values (Visual Composer)
		if ( $this->get_visual_composer() ) {
			$input = preg_replace_callback( '/\[vc_raw_html\]([a-zA-Z0-9\/+]+={0,2})\[\/vc_raw_html\]/S', array( $this, 'replace_visual_composer_values_callback' ), $input );
		}

		// Replace base64 encoded values (Oxygen Builder)
		if ( $this->get_oxygen_builder() ) {
			$input = preg_replace_callback( '/\\\\"(code-php|code-css|code-js)\\\\":\\\\"([a-zA-Z0-9\/+]+={0,2})\\\\"/S', array( $this, 'replace_oxygen_builder_values_callback' ), $input );
		}

		// Replace base64 encoded values (BeTheme Responsive, Optimize Press and Avada Fusion Builder)
		if ( $this->get_betheme_responsive() || $this->get_optimize_press() || $this->get_avada_fusion_builder() ) {
			$input = preg_replace_callback( "/'([a-zA-Z0-9\/+]+={0,2})'/S", array( $this, 'replace_base64_values_callback' ), $input );
		}

		return $input;
	}

	/**
	 * Replace base64 values callback (Visual Composer)
	 *
	 * @param  array $matches List of matches
	 * @return string
	 */
	protected function replace_visual_composer_values_callback( $matches ) {
		// Validate base64 data
		if ( DatabaseUtility::base64_validate( $matches[1] ) ) {

			// Decode base64 characters
			$matches[1] = DatabaseUtility::base64_decode( $matches[1] );

			// Encode base64 characters
			$matches[1] = DatabaseUtility::base64_encode( $matches[1] );
		}

		return '[vc_raw_html]' . $matches[1] . '[/vc_raw_html]';
	}

	/**
	 * Replace base64 values callback (Oxygen Builder)
	 *
	 * @param  array $matches List of matches
	 * @return string
	 */
	protected function replace_oxygen_builder_values_callback( $matches ) {
		// Validate base64 data
		if ( DatabaseUtility::base64_validate( $matches[2] ) ) {

			// Decode base64 characters
			$matches[2] = DatabaseUtility::base64_decode( $matches[2] );

			// Encode base64 characters
			$matches[2] = DatabaseUtility::base64_encode( $matches[2] );
		}

		return '\"' . $matches[1] . '\":\"' . $matches[2] . '\"';
	}

	/**
	 * Replace base64 values callback (BeTheme Responsive and Optimize Press)
	 *
	 * @param  array $matches List of matches
	 * @return string
	 */
	protected function replace_base64_values_callback( $matches ) {
		// Validate base64 data
		if ( DatabaseUtility::base64_validate( $matches[1] ) ) {

			// Decode base64 characters
			$matches[1] = DatabaseUtility::base64_decode( $matches[1] );

			// Encode base64 characters
			$matches[1] = DatabaseUtility::base64_encode( $matches[1] );
		}

		return "'" . $matches[1] . "'";
	}

	/**
	 * Replace table collations
	 *
	 * @param  string $input SQL statement
	 * @return string
	 */
	protected function replace_table_collations( $input ) {
		if ( null === $this->collation_map ) {
			$this->collation_map = $this->build_collation_map();
		}

		if ( empty( $this->collation_map ) ) {
			return $input;
		}

		$output = str_replace( array_keys( $this->collation_map ), array_values( $this->collation_map ), $input );

		if ( $output !== $input ) {
			foreach ( $this->collation_map as $from => $to ) {
				if ( strpos( $input, $from ) !== false ) {
					$this->collations_replaced[ $from ] = $to;
				}
			}
		}

		return $output;
	}

	/**
	 * Build the collation rewrite map for this server
	 *
	 * The cache is per instance rather than a static inside the method: two instances can be
	 * pointed at two different servers, and a process-wide cache hands the second one the
	 * first one's answer.
	 *
	 * @return array Map of collation or charset found in the dump, to what this server accepts.
	 */
	protected function build_collation_map() {
		$map = array();

		// MariaDB 10.10+ writes collations that no MySQL server understands, and neither do
		// older MariaDB releases. They are absent from MySQL's SHOW COLLATION entirely.
		$uca1400 = $this->get_supported_collations( 'uca1400' );

		if ( empty( $uca1400 ) ) {
			$map['utf8mb4_uca1400_ai_ci']       = 'utf8mb4_unicode_ci';
			$map['utf8mb4_uca1400_as_cs']       = 'utf8mb4_unicode_ci';
			$map['utf8mb3_uca1400_ai_ci']       = 'utf8_unicode_ci';
			$map['utf8mb4_uca1400_nopad_ai_ci'] = 'utf8mb4_unicode_ci';
		}

		if ( ! $this->wpdb->has_cap( 'utf8mb4' ) ) {
			// The only genuinely lossy rewrite in this map: four-byte characters, which is to
			// say emoji and most of CJK extension B, do not survive the narrower charset. The
			// importer reports it rather than performing it quietly.
			$map['utf8mb4_0900_ai_ci']     = 'utf8_unicode_ci';
			$map['utf8mb4_unicode_520_ci'] = 'utf8_unicode_ci';
			$map['utf8mb4_unicode_ci']     = 'utf8_unicode_ci';
			$map['utf8mb4_general_ci']     = 'utf8_general_ci';
			$map['utf8mb4']                = 'utf8';

			return $map;
		}

		if ( ! $this->wpdb->has_cap( 'utf8mb4_520' ) ) {
			$map['utf8mb4_0900_ai_ci']     = 'utf8mb4_unicode_ci';
			$map['utf8mb4_unicode_520_ci'] = 'utf8mb4_unicode_ci';

			return $map;
		}

		// MySQL 8's default collation is unknown to 5.7 and to every MariaDB.
		if ( ! $this->get_collation( 'utf8mb4_0900_ai_ci' ) ) {
			$map['utf8mb4_0900_ai_ci'] = 'utf8mb4_unicode_520_ci';
		}

		return $map;
	}

	/**
	 * Collations this server knows whose name contains the given fragment
	 *
	 * @param  string $fragment Substring to look for.
	 * @return array
	 */
	protected function get_supported_collations( $fragment ) {
		$found  = array();
		$result = $this->query( sprintf( "SHOW COLLATION LIKE '%%%s%%'", $this->escape( $fragment ) ) );

		if ( $result ) {
			$row = $this->fetch_assoc( $result );

			while ( $row ) {
				if ( isset( $row['Collation'] ) ) {
					$found[] = $row['Collation'];
				}

				$row = $this->fetch_assoc( $result );
			}

			$this->free_result( $result );
		}

		return $found;
	}

	/**
	 * Which collations were rewritten, and to what
	 *
	 * @return array
	 */
	public function get_collations_replaced() {
		return $this->collations_replaced;
	}

	/**
	 * Whether any rewrite performed so far loses characters
	 *
	 * @return boolean
	 */
	public function has_lossy_collation_change() {
		foreach ( $this->collations_replaced as $from => $to ) {
			if ( strpos( $from, 'utf8mb4' ) === 0 && strpos( $to, 'utf8mb4' ) !== 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether input is transient query
	 *
	 * @param  string $input SQL statement
	 * @return boolean
	 */
	protected function is_transient_query( $input ) {
		return strpos( $input, "'_transient_" ) !== false;
	}

	/**
	 * Check whether input is site transient query
	 *
	 * @param  string $input SQL statement
	 * @return boolean
	 */
	protected function is_site_transient_query( $input ) {
		return strpos( $input, "'_site_transient_" ) !== false;
	}

	/**
	 * Check whether input is WooCommerce session query
	 *
	 * @param  string $input SQL statement
	 * @return boolean
	 */
	protected function is_wc_session_query( $input ) {
		return strpos( $input, "'_wc_session_" ) !== false;
	}

	/**
	 * Check whether input is START TRANSACTION query
	 *
	 * @param  string $input SQL statement
	 * @return boolean
	 */
	protected function is_start_transaction_query( $input ) {
		return strpos( $input, 'START TRANSACTION' ) === 0;
	}

	/**
	 * Check whether input is COMMIT query
	 *
	 * @param  string $input SQL statement
	 * @return boolean
	 */
	protected function is_commit_query( $input ) {
		return strpos( $input, 'COMMIT' ) === 0;
	}

	/**
	 * Check whether input is DROP TABLE query
	 *
	 * @param  string $input SQL statement
	 * @return boolean
	 */
	protected function is_drop_table_query( $input ) {
		return strpos( $input, 'DROP TABLE' ) === 0;
	}

	/**
	 * Check whether input is CREATE TABLE query
	 *
	 * @param  string $input SQL statement
	 * @return boolean
	 */
	protected function is_create_table_query( $input ) {
		return strpos( $input, 'CREATE TABLE' ) === 0;
	}

	/**
	 * Check whether input is INSERT INTO query
	 *
	 * @param  string $input      SQL statement
	 * @param  string $table_name Table name (case insensitive)
	 * @return boolean
	 */
	protected function is_insert_into_query( $input, $table_name ) {
		return stripos( $input, sprintf( 'INSERT INTO `%s`', $table_name ) ) === 0;
	}

	/**
	 * Check whether input is cache query
	 *
	 * @param  string $input SQL statement
	 * @return boolean
	 */
	public function is_cache_query( $input ) {
		$cache = false;

		// Skip cache based on table query
		switch ( true ) {
			case $this->is_transient_query( $input ):
			case $this->is_site_transient_query( $input ):
			case $this->is_wc_session_query( $input ):
				$cache = true;
				break;
		}

		return $cache;
	}

	/**
	 * Check whether input is atomic query
	 *
	 * @param  string $input SQL statement
	 * @return boolean
	 */
	protected function is_atomic_query( $input ) {
		$atomic = false;

		// Skip timeout based on table query
		switch ( true ) {
			case $this->is_drop_table_query( $input ):
			case $this->is_create_table_query( $input ):
			case $this->is_start_transaction_query( $input ):
			case $this->is_commit_query( $input ):
				$atomic = true;
				break;

			default:
				// Skip timeout based on table query and table name
				foreach ( $this->get_atomic_tables() as $table_name ) {
					if ( $this->is_insert_into_query( $input, $table_name ) ) {
						$atomic = true;
						break;
					}
				}
		}

		return $atomic;
	}

	/**
	 * Replace table options
	 *
	 * @param  string $input SQL statement
	 * @return string
	 */
	protected function replace_table_options( $input ) {
		$search  = array(
			'TYPE=InnoDB',
			'TYPE=MyISAM',
			'ENGINE=Aria',
			'TRANSACTIONAL=0',
			'TRANSACTIONAL=1',
			'PAGE_CHECKSUM=0',
			'PAGE_CHECKSUM=1',
			'TABLE_CHECKSUM=0',
			'TABLE_CHECKSUM=1',
			'ROW_FORMAT=PAGE',
			'ROW_FORMAT=FIXED',
			'ROW_FORMAT=DYNAMIC',
		);
		$replace = array(
			'ENGINE=InnoDB',
			'ENGINE=MyISAM',
			'ENGINE=MyISAM',
			'',
			'',
			'',
			'',
			'',
			'',
			'',
			'',
			'',
		);

		return str_ireplace( $search, $replace, $input );
	}

	/**
	 * Replace table engines
	 *
	 * @param  string $input SQL statement
	 * @return string
	 */
	protected function replace_table_engines( $input ) {
		$search  = array(
			'ENGINE=MyISAM',
			'ENGINE=Aria',
		);
		$replace = array(
			'ENGINE=InnoDB',
			'ENGINE=InnoDB',
		);

		return str_ireplace( $search, $replace, $input );
	}

	/**
	 * Replace table row format
	 *
	 * @param  string $input SQL statement
	 * @return string
	 */
	protected function replace_table_row_format( $input ) {
		$search  = array(
			'ENGINE=InnoDB',
			'ENGINE=MyISAM',
		);
		$replace = array(
			'ENGINE=InnoDB ROW_FORMAT=DYNAMIC',
			'ENGINE=MyISAM ROW_FORMAT=DYNAMIC',
		);

		return str_ireplace( $search, $replace, $input );
	}
	/**
	 * Replace table full-text indexes (MySQL <= 5.5)
	 *
	 * @param  string $input SQL statement
	 * @return string
	 */
	protected function replace_table_fulltext_indexes( $input ) {
		$pattern = array(
			'/\s+FULLTEXT KEY(.+),/i',
			'/,\s+FULLTEXT KEY(.+)/i',
		);

		return preg_replace( $pattern, '', $input );
	}

	/**
	 * Returns header for dump file
	 *
	 * @return string
	 */
	protected function get_header() {
		// Some info about software, source and time
		$header = sprintf(
			"-- Site Migrator SQL Dump\n" .
			"--\n" .
			"-- Host: %s\n" .
			"-- Database: %s\n" .
			"-- Class: %s\n" .
			"--\n\n",
			$this->wpdb->dbhost,
			$this->wpdb->dbname,
			get_class( $this )
		);

		// The importing server's session has to be prepared before any DDL runs, or the dump
		// is not loadable on a default-configured MySQL.
		//
		// WordPress schemas are full of `DEFAULT '0000-00-00 00:00:00'`, which strict mode
		// (NO_ZERO_DATE, on by default since MySQL 5.7) rejects outright with "Invalid default
		// value". Tables are also written in alphabetical order, not dependency order, so a
		// plugin's foreign key can reference a table that does not exist yet.
		$header .= "SET @NFD_SM_SQL_MODE=@@SQL_MODE;\n";
		$header .= "SET @NFD_SM_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS;\n";
		$header .= "SET @NFD_SM_UNIQUE_CHECKS=@@UNIQUE_CHECKS;\n";
		$header .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO,ALLOW_INVALID_DATES';\n";
		$header .= "SET FOREIGN_KEY_CHECKS=0;\n";
		$header .= "SET UNIQUE_CHECKS=0;\n\n";

		return $header;
	}

	/**
	 * Returns footer for dump file
	 *
	 * Restores everything the header changed. Written only when the export completes, so a
	 * resumed dump does not get a footer in the middle of it.
	 *
	 * @return string
	 */
	protected function get_footer() {
		return "\nSET FOREIGN_KEY_CHECKS=@NFD_SM_FOREIGN_KEY_CHECKS;\n"
			. "SET UNIQUE_CHECKS=@NFD_SM_UNIQUE_CHECKS;\n"
			. "SET SQL_MODE=@NFD_SM_SQL_MODE;\n"
			. "-- Dump complete.\n";
	}

	/**
	 * Prepare table values
	 *
	 * @param  string  $input       Table value
	 * @param  integer $column_type Column type
	 * @return string
	 */
	protected function prepare_table_values( $input, $column_type ) {
		switch ( true ) {
			case is_null( $input ):
				return 'NULL';

			case stripos( $column_type, 'tinyint' ) === 0:
			case stripos( $column_type, 'smallint' ) === 0:
			case stripos( $column_type, 'mediumint' ) === 0:
			case stripos( $column_type, 'int' ) === 0:
			case stripos( $column_type, 'bigint' ) === 0:
			case stripos( $column_type, 'float' ) === 0:
			case stripos( $column_type, 'double' ) === 0:
			case stripos( $column_type, 'decimal' ) === 0:
			case stripos( $column_type, 'bit' ) === 0:
				return $input;

			case stripos( $column_type, 'binary' ) === 0:
			case stripos( $column_type, 'varbinary' ) === 0:
			case stripos( $column_type, 'tinyblob' ) === 0:
			case stripos( $column_type, 'mediumblob' ) === 0:
			case stripos( $column_type, 'longblob' ) === 0:
			case stripos( $column_type, 'blob' ) === 0:
				return '0x' . bin2hex( $input );

			default:
				return "'" . $this->escape( $input ) . "'";
		}
	}

	/**
	 * Run MySQL query
	 *
	 * @param  string $input SQL query
	 * @return resource
	 */
	abstract public function query( $input );

	/**
	 * Escape string input for mysql query
	 *
	 * @param  string $input String to escape
	 * @return string
	 */
	abstract public function escape( $input );

	/**
	 * Return the error code for the most recent function call
	 *
	 * @return integer
	 */
	abstract public function errno();

	/**
	 * Return a string description of the last error
	 *
	 * @return string
	 */
	abstract public function error();

	/**
	 * Return server version
	 *
	 * @return string
	 */
	abstract public function version();

	/**
	 * Return the result from MySQL query as associative array
	 *
	 * @param  resource $result MySQL resource
	 * @return array
	 */
	abstract public function fetch_assoc( $result );

	/**
	 * Return the result from MySQL query as row
	 *
	 * @param  resource $result MySQL resource
	 * @return array
	 */
	abstract public function fetch_row( $result );

	/**
	 * Return the number for rows from MySQL results
	 *
	 * @param  resource $result MySQL resource
	 * @return integer
	 */
	abstract public function num_rows( $result );

	/**
	 * Free MySQL result memory
	 *
	 * @param  resource $result MySQL resource
	 * @return boolean
	 */
	abstract public function free_result( $result );
}
