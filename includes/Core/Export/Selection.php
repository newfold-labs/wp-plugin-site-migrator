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
	 * Options that may never travel as rows, whatever a scan of a plugin's code turned up.
	 *
	 * This is the `$required_tables` of the options table, and it exists for a sharper reason. A
	 * plugin's settings are found by reading the names it passes to `get_option()` -- and plugins
	 * read core's options constantly, so a scan of almost any plugin comes back naming `home`,
	 * `siteurl`, `template` or `active_plugins`. Carrying one of those into a destination that is
	 * keeping its own site would move that site's address, its theme, or the list of plugins it
	 * loads, out of a package that promised to bring a plugin's settings.
	 *
	 * Enforced on both sides: refused here when the selection is saved, and refused again by
	 * `OptionMerger` when a package asks for one, because a package is input this site did not
	 * write.
	 *
	 * @var array
	 */
	protected static $protected_options = array(
		'siteurl',
		'home',
		'blogname',
		'blogdescription',
		'admin_email',
		'new_admin_email',
		'users_can_register',
		'default_role',
		'template',
		'stylesheet',
		'current_theme',
		'theme_switched',
		'active_plugins',
		'recently_activated',
		'permalink_structure',
		'rewrite_rules',
		'db_version',
		'initial_db_version',
		'upload_path',
		'upload_url_path',
		'cron',
		'wp_user_roles',
		'sidebars_widgets',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'blog_charset',
		'wplang',
		'timezone_string',
		'gmt_offset',
		'site_icon',
		'nfd_site_migrator',
	);

	/**
	 * The rest of what WordPress itself puts in the options table.
	 *
	 * The list above is the dangerous half -- carrying one of those moves the destination's
	 * address or its theme. This is the other half, and it is refused for a different reason: a
	 * setting is not a plugin's just because the plugin read it. Contact Form 7 asks for
	 * `date_format` to print a date and Yoast asks for `category_base` to build a URL, and a scan
	 * that credits them with owning those names offers to carry the destination's date format and
	 * permalink bases away under the heading of a plugin's own data. Nothing breaks, which is what
	 * makes it worse: the destination quietly starts formatting its dates like another site.
	 *
	 * Taken from core's `populate_options()`, which is the definition of "core put this here",
	 * plus the ones core writes from elsewhere.
	 *
	 * @var array
	 */
	protected static $core_options = array(
		'admin_email_lifespan',
		'adminhash',
		'auto_plugin_theme_update_emails',
		'auto_update_core_dev',
		'auto_update_core_major',
		'auto_update_core_minor',
		'avatar_default',
		'avatar_rating',
		'blog_public',
		'can_compress_scripts',
		'category_base',
		'close_comments_days_old',
		'close_comments_for_old_posts',
		'comment_max_links',
		'comment_moderation',
		'comment_order',
		'comment_previously_approved',
		'comment_registration',
		'comments_notify',
		'comments_per_page',
		'date_format',
		'default_category',
		'default_comment_status',
		'default_comments_page',
		'default_email_category',
		'default_link_category',
		'default_ping_status',
		'default_pingback_flag',
		'default_post_format',
		'disallowed_keys',
		'finished_splitting_shared_terms',
		'fresh_site',
		'hack_file',
		'html_type',
		'https_detection_errors',
		'https_migration_required',
		'image_default_align',
		'image_default_link_type',
		'image_default_size',
		'large_size_h',
		'large_size_w',
		'link_manager_enabled',
		'links_updated_date_format',
		'mailserver_login',
		'mailserver_pass',
		'mailserver_port',
		'mailserver_url',
		'medium_large_size_h',
		'medium_large_size_w',
		'medium_size_h',
		'medium_size_w',
		'moderation_keys',
		'moderation_notify',
		'nav_menu_options',
		'page_comments',
		'ping_sites',
		'posts_per_page',
		'posts_per_rss',
		'recently_edited',
		'recovery_keys',
		'require_name_email',
		'rss_use_excerpt',
		'show_avatars',
		'show_comments_cookies_opt_in',
		'site_logo',
		'start_of_week',
		'sticky_posts',
		'tag_base',
		'thread_comments',
		'thread_comments_depth',
		'thumbnail_crop',
		'thumbnail_size_h',
		'thumbnail_size_w',
		'time_format',
		'uninstall_plugins',
		'uploads_use_yearmonth_folders',
		'use_balancetags',
		'use_smilies',
		'use_trackback',
		'user_count',
		'widget_block',
		'widget_categories',
		'widget_rss',
		'widget_text',
		'wp_attachment_pages_enabled',
		'wp_calendar_block_has_published_posts',
		'wp_force_deactivated_plugins',
		'wp_notes_notify',
		'wp_page_for_privacy_policy',
	);

	/**
	 * The database filters this understands, and their defaults.
	 *
	 * @var array
	 */
	protected static $database_flags = array(
		'skip_database'   => false,
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

		// The other direction, and only meaningful with `skip_database`: a package that carries no
		// database can still carry a plugin's own tables and its settings, which is what makes
		// "just the plugins" mean the plugins rather than their empty shells. Core's tables are
		// refused here for the reason they may not be skipped there -- `wp_options` arriving whole
		// would replace the destination's own settings, its permalinks and its active plugins,
		// which is the opposite of keeping its site. Its *rows* travel through `carry_options`.
		$carry = array();

		foreach ( (array) \nfd_sm_data_get( $raw, 'carry_tables', array() ) as $table ) {
			$table = \preg_replace( '/[^A-Za-z0-9_$\-]/', '', (string) $table );

			if ( '' !== $table && ! self::is_required_table( $table ) ) {
				$carry[] = $table;
			}
		}

		if ( ! empty( $carry ) ) {
			$database['carry_tables'] = \array_values( \array_unique( $carry ) );
		}

		$options = array();

		foreach ( (array) \nfd_sm_data_get( $raw, 'carry_options', array() ) as $option ) {
			$option = \preg_replace( '/[^A-Za-z0-9_\-.:\/]/', '', (string) $option );

			if ( '' !== $option && ! self::is_protected_option( $option ) ) {
				$options[] = $option;
			}
		}

		if ( ! empty( $options ) ) {
			$database['carry_options'] = \array_values( \array_unique( $options ) );
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
		return \in_array( \strtolower( self::unprefixed( $table ) ), self::$required_tables, true );
	}

	/**
	 * Whether an option belongs to the destination rather than to a plugin.
	 *
	 * Transients go too: they are a cache with a clock on it, and a cached value from another site
	 * is the one kind of stale that looks like data.
	 *
	 * @param string $name Option name.
	 *
	 * @return bool
	 */
	public static function is_protected_option( $name ) {
		$name = \strtolower( \trim( (string) $name ) );

		if ( '' === $name ) {
			return true;
		}

		if ( 0 === \strpos( $name, '_transient_' ) || 0 === \strpos( $name, '_site_transient_' ) ) {
			return true;
		}

		if ( \defined( 'NFD_SM_OPTIONS_LIST' ) && \in_array( $name, (array) NFD_SM_OPTIONS_LIST, true ) ) {
			return true;
		}

		return \in_array( $name, self::$protected_options, true )
			|| \in_array( $name, self::$core_options, true );
	}

	/**
	 * The options that may never travel, for a screen that wants to say so.
	 *
	 * @return array
	 */
	public static function protected_options() {
		return \array_merge( self::$protected_options, self::$core_options );
	}

	/**
	 * A table name with this site's prefix taken off, if it is wearing one.
	 *
	 * The one place that decides `wp_posts` and `posts` are the same answer. A name that does not
	 * start with the prefix is returned untouched — on a site prefixed `wp7_` that is what keeps
	 * another install's `wp_posts` a skippable extra rather than a core table.
	 *
	 * @param string $table Table name, with or without the prefix.
	 *
	 * @return string
	 */
	protected static function unprefixed( $table ) {
		$prefix = \nfd_sm_table_prefix();
		$table  = (string) $table;

		if ( '' !== $prefix && 0 === \strpos( $table, $prefix ) ) {
			return \substr( $table, \strlen( $prefix ) );
		}

		return $table;
	}

	/**
	 * Whether the dump leaves this table out.
	 *
	 * Both sides are compared with the prefix off, for the reason `is_required_table()` does it:
	 * somebody naming a table means the same table whether they type `acme_log` or `wp_acme_log`,
	 * and the two surfaces disagree about which they send. The picker lists what `SHOW TABLE
	 * STATUS` returned, so it sends the prefixed form; a person writing `--set` by hand reads the
	 * prefix off their own site and usually does not. Matching on the raw string honoured the
	 * first and silently ignored the second, which is a refusal that packages the table anyway.
	 *
	 * Normalising here rather than in `sanitize()` is deliberate. A selection is stored, and it
	 * travels in the manifest for the destination to describe — so baking *this* site's prefix
	 * into it would write a source's `wp_` into data a `wp7_` destination reads back.
	 *
	 * @param string $table Table name, with or without the prefix.
	 *
	 * @return bool
	 */
	public function skips_table( $table ) {
		$needle = self::unprefixed( $table );

		if ( '' === $needle ) {
			return false;
		}

		foreach ( $this->skipped_tables() as $skipped ) {
			if ( self::unprefixed( $skipped ) === $needle ) {
				return true;
			}
		}

		return false;
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
	 * Whether the package carries a database at all.
	 *
	 * The twelve tables in `$required_tables` may never be skipped one at a time, because a
	 * package holding some of core's tables and not others describes a site that cannot boot. That
	 * rule protects against a *half* database, which is exactly the thing this flag does not
	 * produce: refusing the database refuses all of it, and what arrives is code with no data —
	 * plugins and themes for a destination that keeps its own site. So the two settings are not in
	 * conflict, and the required list is simply not consulted when there is no dump to put them in.
	 *
	 * @return bool
	 */
	public function wants_database() {
		return ! $this->skips( 'skip_database' );
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
	 * The tables a code-only package carries anyway.
	 *
	 * Empty unless the database was refused: with a dump on its way every table is in it, and a
	 * list of tables to carry would be a list of tables that are already coming.
	 *
	 * @return array
	 */
	public function carried_tables() {
		return $this->wants_database()
			? array()
			: (array) \nfd_sm_data_get( $this->database, 'carry_tables', array() );
	}

	/**
	 * The option rows a code-only package carries anyway.
	 *
	 * Rows, not the table: a plugin's settings can travel without its settings *table* travelling,
	 * and the difference is whether the destination keeps being itself.
	 *
	 * @return array
	 */
	public function carried_options() {
		return $this->wants_database()
			? array()
			: (array) \nfd_sm_data_get( $this->database, 'carry_options', array() );
	}

	/**
	 * Whether this selection produces a dump that merges rather than replaces.
	 *
	 * @return bool
	 */
	public function is_partial_database() {
		return ! $this->wants_database()
			&& ( ! empty( $this->carried_tables() ) || ! empty( $this->carried_options() ) );
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

		// First, and phrased as the whole thing rather than as one more omission: a package with
		// no database is a different kind of package, and a destination reading this list has to
		// see that before it reads which parts of a site it is not getting.
		if ( ! $this->wants_database() ) {
			$carried  = \count( $this->carried_tables() );
			$settings = \count( $this->carried_options() );

			if ( $carried < 1 && $settings < 1 ) {
				$said[] = 'the database — this package carries code only';
			} else {
				$said[] = \sprintf(
					'the database, apart from %d table(s) and %d setting(s) belonging to what was chosen',
					$carried,
					$settings
				);
			}
		}

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
