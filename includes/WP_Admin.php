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
			__( 'Site Migrator', 'nfd_site_migrator' ),
			__( 'Site Migrator', 'nfd_site_migrator' ),
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
				NFD_SM_PLUGIN_BUILD_URL . '/nfd-site-migrator.js',
				array_merge( $asset['dependencies'], array() ),
				$asset['version'],
				true
			);

			\wp_register_style(
				self::$slug,
				NFD_SM_PLUGIN_BUILD_URL . '/nfd-site-migrator.css',
				array(),
				$asset['version']
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
