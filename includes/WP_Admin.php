<?php

namespace NewfoldLabs\WP\SiteMigrator;

/**
 * Register admin menu, assets and other functionality WordPress.
 */
final class WP_Admin {

	/**
	 * Identifier for page and assets.
	 *
	 * @var string
	 */
	public static $slug = 'nfd-site-migrator';

	/**
	 * Tap WordPress Hooks
	 *
	 * @return void
	 */
	public function __construct() {
		\add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		\add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register the admin menu for site migrator
	 */
	public static function register_admin_menu() {
		\add_menu_page(
			__( 'Site Migrator', 'nfd-site-migrator' ),
			__( 'Site Migrator', 'nfd-site-migrator' ),
			'manage_options',
			'nfd-site-migrator',
			array( __CLASS__, 'render_page' ),
			'dashicons-migrate'
		);
	}

	/**
	 * Register built assets with WordPress
	 */
	public static function register_assets() {
		$asset_file = NFD_SM_PLUGIN_BUILD_DIR . '/nfd-site-migrator.asset.php';

		if ( is_readable( $asset_file ) ) {
			$asset = include_once $asset_file;

			\wp_register_script(
				self::$slug,
				\nfd_sm_plugin_url( 'build/nfd-site-migrator.js' ),
				array_merge( $asset['dependencies'], array() ),
				$asset['version'],
				true
			);

			\wp_register_style(
				self::$slug,
				\nfd_sm_plugin_url( 'build/nfd-site-migrator.css' ),
				array(),
				$asset['version']
			);

			// Two REST roots, deliberately.
			//
			// `rest_url()` returns the pretty form, `/wp-json/…`, whenever the site has a
			// permalink structure. That is the right URL right up until an import replaces the
			// options table with the source site's — at which point the permalink structure is
			// the source's, and if the two sites differed, `/wp-json/` stops resolving and
			// starts serving the home page instead. The tab driving the import is then talking
			// to an address that no longer exists, one stage past the point of no return.
			//
			// `?rest_route=` is the form WordPress falls back to when there is no permalink
			// structure at all, and it works whatever the setting is. Anything that has to keep
			// working across the swap uses it.
			\wp_localize_script(
				self::$slug,
				'nfdSiteMigrator',
				array(
					'restUrl'      => \esc_url_raw( \rest_url( 'nfd-site-migrator/v1/' ) ),
					'restRouteUrl' => \esc_url_raw(
						\untrailingslashit( \home_url() ) . '/?rest_route=/nfd-site-migrator/v1/'
					),
					'nonce'        => \wp_create_nonce( 'wp_rest' ),
					// Shown in the masthead. The same number the manifest stamps into a
					// package, so what a user reads on screen is what a destination reads
					// out of the package they hand it.
					'version'      => \defined( 'NFD_SM_VERSION' ) ? NFD_SM_VERSION : '',
				)
			);

			\wp_enqueue_style( self::$slug );
			\wp_enqueue_script( self::$slug );
		}
	}

	/**
	 * Render DOM element for React SPA mount.
	 *
	 * @return void
	 */
	public static function render_page() {
		echo PHP_EOL;
		echo '<!-- NFD:SITE:MIGRATOR -->';
		echo PHP_EOL;
		echo '<div id="nfd-sm-app" class="nfd-sm"></div>';
		echo PHP_EOL;
		echo '<!-- /NFD:SITE:MIGRATOR -->';
		echo PHP_EOL;
	}
}
