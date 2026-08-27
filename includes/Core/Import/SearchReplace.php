<?php
/**
 * URL and path rewriting across the staged tables.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

use NewfoldLabs\WP\SiteMigrator\Utils\DatabaseUtility;

/**
 * Rewrites the source's URLs and paths to the destination's, in place, on the staged tables.
 *
 * A plain SQL `REPLACE()` cannot do this. WordPress stores PHP-serialized arrays in options and
 * meta, and a serialized string carries its own byte length: turning `s:18:"http://source.test"`
 * into `s:18:"http://dest.test"` produces a blob that `unserialize()` refuses, and the setting
 * silently reverts to its default. Every candidate value is therefore unserialized, walked, and
 * re-serialized — which is what the retained `replace_serialized_values()` was written for.
 *
 * Runs before the swap, so a run that dies here has changed nothing the live site can see.
 */
class SearchReplace {

	/**
	 * Rows read per query.
	 */
	const BATCH = 500;

	/**
	 * Staged table names, in a stable order.
	 *
	 * @var array
	 */
	protected $tables;

	/**
	 * Strings to find.
	 *
	 * @var array
	 */
	protected $from = array();

	/**
	 * Their replacements, by position.
	 *
	 * @var array
	 */
	protected $to = array();

	/**
	 * Constructor.
	 *
	 * @param array $tables Staged table names.
	 * @param array $pairs  Map of search string to replacement.
	 */
	public function __construct( array $tables, array $pairs ) {
		\sort( $tables );

		$this->tables = \array_values( $tables );

		foreach ( $pairs as $from => $to ) {
			if ( '' === (string) $from || $from === $to ) {
				continue;
			}

			$this->from[] = (string) $from;
			$this->to[]   = (string) $to;
		}
	}

	/**
	 * Build the replacement pairs for a migration.
	 *
	 * @param array $source Manifest `source` block.
	 * @param array $target Destination facts: `site_url`, `home_url`, `abspath`, `content_dir`.
	 *
	 * @return array
	 */
	public static function pairs( array $source, array $target ) {
		$pairs = array();

		$urls = array(
			array(
				isset( $source['site_url'] ) ? $source['site_url'] : '',
				isset( $target['site_url'] ) ? $target['site_url'] : '',
			),
			array(
				isset( $source['home_url'] ) ? $source['home_url'] : '',
				isset( $target['home_url'] ) ? $target['home_url'] : '',
			),
		);

		foreach ( $urls as $pair ) {
			list( $from, $to ) = $pair;

			$from = \untrailingslashit( (string) $from );
			$to   = \untrailingslashit( (string) $to );

			if ( '' === $from || $from === $to ) {
				continue;
			}

			$pairs[ $from ] = $to;

			// Gutenberg block attributes and any JSON-encoded meta escape the slashes, so the
			// plain form never matches them.
			$pairs[ \str_replace( '/', '\\/', $from ) ] = \str_replace( '/', '\\/', $to );

			// And again for the scheme the source does not itself use. A site on http:// still
			// accumulates https:// references to its own host -- a real migration left
			// `yith_shippo_webhook_address` pointing at the source over https, because only the
			// http form was ever searched for. The destination's own scheme is what they become:
			// an absolute URL naming the old host is wrong wherever it points.
			$other = self::other_scheme( $from );

			if ( '' !== $other ) {
				$escaped = \str_replace( '/', '\\/', $other );

				$pairs[ $other ]   = $to;
				$pairs[ $escaped ] = \str_replace( '/', '\\/', $to );
			}
		}

		// Both sides guarded, not just the source. This is a public method behind a public
		// filter, so a caller passing the two facts it actually has should get the pairs those
		// support rather than a warning about the ones it does not.
		$paths = array(
			array(
				isset( $source['abspath'] ) ? $source['abspath'] : '',
				isset( $target['abspath'] ) ? $target['abspath'] : '',
			),
			array(
				isset( $source['content_dir'] ) ? $source['content_dir'] : '',
				isset( $target['content_dir'] ) ? $target['content_dir'] : '',
			),
		);

		foreach ( $paths as $pair ) {
			list( $from, $to ) = $pair;

			$from = \rtrim( (string) $from, '/\\' );
			$to   = \rtrim( (string) $to, '/\\' );

			if ( '' === $from || $from === $to ) {
				continue;
			}

			$pairs[ $from ] = $to;
		}

		/**
		 * Filter the search and replace pairs applied during an import.
		 *
		 * @param array $pairs  Map of search string to replacement.
		 * @param array $source Manifest source block.
		 * @param array $target Destination facts.
		 */
		return \apply_filters( 'nfd_sm_search_replace_pairs', $pairs, $source, $target );
	}

	/**
	 * The same URL under the other scheme.
	 *
	 * Only ever swaps a leading `http://` for `https://` or the reverse. Anything else -- a
	 * protocol-relative `//host`, a bare host -- is left alone: those match far more than this
	 * site's own address, and a replacement that is too eager corrupts content that was never
	 * about the migration.
	 *
	 * @param string $url URL to flip.
	 *
	 * @return string The counterpart, or '' when there is not one.
	 */
	protected static function other_scheme( $url ) {
		if ( 0 === \strpos( $url, 'http://' ) ) {
			return 'https://' . \substr( $url, 7 );
		}

		if ( 0 === \strpos( $url, 'https://' ) ) {
			return 'http://' . \substr( $url, 8 );
		}

		return '';
	}

	/**
	 * Rewrite as much as the deadline allows.
	 *
	 * @param array $state    Import state, modified in place.
	 * @param float $deadline Unix timestamp to stop by, or 0 for no limit.
	 *
	 * @return bool True when every table has been walked.
	 */
	public function step( array &$state, $deadline ) {
		if ( empty( $this->from ) ) {
			return true;
		}

		$count = \count( $this->tables );

		while ( $state['sr_table'] < $count ) {
			$table = $this->tables[ $state['sr_table'] ];

			if ( ! $this->walk_table( $table, $state, $deadline ) ) {
				return false;
			}

			++$state['sr_table'];
			$state['sr_offset'] = 0;

			if ( $deadline > 0 && \microtime( true ) >= $deadline ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Walk one table from the recorded position.
	 *
	 * @param string $table    Staged table name.
	 * @param array  $state    Import state, modified in place.
	 * @param float  $deadline Unix timestamp to stop by.
	 *
	 * @return bool True when the table is finished.
	 */
	protected function walk_table( $table, array &$state, $deadline ) {
		global $wpdb;

		$columns = $this->columns( $table );

		if ( empty( $columns['text'] ) ) {
			return true;
		}

		$keys   = $columns['keys'];
		$keyset = 1 === \count( $keys ) && $columns['numeric_key'];

		while ( true ) {
			if ( $keyset ) {
				$sql = $wpdb->prepare(
					'SELECT * FROM `' . \esc_sql( $table ) . '` WHERE `' . \esc_sql( $keys[0] ) . '` > %d'
					. ' ORDER BY `' . \esc_sql( $keys[0] ) . '` ASC LIMIT %d',
					(int) $state['sr_offset'],
					self::BATCH
				);
			} else {
				$order = empty( $keys ) ? '' : ' ORDER BY `' . \esc_sql( \implode( '`, `', $keys ) ) . '` ASC';

				$sql = $wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $order is escaped identifiers; the values below are placeholders.
					'SELECT * FROM `' . \esc_sql( $table ) . '`' . $order . ' LIMIT %d, %d',
					(int) $state['sr_offset'],
					self::BATCH
				);
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $sql, ARRAY_A );

			if ( empty( $rows ) ) {
				return true;
			}

			foreach ( $rows as $row ) {
				$changed = $this->rewrite_row( $table, $row, $columns );

				if ( ! empty( $changed ) ) {
					$state['sr_changed'] += \count( $changed );
				}

				if ( $keyset ) {
					$state['sr_offset'] = (int) $row[ $keys[0] ];
				} else {
					++$state['sr_offset'];
				}
			}

			if ( \count( $rows ) < self::BATCH ) {
				return true;
			}

			if ( $deadline > 0 && \microtime( true ) >= $deadline ) {
				return false;
			}
		}
	}

	/**
	 * Rewrite one row's text columns.
	 *
	 * @param string $table   Staged table name.
	 * @param array  $row     Row as an associative array.
	 * @param array  $columns Column metadata.
	 *
	 * @return array Columns that changed.
	 */
	protected function rewrite_row( $table, array $row, array $columns ) {
		global $wpdb;

		$updates = array();

		foreach ( $columns['text'] as $column ) {
			$value = isset( $row[ $column ] ) ? $row[ $column ] : null;

			if ( null === $value || '' === $value || ! $this->contains_search( $value ) ) {
				continue;
			}

			$replaced = DatabaseUtility::replace_serialized_values( $this->from, $this->to, $value );

			if ( \is_array( $replaced ) || \is_object( $replaced ) ) {
				// replace_serialized_values() only re-serializes when it was told the input was
				// serialized; a bare array coming back means the value was not what it looked
				// like. Leaving it alone is safer than writing a different type into the column.
				continue;
			}

			if ( $replaced !== $value ) {
				$updates[ $column ] = $replaced;
			}
		}

		if ( empty( $updates ) ) {
			return array();
		}

		$where = array();

		if ( ! empty( $columns['keys'] ) ) {
			foreach ( $columns['keys'] as $key ) {
				$where[ $key ] = $row[ $key ];
			}
		} else {
			// No key to address the row by, so match it on the values it already has. Two rows
			// that are byte-identical are updated together, which gives the same result.
			foreach ( $row as $column => $value ) {
				if ( null !== $value ) {
					$where[ $column ] = $value;
				}
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, $updates, $where );

		return \array_keys( $updates );
	}

	/**
	 * Whether a value holds any of the search strings.
	 *
	 * A plain strpos over the raw column ahead of the unserialize/walk/re-serialize work, which
	 * is orders of magnitude more expensive and needed by a tiny fraction of rows.
	 *
	 * @param string $value Column value.
	 *
	 * @return bool
	 */
	protected function contains_search( $value ) {
		foreach ( $this->from as $needle ) {
			if ( false !== \strpos( $value, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Column metadata for a table.
	 *
	 * @param string $table Table name.
	 *
	 * @return array `text`, `keys`, `numeric_key`.
	 */
	protected function columns( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$described = $wpdb->get_results( 'DESCRIBE `' . \esc_sql( $table ) . '`', ARRAY_A );

		$text        = array();
		$keys        = array();
		$numeric_key = false;

		foreach ( (array) $described as $column ) {
			$type = \strtolower( isset( $column['Type'] ) ? $column['Type'] : '' );
			$name = isset( $column['Field'] ) ? $column['Field'] : '';

			if ( 'PRI' === ( isset( $column['Key'] ) ? $column['Key'] : '' ) ) {
				$keys[] = $name;

				$numeric_key = (bool) \preg_match( '/^(tiny|small|medium|big)?int/', $type );
			}

			// Binary columns are excluded deliberately. A blob can hold serialized data, but it
			// can equally hold an image, and a byte sequence that happens to match a URL inside
			// one would be corrupted by rewriting it.
			if ( \preg_match( '/(char|text|json|enum)/', $type ) && ! \preg_match( '/binary|blob/', $type ) ) {
				$text[] = $name;
			}
		}

		return array(
			'text'        => $text,
			'keys'        => $keys,
			'numeric_key' => $numeric_key && 1 === \count( $keys ),
		);
	}
}
