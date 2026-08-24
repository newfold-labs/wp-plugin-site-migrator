<?php
/**
 * Reconciles the destination's accounts with the source's.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

/**
 * The one table that is merged rather than replaced.
 *
 * Every other table is overwritten wholesale, because merging rows joined by integer primary
 * keys means either collapsing two different people into one ID or renumbering an ID that is
 * referenced from places no schema declares — inside serialized options, inside JSON in
 * postmeta, inside page-builder blobs.
 *
 * That argument does not apply here, and the reason is structural rather than a special case:
 * this merge goes one way, into an ID space nothing else references. The destination's posts,
 * comments and meta are being discarded, so destination user IDs have almost no referents left.
 * Source user IDs, which have referents everywhere, never move. The complete list of columns
 * needing a rewrite is `usermeta.user_id`, for destination-origin rows only.
 *
 * Everything happens on the staged tables, before the swap.
 */
class UserMerger {

	const MODE_MERGE   = 'merge';
	const MODE_REPLACE = 'replace';

	/**
	 * Usermeta keys that embed the table prefix rather than a user ID.
	 *
	 * Miss one of these and every user on the site is silently a subscriber, which is the
	 * classic symptom of a hand-rolled migration.
	 *
	 * @var array
	 */
	protected static $prefixed_meta = array(
		'capabilities',
		'user_level',
		'user-settings',
		'user-settings-time',
		'dashboard_quick_press_last_post_id',
	);

	/**
	 * Staged table prefix.
	 *
	 * @var string
	 */
	protected $stage;

	/**
	 * The destination's live table prefix, which is also the final prefix.
	 *
	 * @var string
	 */
	protected $live;

	/**
	 * The source's table prefix, as recorded in the dump.
	 *
	 * @var string
	 */
	protected $source;

	/**
	 * Merge or replace.
	 *
	 * @var string
	 */
	protected $mode;

	/**
	 * Destination user ID whose administrator access must survive.
	 *
	 * @var int
	 */
	protected $acting;

	/**
	 * Role slug given to a destination user whose role the source does not define.
	 *
	 * @var string
	 */
	protected $fallback_role = 'subscriber';

	/**
	 * What happened, for the completion report.
	 *
	 * @var array
	 */
	protected $report = array(
		'matched'       => array(),
		'carried'       => array(),
		'renamed'       => array(),
		'demoted'       => array(),
		'login_changes' => array(),
	);

	/**
	 * Constructor.
	 *
	 * @param string $stage  Staged table prefix.
	 * @param string $live   Destination's live table prefix.
	 * @param string $source Source's table prefix.
	 * @param int    $acting Destination user ID to guarantee administrator access for.
	 * @param string $mode   Merge or replace.
	 */
	public function __construct( $stage, $live, $source, $acting = 0, $mode = self::MODE_MERGE ) {
		$this->stage  = $stage;
		$this->live   = $live;
		$this->source = $source;
		$this->acting = (int) $acting;
		$this->mode   = self::MODE_REPLACE === $mode ? self::MODE_REPLACE : self::MODE_MERGE;
	}

	/**
	 * Reconcile the staged users table.
	 *
	 * Not resumable, and deliberately so. It touches a few thousand rows at most on any real
	 * site, and a half-applied merge is not a state worth being able to resume into.
	 *
	 * @return array The report.
	 *
	 * @throws \RuntimeException If the result would contain duplicate logins or emails.
	 */
	public function run() {
		$this->rewrite_prefixed_meta();
		$this->rewrite_user_roles_option();

		if ( self::MODE_MERGE === $this->mode ) {
			$this->merge_destination_users();
		} else {
			$this->carry_acting_user();
		}

		$this->guarantee_acting_administrator();
		$this->demote_undefined_roles();
		$this->assert_unique();

		return $this->report;
	}

	/**
	 * Rewrite the prefix-bearing usermeta keys to the destination's prefix.
	 *
	 * @return void
	 */
	protected function rewrite_prefixed_meta() {
		global $wpdb;

		if ( $this->source === $this->live ) {
			return;
		}

		foreach ( self::$prefixed_meta as $suffix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$this->stage . 'usermeta',
				array( 'meta_key' => $this->live . $suffix ),
				array( 'meta_key' => $this->source . $suffix )
			);
		}
	}

	/**
	 * Rewrite the `{prefix}user_roles` option to the destination's prefix.
	 *
	 * The companion to the meta keys above: the capabilities meta names a role, and this option
	 * is what turns that name into capabilities.
	 *
	 * @return void
	 */
	protected function rewrite_user_roles_option() {
		global $wpdb;

		if ( $this->source === $this->live ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$this->stage . 'options',
			array( 'option_name' => $this->live . 'user_roles' ),
			array( 'option_name' => $this->source . 'user_roles' )
		);
	}

	/**
	 * Bring the destination's own accounts into the staged users table.
	 *
	 * @return void
	 */
	protected function merge_destination_users() {
		$source_users = $this->staged_users();
		$dest_users   = $this->live_users();

		$by_email = array();
		$by_login = array();

		foreach ( $source_users as $user ) {
			$by_email[ \strtolower( $user['user_email'] ) ] = $user;
			$by_login[ \strtolower( $user['user_login'] ) ] = $user;
		}

		$claimed = array();
		$matches = array();
		$carried = array();

		// The email address is the identity. Two accounts sharing one is one person; two
		// accounts sharing a username is `admin`, which exists on almost every WordPress site
		// and belongs to a different person on each of them. Merging those two collapses two
		// people into one account and throws one of their email addresses away, and there is
		// no undoing it afterwards — whereas leaving two accounts is something a human can
		// reconcile in a minute. So a shared username with different emails is treated as the
		// collision it usually is, not as a match.
		foreach ( $dest_users as $dest ) {
			$key = \strtolower( $dest['user_email'] );

			if ( isset( $by_email[ $key ] ) && ! isset( $claimed[ $by_email[ $key ]['ID'] ] ) ) {
				$matches[ $dest['ID'] ]             = $by_email[ $key ];
				$claimed[ $by_email[ $key ]['ID'] ] = true;
			}
		}

		/**
		 * Filter whether a shared username counts as the same person when the emails differ.
		 *
		 * Off by default. Worth turning on only when you know both sites belong to the same
		 * group of people and somebody has changed their email address on one of them.
		 *
		 * @param bool $match_by_login Whether to match on user_login.
		 */
		$match_by_login = (bool) \apply_filters( 'nfd_sm_match_users_by_login', false );

		foreach ( $dest_users as $dest ) {
			if ( isset( $matches[ $dest['ID'] ] ) ) {
				continue;
			}

			$key = \strtolower( $dest['user_login'] );

			if ( $match_by_login && isset( $by_login[ $key ] ) && ! isset( $claimed[ $by_login[ $key ]['ID'] ] ) ) {
				$matches[ $dest['ID'] ]             = $by_login[ $key ];
				$claimed[ $by_login[ $key ]['ID'] ] = true;

				continue;
			}

			$carried[] = $dest;
		}

		foreach ( $matches as $dest_id => $source_user ) {
			$this->apply_match( $dest_users[ $dest_id ], $source_user );
		}

		$next_id = $this->next_free_id( $source_users, $dest_users );

		foreach ( $carried as $dest ) {
			$this->carry_user( $dest, $next_id );
			++$next_id;
		}
	}

	/**
	 * Reconcile one destination account with its source counterpart.
	 *
	 * The source row keeps its ID, login and nicename, because the migrated content is already
	 * attributed to that ID and `/author/{nicename}/` is linked from it and indexed. The
	 * destination keeps the credentials, because those are what the person used ten minutes ago
	 * to start this migration.
	 *
	 * @param array $dest        Destination user row.
	 * @param array $source_user Staged source user row.
	 *
	 * @return void
	 */
	protected function apply_match( array $dest, array $source_user ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$this->stage . 'users',
			array(
				'user_pass'           => $dest['user_pass'],
				'user_activation_key' => $dest['user_activation_key'],
			),
			array( 'ID' => $source_user['ID'] )
		);

		// The session store is replaced in one instant at the swap. Carrying the destination's
		// tokens across is the only thing that gives the current login a chance of surviving it.
		$tokens = $this->live_meta_value( $dest['ID'], 'session_tokens' );

		if ( null !== $tokens ) {
			$this->set_staged_meta( $source_user['ID'], 'session_tokens', $tokens );
		}

		$this->report['matched'][] = array(
			'source_id'    => (int) $source_user['ID'],
			'dest_id'      => (int) $dest['ID'],
			'email'        => $source_user['user_email'],
			'source_login' => $source_user['user_login'],
			'dest_login'   => $dest['user_login'],
		);

		if ( \strtolower( $dest['user_login'] ) !== \strtolower( $source_user['user_login'] ) ) {
			$this->report['login_changes'][] = array(
				'was' => $dest['user_login'],
				'now' => $source_user['user_login'],
			);
		}
	}

	/**
	 * Insert a destination-only account into the staged tables under a fresh ID.
	 *
	 * @param array $dest    Destination user row.
	 * @param int   $new_id  ID to give it.
	 *
	 * @return void
	 */
	protected function carry_user( array $dest, $new_id ) {
		global $wpdb;

		$row       = $dest;
		$row['ID'] = (int) $new_id;

		$original_login = $row['user_login'];

		// `admin` exists on both sides of most migrations, belonging to two different people.
		// Both are kept and the destination's is suffixed, because silently dropping either
		// one loses an account somebody still logs in with.
		$row['user_login']    = $this->unique_value( 'user_login', $row['user_login'] );
		$row['user_nicename'] = $this->unique_value( 'user_nicename', $row['user_nicename'] );
		$row['user_email']    = $this->unique_value( 'user_email', $row['user_email'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $this->stage . 'users', $row );

		// The one column in the entire schema that needs renumbering.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$meta = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_key, meta_value FROM `' . \esc_sql( $this->live . 'usermeta' ) . '` WHERE user_id = %d',
				$dest['ID']
			),
			ARRAY_A
		);

		foreach ( (array) $meta as $entry ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$this->stage . 'usermeta',
				array(
					'user_id'    => (int) $new_id,
					'meta_key'   => $entry['meta_key'],
					'meta_value' => $entry['meta_value'],
				)
			);
		}

		$this->report['carried'][] = array(
			'dest_id' => (int) $dest['ID'],
			'new_id'  => (int) $new_id,
			'login'   => $row['user_login'],
			'email'   => $row['user_email'],
		);

		if ( $row['user_login'] !== $original_login ) {
			$this->report['renamed'][] = array(
				'was'    => $original_login,
				'now'    => $row['user_login'],
				'reason' => 'a different person on the source site already uses that username',
			);
		}
	}

	/**
	 * Replace mode: keep only the source's users, plus whoever is running the import.
	 *
	 * @return void
	 */
	protected function carry_acting_user() {
		if ( $this->acting < 1 ) {
			return;
		}

		$dest = $this->live_users();

		if ( ! isset( $dest[ $this->acting ] ) ) {
			return;
		}

		$source_users = $this->staged_users();

		foreach ( $source_users as $user ) {
			if ( \strtolower( $user['user_email'] ) === \strtolower( $dest[ $this->acting ]['user_email'] ) ) {
				$this->apply_match( $dest[ $this->acting ], $user );

				return;
			}
		}

		$this->carry_user( $dest[ $this->acting ], $this->next_free_id( $source_users, $dest ) );
	}

	/**
	 * Make sure whoever is running the import is still an administrator afterwards.
	 *
	 * A source-side Editor whose account matched must not be able to lock themselves out of the
	 * site they are migrating into halfway through the migration.
	 *
	 * @return void
	 */
	protected function guarantee_acting_administrator() {
		if ( $this->acting < 1 ) {
			return;
		}

		$id = $this->staged_id_for_dest( $this->acting );

		if ( $id < 1 ) {
			return;
		}

		$this->set_staged_meta( $id, $this->live . 'capabilities', \serialize( array( 'administrator' => true ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->set_staged_meta( $id, $this->live . 'user_level', '10' );
	}

	/**
	 * Demote carried users whose role the source site does not define.
	 *
	 * A `shop_manager` arriving on a site with no WooCommerce holds a capability key for a role
	 * that does not exist, which yields no capabilities at all — and does so silently.
	 *
	 * @return void
	 */
	protected function demote_undefined_roles() {
		global $wpdb;

		$roles = $this->staged_roles();

		if ( empty( $roles ) ) {
			return;
		}

		foreach ( $this->report['carried'] as $carried ) {
			$id = (int) $carried['new_id'];

			$value = $this->staged_meta_value( $id, $this->live . 'capabilities' );

			if ( null === $value ) {
				continue;
			}

			$caps = \maybe_unserialize( $value );

			if ( ! \is_array( $caps ) || empty( $caps ) ) {
				continue;
			}

			$kept = array();

			foreach ( $caps as $name => $granted ) {
				if ( isset( $roles[ $name ] ) || ! $this->looks_like_role( $name, $roles ) ) {
					$kept[ $name ] = $granted;
				} else {
					$this->report['demoted'][] = array(
						'login' => $carried['login'],
						'was'   => $name,
						'now'   => $this->fallback_role,
					);
				}
			}

			if ( $kept === $caps ) {
				continue;
			}

			if ( empty( $kept ) ) {
				$kept = array( $this->fallback_role => true );
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			$this->set_staged_meta( $id, $this->live . 'capabilities', \serialize( $kept ) );
		}

		// The acting user's administrator grant is re-asserted, because a demotion pass that
		// ran after it could otherwise take it away again.
		$this->guarantee_acting_administrator();
	}

	/**
	 * Whether a capability key names a role rather than an individual capability.
	 *
	 * WordPress stores both in the same array. A key that is not in the role list but is also
	 * not shaped like a role — it is granted individually — is left alone.
	 *
	 * @param string $name  Capability key.
	 * @param array  $roles Roles the source defines.
	 *
	 * @return bool
	 */
	protected function looks_like_role( $name, array $roles ) {
		if ( isset( $roles[ $name ] ) ) {
			return true;
		}

		// Every capability WordPress core and its plugins define appears inside some role's
		// capability list. A key in none of them, on a user who has no other role, is a role
		// that no longer exists.
		foreach ( $roles as $role ) {
			if ( isset( $role['capabilities'] ) && \is_array( $role['capabilities'] ) && isset( $role['capabilities'][ $name ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Refuse to hand a duplicate login, nicename or email to the swap.
	 *
	 * `wp_users` declares `KEY user_login_key` and `KEY user_email` and both are non-unique:
	 * WordPress enforces uniqueness in `wp_insert_user()`, at the API layer, not in the schema.
	 * Writing rows with direct SQL means a duplicate inserts cleanly and `get_user_by()` then
	 * returns whichever row the index happens to yield. So it is checked here, and treated as a
	 * failed import rather than a warning.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If any duplicate survived.
	 */
	protected function assert_unique() {
		global $wpdb;

		foreach ( array( 'user_login', 'user_email', 'user_nicename' ) as $column ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$duplicates = $wpdb->get_col(
				'SELECT LOWER(`' . \esc_sql( $column ) . '`) AS v FROM `' . \esc_sql( $this->stage . 'users' ) . '`'
				. ' GROUP BY v HAVING COUNT(*) > 1'
			);

			if ( ! empty( $duplicates ) ) {
				throw new \RuntimeException(
					\sprintf(
						'The merged users table would contain duplicate %s values (%s), which WordPress '
							. 'cannot resolve. Nothing has been changed.',
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- exception messages, not output.
						$column,
						\implode( ', ', $duplicates )
						// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					)
				);
			}
		}
	}

	/**
	 * A value not already present in the staged users table.
	 *
	 * @param string $column Column to check.
	 * @param string $value  Desired value.
	 *
	 * @return string
	 */
	protected function unique_value( $column, $value ) {
		$candidate = $value;
		$suffix    = 1;

		while ( $this->staged_value_exists( $column, $candidate ) ) {
			++$suffix;
			$candidate = $value . '-' . $suffix;
		}

		return $candidate;
	}

	/**
	 * Whether a value already exists in a staged users column.
	 *
	 * @param string $column Column.
	 * @param string $value  Value.
	 *
	 * @return bool
	 */
	protected function staged_value_exists( $column, $value ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM `' . \esc_sql( $this->stage . 'users' ) . '` WHERE LOWER(`'
				. \esc_sql( $column ) . '`) = %s',
				\strtolower( $value )
			)
		);
	}

	/**
	 * The first ID above everything either side is using.
	 *
	 * @param array $source_users Staged users.
	 * @param array $dest_users   Live users.
	 *
	 * @return int
	 */
	protected function next_free_id( array $source_users, array $dest_users ) {
		$max = 0;

		foreach ( \array_merge( \array_keys( $source_users ), \array_keys( $dest_users ) ) as $id ) {
			$max = \max( $max, (int) $id );
		}

		return $max + 1;
	}

	/**
	 * The staged user ID a destination user ended up as.
	 *
	 * @param int $dest_id Destination user ID.
	 *
	 * @return int Zero when it is not in the staged table.
	 */
	protected function staged_id_for_dest( $dest_id ) {
		foreach ( $this->report['matched'] as $match ) {
			if ( (int) $match['dest_id'] === (int) $dest_id ) {
				return (int) $match['source_id'];
			}
		}

		foreach ( $this->report['carried'] as $carried ) {
			if ( (int) $carried['dest_id'] === (int) $dest_id ) {
				return (int) $carried['new_id'];
			}
		}

		return 0;
	}

	/**
	 * Staged users, keyed by ID.
	 *
	 * @return array
	 */
	protected function staged_users() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( 'SELECT * FROM `' . \esc_sql( $this->stage . 'users' ) . '`', ARRAY_A );

		return $this->key_by_id( $rows );
	}

	/**
	 * Live users, keyed by ID.
	 *
	 * @return array
	 */
	protected function live_users() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( 'SELECT * FROM `' . \esc_sql( $this->live . 'users' ) . '`', ARRAY_A );

		return $this->key_by_id( $rows );
	}

	/**
	 * The roles the source site defines.
	 *
	 * @return array
	 */
	protected function staged_roles() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT option_value FROM `' . \esc_sql( $this->stage . 'options' ) . '` WHERE option_name = %s',
				$this->live . 'user_roles'
			)
		);

		$roles = \maybe_unserialize( $value );

		return \is_array( $roles ) ? $roles : array();
	}

	/**
	 * Key rows by their ID column.
	 *
	 * @param array $rows Rows.
	 *
	 * @return array
	 */
	protected function key_by_id( $rows ) {
		$keyed = array();

		foreach ( (array) $rows as $row ) {
			$keyed[ (int) $row['ID'] ] = $row;
		}

		return $keyed;
	}

	/**
	 * Read one live usermeta value.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 *
	 * @return string|null
	 */
	protected function live_meta_value( $user_id, $key ) {
		return $this->meta_value( $this->live . 'usermeta', $user_id, $key );
	}

	/**
	 * Read one staged usermeta value.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 *
	 * @return string|null
	 */
	protected function staged_meta_value( $user_id, $key ) {
		return $this->meta_value( $this->stage . 'usermeta', $user_id, $key );
	}

	/**
	 * Read one usermeta value from a table.
	 *
	 * @param string $table   Table name.
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 *
	 * @return string|null
	 */
	protected function meta_value( $table, $user_id, $key ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_value FROM `' . \esc_sql( $table ) . '` WHERE user_id = %d AND meta_key = %s LIMIT 1',
				$user_id,
				$key
			)
		);
	}

	/**
	 * Write one staged usermeta value.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 * @param string $value   Meta value.
	 *
	 * @return void
	 */
	protected function set_staged_meta( $user_id, $key, $value ) {
		global $wpdb;

		$table = $this->stage . 'usermeta';

		if ( null === $this->staged_meta_value( $user_id, $key ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				array(
					'user_id'    => (int) $user_id,
					'meta_key'   => $key,
					'meta_value' => $value,
				)
			);

			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array( 'meta_value' => $value ),
			array(
				'user_id'  => (int) $user_id,
				'meta_key' => $key,
			)
		);
	}
}
