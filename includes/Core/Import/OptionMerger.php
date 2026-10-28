<?php
/**
 * Copy a plugin's settings into a destination that is keeping its own.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

use NewfoldLabs\WP\SiteMigrator\Core\Export\Selection;

/**
 * The one place rows are written into a live table rather than renamed into place.
 *
 * Everything else about an import is a rename: staged tables become live in a single statement,
 * and the site that was here is one rename away from coming back. Settings cannot work that way.
 * `wp_options` is not a plugin's table, it is *the destination's* table -- its address, its
 * permalinks, the list of plugins it loads and every other plugin's settings are rows in it -- so
 * carrying a plugin's settings means carrying rows, into a table that stays where it is.
 *
 * That makes this the most careful code in the import, and it is deliberately narrow:
 *
 * - **Only the names the package asks for**, and only those the source's own selection recorded.
 * - **Never a name that belongs to the destination.** `Selection::is_protected_option()` refuses
 *   `home`, `siteurl`, `active_plugins`, `template` and the rest — checked here as well as at the
 *   source, because a package is input this site did not write, and the failure mode is a site
 *   silently adopting another site's address.
 * - **Every previous value is recorded before it is overwritten**, including the absence of one,
 *   so the undo is exact: what was there goes back, and what was never there is deleted.
 *
 * The values come out of the staged copy of the source's options table, which means they have
 * already been through `SearchReplace` — a plugin's settings full of the source's URLs arrive
 * rewritten to the destination's, the same as every other table's rows.
 */
class OptionMerger {

	/**
	 * Staged options table, holding only the rows the source chose.
	 *
	 * @var string
	 */
	protected $staged = '';

	/**
	 * Constructor.
	 *
	 * @param string $staged Staged options table name.
	 */
	public function __construct( $staged ) {
		$this->staged = (string) $staged;
	}

	/**
	 * Write the carried rows into this site's options table.
	 *
	 * @param array $names Option names the package declares.
	 * @param array $state Import state, modified in place: `options_before` is the undo.
	 *
	 * @return array Names actually written.
	 */
	public function merge( array $names, array &$state ) {
		global $wpdb;

		$wanted = array();

		foreach ( $names as $name ) {
			$name = (string) $name;

			if ( '' !== $name && ! Selection::is_protected_option( $name ) ) {
				$wanted[ $name ] = true;
			}
		}

		if ( empty( $wanted ) ) {
			return array();
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT `option_name`, `option_value`, `autoload` FROM `' . \esc_sql( $this->staged ) . '`',
			ARRAY_A
		);

		if ( ! \is_array( $rows ) ) {
			return array();
		}

		if ( ! isset( $state['options_before'] ) || ! \is_array( $state['options_before'] ) ) {
			$state['options_before'] = array();
		}

		$written = array();

		foreach ( $rows as $row ) {
			$name = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';

			// The dump is the source's, the list is the source's, and they should agree -- but a
			// row the manifest did not declare is a row nobody was shown, so it is not written.
			if ( ! isset( $wanted[ $name ] ) ) {
				continue;
			}

			$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s", $name )
			);

			// Recorded before the write, and `null` is meaningful: it is the difference between
			// putting a value back and removing a row that was never here.
			if ( ! \array_key_exists( $name, $state['options_before'] ) ) {
				$state['options_before'][ $name ] = null === $existing ? null : (string) $existing;
			}

			$value    = isset( $row['option_value'] ) ? (string) $row['option_value'] : '';
			$autoload = isset( $row['autoload'] ) ? (string) $row['autoload'] : 'yes';

			if ( null === $existing ) {
				$done = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->options,
					array(
						'option_name'  => $name,
						'option_value' => $value,
						'autoload'     => $autoload,
					)
				);
			} else {
				$done = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->options,
					array(
						'option_value' => $value,
						'autoload'     => $autoload,
					),
					array( 'option_name' => $name )
				);
			}

			if ( false !== $done ) {
				$written[] = $name;
			}
		}

		\wp_cache_flush();

		return $written;
	}

	/**
	 * Put the destination's own settings back.
	 *
	 * @param array $before Map of option name => previous value, or null for "there was none".
	 *
	 * @return int How many rows were restored or removed.
	 */
	public static function restore( array $before ) {
		global $wpdb;

		$count = 0;

		foreach ( $before as $name => $value ) {
			$name = (string) $name;

			if ( '' === $name || Selection::is_protected_option( $name ) ) {
				continue;
			}

			if ( null === $value ) {
				$done = $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				$done = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->options,
					array( 'option_value' => (string) $value ),
					array( 'option_name' => $name )
				);
			}

			if ( false !== $done ) {
				++$count;
			}
		}

		\wp_cache_flush();

		return $count;
	}
}
