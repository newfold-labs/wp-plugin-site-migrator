<?php
/**
 * Enough WordPress to run `Core/` outside WordPress.
 *
 * `Core/` is transport-agnostic by design and touches WordPress only through a small, named set
 * of functions. Faking that set is far cheaper than booting WordPress, and it keeps the unit
 * suite fast enough that people actually run it — a suite needing a database and two installs is
 * a suite that gets skipped, which is how this plugin ended up with zero PHP tests in the first
 * place.
 *
 * What this cannot test is anything that depends on WordPress really being there: the REST
 * controllers, the swap, the users merge. Those need `tests/roundtrip.sh` and two real installs,
 * and no amount of stubbing substitutes for it.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

define( 'NFD_SM_TESTS', true );
define( 'ABSPATH', \sys_get_temp_dir() . '/nfd-sm-tests-abspath/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

// `$wpdb` result-format constants. WordPress defines these globally, and `SiteProfile` passes
// ARRAY_A to get_row(); without them PHP resolves the bare name inside the class's namespace.
define( 'OBJECT', 'OBJECT' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Plugin-location helpers. `constants.php` calls these while it is being included, so they have
 * to exist before it is required rather than alongside the rest of the stubs below.
 */
function plugin_dir_path( $file ) {
	return \rtrim( \dirname( $file ), '/\\' ) . '/';
}

function plugin_dir_url( $file ) {
	return 'http://source.test/wp-content/plugins/nfd-site-migrator/';
}

function plugins_url( $path = '', $plugin = '' ) {
	return 'http://source.test/wp-content/plugins/' . \ltrim( $path, '/' );
}

function plugin_basename( $file ) {
	return 'nfd-site-migrator/nfd-site-migrator.php';
}

require_once __DIR__ . '/../constants.php';

/**
 * Per-test state. `Fixture::reset()` clears it between tests, so nothing leaks from one to the
 * next — the reason options and the upload directory live here rather than in globals set once.
 */
class Fixture {

	/**
	 * Fake option store.
	 *
	 * @var array
	 */
	public static $options = array();

	/**
	 * Fake transient store.
	 *
	 * @var array
	 */
	public static $transients = array();

	/**
	 * Where `wp_get_upload_dir()` points.
	 *
	 * @var string
	 */
	public static $uploads = '';

	/**
	 * What `get_site_url()` answers.
	 *
	 * @var string
	 */
	public static $site_url = 'http://source.test';

	/**
	 * Filters registered during a test, keyed by hook.
	 *
	 * @var array
	 */
	public static $filters = array();

	/**
	 * Start a test with nothing carried over from the last one.
	 *
	 * @return string The temporary uploads directory.
	 */
	public static function reset() {
		self::$options    = array();
		self::$transients = array();
		self::$site_url   = 'http://source.test';
		self::$filters    = array();
		self::$uploads    = \sys_get_temp_dir() . '/nfd-sm-tests/' . \uniqid( 'u', true );

		\wp_mkdir_p( self::$uploads );

		return self::$uploads;
	}

	/**
	 * Remove a directory tree written during a test.
	 *
	 * @param string $dir Directory.
	 *
	 * @return void
	 */
	public static function rmdir( $dir ) {
		if ( '' === $dir || ! \is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				\rmdir( $item->getPathname() );
			} else {
				\unlink( $item->getPathname() );
			}
		}

		\rmdir( $dir );
	}
}

// --- Options -----------------------------------------------------------------------------

function get_option( $key, $default_value = false ) {
	return \array_key_exists( $key, Fixture::$options ) ? Fixture::$options[ $key ] : $default_value;
}

function update_option( $key, $value, $autoload = null ) {
	Fixture::$options[ $key ] = $value;

	return true;
}

function delete_option( $key ) {
	unset( Fixture::$options[ $key ] );

	return true;
}

function get_transient( $key ) {
	return \array_key_exists( $key, Fixture::$transients ) ? Fixture::$transients[ $key ] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	Fixture::$transients[ $key ] = $value;

	return true;
}

function delete_transient( $key ) {
	unset( Fixture::$transients[ $key ] );

	return true;
}

// --- Paths and URLs ----------------------------------------------------------------------

function wp_get_upload_dir() {
	return array( 'basedir' => Fixture::$uploads );
}

function wp_mkdir_p( $dir ) {
	return \is_dir( $dir ) || \mkdir( $dir, 0777, true );
}

function get_site_url() {
	return Fixture::$site_url;
}

function get_home_url() {
	return Fixture::$site_url;
}

function trailingslashit( $value ) {
	return \rtrim( $value, '/\\' ) . '/';
}

function untrailingslashit( $value ) {
	return \rtrim( $value, '/\\' );
}

// --- Escaping and text -------------------------------------------------------------------

function esc_url_raw( $value ) {
	return $value;
}

function esc_html( $value ) {
	return \htmlspecialchars( (string) $value, ENT_QUOTES );
}

function esc_xml( $value ) {
	return \htmlspecialchars( (string) $value, ENT_QUOTES );
}

function sanitize_text_field( $value ) {
	return \trim( \strip_tags( (string) $value ) );
}

function wp_unslash( $value ) {
	return $value;
}

function wp_json_encode( $data ) {
	return \json_encode( $data );
}

function size_format( $bytes, $decimals = 0 ) {
	return $bytes . ' B';
}

function __( $text, $domain = '' ) {
	return $text;
}

// --- Hooks -------------------------------------------------------------------------------

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	Fixture::$filters[ $hook ][] = $callback;

	return true;
}

function remove_filter( $hook, $callback, $priority = 10 ) {
	if ( ! isset( Fixture::$filters[ $hook ] ) ) {
		return false;
	}

	foreach ( Fixture::$filters[ $hook ] as $index => $registered ) {
		if ( $registered === $callback ) {
			unset( Fixture::$filters[ $hook ][ $index ] );
		}
	}

	return true;
}

function apply_filters( $hook, $value ) {
	$args = \array_slice( \func_get_args(), 2 );

	foreach ( ( isset( Fixture::$filters[ $hook ] ) ? Fixture::$filters[ $hook ] : array() ) as $callback ) {
		$value = \call_user_func_array( $callback, \array_merge( array( $value ), $args ) );
	}

	return $value;
}

function do_action( $hook ) {
	return null;
}

// --- Passwords ---------------------------------------------------------------------------

function wp_hash_password( $password ) {
	return 'hash:' . \md5( $password );
}

function wp_check_password( $password, $hash ) {
	return 'hash:' . \md5( $password ) === $hash;
}

// --- HTTP and errors ---------------------------------------------------------------------

class WP_Error {

	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * REST scaffolding. `Rest\Controller` extends `WP_REST_Controller`, and `resolve_range()` is a
 * pure static on it that the transfer and download endpoints both share -- so testing the range
 * arithmetic means the class has to be loadable, not that WordPress has to be running.
 */
class WP_REST_Controller {}

class WP_REST_Server {

	const READABLE  = 'GET';
	const CREATABLE = 'POST';
	const EDITABLE  = 'POST, PUT, PATCH';
	const DELETABLE = 'DELETE';
}

function register_rest_route() {}

function rest_ensure_response( $data ) {
	return $data;
}

function rest_authorization_required_code() {
	return 401;
}

function current_user_can( $capability ) {
	return true;
}

function status_header( $code ) {}

// --- Site facts --------------------------------------------------------------------------

function is_multisite() {
	return false;
}

function get_locale() {
	return 'en_US';
}

function get_bloginfo( $show = '' ) {
	return 'version' === $show ? '6.5' : 'Test Site';
}

/**
 * Just enough `$wpdb` to let `SiteProfile::gather()` run.
 *
 * Every query answers "nothing", which is the point: these tests are about the *shape* of what
 * comes back, not its contents. Anything that depends on real rows -- collations, table sizes, the
 * `RENAME TABLE` probe -- is the round trip's job, and stubbing it here would only prove the stub
 * works.
 */
class WPDB_Stub {

	public $prefix    = 'wp_';
	public $dbname    = 'nfd_sm_tests';
	public $users     = 'wp_users';
	public $usermeta  = 'wp_usermeta';
	public $options   = 'wp_options';
	public $posts     = 'wp_posts';
	public $postmeta  = 'wp_postmeta';

	public function prepare( $query ) {
		return $query;
	}

	public function query( $query ) {
		return 0;
	}

	public function get_col( $query, $column = 0 ) {
		return array();
	}

	public function get_var( $query ) {
		return null;
	}

	public function get_row( $query, $output = null ) {
		return null;
	}

	public function get_results( $query, $output = null ) {
		return array();
	}
}

function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return \substr( \str_repeat( 'abcdef0123456789', 8 ), 0, $length );
}

function wp_upload_dir() {
	return wp_get_upload_dir();
}


function is_ssl(  ) {
	return false;
}

/**
 * Defined so `SiteProfile::required_php()` does not try to load `wp-admin/includes/plugin.php`
 * from an ABSPATH that is not a WordPress install. No plugins means no `Requires PHP` headers,
 * which is the honest answer here.
 */
function get_plugins() {
	return array();
}

/**
 * A theme with no `Requires PHP` header, matching the empty plugin list above.
 */
class WP_Theme_Stub {

	public function get( $header ) {
		return '';
	}

	public function parent() {
		return false;
	}
}

function wp_get_theme( $stylesheet = '' ) {
	return new WP_Theme_Stub();
}

$GLOBALS['wpdb']          = new WPDB_Stub();
$GLOBALS['wp_version']    = '6.5';
$GLOBALS['wp_db_version'] = 57155;

require_once __DIR__ . '/../functions.php';
