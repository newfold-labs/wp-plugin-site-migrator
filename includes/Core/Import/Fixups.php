<?php
/**
 * Everything that has to be true once the swap has run.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

/**
 * Post-swap repairs.
 *
 * Small, individually unglamorous, and collectively the difference between a site that works
 * and a site that loads a white screen. Each one is here because leaving it out produces a
 * failure the user cannot diagnose: a plugin file that is not there, a permalink structure
 * cached from the previous site, a migrator that deactivated itself at the moment it needed to
 * show a completion screen.
 *
 * Every repair records what it did. The report is the only account of the swap the user gets.
 */
class Fixups {

	/**
	 * What each repair did.
	 *
	 * @var array
	 */
	protected $notes = array();

	/**
	 * Run them all.
	 *
	 * @param array $target    Destination facts: `site_url`, `home_url`.
	 * @param int   $acting_id  Final ID of the account running the import, or 0.
	 *
	 * @return array Notes.
	 */
	public function run( array $target, $acting_id = 0 ) {
		// Everything below reads options. The object cache is still holding the destination's
		// pre-swap values, so this has to come first or every check below tests the old site.
		\wp_cache_flush();

		$this->restore_urls( $target );
		$this->keep_migrator_active();
		$this->drop_missing_plugins();
		$this->flush_permalinks();
		$this->clear_transients();
		$this->note_dropins();
		$this->upgrade_database();
		$this->reestablish_session( (int) $acting_id );

		\wp_cache_flush();

		return $this->notes;
	}

	/**
	 * Notes recorded so far.
	 *
	 * @return array
	 */
	public function notes() {
		return $this->notes;
	}

	/**
	 * Make sure the site knows its own address.
	 *
	 * The search and replace should already have done this. It is asserted anyway, because
	 * every other repair here depends on it and a site pointing at the source's domain is
	 * unreachable — there is no admin screen left to fix it from.
	 *
	 * @param array $target Destination facts.
	 *
	 * @return void
	 */
	protected function restore_urls( array $target ) {
		foreach ( array(
			'siteurl' => 'site_url',
			'home'    => 'home_url',
		) as $option => $key ) {
			$expected = \untrailingslashit( $target[ $key ] );
			$actual   = \untrailingslashit( (string) \get_option( $option ) );

			if ( $actual === $expected ) {
				continue;
			}

			\update_option( $option, $expected );

			$this->notes[] = \sprintf( 'Set %s to %s (it arrived as %s).', $option, $expected, $actual );
		}
	}

	/**
	 * Put this plugin back into the active list.
	 *
	 * `active_plugins` now comes from the source, and the source correctly excluded this plugin
	 * from its own archive — so the importer has just deactivated itself, one step before it
	 * needed to report what it did.
	 *
	 * @return void
	 */
	protected function keep_migrator_active() {
		$slug   = NFD_SM_PLUGIN_NAME . '/' . NFD_SM_PLUGIN_NAME . '.php';
		$active = (array) \get_option( 'active_plugins', array() );

		if ( \in_array( $slug, $active, true ) ) {
			return;
		}

		$active[] = $slug;

		\update_option( 'active_plugins', \array_values( $active ) );

		$this->notes[] = 'Reactivated the migrator, which the source site\'s plugin list did not include.';
	}

	/**
	 * Deactivate plugins whose files did not arrive.
	 *
	 * WordPress tolerates a missing plugin file, but a plugin that is listed and absent shows an
	 * error on every admin page load and cannot be deactivated from the UI.
	 *
	 * @return void
	 */
	protected function drop_missing_plugins() {
		$active  = (array) \get_option( 'active_plugins', array() );
		$kept    = array();
		$dropped = array();

		foreach ( $active as $plugin ) {
			if ( \file_exists( \WP_PLUGIN_DIR . '/' . $plugin ) ) {
				$kept[] = $plugin;

				continue;
			}

			$dropped[] = $plugin;
		}

		if ( empty( $dropped ) ) {
			return;
		}

		\update_option( 'active_plugins', \array_values( $kept ) );

		$this->notes[] = \sprintf(
			'Deactivated %d plugin(s) whose files are not on this server: %s.',
			\count( $dropped ),
			\implode( ', ', $dropped )
		);
	}

	/**
	 * Throw away the source's compiled rewrite rules.
	 *
	 * They were generated against the source's URL and its plugins. Emptying the option makes
	 * WordPress regenerate them on the next request, which is more reliable than calling
	 * `flush_rewrite_rules()` here, where the post types that contribute rules are not all
	 * registered yet.
	 *
	 * @return void
	 */
	protected function flush_permalinks() {
		\update_option( 'rewrite_rules', '' );

		$this->notes[] = 'Cleared the rewrite rules so permalinks are rebuilt on the next request.';
	}

	/**
	 * Remove transients that survived the dump.
	 *
	 * Most are skipped on the way in. The rest are cache keyed to the source's URL and would
	 * otherwise serve stale data until they expired on their own.
	 *
	 * @return void
	 */
	protected function clear_transients() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->query(
			"DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '\_transient\_%'"
			. " OR option_name LIKE '\_site\_transient\_%'"
		);

		if ( $deleted > 0 ) {
			$this->notes[] = \sprintf( 'Removed %d cached transient row(s).', (int) $deleted );
		}
	}

	/**
	 * Point out drop-ins that arrived from the source.
	 *
	 * They are not removed. `object-cache.php` talking to a Redis server that does not exist
	 * here is a real problem, but so is silently deleting a file the user put there — and the
	 * destination may well have the same backing service.
	 *
	 * @return void
	 */
	protected function note_dropins() {
		$found = array();

		foreach ( array( 'object-cache.php', 'advanced-cache.php', 'db.php' ) as $dropin ) {
			if ( \file_exists( \WP_CONTENT_DIR . '/' . $dropin ) ) {
				$found[] = $dropin;
			}
		}

		if ( empty( $found ) ) {
			return;
		}

		$this->notes[] = \sprintf(
			'The source site\'s drop-ins are now in place (%s). They talk to services this server '
			. 'may not have; remove them if the site misbehaves.',
			\implode( ', ', $found )
		);
	}

	/**
	 * Sign the person running this back in, under whatever identity they now have.
	 *
	 * WordPress's auth cookie names the user's *login*, not their ID. The merge can legitimately
	 * change a login — a matched account takes the source's username, and a collision suffixes
	 * the destination's — and at that moment the cookie in the browser stops resolving to
	 * anybody. The import itself carries on, because the step requests authenticate with a token
	 * instead, but the admin screen behind them is one refresh away from a login form.
	 *
	 * A fresh cookie costs nothing and is issued to the same person who started the import, on
	 * a request they authenticated. Skipped outside a web request, where there is nobody to
	 * hand it to.
	 *
	 * @param int $acting_id Final user ID.
	 *
	 * @return void
	 */
	protected function reestablish_session( $acting_id ) {
		if ( $acting_id < 1 || 'cli' === PHP_SAPI || \headers_sent() ) {
			return;
		}

		$user = \get_userdata( $acting_id );

		if ( ! $user ) {
			return;
		}

		\wp_set_current_user( $acting_id );
		\wp_set_auth_cookie( $acting_id, false );

		$this->notes[] = \sprintf(
			'You are still signed in, now as %s.',
			$user->user_login
		);
	}

	/**
	 * Run WordPress's own database upgrade if the schema is behind the core files.
	 *
	 * The destination is never on an older WordPress than the source — preflight blocks that —
	 * but it is often on a newer one, and the arriving schema is then a version behind. Left
	 * alone, WordPress shows its "database update required" screen to the next visitor with
	 * admin rights. Doing it here means the migration finishes finished.
	 *
	 * @return void
	 */
	protected function upgrade_database() {
		global $wp_db_version;

		$current = (int) \get_option( 'db_version' );

		if ( ! isset( $wp_db_version ) || $current === (int) $wp_db_version ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		try {
			\wp_upgrade();

			$this->notes[] = \sprintf(
				'Ran the WordPress database upgrade, from schema %d to %d.',
				$current,
				(int) $wp_db_version
			);
		} catch ( \Exception $e ) {
			$this->notes[] = \sprintf(
				'The WordPress database upgrade did not finish (%s). Visit wp-admin to complete it.',
				$e->getMessage()
			);
		}
	}
}
