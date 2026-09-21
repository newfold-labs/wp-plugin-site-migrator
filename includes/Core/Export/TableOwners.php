<?php
/**
 * Which tables belong to which plugin, read from the plugin's own code.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Export;

/**
 * Matches a site's tables to the plugins and themes that name them.
 *
 * WordPress has no registry of this. A plugin that wants a table of its own writes
 * `$wpdb->prefix . 'acme_log'` and calls `dbDelta()`, and nothing anywhere records that the
 * resulting `wp_acme_log` has anything to do with `acme-widgets`. Guessing from the name alone --
 * "the table starts with the slug" -- is wrong in both directions: WooCommerce's tables are
 * `wp_wc_*`, Wordfence's are `wp_wf*`, and a `wp_forms` table could belong to any of four plugins
 * or to none of them.
 *
 * So this reads the code instead. A table a plugin uses is a table that plugin *names*, and the
 * two ways to name one are the two this looks for:
 *
 *     $wpdb->prefix . 'acme_log'      // concatenation, in any spacing
 *     "{$wpdb->prefix}acme_log"       // interpolation
 *
 * **Every candidate is then intersected with the tables that actually exist.** That is what makes
 * a false positive harmless: a name assembled from a variable, a table belonging to a plugin
 * version that is no longer installed, or a string that merely looks like one, all fail to match a
 * real table and are dropped. What survives is a list a person can be shown and can argue with,
 * which is the only honest way to use a heuristic -- the picker offers it ticked, and un-ticking it
 * is one click.
 *
 * Deliberately not clever about it. There is no attempt to follow `$table = $prefix . $name;`
 * through a variable, because a scan that is right most of the time and silently wrong the rest is
 * worse here than one whose limits are easy to state: if the plugin does not write the table's
 * name as a literal beside `$wpdb->prefix`, this does not find it, and the table stays in the
 * unclaimed list where somebody can tick it themselves.
 */
class TableOwners {

	/**
	 * How much of one plugin's PHP to read before giving up on it.
	 *
	 * A per-plugin budget rather than a global one, so a single vendored monster cannot use up
	 * the reading that the twenty plugins after it need. `CodeCompatibility` takes the same
	 * approach for the same reason.
	 */
	const MAX_BYTES = 16777216;

	/**
	 * How long the whole scan may take, in seconds.
	 *
	 * Measured before this existed: a real 13-plugin site -- Jetpack, WooCommerce, WPForms, Yoast
	 * -- took **132 seconds** with a warm cache, which is not a slow answer but no answer at all.
	 * The request outlives nothing and the screen waits on a spinner for ever. So the scan is
	 * bounded by the clock and says when it stopped early, because a partial list somebody can
	 * see is worth incomparably more than a complete one that never arrives.
	 */
	const MAX_SECONDS = 8.0;

	/**
	 * The tables this site actually has.
	 *
	 * @var array
	 */
	protected $tables = array();

	/**
	 * This site's table prefix.
	 *
	 * @var string
	 */
	protected $prefix = '';

	/**
	 * What each directory turned out to name, so it is read once.
	 *
	 * @var array
	 */
	protected $scanned = array();

	/**
	 * When the whole scan has to stop, whatever it has found by then.
	 *
	 * @var float
	 */
	protected $deadline = 0.0;

	/**
	 * Whether everything was read, or the clock ran out first.
	 *
	 * @var bool
	 */
	protected $complete = true;

	/**
	 * Whether to read every file rather than the ones that declare things.
	 *
	 * @var bool
	 */
	protected $deep = false;

	/**
	 * Constructor.
	 *
	 * @param array  $tables  Table names as the database reports them, with the prefix on.
	 * @param string $prefix  Table prefix. Defaults to this site's.
	 * @param float  $seconds How long this instance may spend reading, across every directory.
	 * @param bool   $deep    Read every file rather than the ones that declare things.
	 */
	public function __construct( array $tables, $prefix = '', $seconds = self::MAX_SECONDS, $deep = false ) {
		$this->tables   = \array_values( \array_filter( \array_map( 'strval', $tables ) ) );
		$this->prefix   = '' !== $prefix ? (string) $prefix : \nfd_sm_table_prefix();
		$this->deadline = \microtime( true ) + (float) $seconds;
		$this->deep     = (bool) $deep;
	}

	/**
	 * Whether the scan read everything it meant to.
	 *
	 * @return bool
	 */
	public function is_complete() {
		return $this->complete;
	}

	/**
	 * The tables named by the code in one directory.
	 *
	 * @param string $dir Plugin or theme directory.
	 *
	 * @return array Table names, prefixed, in the order the database lists them.
	 */
	public function owned_by( $dir ) {
		$scan  = $this->scan( $dir );
		$names = $scan['tables'];

		if ( empty( $names ) ) {
			return array();
		}

		return $this->matching( $names );
	}

	/**
	 * The site's tables whose bare names a scan turned up, core's own excluded.
	 *
	 * Excluding core's is not tidiness. Plugins query `wp_posts`, `wp_options` and `wp_terms`
	 * constantly and name them exactly the way they name their own, so a scan of WooCommerce came
	 * back "owning" five of them — and a row reading *WooCommerce: 53 tables* with `wp_posts`
	 * among them offers to carry the site's content under the heading of carrying a plugin's data.
	 * The save would have refused them, which is not the point: it must not be offered.
	 *
	 * @param array $names Map of lowercased bare name => true.
	 *
	 * @return array
	 */
	protected function matching( array $names ) {
		$found = array();

		foreach ( $this->tables as $table ) {
			$bare = $this->unprefixed( $table );

			if ( '' === $bare || Selection::is_required_table( $table ) ) {
				continue;
			}

			if ( isset( $names[ \strtolower( $bare ) ] ) ) {
				$found[] = $table;
			}
		}

		return $found;
	}

	/**
	 * The option names the code in one directory reads or writes.
	 *
	 * The same technique as the tables and a better signal than either naming or prefixes: a
	 * plugin's settings are whatever it passes to `get_option()` and friends, and it passes them
	 * as literals because that is the only way WordPress's API takes them. What comes back is
	 * intersected with the options this site really has, so a key belonging to a feature nobody
	 * enabled simply is not there to carry.
	 *
	 * @param string $dir     Plugin or theme directory.
	 * @param array  $present Option names this site holds.
	 *
	 * @return array Option names.
	 */
	public function options_in( $dir, array $present ) {
		$scan  = $this->scan( $dir );
		$named = $scan['options'];

		if ( empty( $named ) ) {
			return array();
		}

		$found = array();

		foreach ( $present as $option ) {
			$option = (string) $option;

			// Plugins read core's options all the time -- `home`, `siteurl`, `template` -- so a
			// scan that reported them would offer somebody a checkbox that says "bring this
			// plugin's settings" and means "adopt the other site's address". `Selection` refuses
			// them when the choice is saved; they are dropped here so the choice is never drawn.
			if ( isset( $named[ \strtolower( $option ) ] ) && ! Selection::is_protected_option( $option ) ) {
				$found[] = $option;
			}
		}

		return $found;
	}

	/**
	 * Every directory's tables, keyed by its name.
	 *
	 * @param array $dirs Map of name => absolute directory.
	 *
	 * @return array Map of name => table names. Names owning nothing are left out.
	 */
	public function map( array $dirs ) {
		$owned = array();

		foreach ( $dirs as $name => $dir ) {
			$tables = $this->owned_by( $dir );

			if ( ! empty( $tables ) ) {
				$owned[ (string) $name ] = $tables;
			}
		}

		return $owned;
	}

	/**
	 * The tables nothing claimed, core's own excluded.
	 *
	 * What is left after the scan is the interesting part of it: a table here is either something
	 * a plugin builds a name for at runtime, or the remains of a plugin that is no longer
	 * installed. Neither can be decided for somebody, so both are offered to them.
	 *
	 * @param array $claimed Map of name => tables, as `map()` returns.
	 *
	 * @return array Table names.
	 */
	public function unclaimed( array $claimed ) {
		$taken = array();

		foreach ( $claimed as $tables ) {
			foreach ( (array) $tables as $table ) {
				$taken[ $table ] = true;
			}
		}

		$left = array();

		foreach ( $this->tables as $table ) {
			if ( ! isset( $taken[ $table ] ) && ! Selection::is_required_table( $table ) ) {
				$left[] = $table;
			}
		}

		return $left;
	}

	/**
	 * Read a directory's PHP, collecting both the table names and the option names.
	 *
	 * One walk rather than two: the directories are the same and the reading is the expensive
	 * part. Cached per directory, because the picker asks about tables and options separately and
	 * a plugin with a vendored library in it is not cheap to read twice.
	 *
	 * @param string $dir Directory.
	 *
	 * @return array `tables` and `options`, each a map of lowercased name => true.
	 */
	protected function scan( $dir ) {
		$dir = \rtrim( (string) $dir, '/\\' );

		if ( isset( $this->scanned[ $dir ] ) ) {
			return $this->scanned[ $dir ];
		}

		$found = $this->scan_from( $dir, 0 );

		$this->scanned[ $dir ] = $found;

		return $found;
	}

	/**
	 * Read part of a directory's PHP, starting where the last pass stopped.
	 *
	 * **This is why the scan is stepped.** Measured on a real thirteen-plugin site — Jetpack,
	 * WooCommerce, WPForms, Yoast — reading every plugin's PHP took 132 seconds, and 43 even with
	 * fixtures, tests and `node_modules` skipped and a substring check standing in front of every
	 * regex. Jetpack alone is 19 of those seconds. No request survives that, so the screen waited
	 * on a spinner that could never end: the scan has to hand back what it has and be asked again.
	 *
	 * The offset is a count of files already read, and the walk is re-entered and skipped forward
	 * rather than remembered. Skipping is a stat per entry and reading is not, so the re-walk
	 * costs a fraction of what it avoids — and nothing has to survive between two requests except
	 * a number.
	 *
	 * @param string $dir    Directory.
	 * @param int    $offset How many files earlier passes already read.
	 *
	 * @return array `tables`, `options`, and `next` — the offset to resume from, or 0 when the
	 *               directory is finished.
	 */
	public function scan_from( $dir, $offset = 0 ) {
		$dir = \rtrim( (string) $dir, '/\\' );

		$found = array(
			'tables'  => array(),
			'options' => array(),
			'next'    => 0,
		);

		if ( '' === $dir || ! \is_dir( $dir ) ) {
			return $found;
		}

		try {
			$walk = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( \Throwable $e ) {
			return $found;
		}

		$seen  = 0;
		$spent = 0;

		foreach ( $walk as $file ) {
			if ( ! $file->isFile() || 'php' !== \strtolower( $file->getExtension() ) ) {
				continue;
			}

			if ( self::is_uninteresting( $file->getPathname() ) ) {
				continue;
			}

			if ( ! $this->worth_reading( $dir, $file, $walk->getDepth() ) ) {
				continue;
			}

			++$seen;

			// Already read by an earlier pass. The walk is deterministic for an unchanged tree,
			// and a tree that changed mid-scan costs a suggestion, not correctness.
			if ( $seen <= (int) $offset ) {
				continue;
			}

			if ( \microtime( true ) > $this->deadline ) {
				$this->complete = false;
				$found['next']  = $seen - 1;

				return $found;
			}

			$spent += (int) $file->getSize();

			if ( $spent > self::MAX_BYTES ) {
				break;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions
			$source = \file_get_contents( $file->getPathname() );

			if ( false === $source ) {
				continue;
			}

			// A substring search runs at memory speed and a regex does not, and the overwhelming
			// majority of a plugin's files mention neither. Asking the cheap question first took
			// the same real site from 132 seconds to 43.
			if ( false !== \strpos( $source, 'prefix' ) ) {
				foreach ( $this->candidates( $source ) as $name ) {
					$found['tables'][ \strtolower( $name ) ] = true;
				}
			}

			if ( false !== \strpos( $source, '_option' ) ) {
				foreach ( $this->option_candidates( $source ) as $name ) {
					$found['options'][ \strtolower( $name ) ] = true;
				}
			}
		}

		return $found;
	}

	/**
	 * Turn the names one pass found into this site's real tables and options.
	 *
	 * @param array $names   Map of lowercased bare table name => true.
	 * @param array $options Map of lowercased option name => true.
	 * @param array $present Option names this site holds.
	 *
	 * @return array `tables` and `options`, as lists of real names.
	 */
	public function resolve( array $names, array $options, array $present ) {
		$tables   = $this->matching( $names );
		$settings = array();

		foreach ( $present as $option ) {
			$option = (string) $option;

			if ( isset( $options[ \strtolower( $option ) ] ) && ! Selection::is_protected_option( $option ) ) {
				$settings[] = $option;
			}
		}

		return array(
			'tables'  => $tables,
			'options' => $settings,
		);
	}

	/**
	 * Whether a file is one of the few that would say what a plugin owns.
	 *
	 * **The scan is shallow on purpose, and this is the whole reason it finishes.** Reading every
	 * PHP file of every plugin costs 20ms a file on an ordinary disk; WooCommerce and Jetpack have
	 * thousands each, so a thorough scan of a real site is minutes of somebody's afternoon spent
	 * confirming what they could have ticked by hand. Watched live on a 22-plugin site, one plugin
	 * alone was still going after four requests.
	 *
	 * So it reads the files that actually declare things — everything in the plugin's own root and
	 * one level below it, where the main file, `includes/` and `src/` live, plus anything named
	 * like a schema (`install`, `activate`, `table`, `migration`, `upgrade`, `db`) however deep it
	 * is buried. That is where `dbDelta()` calls and option defaults are, and it is a fraction of
	 * the files.
	 *
	 * What it misses is a table named only in some leaf class, and that is why the list is a
	 * suggestion with `unclaimed` beside it: anything not attributed is still offered to be ticked
	 * by hand, which is the same answer as before at none of the cost.
	 *
	 * @param string       $dir   The directory being scanned.
	 * @param \SplFileInfo $file  The file.
	 * @param int          $depth How deep the walk is, 0 being the directory itself.
	 *
	 * @return bool
	 */
	protected function worth_reading( $dir, $file, $depth ) {
		if ( $this->deep || $depth <= 1 ) {
			return true;
		}

		return (bool) \preg_match(
			'/(install|activat|schema|table|migrat|upgrade|db|database|setup)/i',
			$file->getBasename( '.php' )
		);
	}

	/**
	 * Paths that cannot tell us anything about what a plugin owns.
	 *
	 * The same rule `CodeCompatibility` uses, and for the same reason: a fixture is a file written
	 * to be wrong, and a test names tables that only ever exist while the test runs. `node_modules`
	 * is build-time JavaScript that PHP never loads, and the export refuses to package it anyway,
	 * so a table named only there could not travel even if it were real.
	 *
	 * Note `vendor/` is deliberately *not* here. Action Scheduler lives in WooCommerce's vendor
	 * directory and owns four real tables, and a scan that skipped it would quietly leave them out
	 * of the list somebody is choosing from.
	 *
	 * @param string $path File path.
	 *
	 * @return bool
	 */
	protected static function is_uninteresting( $path ) {
		return (bool) \preg_match(
			'#(^|/)(tests?|fixtures?|stubs?|examples?|node_modules|\.git)/#i',
			\str_replace( '\\', '/', (string) $path )
		);
	}

	/**
	 * Option names one file passes to the options API as a literal.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array Names.
	 */
	protected function option_candidates( $source ) {
		\preg_match_all(
			'/\b(?:get|update|add|delete)_(?:site_)?option\s*\(\s*([\'"])([A-Za-z0-9_\-]+)\1/',
			(string) $source,
			$matches
		);

		return \array_unique( (array) $matches[2] );
	}

	/**
	 * The names one file writes beside `$wpdb->prefix`.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array Names, unprefixed.
	 */
	protected function candidates( $source ) {
		$found = array();

		// `$wpdb->prefix . 'acme_log'`, and the same with `base_prefix`, double quotes, or no
		// spaces at all. `->` may also be `?->` on PHP 8, which costs nothing to allow.
		\preg_match_all(
			'/\$[A-Za-z_][A-Za-z0-9_]*\s*\??->\s*(?:base_)?prefix\s*\.\s*([\'"])([A-Za-z0-9_]+)\1/',
			(string) $source,
			$concatenated
		);

		foreach ( (array) $concatenated[2] as $name ) {
			$found[] = $name;
		}

		// `"{$wpdb->prefix}acme_log"`, which is how most of them are actually written.
		\preg_match_all(
			'/\{\s*\$[A-Za-z_][A-Za-z0-9_]*\s*\??->\s*(?:base_)?prefix\s*\}([A-Za-z0-9_]+)/',
			(string) $source,
			$interpolated
		);

		foreach ( (array) $interpolated[1] as $name ) {
			$found[] = $name;
		}

		return \array_unique( $found );
	}

	/**
	 * A table name with this site's prefix taken off.
	 *
	 * @param string $table Table name.
	 *
	 * @return string Empty when it is not this site's table at all.
	 */
	protected function unprefixed( $table ) {
		if ( '' === $this->prefix || 0 !== \strpos( $table, $this->prefix ) ) {
			return '';
		}

		return \substr( $table, \strlen( $this->prefix ) );
	}
}
