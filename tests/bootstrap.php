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
// The directory layout `PartSpecs` reads. Real WordPress defines these in wp-settings.php; the
// unit suite needs them because the part list — and therefore what an export packages — is
// derived from where these actually point.
define( 'WP_CONTENT_DIR', \rtrim( ABSPATH, '/' ) . '/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );

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
	 * What `get_current_user_id()` answers.
	 *
	 * @var int
	 */
	public static $current_user_id = 1;

	/**
	 * Filters registered during a test, keyed by hook.
	 *
	 * @var array
	 */
	public static $filters = array();

	/**
	 * What the next `wp_remote_get()` calls return, in order.
	 *
	 * A queue rather than one value, because the calls that matter here try more than one URL:
	 * `Pairing` asks the `?rest_route=` form and then the `/wp-json/` one, and "the first
	 * failed, the second answered" is a different outcome from "both failed".
	 *
	 * @var array
	 */
	public static $http = array();

	/**
	 * Sites `get_sites()` reports, and which one is switched to.
	 *
	 * Empty means single site. A network is the case `uninstall.php` has to iterate, because
	 * `wp_get_upload_dir()` follows `switch_to_blog()` and every site has its own storage
	 * directory.
	 *
	 * @var array
	 */
	public static $sites = array();

	/**
	 * Whether `wp_is_large_network()` says yes.
	 *
	 * @var bool
	 */
	public static $large_network = false;

	/**
	 * Every blog switched to during a test, in order.
	 *
	 * @var array
	 */
	public static $switched = array();

	/**
	 * The uploads directory to come back to after a `restore_current_blog()`.
	 *
	 * @var string
	 */
	public static $network_root = '';

	/**
	 * Every `wp_remote_get()` call, as `url` and `args`.
	 *
	 * The arguments are kept because what a request does *not* carry is sometimes the point --
	 * a reachability probe that sent a pairing header would spend an attempt on the code the
	 * user is about to use.
	 *
	 * @var array
	 */
	public static $requests = array();

	/**
	 * Start a test with nothing carried over from the last one.
	 *
	 * @return string The temporary uploads directory.
	 */
	public static function reset() {
		self::$options    = array();
		self::$transients = array();
		self::$site_url   = 'http://source.test';
		self::$current_user_id = 1;
		self::$filters    = array();
		self::$http       = array();
		self::$sites         = array();
		self::$large_network = false;
		self::$switched      = array();
		self::$requests   = array();
		self::$uploads      = \sys_get_temp_dir() . '/nfd-sm-tests/' . \uniqid( 'u', true );
		self::$network_root = self::$uploads;

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

function get_current_user_id() {
	return \Fixture::$current_user_id;
}

function status_header( $code ) {}

// --- Site facts --------------------------------------------------------------------------

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
	// Real `$wpdb` has both, and `nfd_sm_table_prefix()` reads the base one. A stub missing a
	// property the code under test reads is a stub that fails as a fatal rather than as a test.
	public $base_prefix = 'wp_';
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

/**
 * The theme roots, in the shape `nfd_sm_themes_dir()` expects.
 *
 * WordPress returns one entry per theme with its root beside it, and the helper reduces that to
 * the distinct roots — so a stub returning the root directly would be testing a function that
 * does not exist.
 */
function search_theme_directories() {
	$root  = WP_CONTENT_DIR . '/themes';
	$found = array();

	if ( ! \is_dir( $root ) ) {
		return $found;
	}

	foreach ( (array) \scandir( $root ) as $name ) {
		if ( '.' === $name || '..' === $name || ! \is_dir( $root . '/' . $name ) ) {
			continue;
		}

		$found[ $name ] = array( 'theme_root' => $root );
	}

	return $found;
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

/**
 * Outbound HTTP. Answers come from `Fixture::$http`, and an empty queue is a connection failure
 * -- which makes "the destination is not there" the default a test has to opt out of rather than
 * one it has to remember to arrange.
 *
 * @param string $url  Requested URL.
 * @param array  $args Request arguments.
 *
 * @return array|WP_Error
 */
function wp_remote_get( $url, $args = array() ) {
	Fixture::$requests[] = array(
		'url'  => $url,
		'args' => $args,
	);

	if ( empty( Fixture::$http ) ) {
		return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
	}

	return \array_shift( Fixture::$http );
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_header( $response, $name ) {
	return isset( $response['headers'][ $name ] ) ? $response['headers'][ $name ] : '';
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

/**
 * Multisite. Single site unless a test says otherwise, which is what nearly all of them want.
 *
 * `switch_to_blog()` moves the uploads directory, so the stub moves `Fixture::$uploads` the same
 * way -- a per-site directory is the whole reason the uninstall has to iterate at all.
 */
function is_multisite() {
	return ! empty( Fixture::$sites );
}

/**
 * Faithful about `number`, which is the point of it.
 *
 * `WP_Site_Query` defaults to **100** and treats 0 as no limit. A stub that ignored that returned
 * every site whatever the caller asked for, so a caller relying on the default would have looked
 * correct here and silently purged the first hundred sites of a network in production.
 */
function get_sites( $args = array() ) {
	$number = isset( $args['number'] ) ? (int) $args['number'] : 100;
	$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;

	if ( 0 === $number ) {
		return \array_slice( Fixture::$sites, $offset );
	}

	return \array_slice( Fixture::$sites, $offset, $number );
}

function wp_is_large_network( $using = 'sites' ) {
	return Fixture::$large_network;
}

function switch_to_blog( $blog_id ) {
	Fixture::$switched[] = (int) $blog_id;
	Fixture::$uploads    = Fixture::$network_root . '/sites/' . (int) $blog_id;

	\wp_mkdir_p( Fixture::$uploads );

	return true;
}

function restore_current_blog() {
	Fixture::$uploads = Fixture::$network_root;

	return true;
}

$GLOBALS['wpdb']          = new WPDB_Stub();
$GLOBALS['wp_version']    = '6.5';
$GLOBALS['wp_db_version'] = 57155;

require_once __DIR__ . '/../functions.php';
