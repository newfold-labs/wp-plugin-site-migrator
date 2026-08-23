<?php
/**
 * Shared REST controller behaviour.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Rest;

/**
 * Base for this plugin's controllers.
 */
abstract class Controller extends \WP_REST_Controller {

	/**
	 * The namespace of this controller's routes.
	 *
	 * @var string
	 */
	protected $namespace = 'nfd-site-migrator/v1';

	/**
	 * Only administrators.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permission() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden_context',
				\__( 'Sorry, you are not allowed to access this endpoint.', 'nfd-site-migrator' ),
				array( 'status' => \rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * A 404 shaped exactly like WordPress's own "no such route".
	 *
	 * Used where confirming that an endpoint exists would itself leak something. A 401 would
	 * tell a scanner the plugin is installed and worth probing; this does not.
	 *
	 * @return \WP_Error
	 */
	protected function not_found() {
		return new \WP_Error(
			'rest_no_route',
			\__( 'No route was found matching the URL and request method.', 'nfd-site-migrator' ),
			array( 'status' => 404 )
		);
	}
}
