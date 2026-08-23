<?php
/**
 * REST route registration.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Rest;

/**
 * Registers every controller.
 */
class Routes {

	/**
	 * Controllers to register.
	 *
	 * @var array
	 */
	protected static $controllers = array(
		'NewfoldLabs\\WP\\SiteMigrator\\Rest\\PreflightController',
		'NewfoldLabs\\WP\\SiteMigrator\\Rest\\PairingController',
		'NewfoldLabs\\WP\\SiteMigrator\\Rest\\ExportController',
	);

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function register() {
		\add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the routes of every controller.
	 *
	 * @return void
	 */
	public static function register_routes() {
		foreach ( self::$controllers as $controller ) {
			$instance = new $controller();
			$instance->register_routes();
		}
	}
}
