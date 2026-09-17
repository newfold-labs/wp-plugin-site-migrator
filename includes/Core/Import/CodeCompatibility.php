<?php
/**
 * What the package's own code will not survive on this server.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

use NewfoldLabs\WP\SiteMigrator\Core\Package\Manifest;
use NewfoldLabs\WP\SiteMigrator\Core\Package\ZipReader;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Report;

/**
 * Reads the package's PHP for language features the destination has removed.
 *
 * Comparing version numbers says a move is from 7.4 to 8.5 and stops there, and comparing
 * `php.requires` from plugin headers only covers plugins that declare one -- a theme declares
 * nothing at all. Neither notices that the theme being carried calls `create_function()`, which
 * PHP 8.0 removed. The result is a migration that verifies, imports, swaps, and leaves a site
 * that fatals on every request including the REST call the screen offering to undo it depends
 * on.
 *
 * So this reads the code rather than its metadata. It is deliberately a **block** on the
 * symbols it knows, because the failure it prevents is total and is discovered after the point
 * of no return.
 *
 * Tokenised, never matched with a regex, for the reason `ConfigScanner` is: WordPress themes
 * carry JavaScript inside PHP strings, and `_.each(` and `$.each(` are Underscore and jQuery.
 * A regex pass over one real theme reported thirty-seven findings of which every single one
 * was a false positive.
 *
 * Every file is also parsed by the PHP that will run it (`TOKEN_PARSE`), which finds syntax a
 * version no longer accepts -- `$str{0}`, `(real)`, a keyword used as a name -- without a list
 * to keep. Two PHP 8.0 removals the parser still accepts and only the compiler refuses, the
 * `(unset)` cast and an unparenthesised nested ternary, are found from tokens. A file that does
 * not parse only blocks when this PHP is newer than the source's; see `check()`. Directories of
 * tests, fixtures, stubs and examples are not read at all: a real site carried 813 deliberately
 * broken PHP_CodeSniffer fixtures and 126 removals inside them, and none of them ever run.
 */
class CodeCompatibility {

	/**
	 * Largest amount of PHP to read out of one package.
	 *
	 * A preview is a screen somebody is waiting on, not a linter, so there is a limit -- a hostile
	 * or strange package must not make the review screen hang. It was 64MB, and a real site's
	 * plugins held 120MB of PHP outside their tests, WooCommerce and its vendored libraries most
	 * of it: every such site would have been told only part of its code was checked. Reading and
	 * parsing costs about half a second per 15MB, and the same preview has already hashed every
	 * byte of the package, so 256MB is a few seconds on top of work many times larger.
	 */
	const MAX_BYTES = 268435456;

	/**
	 * Findings to report before saying "and more".
	 */
	const MAX_REPORTED = 25;

	/**
	 * Functions PHP removed, and the version that removed them.
	 *
	 * Only outright removals belong here. A deprecation still runs, and blocking a migration
	 * over a notice would be the same overreach in the other direction.
	 *
	 * @var array
	 */
	protected static $removed = array(
		// PHP 7.0.
		'split'                    => '7.0',
		'spliti'                   => '7.0',
		'sql_regcase'              => '7.0',
		'ereg'                     => '7.0',
		'eregi'                    => '7.0',
		'ereg_replace'             => '7.0',
		'eregi_replace'            => '7.0',
		'call_user_method'         => '7.0',
		'call_user_method_array'   => '7.0',
		'mysql_connect'            => '7.0',
		'mysql_pconnect'           => '7.0',
		'mysql_query'              => '7.0',
		'mysql_select_db'          => '7.0',
		'mysql_fetch_array'        => '7.0',
		'mysql_fetch_assoc'        => '7.0',
		'mysql_fetch_row'          => '7.0',
		'mysql_fetch_object'       => '7.0',
		'mysql_num_rows'           => '7.0',
		'mysql_real_escape_string' => '7.0',
		'mysql_escape_string'      => '7.0',
		'mysql_close'              => '7.0',
		'mysql_error'              => '7.0',
		'mysql_insert_id'          => '7.0',
		// PHP 8.0.
		'create_function'          => '8.0',
		'each'                     => '8.0',
		'money_format'             => '8.0',
		'restore_include_path'     => '8.0',
		'get_magic_quotes_gpc'     => '8.0',
		'get_magic_quotes_runtime' => '8.0',
		'convert_cyr_string'       => '8.0',
		'hebrevc'                  => '8.0',
		'fgetss'                   => '8.0',
		'read_exif_data'           => '8.0',
		'png2wbmp'                 => '8.0',
		'jpeg2wbmp'                => '8.0',
		'gmp_random'               => '8.0',
		'image2wbmp'               => '8.0',
		'__autoload'               => '8.0',
		'zip_open'                 => '8.0',
		'zip_read'                 => '8.0',
		'zip_close'                => '8.0',
		'zip_entry_open'           => '8.0',
		'zip_entry_read'           => '8.0',
		'zip_entry_close'          => '8.0',
		'zip_entry_name'           => '8.0',
		'zip_entry_filesize'       => '8.0',
		'ldap_sort'                => '8.0',
		// PHP 8.1.
		'mhash'                    => '8.1',
		'mhash_count'              => '8.1',
		'mhash_get_block_size'     => '8.1',
		'mhash_get_hash_name'      => '8.1',
		'mhash_keygen_s2k'         => '8.1',
	);

	/**
	 * Version that stopped treating a same-named method as a constructor.
	 */
	const CONSTRUCTOR_REMOVED = '8.0';

	/**
	 * Package directory.
	 *
	 * @var string
	 */
	protected $dir = '';

	/**
	 * The package's manifest.
	 *
	 * @var Manifest|null
	 */
	protected $manifest = null;

	/**
	 * Whether the last scan read all of the package's code.
	 *
	 * @var bool
	 */
	protected $complete = true;

	/**
	 * Files from the last scan that `SyntaxFixer` can make clean, with how many changes each needs.
	 *
	 * @var array
	 */
	protected $fixable = array();

	/**
	 * Constructor.
	 *
	 * @param string        $dir      Package directory.
	 * @param Manifest|null $manifest Its manifest.
	 */
	public function __construct( $dir, $manifest = null ) {
		$this->dir      = \rtrim( (string) $dir, '/\\' );
		$this->manifest = $manifest;
	}

	/**
	 * Add what this server cannot run to the report.
	 *
	 * @param Report $report  Report to add to.
	 * @param string $version PHP version to judge against. Defaults to this server's.
	 * @param bool   $fix     Whether safe syntax fixes will be applied as the files are written.
	 *
	 * @return void
	 */
	public function check( Report $report, $version = '', $fix = false ) {
		$version = '' !== $version ? $version : PHP_VERSION;

		$findings = $this->scan( $version );

		if ( null === $findings ) {
			// Deliberately a warning and not the usual "indeterminate blocks". Being unable to
			// read a part is not evidence of anything in it, the parts are verified against
			// their own checksums a moment earlier, and refusing every package this cannot open
			// would turn a best-effort extra check into a new way to be stuck.
			$report->warn(
				'php_code',
				'The package\'s code could not be read, so it was not checked against this server\'s PHP.',
				array( 'detail' => 'Every file is still checked against its own checksum before anything is written.' )
			);

			return;
		}

		list( $refused, $carried ) = $this->split( $findings, $version );

		// A file is fixable when `SyntaxFixer` removed every refusal in it and the result checked
		// clean. With fixes chosen, those files stop being refusals and become a notice; whatever
		// is left still blocks on its own.
		$fixable = array();
		$fixing  = array();

		foreach ( $refused as $finding ) {
			if ( isset( $this->fixable[ $finding['file'] ] ) ) {
				$fixable[ $finding['file'] ] = true;
			}
		}

		if ( $fix && ! empty( $fixable ) ) {
			$left = array();

			foreach ( $refused as $finding ) {
				if ( isset( $fixable[ $finding['file'] ] ) ) {
					$fixing[] = $finding;
				} else {
					$left[] = $finding;
				}
			}

			$refused = $left;
		}

		$php = $this->short( $version );

		if ( ! empty( $refused ) ) {
			$files   = \count( \array_unique( \array_column( $refused, 'file' ) ) );
			$context = array(
				'missing' => $this->describe( $refused, $version ),
				'detail'  => \sprintf(
					'Each of these went from PHP after the version the site came from, so this is code that runs there and '
					. 'cannot here: a file that will not parse fails the moment WordPress loads it, and a call fails when it is '
					. 'reached. Fix them on the source and export again, or import onto a server still running PHP %s.',
					$this->short( $this->source_version() )
				),
				'fix'     => 'Each one is a small edit in the file named.',
				'fixable' => \count( $fixable ),
				'php'     => $php,
			);

			if ( ! empty( $fixing ) ) {
				$context['fix'] = \sprintf( 'The other %d file(s) will be fixed as they are imported; these need an edit in the file named.', \count( $fixable ) );
			} elseif ( \count( $fixable ) === $files ) {
				$context['fix'] = 'All of these can be fixed automatically as they are imported, keeping the originals.';
			} elseif ( ! empty( $fixable ) ) {
				$context['fix'] = \sprintf( '%d of these files can be fixed automatically, keeping the originals. The rest need an edit in the file named.', \count( $fixable ) );
			}

			$report->block( 'php_code', \sprintf( 'The package contains code that PHP %s cannot run.', $php ), $context );

			return;
		}

		if ( ! empty( $fixing ) ) {
			$report->warn(
				'php_code',
				\sprintf( '%d file(s) use syntax PHP %s no longer accepts, and will be fixed as they are imported.', \count( $fixable ), $php ),
				array(
					'missing' => $this->describe( $fixing, $version ),
					'detail'  => 'Only syntax with an exact replacement is changed, and every fixed file is parsed and checked again before '
						. 'it is written. The original of each one is kept with this import\'s records.',
					'fixable' => \count( $fixable ),
					'php'     => $php,
				)
			);

			return;
		}

		if ( ! empty( $carried ) ) {
			$calls  = \count( $carried ) - \count( \array_keys( \array_column( $carried, 'kind' ), 'parse', true ) );
			$source = $this->short( $this->source_version() );

			if ( 0 === $calls ) {
				$label = \sprintf( 'Some of the package\'s PHP does not parse on PHP %s.', $php );
			} elseif ( \count( $carried ) === $calls ) {
				$label = \sprintf( '%d call(s) in the package are to functions PHP %s no longer has.', $calls, $php );
			} else {
				$label = \sprintf( 'Some of the package\'s code would not run on PHP %s.', $php );
			}

			$report->warn(
				'php_code',
				$label,
				array(
					'missing' => $this->describe( $carried, $version ),
					'detail'  => \sprintf(
						'Not refused, because the site was already running PHP %s with this same code: whatever this names is not '
						. 'reached there, and the migration changes nothing about it. Plugins carry compatibility shims and vendored '
						. 'libraries for other PHP versions as a matter of course. If WordPress does reach one, that request fails -- '
						. 'there as much as here.',
						$source
					),
					'php'     => $php,
				)
			);

			return;
		}

		if ( ! $this->complete ) {
			$report->warn(
				'php_code',
				'The package holds more PHP than this check reads, so only part of it was checked.',
				array( 'detail' => \sprintf( 'Nothing in the first %dMB of code would fail on PHP %s.', (int) ( static::MAX_BYTES / 1048576 ), $this->short( $version ) ) )
			);

			return;
		}

		$report->pass( 'php_code', \sprintf( 'The package\'s code parses on PHP %s and uses nothing it has removed.', $this->short( $version ) ) );
	}

	/**
	 * The files a safe fix would make runnable here, for the import to fix as it writes them.
	 *
	 * The same scan and the same refusals as `check()`, so precheck can only ever fix what the
	 * review screen said it would.
	 *
	 * @param string $version PHP version to judge against. Defaults to this server's.
	 *
	 * @return array Entry names.
	 */
	public function fixable_files( $version = '' ) {
		$version  = '' !== $version ? $version : PHP_VERSION;
		$findings = $this->scan( $version );

		if ( null === $findings ) {
			return array();
		}

		list( $refused ) = $this->split( $findings, $version );

		$files = array();

		foreach ( $refused as $finding ) {
			if ( isset( $this->fixable[ $finding['file'] ] ) ) {
				$files[ $finding['file'] ] = true;
			}
		}

		return \array_keys( $files );
	}

	/**
	 * Findings that refuse the import, and findings the source was already living with.
	 *
	 * **The question is never "can this PHP run it", it is "does this migration break it".** A
	 * package carries whole plugins, and a plugin routinely ships code for PHP versions it is not
	 * running on: a compatibility shim, a vendored library's legacy driver, a wrapper around a
	 * function that went years ago. None of that is reached, which is why the site it came from
	 * works. So a finding only refuses the import when the source's own PHP still had what this
	 * one has taken away -- the range between the two versions, and nothing outside it.
	 *
	 * For a **removed function** that is exact: `removed_in` says which version took it, and a
	 * source already past that version was running the same file without it. WP Defender vendors
	 * `thecodingmachine/safe`, whose generated wrappers call `create_function()`, `mysql_query()`
	 * and `zip_entry_read()` inside function bodies nobody calls; a real 8.2 site refused a package
	 * from another 8.2 site over them, on a migration where PHP did not change at all.
	 *
	 * For a **file this PHP cannot parse** there is no such number, so the comparison is the two
	 * PHP versions: newer here than there and the syntax was removed on the way up; the same or
	 * older and the source could not have been loading it either. WooCommerce 11.0 declares PHP 7.4
	 * and carries 46 files of PHP 8 syntax.
	 *
	 * A source that does not say which PHP it ran gets the strict reading, because then there is
	 * nothing to compare against.
	 *
	 * @param array  $findings Findings from `scan()`.
	 * @param string $version  PHP version they were judged against.
	 *
	 * @return array Refusals, then what the source was already carrying, which only warns.
	 */
	protected function split( array $findings, $version ) {
		$source  = $this->short( $this->source_version() );
		$newer   = $this->newer_than_source( $version );
		$refused = array();
		$carried = array();

		foreach ( $findings as $finding ) {
			if ( 'parse' === $finding['kind'] ) {
				if ( $newer ) {
					$refused[] = $finding;
				} else {
					$carried[] = $finding;
				}

				continue;
			}

			$gone = (string) $finding['removed_in'];

			if ( '' !== $source && '' !== $gone && \version_compare( $gone, $source, '<=' ) ) {
				$carried[] = $finding;

				continue;
			}

			$refused[] = $finding;
		}

		return array( $refused, $carried );
	}

	/**
	 * Every removal the package's code would hit on this version.
	 *
	 * @param string $version PHP version to judge against.
	 *
	 * @return array|null Findings, or null when the package's code could not be read.
	 */
	public function scan( $version ) {
		if ( ! ZipReader::available() ) {
			return null;
		}

		$parts = $this->code_parts();

		if ( empty( $parts ) ) {
			return array();
		}

		$findings       = array();
		$budget         = static::MAX_BYTES;
		$opened         = 0;
		$this->complete = true;
		$this->fixable  = array();

		foreach ( $parts as $relative ) {
			$path = $this->dir . DIRECTORY_SEPARATOR . $relative;

			if ( ! \is_readable( $path ) ) {
				continue;
			}

			try {
				$zip = ZipReader::open( $path );
			} catch ( \RuntimeException $e ) {
				continue;
			}

			++$opened;

			$entries = $zip->count();

			for ( $i = 0; $i < $entries; $i++ ) {
				$name = $zip->name( $i );

				// Skipped before it is read, so tests and fixtures cost neither time nor read budget.
				if ( false === $name || ! $this->is_php( $name ) || $this->incidental( $name ) ) {
					continue;
				}

				// Stopping here used to return whatever had been found so far, and an empty list read
				// as a pass for code nobody had looked at.
				if ( $zip->size( $i ) > $budget ) {
					$this->complete = false;
					break 2;
				}

				$source = $zip->contents( $i );

				if ( false === $source ) {
					continue;
				}

				$budget -= \strlen( $source );
				$found   = $this->inspect( $source, $version, $name );

				foreach ( $found as $finding ) {
					$finding['file'] = $name;
					$findings[]      = $finding;
				}

				// Tried here, while the source is in hand, and only for a file with something wrong
				// with it. Only the PHP running this can check a fix, as only it can check a parse.
				if ( ! empty( $found ) && $this->judges_this_php( $version ) ) {
					$fixed = ( new SyntaxFixer() )->fix( $source );

					if ( null !== $fixed ) {
						$this->fixable[ $name ] = \count( $fixed['changes'] );
					}
				}
			}

			$zip->close();
		}

		return 0 === $opened ? null : $findings;
	}

	/**
	 * One file's worth of removals.
	 *
	 * @param string $source  PHP source.
	 * @param string $version PHP version to judge against.
	 * @param string $name    Entry name, which decides whether the file is parsed.
	 *
	 * @return array
	 */
	public function inspect( $source, $version, $name = '' ) {
		if ( $this->incidental( $name ) ) {
			return array();
		}

		// Only the PHP running this can say what it will parse, so a parse check is made only when
		// that is the version being judged -- which it always is outside the tests. Tokenising
		// every file costs little next to reading it: 27,839 files, 131MB, in under three seconds.
		if ( $this->judges_this_php( $version ) ) {
			try {
				// Silenced because a file that parses can still raise deprecations while it does.
				$tokens = @\token_get_all( $source, TOKEN_PARSE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} catch ( \Throwable $e ) {
				// `\Throwable`, not `\ParseError`: one uncaught error from one strange file would take
				// down the whole review screen.
				return array(
					array(
						'kind'       => 'parse',
						'symbol'     => $e->getMessage(),
						'line'       => (int) $e->getLine(),
						'removed_in' => '',
					),
				);
			}
		} else {
			$tokens = @\token_get_all( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( empty( $tokens ) ) {
			return array();
		}

		return \array_merge(
			$this->removed_calls( $tokens, $version ),
			$this->php4_constructors( $tokens, $version ),
			$this->removed_syntax( $tokens, $version )
		);
	}

	/**
	 * Calls to functions this version no longer has.
	 *
	 * @param array  $tokens  Token stream.
	 * @param string $version PHP version to judge against.
	 *
	 * @return array
	 */
	protected function removed_calls( array $tokens, $version ) {
		$findings  = array();
		$count     = \count( $tokens );
		$guarded   = $this->guarded_names( $tokens );
		$qualified = \defined( 'T_NAME_FULLY_QUALIFIED' ) ? \constant( 'T_NAME_FULLY_QUALIFIED' ) : -1;

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! \is_array( $token ) || ( T_STRING !== $token[0] && $qualified !== $token[0] ) ) {
				continue;
			}

			// PHP 8 reads `\create_function` as one name rather than a separator and a name.
			$name = \strtolower( \ltrim( $token[1], '\\' ) );

			if ( ! isset( self::$removed[ $name ] ) || isset( $guarded[ $name ] ) ) {
				continue;
			}

			if ( ! $this->removed_by( self::$removed[ $name ], $version ) ) {
				continue;
			}

			// A method, a property, a declaration or a class constant of the same name is not a
			// call to the function -- `$this->each()` and `function each()` are somebody's own
			// code and keep working.
			$before = $this->meaningful( $tokens, $i, -1 );

			if ( \is_array( $before ) && \in_array( $before[0], $this->not_a_call(), true ) ) {
				continue;
			}

			// And a bare mention that is not followed by `(` is a constant or a string key.
			if ( '(' !== $this->meaningful( $tokens, $i, 1 ) ) {
				continue;
			}

			$findings[] = array(
				'kind'       => 'removed',
				'symbol'     => \ltrim( $token[1], '\\' ) . '()',
				'line'       => (int) $token[2],
				'removed_in' => self::$removed[ $name ],
			);
		}

		return $findings;
	}

	/**
	 * Functions the file asks about before calling.
	 *
	 * `function_exists( 'get_magic_quotes_gpc' ) && get_magic_quotes_gpc()` is how code that still
	 * supports an old PHP calls something a newer one removed, and on the newer one it never runs.
	 * MetaSlider bundles exactly that line, and the check refused a site running it on PHP 8
	 * without trouble. Matched per file rather than per call, because the guard and the call are
	 * often in different methods, and a file that asks whether a function exists knows it may not.
	 *
	 * @param array $tokens Token stream.
	 *
	 * @return array Lowercased names as keys.
	 */
	protected function guarded_names( array $tokens ) {
		$names     = array();
		$count     = \count( $tokens );
		$qualified = \defined( 'T_NAME_FULLY_QUALIFIED' ) ? \constant( 'T_NAME_FULLY_QUALIFIED' ) : -1;

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! \is_array( $token ) || ( T_STRING !== $token[0] && $qualified !== $token[0] ) ) {
				continue;
			}

			if ( ! \in_array( \strtolower( \ltrim( $token[1], '\\' ) ), array( 'function_exists', 'is_callable' ), true ) ) {
				continue;
			}

			$open = $this->meaningful_index( $tokens, $i );

			if ( null === $open || '(' !== $tokens[ $open ] ) {
				continue;
			}

			$argument = $this->meaningful_index( $tokens, $open );

			if ( null !== $argument && \is_array( $tokens[ $argument ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $argument ][0] ) {
				$names[ \strtolower( \trim( $tokens[ $argument ][1], '\'"\\' ) ) ] = true;
			}
		}

		return $names;
	}

	/**
	 * Methods named after their class, which stopped being constructors in PHP 8.
	 *
	 * The failure this catches does not name itself: the class simply inherits its parent's
	 * constructor, so the error arrives as "Too few arguments to WP_Widget::__construct()" from
	 * inside WordPress, pointing at core rather than at the theme that caused it.
	 *
	 * @param array  $tokens  Token stream.
	 * @param string $version PHP version to judge against.
	 *
	 * @return array
	 */
	protected function php4_constructors( array $tokens, $version ) {
		if ( ! $this->removed_by( self::CONSTRUCTOR_REMOVED, $version ) ) {
			return array();
		}

		$count = \count( $tokens );

		// A same-named method inside a namespaced class was never a constructor, in any version,
		// so a namespaced file has nothing here to find.
		for ( $i = 0; $i < $count; $i++ ) {
			if ( \is_array( $tokens[ $i ] ) && T_NAMESPACE === $tokens[ $i ][0] ) {
				return array();
			}
		}

		$findings    = array();
		$candidates  = array();
		$constructed = false;
		$class       = '';
		$depth       = 0;
		$opened      = -1;

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( '{' === $token ) {
				++$depth;
				continue;
			}

			if ( '}' === $token ) {
				--$depth;

				if ( '' !== $class && $depth <= $opened ) {
					if ( ! $constructed ) {
						$findings = \array_merge( $findings, $candidates );
					}

					$class  = '';
					$opened = -1;
				}

				continue;
			}

			if ( \is_array( $token ) && T_CURLY_OPEN === $token[0] ) {
				++$depth;
				continue;
			}

			if ( ! \is_array( $token ) ) {
				continue;
			}

			if ( T_CLASS === $token[0] ) {
				$name = $this->meaningful( $tokens, $i, 1 );

				// `new class {` is anonymous and has no name to collide with.
				if ( \is_array( $name ) && T_STRING === $name[0] ) {
					$class       = $name[1];
					$opened      = $depth;
					$candidates  = array();
					$constructed = false;
				}

				continue;
			}

			if ( T_FUNCTION !== $token[0] || '' === $class ) {
				continue;
			}

			$name = $this->meaningful( $tokens, $i, 1 );

			if ( ! \is_array( $name ) || T_STRING !== $name[0] ) {
				continue;
			}

			// A class that also declares `__construct()` has used that one since PHP 5, and its
			// same-named method is an ordinary method that keeps working. Core's `rss.php` keeps
			// `MagpieRSS::MagpieRSS()` beside a `__construct()` for exactly that reason.
			if ( '__construct' === \strtolower( $name[1] ) ) {
				$constructed = true;
				continue;
			}

			if ( \strtolower( $name[1] ) !== \strtolower( $class ) ) {
				continue;
			}

			$candidates[] = array(
				'kind'       => 'removed',
				'symbol'     => \sprintf( '%s::%s() as a constructor', $class, $name[1] ),
				'line'       => (int) $name[2],
				'removed_in' => self::CONSTRUCTOR_REMOVED,
			);
		}

		if ( '' !== $class && ! $constructed ) {
			$findings = \array_merge( $findings, $candidates );
		}

		return $findings;
	}

	/**
	 * The next or previous token that is not whitespace or a comment.
	 *
	 * @param array $tokens    Token stream.
	 * @param int   $index     Position to start from.
	 * @param int   $direction 1 forward, -1 back.
	 *
	 * @return array|string|null
	 */
	protected function meaningful( array $tokens, $index, $direction ) {
		$skip  = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );
		$count = \count( $tokens );

		for ( $i = $index + $direction; $i >= 0 && $i < $count; $i += $direction ) {
			$token = $tokens[ $i ];

			if ( \is_array( $token ) && \in_array( $token[0], $skip, true ) ) {
				continue;
			}

			return $token;
		}

		return null;
	}

	/**
	 * Token types that mean the name before them is not a function call.
	 *
	 * @return array
	 */
	protected function not_a_call() {
		$types = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST );

		// PHP 8.0's `?->`. Named rather than used directly so this still parses on 7.4.
		if ( \defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
			$types[] = T_NULLSAFE_OBJECT_OPERATOR;
		}

		return $types;
	}

	/**
	 * PHP 8.0 removals that parse and fail only when compiled.
	 *
	 * `TOKEN_PARSE` accepts both of these, and `php -l` refuses both, so without this the parse
	 * check would pass a file that fatals the moment it is included.
	 *
	 * @param array  $tokens  Token stream.
	 * @param string $version PHP version to judge against.
	 *
	 * @return array
	 */
	protected function removed_syntax( array $tokens, $version ) {
		if ( ! $this->removed_by( '8.0', $version ) ) {
			return array();
		}

		$findings = array();

		if ( \defined( 'T_UNSET_CAST' ) ) {
			foreach ( $tokens as $token ) {
				if ( \is_array( $token ) && T_UNSET_CAST === $token[0] ) {
					$findings[] = array(
						'kind'       => 'removed',
						'symbol'     => 'the (unset) cast',
						'line'       => (int) $token[2],
						'removed_in' => '8.0',
					);
				}
			}
		}

		return \array_merge( $findings, $this->nested_ternaries( $tokens ) );
	}

	/**
	 * Ternaries nested on the left without parentheses: `a ? b : c ? d : e`.
	 *
	 * PHP 8.0 refuses a ternary that follows a finished one in the same expression, and the
	 * mixed short forms `a ?: b ? c : d` and `a ? b : c ?: d`. A chain of short ones, `a ?: b ?: c`,
	 * and a ternary nested in the middle operand are both still fine. Parentheses, brackets and
	 * braces start a new expression, and so does anything that binds more loosely than a
	 * ternary -- an assignment, `and`, `print`, a comma, a statement's end. A `?` straight after
	 * `(`, `,`, `:` or a modifier is a nullable type, not a ternary.
	 *
	 * Checked against PHP's own compiler on labelled cases and on 27,839 files from real plugins,
	 * where it agrees with `php -l` everywhere.
	 *
	 * @param array $tokens Token stream.
	 *
	 * @return array
	 */
	protected function nested_ternaries( array $tokens ) {
		$findings = array();

		foreach ( $this->ternary_sites( $tokens ) as $site ) {
			$findings[] = array(
				'kind'       => 'removed',
				'symbol'     => 'a nested ternary without parentheses',
				'line'       => $site['line'],
				'removed_in' => '8.0',
			);
		}

		return $findings;
	}

	/**
	 * Where each unparenthesised nested ternary is, and where the expression before it starts.
	 *
	 * The detector and `SyntaxFixer` share this, so a fix can only ever parenthesise exactly what
	 * the check refused. `start` is the first token of the chain's left operand and `before` the
	 * last token ahead of the offending `?`, and wrapping that span is the grouping PHP 7 applied:
	 * the ternary associated to the left. The start is the first token after whatever binds more
	 * loosely than a ternary at the same depth, after an `if` or `while` condition, or after a
	 * block. Where that is wrong the wrapped file does not parse, and `SyntaxFixer` checks.
	 *
	 * @param array $tokens Token stream from `token_get_all()`.
	 *
	 * @return array Each with `start`, `before`, `question` and `line`.
	 */
	public function ternary_sites( array $tokens ) {
		// Keyed for `isset()`: this runs once per token of every file in the package.
		$skip     = \array_fill_keys( array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
		$opens    = \array_fill_keys( array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true );
		$markers  = \array_fill_keys( array( '(', ',', ':', T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_STATIC, T_CONST ), true );
		$controls = \array_fill_keys( array( T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_DECLARE ), true );
		$offsets  = \array_fill_keys( array( T_VARIABLE, '$', T_OBJECT_OPERATOR, T_DOUBLE_COLON, ']', '}' ), true );
		$ends     = array(
			T_ELSE,
			T_INCLUDE,
			T_INCLUDE_ONCE,
			T_REQUIRE,
			T_REQUIRE_ONCE,
			T_THROW,
			';',
			',',
			'=',
			T_DOUBLE_ARROW,
			T_OPEN_TAG,
			T_OPEN_TAG_WITH_ECHO,
			T_CLOSE_TAG,
			T_INLINE_HTML,
			T_LOGICAL_AND,
			T_LOGICAL_OR,
			T_LOGICAL_XOR,
			T_PRINT,
			T_ECHO,
			T_RETURN,
			T_CASE,
			T_YIELD,
			T_YIELD_FROM,
			T_PLUS_EQUAL,
			T_MINUS_EQUAL,
			T_MUL_EQUAL,
			T_DIV_EQUAL,
			T_CONCAT_EQUAL,
			T_MOD_EQUAL,
			T_AND_EQUAL,
			T_OR_EQUAL,
			T_XOR_EQUAL,
			T_SL_EQUAL,
			T_SR_EQUAL,
			T_POW_EQUAL,
			T_COALESCE_EQUAL,
		);
		$ends     = \array_fill_keys( $ends, true );

		// Named rather than used directly so this still parses on 7.4.
		if ( \defined( 'T_ATTRIBUTE' ) ) {
			$opens[ \constant( 'T_ATTRIBUTE' ) ] = true;
		}

		if ( \defined( 'T_READONLY' ) ) {
			$markers[ \constant( 'T_READONLY' ) ] = true;
		}

		$fresh    = array(
			'pending' => 0,
			'done'    => false,
			'short'   => true,
			'start'   => null,
			'control' => false,
		);
		$levels   = array( $fresh );
		$sites    = array();
		$previous = null;
		$last     = null;
		$line     = 1;
		$count    = \count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			$type  = \is_array( $token ) ? $token[0] : $token;

			if ( \is_array( $token ) ) {
				$line = (int) $token[2] + \substr_count( $token[1], "\n" );
			}

			if ( isset( $skip[ $type ] ) ) {
				continue;
			}

			$top    = \count( $levels ) - 1;
			$before = \is_array( $previous ) ? $previous[0] : (string) $previous;

			if ( isset( $opens[ $type ] ) ) {
				if ( null === $levels[ $top ]['start'] ) {
					$levels[ $top ]['start'] = $i;
				}

				$child            = $fresh;
				$child['control'] = ( '(' === $type && isset( $controls[ $before ] ) )
					|| ( '{' === $type && ! isset( $offsets[ $before ] ) );
				$levels[]         = $child;
			} elseif ( ')' === $type || ']' === $type || '}' === $type ) {
				// The expression around a closing bracket carries on past it, as in
				// `$a ? function () {} : $b ? 1 : 2`, which PHP refuses. Only a closed block or a
				// control structure's condition, outside any ternary, begins a new statement --
				// which moves where a fix starts, never what is found. A `{` after a variable, an
				// arrow or an index is an offset or a dynamic name, not a block.
				if ( $top > 0 ) {
					$closed = \array_pop( $levels );
					$parent = $top - 1;

					if ( $closed['control'] && 0 === $levels[ $parent ]['pending'] && ! $levels[ $parent ]['done'] ) {
						$levels[ $parent ]['start'] = null;
					}
				}
			} elseif ( isset( $ends[ $type ] ) || ( ':' === $type && 0 === $levels[ $top ]['pending'] ) ) {
				// A `:` that closes no ternary is a label, a `case`, a return type or a named
				// argument, and a new expression follows it.
				$levels[ $top ] = $fresh;
			} elseif ( '?' === $type ) {
				if ( ! isset( $markers[ $before ] ) ) {
					$next  = $this->meaningful_index( $tokens, $i );
					$short = null !== $next && ':' === $tokens[ $next ];
					$state = $levels[ $top ];

					if ( 0 === $state['pending'] && $state['done'] && ! ( $state['short'] && $short ) ) {
						$sites[] = array(
							'start'    => $state['start'],
							'before'   => $last,
							'question' => $i,
							'line'     => $line,
						);

						// One site per chain, and the chain still starts where it did.
						$state          = $fresh;
						$state['start'] = $sites[ \count( $sites ) - 1 ]['start'];
					}

					if ( $short ) {
						// `?:` finishes as it starts. Inside another ternary's middle operand it
						// finishes nothing at this level.
						if ( 0 === $state['pending'] ) {
							$state['short'] = $state['done'] ? $state['short'] : true;
							$state['done']  = true;
						}

						$i = $next;
					} else {
						++$state['pending'];
					}

					$levels[ $top ] = $state;
				}
			} elseif ( ':' === $type ) {
				// Only a `:` that closes a ternary gets here.
				--$levels[ $top ]['pending'];

				if ( 0 === $levels[ $top ]['pending'] ) {
					$levels[ $top ]['done']  = true;
					$levels[ $top ]['short'] = false;
				}
			} elseif ( null === $levels[ $top ]['start'] ) {
				$levels[ $top ]['start'] = $i;
			}

			// `$i` may have moved past a `?:`, so both are read back rather than kept from above.
			$previous = $tokens[ $i ];
			$last     = $i;
		}

		return $sites;
	}

	/**
	 * Position of the next token that is not whitespace or a comment.
	 *
	 * @param array $tokens Token stream.
	 * @param int   $index  Position to start after.
	 *
	 * @return int|null
	 */
	protected function meaningful_index( array $tokens, $index ) {
		$skip  = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );
		$count = \count( $tokens );

		for ( $i = $index + 1; $i < $count; $i++ ) {
			if ( ! \is_array( $tokens[ $i ] ) || ! \in_array( $tokens[ $i ][0], $skip, true ) ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Whether the version being judged is the one running this code.
	 *
	 * @param string $version PHP version to judge against.
	 *
	 * @return bool
	 */
	protected function judges_this_php( $version ) {
		return $this->short( $version ) === $this->short( PHP_VERSION );
	}

	/**
	 * Whether a file sits somewhere nothing loads it from.
	 *
	 * Measured, not guessed: judged as PHP 8.5, a real site's plugins had 813 files that did not
	 * parse and 126 removals, and every one of them sat in `tests/` inside a vendored
	 * PHP_CodeSniffer. Outside such directories the same 27,839 files produced nothing at all.
	 *
	 * @param string $name Entry name.
	 *
	 * @return bool
	 */
	protected function incidental( $name ) {
		return (bool) \preg_match( '#(^|/)(tests?|fixtures?|stubs?|examples?)/#i', \str_replace( '\\', '/', (string) $name ) );
	}

	/**
	 * The PHP version the package was exported under.
	 *
	 * @return string Empty when the manifest does not say.
	 */
	protected function source_version() {
		if ( null === $this->manifest ) {
			return '';
		}

		$version = (string) $this->manifest->get( 'source.php_version', '' );

		return '' !== $version ? $version : (string) $this->manifest->get( 'profile.php.version', '' );
	}

	/**
	 * Whether this PHP is newer than the source's, so a file that does not parse here is new.
	 *
	 * A source that did not record its version counts as older, which refuses: a check that
	 * cannot tell is not a check that passed.
	 *
	 * @param string $version PHP version being judged.
	 *
	 * @return bool
	 */
	protected function newer_than_source( $version ) {
		$source = $this->source_version();

		return '' === $source || \version_compare( $this->short( $version ), $this->short( $source ), '>' );
	}

	/**
	 * Whether a removal has already happened by the version given.
	 *
	 * @param string $removed_in Version that removed it.
	 * @param string $version    Version being judged.
	 *
	 * @return bool
	 */
	protected function removed_by( $removed_in, $version ) {
		return \version_compare( $this->short( $version ), $removed_in, '>=' );
	}

	/**
	 * A version reduced to major.minor.
	 *
	 * @param string $version Full version string.
	 *
	 * @return string
	 */
	protected function short( $version ) {
		$parts = \explode( '.', (string) $version );

		return isset( $parts[1] ) ? $parts[0] . '.' . $parts[1] : (string) $version;
	}

	/**
	 * Findings as lines somebody can act on.
	 *
	 * @param array  $findings Findings.
	 * @param string $version  PHP version they were judged against.
	 *
	 * @return array
	 */
	protected function describe( array $findings, $version ) {
		$lines = array();

		foreach ( \array_slice( $findings, 0, self::MAX_REPORTED ) as $finding ) {
			if ( 'parse' === $finding['kind'] ) {
				$lines[] = \sprintf(
					'%s:%d does not parse on PHP %s: %s',
					$finding['file'],
					$finding['line'],
					$this->short( $version ),
					$finding['symbol']
				);

				continue;
			}

			$lines[] = \sprintf(
				'%s:%d uses %s, removed in PHP %s.',
				$finding['file'],
				$finding['line'],
				$finding['symbol'],
				$finding['removed_in']
			);
		}

		$extra = \count( $findings ) - \count( $lines );

		if ( $extra > 0 ) {
			$lines[] = \sprintf( 'and %d more.', $extra );
		}

		return $lines;
	}

	/**
	 * Whether an entry is PHP this server would ever execute.
	 *
	 * @param string $name Entry name.
	 *
	 * @return bool
	 */
	protected function is_php( $name ) {
		return (bool) \preg_match( '/\.(php|php[57]|phtml|inc)$/i', (string) $name );
	}

	/**
	 * The parts that hold code.
	 *
	 * `uploads` is skipped because it is almost all of a package's size and none of its code --
	 * and a PHP file in `uploads` is not loaded by WordPress, so it cannot cause the failure
	 * this exists to prevent.
	 *
	 * @return array Relative paths of part files.
	 */
	protected function code_parts() {
		$parts = array();

		if ( null === $this->manifest ) {
			return $parts;
		}

		foreach ( (array) $this->manifest->get( 'parts', array() ) as $part ) {
			$file = isset( $part['file'] ) ? (string) $part['file'] : '';

			if ( '' === $file ) {
				continue;
			}

			$name = \basename( $file );

			if ( 0 === \strpos( $name, 'uploads' ) ) {
				continue;
			}

			$parts[] = $file;
		}

		return $parts;
	}
}
