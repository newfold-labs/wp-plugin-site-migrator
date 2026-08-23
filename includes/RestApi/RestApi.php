<?php

namespace NewfoldLabs\WP\SiteMigrator\RestApi;

/**
 * Initialize them APIs
 */
class RestApi {
	/**
	 * Constructor
	 */
	public function __construct() {
		\add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes from all classes.
	 */
	public function register_routes() {
		$controllers = array(
			'NewfoldLabs\\WP\\SiteMigrator\\RestApi\\MigrationCheckController',
		);

		foreach ( $controllers as $controller ) {
			$instance = new $controller();
			$instance->register_routes();
		}
	}
}
