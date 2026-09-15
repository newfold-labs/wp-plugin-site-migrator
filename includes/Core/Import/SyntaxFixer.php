<?php
/**
 * Exact rewrites for syntax PHP 8 removed.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

/**
 * Rewrites the three PHP 8 removals that have an exact replacement, and nothing else.
 *
 * `$str{0}` always meant `$str[0]`, `(real)` always meant `(float)`, and `a ? b : c ? d : e` always
 * meant `(a ? b : c) ? d : e`, because the ternary associated to the left on every PHP that accepted
 * it. Each is a change of spelling rather than of meaning, and that is what keeps `create_function()`,
 * `each()` and PHP 4 constructors out: their replacement depends on what the code around them does,
 * and a guess there changes behaviour without a word.
 *
 * Nothing here is assumed to have worked. A rewrite is used only when the result parses on this PHP,
 * passes `CodeCompatibility` again, and differs from the original in nothing but the kind of bracket,
 * added parentheses and the spelling of a cast -- compared token by token. Otherwise the file is left
 * exactly as it came, and it still blocks the import.
 *
 * It is opt-in and runs on the destination as each file is written: the review screen offers it,
 * `import --fix-php` asks for it, and the original of every file it changes is kept in the import's
 * state directory.
 */
class SyntaxFixer {

	/**
	 * Where originals are kept, under the import state directory.
	 */
	const BACKUP_DIR = 'php-originals';

	/**
	 * Rewrite a file's source, if everything wrong with it has an exact replacement.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array|null `source` and `changes` (each a `line` and a `fix`), or null when there is
	 *                    nothing to change or the result did not check out.
	 */
	public function fix( $source ) {
		$source  = (string) $source;
		$changes = array();

		$fixed = $this->brace_offsets( $source, $changes );
		$fixed = $this->real_casts( $fixed, $changes );
		$fixed = $this->ternaries( $fixed, $changes );

		if ( empty( $changes ) ) {
			return null;
		}

		// Parsed by this PHP and checked for every removal, not only the three fixed here: a file
		// that still calls `create_function()` is no more runnable for having its braces changed.
		$checker = new CodeCompatibility( '' );

		if ( ! empty( $checker->inspect( $fixed, PHP_VERSION ) ) ) {
			return null;
		}

		if ( $this->shape( $source ) !== $this->shape( $fixed ) ) {
			return null;
		}

		return array(
			'source'  => $fixed,
			'changes' => $changes,
		);
	}

	/**
	 * Fix a file that has just been written, keeping its original.
	 *
	 * The original is saved first -- no backup, no fix -- and the fixed source goes to a temporary
	 * file beside the target and is renamed over it, so an interrupted write leaves the package's
	 * own bytes rather than half of each.
	 *
	 * The originals keep their `.php` names deliberately. Every one of them is code this PHP refused
	 * to parse or compile, so a request for one executes nothing, where under another name a server
	 * that ignores the storage directory's `.htaccess` would hand the source out as text.
	 *
	 * @param string $path Absolute path of the file on this site.
	 * @param string $name Its entry name in the package, which is where the original is kept.
	 *
	 * @return array|null `file`, `changes` and `backup`, or null when nothing was changed.
	 */
	public function apply( $path, $name ) {
		$source = \is_readable( $path ) ? \file_get_contents( $path ) : false;

		if ( false === $source ) {
			return null;
		}

		$fixed = $this->fix( $source );

		if ( null === $fixed ) {
			return null;
		}

		$backup = PathMap::safe_path( self::backup_dir(), $name );

		if ( '' === $backup || ! \wp_mkdir_p( \dirname( $backup ) ) || false === \file_put_contents( $backup, $source ) ) {
			return null;
		}

		$temporary = $path . '.nfd-sm-fixing';

		if ( false === \file_put_contents( $temporary, $fixed['source'] ) || ! \rename( $temporary, $path ) ) {
			if ( \file_exists( $temporary ) ) {
				\unlink( $temporary );
			}

			return null;
		}

		return array(
			'file'    => $name,
			'changes' => $fixed['changes'],
			'backup'  => $backup,
		);
	}

	/**
	 * Where originals are kept.
	 *
	 * @return string
	 */
	public static function backup_dir() {
		return \rtrim( ImportCheckpoint::state_dir(), '/\\' ) . DIRECTORY_SEPARATOR . self::BACKUP_DIR;
	}

	/**
	 * Remove the originals an earlier import kept.
	 *
	 * Called as the next migration begins, which is also when the previous import's backup tables
	 * go: a copy of a file from a site that is about to be replaced again has nothing left to put
	 * back. Links are removed, never followed.
	 *
	 * @return void
	 */
	public static function clear_backups() {
		$dir = self::backup_dir();

		if ( ! \is_dir( $dir ) || \is_link( $dir ) ) {
			return;
		}

		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $entries as $entry ) {
			if ( $entry->isDir() && ! $entry->isLink() ) {
				\rmdir( $entry->getPathname() );
			} else {
				\unlink( $entry->getPathname() );
			}
		}

		\rmdir( $dir );
	}

	/**
	 * `$str{0}` to `$str[0]`.
	 *
	 * A `{` is an offset only straight after a variable, an index, another offset, or a property
	 * name after `->`. Everywhere else in valid code it opens a block, and a block follows `)`, a
	 * keyword or `;` -- never one of those. Inside a string the braces are part of the string's own
	 * tokens and are not touched, except inside `{$...}`, where an offset is an offset.
	 *
	 * @param string $source  PHP source.
	 * @param array  $changes Changes made, appended to.
	 *
	 * @return string
	 */
	protected function brace_offsets( $source, array &$changes ) {
		$tokens = @\token_get_all( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$skip   = \array_fill_keys( array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
		$arrows = array( T_OBJECT_OPERATOR => true );

		// Named rather than used directly so this still parses on 7.4.
		if ( \defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
			$arrows[ \constant( 'T_NULLSAFE_OBJECT_OPERATOR' ) ] = true;
		}

		$braces       = array();
		$replace      = array();
		$previous     = null;
		$earlier      = null;
		$after_offset = false;
		$line         = 1;

		foreach ( $tokens as $i => $token ) {
			$type = \is_array( $token ) ? $token[0] : $token;

			if ( \is_array( $token ) ) {
				$line = (int) $token[2] + \substr_count( $token[1], "\n" );
			}

			if ( isset( $skip[ $type ] ) ) {
				continue;
			}

			$closed_offset = false;

			if ( '{' === $type ) {
				$offset = ( T_VARIABLE === $previous && '$' !== $earlier )
					|| ']' === $previous
					|| ( '}' === $previous && $after_offset )
					|| ( T_STRING === $previous && isset( $arrows[ $earlier ] ) );

				$braces[] = $offset;

				if ( $offset ) {
					$replace[ $i ] = '[';
					$changes[]     = array(
						'line' => $line,
						'fix'  => 'a {} string offset written as []',
					);
				}
			} elseif ( T_CURLY_OPEN === $type || T_DOLLAR_OPEN_CURLY_BRACES === $type ) {
				$braces[] = false;
			} elseif ( '}' === $type && true === \array_pop( $braces ) ) {
				$replace[ $i ] = ']';
				$closed_offset = true;
			}

			$after_offset = $closed_offset;
			$earlier      = $previous;
			$previous     = $type;
		}

		return empty( $replace ) ? $source : $this->rebuild( $tokens, $replace );
	}

	/**
	 * `(real)` to `(float)`, which is the same cast by its other name.
	 *
	 * @param string $source  PHP source.
	 * @param array  $changes Changes made, appended to.
	 *
	 * @return string
	 */
	protected function real_casts( $source, array &$changes ) {
		$tokens  = @\token_get_all( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$replace = array();

		foreach ( $tokens as $i => $token ) {
			if ( \is_array( $token ) && T_DOUBLE_CAST === $token[0] && \preg_match( '/^\(\s*real\s*\)$/i', $token[1] ) ) {
				$replace[ $i ] = '(float)';
				$changes[]     = array(
					'line' => (int) $token[2],
					'fix'  => 'a (real) cast written as (float)',
				);
			}
		}

		return empty( $replace ) ? $source : $this->rebuild( $tokens, $replace );
	}

	/**
	 * `a ? b : c ? d : e` to `(a ? b : c) ? d : e`, which is what PHP 7 ran.
	 *
	 * One pass. Every `?` after the first in a chain is a site sharing the chain's start, so
	 * `a ? b : c ? d : e ? f : g` opens two parentheses at `a` and closes one after `c` and one
	 * after `e`. Chains at different depths nest inside one another's brackets and never cross.
	 * Anything this misses is still there for `fix()`'s own check to refuse.
	 *
	 * @param string $source  PHP source.
	 * @param array  $changes Changes made, appended to.
	 *
	 * @return string
	 */
	protected function ternaries( $source, array &$changes ) {
		$tokens = @\token_get_all( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$sites  = ( new CodeCompatibility( '' ) )->ternary_sites( $tokens );
		$prefix = array();
		$suffix = array();

		foreach ( $sites as $site ) {
			// Nothing to wrap from. Left as it is, so the check afterwards refuses the file.
			if ( null === $site['start'] || null === $site['before'] ) {
				return $source;
			}

			$prefix[ $site['start'] ]  = ( isset( $prefix[ $site['start'] ] ) ? $prefix[ $site['start'] ] : '' ) . '(';
			$suffix[ $site['before'] ] = ( isset( $suffix[ $site['before'] ] ) ? $suffix[ $site['before'] ] : '' ) . ')';
			$changes[]                 = array(
				'line' => (int) $site['line'],
				'fix'  => 'a nested ternary given the parentheses PHP 7 applied',
			);
		}

		return empty( $sites ) ? $source : $this->rebuild( $tokens, array(), $prefix, $suffix );
	}

	/**
	 * Source from tokens, with some replaced and text added around others.
	 *
	 * @param array $tokens  Token stream.
	 * @param array $replace Replacement text by token position.
	 * @param array $prefix  Text to add before a token, by position.
	 * @param array $suffix  Text to add after a token, by position.
	 *
	 * @return string
	 */
	protected function rebuild( array $tokens, array $replace, array $prefix = array(), array $suffix = array() ) {
		$source = '';

		foreach ( $tokens as $i => $token ) {
			if ( isset( $prefix[ $i ] ) ) {
				$source .= $prefix[ $i ];
			}

			$source .= isset( $replace[ $i ] ) ? $replace[ $i ] : ( \is_array( $token ) ? $token[1] : $token );

			if ( isset( $suffix[ $i ] ) ) {
				$source .= $suffix[ $i ];
			}
		}

		return $source;
	}

	/**
	 * A file's code with the three things a fix may change taken out of it.
	 *
	 * Two sources with the same shape differ at most in parentheses, in `{}` against `[]`, and in
	 * how a float cast is spelled. Comparing shapes is what proves a rewrite changed nothing else.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array
	 */
	protected function shape( $source ) {
		$shape = array();

		foreach ( @\token_get_all( $source ) as $token ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! \is_array( $token ) ) {
				if ( '(' !== $token && ')' !== $token ) {
					$shape[] = \strtr( $token, '{}', '[]' );
				}

				continue;
			}

			if ( T_WHITESPACE === $token[0] || T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				continue;
			}

			$shape[] = T_DOUBLE_CAST === $token[0] ? 'float cast' : $token[0] . ':' . $token[1];
		}

		return $shape;
	}
}
