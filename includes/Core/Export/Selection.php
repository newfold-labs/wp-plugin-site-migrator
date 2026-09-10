<?php
/**
 * What the user chose to put in the package.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Export;

use NewfoldLabs\WP\SiteMigrator\Utils\Options;

/**
 * The set of choices that narrow an export, and the rules about which choices are allowed.
 *
 * Everything here is an *exclusion*: an empty selection means the whole site, which is what an
 * export has always been and what it still is for anybody who never opens the Contents screen.
 * Storing the exceptions rather than the inclusions is what keeps that true — a part added to
 * `PartSpecs` in a later version is carried by every existing site's saved selection instead of
 * being silently dropped from it.
 *
 * **Paths are recorded per part, not as one path relative to the WordPress root.** Plugins,
 * themes and uploads can all be moved outside `wp-content`, and `PartSpecs` already derives each
 * part's prefix from where its directory actually is; a single root-relative string cannot
 * express "this plugin" on a site whose plugin directory lives somewhere else entirely. A part
 * name plus a path below that part's root can, and it lands directly on
 * `PartSpec::exclude_dirs()` with no interpretation in between.
 *
 * **This class is the boundary, not the screen.** The picker only ever offers safe choices, but
 * the REST route behind it takes whatever it is handed, so the refusing happens here: a path may
 * not climb out of its part, and the tables WordPress cannot run without may not be skipped at
 * all. A package missing `wp_options` is not a smaller migration, it is a broken one.
 */
class Selection {

	/**
	 * Key within the plugin option.
	 */
	const OPTION = 'selection';

	/**
	 * Tables the import needs whatever else is left behind.
	 *
	 * Names without the site's prefix. `links` is here because WordPress still creates it and
	 * `wp_installing()` checks for it; the rest are the tables core reads on every request.
	 *
	 * @var array
	 */
	protected static $required_tables = array(
		'posts',
		'postmeta',
		'options',
		'users',
		'usermeta',
		'terms',
		'termmeta',
		'term_taxonomy',
		'term_relationships',
		'comments',
		'commentmeta',
		'links',
	);

	/**
	 * The database filters this understands, and their defaults.
	 *
	 * @var array
	 */
	protected static $database_flags = array(
		'skip_revisions'  => false,
		'skip_spam'       => false,
		'skip_transients' => false,
	);

	/**
	 * Parts the user turned off, as name => false.
	 *
	 * @var array
	 */
	protected $parts;

	/**
	 * Excluded paths, as part name => list of paths below that part's root.
	 *
	 * @var array
	 */
	protected $paths;

	/**
	 * Database filters.
	 *
	 * @var array
	 */
	protected $database;

	/**
	 * Constructor. Takes raw data and keeps only what it recognises.
	 *
	 * @param array $data Selection data.
	 */
	public function __construct( array $data = array() ) {
		$clean = self::sanitize( $data );

		$this->parts    = $clean['parts'];
		$this->paths    = $clean['paths'];
		$this->database = $clean['database'];
	}

	/**
	 * The selection this site will export with.
	 *
	 * @return Selection
	 */
	public static function current() {
		return new self( (array) Options::get( self::OPTION, array() ) );
	}

	/**
	 * A selection that leaves nothing out.
	 *
	 * @return Selection
	 */
	public static function everything() {
		return new self( array() );
	}

	/**
	 * Store a selection, having cleaned it.
	 *
	 * @param array $data Raw selection.
	 *
	 * @return Selection What was actually stored.
	 */
	public static function store( array $data ) {
		$selection = new self( $data );

		if ( $selection->is_everything() ) {
			Options::delete( self::OPTION );

			return $selection;
		}

		Options::set( self::OPTION, $selection->to_array() );

		return $selection;
	}

	/**
	 * Forget the stored selection, so the next export carries everything.
	 *
	 * @return void
	 */
	public static function forget() {
		Options::delete( self::OPTION );
	}

	/**
	 * Reduce raw input to the shape this class promises.
	 *
	 * Everything unrecognised is dropped rather than corrected: a path that tries to climb out
	 * of its part is not a path with a typo in it.
	 *
	 * @param array $data Raw selection.
	 *
	 * @return array Always `parts`, `paths` and `database`.
	 */
	public static function sanitize( array $data ) {
		$parts = array();

		foreach ( (array) \nfd_sm_data_get( $data, 'parts', array() ) as $name => $wanted ) {
			$name = self::clean_segment( (string) $name );

			// Only the refusals are stored. Recording "plugins => true" would freeze today's
			// part list into the option and drop tomorrow's.
			if ( '' !== $name && ! $wanted ) {
				$parts[ $name ] = false;
			}
		}

		$paths = array();

		foreach ( (array) \nfd_sm_data_get( $data, 'paths', array() ) as $part => $list ) {
			$part = self::clean_segment( (string) $part );

			if ( '' === $part || ! \is_array( $list ) ) {
				continue;
			}

			foreach ( $list as $path ) {
				$path = self::clean_path( $path );

				if ( '' !== $path ) {
					$paths[ $part ][] = $path;
				}
			}

			if ( isset( $paths[ $part ] ) ) {
				$paths[ $part ] = \array_values( \array_unique( $paths[ $part ] ) );
			}
		}

		$database = array();
		$raw      = (array) \nfd_sm_data_get( $data, 'database', array() );

		foreach ( self::$database_flags as $flag => $unused ) {
			if ( ! empty( $raw[ $flag ] ) ) {
				$database[ $flag ] = true;
			}
		}

		$tables = array();

		foreach ( (array) \nfd_sm_data_get( $raw, 'skip_tables', array() ) as $table ) {
			$table = \preg_replace( '/[^A-Za-z0-9_$\-]/', '', (string) $table );

			if ( '' !== $table && ! self::is_required_table( $table ) ) {
				$tables[] = $table;
			}
		}

		if ( ! empty( $tables ) ) {
			$database['skip_tables'] = \array_values( \array_unique( $tables ) );
		}

		return array(
			'parts'    => $parts,
			'paths'    => $paths,
			'database' => $database,
		);
	}

	/**
	 * Whether a table has to travel whatever else is left behind.
	 *
	 * Compared without the site's prefix, because that is what makes `wp_posts` and `wp7_posts`
	 * the same answer. A table whose name does not start with the prefix at all is not a core
	 * table and is skippable.
	 *
	 * @param string $table Full table name.
	 *
	 * @return bool
	 */
	public static function is_required_table( $table ) {
		$prefix = \nfd_sm_table_prefix();
		$table  = (string) $table;

		if ( '' !== $prefix && 0 === \strpos( $table, $prefix ) ) {
			$table = \substr( $table, \strlen( $prefix ) );
		}

		return \in_array( \strtolower( $table ), self::$required_tables, true );
	}

	/**
	 * The tables that may never be skipped, for a screen that wants to grey them out.
	 *
	 * @return array
	 */
	public static function required_tables() {
		return self::$required_tables;
	}

	/**
	 * The database filters this version understands.
	 *
	 * @return array
	 */
	public static function database_flags() {
		return \array_keys( self::$database_flags );
	}

	/**
	 * Whether this selection leaves anything out at all.
	 *
	 * @return bool
	 */
	public function is_everything() {
		return empty( $this->parts ) && empty( $this->paths ) && empty( $this->database );
	}

	/**
	 * Whether a part is carried.
	 *
	 * @param string $name Part name.
	 *
	 * @return bool
	 */
	public function wants_part( $name ) {
		return ! isset( $this->parts[ (string) $name ] );
	}

	/**
	 * The part names turned off.
	 *
	 * @return array
	 */
	public function refused_parts() {
		return \array_keys( $this->parts );
	}

	/**
	 * Paths excluded within one part.
	 *
	 * @param string $name Part name.
	 *
	 * @return array Paths relative to that part's root.
	 */
	public function refused_paths( $name ) {
		$name = (string) $name;

		return isset( $this->paths[ $name ] ) ? $this->paths[ $name ] : array();
	}

	/**
	 * Every excluded path, keyed by part.
	 *
	 * @return array
	 */
	public function all_refused_paths() {
		return $this->paths;
	}

	/**
	 * How many paths are excluded in total.
	 *
	 * @return int
	 */
	public function refused_path_count() {
		$count = 0;

		foreach ( $this->paths as $list ) {
			$count += \count( $list );
		}

		return $count;
	}

	/**
	 * Tables left out of the dump.
	 *
	 * @return array
	 */
	public function skipped_tables() {
		return (array) \nfd_sm_data_get( $this->database, 'skip_tables', array() );
	}

	/**
	 * Whether a database filter is on.
	 *
	 * @param string $flag One of `database_flags()`.
	 *
	 * @return bool
	 */
	public function skips( $flag ) {
		return ! empty( $this->database[ (string) $flag ] );
	}

	/**
	 * The selection as stored.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'parts'    => $this->parts,
			'paths'    => $this->paths,
			'database' => $this->database,
		);
	}

	/**
	 * What this selection leaves out, in phrases a person can read.
	 *
	 * One implementation for three readers: the source's own screen, `wp site-migrator contents`,
	 * and the destination's review screen, which builds it from the manifest rather than from a
	 * second description written on the import side.
	 *
	 * @return array List of short phrases. Empty when nothing is left out.
	 */
	public function describe() {
		$said = array();

		foreach ( $this->refused_parts() as $part ) {
			$said[] = self::part_phrase( $part );
		}

		foreach ( $this->paths as $part => $list ) {
			$said[] = \sprintf(
				1 === \count( $list ) ? '1 item from %2$s (%3$s)' : '%1$d items from %2$s (%3$s)',
				\count( $list ),
				$part,
				\implode( ', ', \array_slice( $list, 0, 5 ) )
			);
		}

		if ( $this->skips( 'skip_revisions' ) ) {
			$said[] = 'post revisions';
		}

		if ( $this->skips( 'skip_spam' ) ) {
			$said[] = 'spam and trashed comments';
		}

		if ( $this->skips( 'skip_transients' ) ) {
			$said[] = 'cached transients';
		}

		$tables = $this->skipped_tables();

		if ( ! empty( $tables ) ) {
			$said[] = \sprintf(
				1 === \count( $tables ) ? '1 database table (%2$s)' : '%1$d database tables (%2$s)',
				\count( $tables ),
				\implode( ', ', \array_slice( $tables, 0, 5 ) )
			);
		}

		return $said;
	}

	/**
	 * A part name, said the way a user thinks of it.
	 *
	 * @param string $part Part name.
	 *
	 * @return string
	 */
	protected static function part_phrase( $part ) {
		$known = array(
			'plugins'       => 'plugins',
			'mu-plugins'    => 'must-use plugins',
			'themes'        => 'themes',
			'uploads'       => 'uploads (the media library)',
			'dropins'       => 'wp-content drop-ins',
			'content-other' => 'the rest of wp-content',
			'root-extras'   => 'top-level files such as .htaccess and robots.txt',
		);

		return isset( $known[ $part ] ) ? $known[ $part ] : $part;
	}

	/**
	 * A single name that may not contain a separator.
	 *
	 * @param string $value Raw.
	 *
	 * @return string Empty when it is not usable.
	 */
	protected static function clean_segment( $value ) {
		$value = \trim( (string) $value );

		if ( '' === $value || false !== \strpos( $value, '/' ) || false !== \strpos( $value, '\\' ) ) {
			return '';
		}

		return '.' === $value || '..' === $value ? '' : $value;
	}

	/**
	 * A path below a part's root, or nothing.
	 *
	 * Rejected outright rather than repaired: `..` anywhere, an absolute path, and a Windows
	 * drive letter. What survives is a plain relative path with forward slashes.
	 *
	 * @param mixed $value Raw path.
	 *
	 * @return string Empty when it is not usable.
	 */
	protected static function clean_path( $value ) {
		$path = \trim( \str_replace( '\\', '/', (string) $value ) );
		$path = \trim( $path, '/' );

		if ( '' === $path || false !== \strpos( $path, ':' ) ) {
			return '';
		}

		foreach ( \explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return '';
			}
		}

		return $path;
	}
}
