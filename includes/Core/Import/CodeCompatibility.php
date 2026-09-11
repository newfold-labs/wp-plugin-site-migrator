<?php
/**
 * What the package's own code will not survive on this server.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

use NewfoldLabs\WP\SiteMigrator\Core\Package\Manifest;
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
 */
class CodeCompatibility {

	/**
	 * Largest amount of PHP to read out of one package.
	 *
	 * A preview is a screen somebody is waiting on, not a linter. The parts that hold code are
	 * small next to the uploads this skips entirely, so in practice nothing reaches this -- it
	 * is here so that a hostile or strange package cannot make the review screen hang.
	 */
	const MAX_BYTES = 67108864;

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
	 *
	 * @return void
	 */
	public function check( Report $report, $version = '' ) {
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

		if ( empty( $findings ) ) {
			$report->pass( 'php_code', \sprintf( 'The package\'s code uses nothing PHP %s has removed.', $this->short( $version ) ) );

			return;
		}

		$report->block(
			'php_code',
			\sprintf(
				'The package contains code that PHP %s cannot run.',
				$this->short( $version )
			),
			array(
				'missing' => $this->describe( $findings ),
				'detail'  => 'These are removals, not deprecations: the site would fatal on every request after the switch, '
					. 'including the screen that offers to undo it. Fix them on the source and export again, or move to a '
					. 'server running the PHP version the site came from.',
				'fix'     => 'Each one is a small edit in the file named.',
			)
		);
	}

	/**
	 * Every removal the package's code would hit on this version.
	 *
	 * @param string $version PHP version to judge against.
	 *
	 * @return array|null Findings, or null when the package's code could not be read.
	 */
	public function scan( $version ) {
		if ( ! \class_exists( '\\ZipArchive' ) ) {
			return null;
		}

		$parts = $this->code_parts();

		if ( empty( $parts ) ) {
			return array();
		}

		$findings = array();
		$budget   = self::MAX_BYTES;
		$opened   = 0;

		foreach ( $parts as $relative ) {
			$path = $this->dir . DIRECTORY_SEPARATOR . $relative;

			if ( ! \is_readable( $path ) ) {
				continue;
			}

			$zip = new \ZipArchive();

			if ( true !== $zip->open( $path ) ) {
				continue;
			}

			++$opened;

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive's own property.
			$entries = (int) $zip->numFiles;

			for ( $i = 0; $i < $entries; $i++ ) {
				$entry = $zip->statIndex( $i );

				if ( false === $entry || ! $this->is_php( $entry['name'] ) ) {
					continue;
				}

				if ( $entry['size'] > $budget ) {
					break 2;
				}

				$source = $zip->getFromIndex( $i );

				if ( false === $source ) {
					continue;
				}

				$budget -= \strlen( $source );

				foreach ( $this->inspect( $source, $version ) as $finding ) {
					$finding['file'] = $entry['name'];
					$findings[]      = $finding;
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
	 *
	 * @return array
	 */
	public function inspect( $source, $version ) {
		// The cheap half. Tokenising every PHP file in a package is the expensive part, and a
		// file mentioning none of these names cannot contain a call to one -- a tokeniser would
		// only confirm that at a hundred times the cost. The class-name check below needs the
		// same prefilter, and `class` is what it has.
		if ( false === \stripos( $source, 'class' ) && ! $this->mentions_any( $source ) ) {
			return array();
		}

		$tokens = @\token_get_all( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( empty( $tokens ) ) {
			return array();
		}

		return \array_merge(
			$this->removed_calls( $tokens, $version ),
			$this->php4_constructors( $tokens, $version )
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
		$findings = array();
		$count    = \count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! \is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}

			$name = \strtolower( $token[1] );

			if ( ! isset( self::$removed[ $name ] ) ) {
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
				'symbol'     => $token[1] . '()',
				'line'       => (int) $token[2],
				'removed_in' => self::$removed[ $name ],
			);
		}

		return $findings;
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

		$findings = array();
		$class    = '';
		$depth    = 0;
		$opened   = -1;

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( '{' === $token ) {
				++$depth;
				continue;
			}

			if ( '}' === $token ) {
				--$depth;

				if ( '' !== $class && $depth <= $opened ) {
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
					$class  = $name[1];
					$opened = $depth;
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

			if ( \strtolower( $name[1] ) !== \strtolower( $class ) ) {
				continue;
			}

			$findings[] = array(
				'symbol'     => \sprintf( '%s::%s() as a constructor', $class, $name[1] ),
				'line'       => (int) $name[2],
				'removed_in' => self::CONSTRUCTOR_REMOVED,
			);
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
	 * Whether the source mentions any removed name at all.
	 *
	 * @param string $source PHP source.
	 *
	 * @return bool
	 */
	protected function mentions_any( $source ) {
		foreach ( \array_keys( self::$removed ) as $name ) {
			if ( false !== \stripos( $source, $name ) ) {
				return true;
			}
		}

		return false;
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
	 * @param array $findings Findings.
	 *
	 * @return array
	 */
	protected function describe( array $findings ) {
		$lines = array();

		foreach ( \array_slice( $findings, 0, self::MAX_REPORTED ) as $finding ) {
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
