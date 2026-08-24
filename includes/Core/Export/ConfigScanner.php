<?php
/**
 * Reads facts out of wp-config.php without executing it.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Export;

/**
 * Extracts the source's `define()` calls so the destination can be told about them.
 *
 * `wp-config.php` is never packaged, never overwritten and never merged. It is executable PHP,
 * not configuration: hosts inject their own blocks into it, plugins append to it, and some
 * installs branch on `$_SERVER`. An automated edit to it is the one action in this pipeline
 * with no rollback path, because a parse error there means WordPress never boots far enough to
 * undo anything.
 *
 * Almost nothing in the file should travel anyway. Credentials, salts, the table prefix and
 * every path are destination-specific by definition. What is left is a short list of behaviour
 * flags worth telling somebody about — so the file is read, never written, and the result is a
 * block of text the user can paste if they want it.
 *
 * The reading is done with `token_get_all()`. Never `include`, because that executes it; never
 * a regular expression, because a commented-out define looks exactly like a live one.
 */
class ConfigScanner {

	/**
	 * Constants worth carrying, with a note explaining each.
	 *
	 * @var array
	 */
	protected static $allowlist = array(
		'WP_MEMORY_LIMIT'            => 'Memory ceiling for the front end. The destination host may cap this lower.',
		'WP_MAX_MEMORY_LIMIT'        => 'Memory ceiling for admin screens and cron.',
		'WP_DEBUG'                   => 'Whether PHP notices are shown.',
		'WP_DEBUG_LOG'               => 'Whether errors are written to wp-content/debug.log.',
		'WP_DEBUG_DISPLAY'           => 'Whether errors are printed into the page.',
		'SCRIPT_DEBUG'               => 'Loads unminified core scripts.',
		'DISALLOW_FILE_EDIT'         => 'Hides the plugin and theme file editors.',
		'DISALLOW_FILE_MODS'         => 'Blocks installing or updating plugins and themes.',
		'AUTOSAVE_INTERVAL'          => 'Seconds between post autosaves.',
		'WP_POST_REVISIONS'          => 'How many revisions each post keeps.',
		'EMPTY_TRASH_DAYS'           => 'How long trashed content is kept.',
		'AUTOMATIC_UPDATER_DISABLED' => 'Turns off background core updates.',
		'WP_AUTO_UPDATE_CORE'        => 'Which core updates apply automatically.',
		'IMAGE_EDIT_OVERWRITE'       => 'Whether edited images replace the original file.',
		'WP_CRON_LOCK_TIMEOUT'       => 'How long a cron run may hold its lock.',
		'CONCATENATE_SCRIPTS'        => 'Whether admin scripts are bundled.',
	);

	/**
	 * Constants that are destination-specific and must never travel.
	 *
	 * Not merely unhelpful: copying the salts would invalidate every session on the
	 * destination, including the one running the import.
	 *
	 * @var array
	 */
	protected static $never = array(
		'DB_NAME',
		'DB_USER',
		'DB_PASSWORD',
		'DB_HOST',
		'DB_CHARSET',
		'DB_COLLATE',
		'WP_HOME',
		'WP_SITEURL',
		'WP_CONTENT_DIR',
		'WP_CONTENT_URL',
		'WP_PLUGIN_DIR',
		'WP_PLUGIN_URL',
		'WPMU_PLUGIN_DIR',
		'UPLOADS',
		'WP_TEMP_DIR',
		'WP_CACHE',
		'ABSPATH',
	);

	/**
	 * Scan a wp-config.php.
	 *
	 * @param string $path Absolute path. Defaults to this install's.
	 *
	 * @return array `carry`, `redacted`, `prefix`, `readable`.
	 */
	public static function scan( $path = '' ) {
		if ( '' === $path ) {
			$path = self::locate();
		}

		$result = array(
			'readable' => false,
			'prefix'   => '',
			'carry'    => array(),
			'redacted' => array(),
		);

		if ( '' === $path || ! \is_readable( $path ) ) {
			return $result;
		}

		$result['readable'] = true;

		// A wp-config.php with a syntax error makes the tokeniser emit warnings. There is nothing
		// useful to do about it here — the file belongs to the user and is only being read — and
		// letting them through would print PHP notices into an admin page.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$tokens = @\token_get_all( \file_get_contents( $path ) );

		if ( ! \is_array( $tokens ) ) {
			return $result;
		}

		foreach ( self::defines( $tokens ) as $name => $value ) {
			if ( \in_array( $name, self::$never, true ) ) {
				continue;
			}

			if ( self::is_secret( $name ) ) {
				$result['redacted'][] = $name;

				continue;
			}

			if ( isset( self::$allowlist[ $name ] ) ) {
				$result['carry'][] = array(
					'name'  => $name,
					'value' => $value,
					'note'  => self::$allowlist[ $name ],
				);

				continue;
			}

			// Named, never printed. A licence key or an internal API endpoint is worth knowing
			// about and not worth putting in a file that travels between servers.
			$result['redacted'][] = $name;
		}

		$result['prefix'] = self::table_prefix( $tokens );

		return $result;
	}

	/**
	 * Render the carried constants as a block the user can paste.
	 *
	 * @param array $scan Result of scan().
	 *
	 * @return string Empty when there is nothing worth adding.
	 */
	public static function to_block( array $scan ) {
		if ( empty( $scan['carry'] ) ) {
			return '';
		}

		$lines = array( '/* Carried over by nfd-site-migrator. */' );

		foreach ( $scan['carry'] as $entry ) {
			$lines[] = '// ' . $entry['note'];
			$lines[] = \sprintf( "define( '%s', %s );", $entry['name'], $entry['value'] );
		}

		return \implode( "\n", $lines ) . "\n";
	}

	/**
	 * Pull `define()` calls out of a token stream.
	 *
	 * Only literal two-argument calls are read. Anything computed is skipped rather than
	 * guessed at, which is the whole reason for tokenising instead of matching text.
	 *
	 * @param array $tokens Token stream.
	 *
	 * @return array Map of constant name to its literal source text.
	 */
	protected static function defines( array $tokens ) {
		$found = array();
		$count = \count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! \is_array( $token ) || T_STRING !== $token[0] || 'define' !== \strtolower( $token[1] ) ) {
				continue;
			}

			$args = self::literal_arguments( $tokens, $i, $count );

			if ( \count( $args ) < 2 ) {
				continue;
			}

			$name = \trim( $args[0], "'\"" );

			if ( '' === $name ) {
				continue;
			}

			$found[ $name ] = $args[1];
		}

		return $found;
	}

	/**
	 * Read the two arguments of a call, provided both are single literals.
	 *
	 * @param array $tokens Token stream.
	 * @param int   $start  Index of the function name.
	 * @param int   $count  Token count.
	 *
	 * @return array
	 */
	protected static function literal_arguments( array $tokens, $start, $count ) {
		$args    = array();
		$current = '';
		$depth   = 0;

		for ( $i = $start + 1; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( \is_array( $token ) ) {
				if ( T_WHITESPACE === $token[0] || T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					continue;
				}

				$current .= $token[1];

				continue;
			}

			if ( '(' === $token ) {
				++$depth;

				if ( 1 === $depth ) {
					continue;
				}
			}

			if ( ')' === $token ) {
				--$depth;

				if ( 0 === $depth ) {
					$args[] = $current;

					return $args;
				}
			}

			if ( ',' === $token && 1 === $depth ) {
				$args[]  = $current;
				$current = '';

				continue;
			}

			$current .= $token;
		}

		return $args;
	}

	/**
	 * The `$table_prefix` assignment, if it is a plain literal.
	 *
	 * @param array $tokens Token stream.
	 *
	 * @return string
	 */
	protected static function table_prefix( array $tokens ) {
		$count = \count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! \is_array( $token ) || T_VARIABLE !== $token[0] || '$table_prefix' !== $token[1] ) {
				continue;
			}

			for ( $j = $i + 1; $j < $count; $j++ ) {
				$next = $tokens[ $j ];

				if ( \is_array( $next ) && T_WHITESPACE === $next[0] ) {
					continue;
				}

				if ( '=' === $next ) {
					continue;
				}

				if ( \is_array( $next ) && T_CONSTANT_ENCAPSED_STRING === $next[0] ) {
					return \trim( $next[1], "'\"" );
				}

				break;
			}
		}

		return '';
	}

	/**
	 * Whether a constant's name says its value is a secret.
	 *
	 * Applied regardless of the allowlist, so a future addition to it cannot accidentally start
	 * publishing a key.
	 *
	 * @param string $name Constant name.
	 *
	 * @return bool
	 */
	protected static function is_secret( $name ) {
		return (bool) \preg_match( '/(KEY|SALT|SECRET|TOKEN|PASS|AUTH|NONCE|LICENSE|LICENCE|API)/i', $name );
	}

	/**
	 * Where this install's wp-config.php is.
	 *
	 * WordPress allows it one directory above the root, which is a common hardening step.
	 *
	 * @return string Empty when it cannot be found.
	 */
	protected static function locate() {
		$root = \rtrim( ABSPATH, '/\\' );

		if ( \is_readable( $root . '/wp-config.php' ) ) {
			return $root . '/wp-config.php';
		}

		$parent = \dirname( $root );

		if ( \is_readable( $parent . '/wp-config.php' ) && ! \is_readable( $parent . '/wp-settings.php' ) ) {
			return $parent . '/wp-config.php';
		}

		return '';
	}
}
