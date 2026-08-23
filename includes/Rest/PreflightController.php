<?php
/**
 * Preflight and compatibility routes.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Rest;

use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Checker;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Compatibility;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Pairing;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\SiteProfile;

/**
 * Can this site export, and can that site receive it?
 */
class PreflightController extends Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		\register_rest_route(
			$this->namespace,
			'/preflight',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_report' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/preflight/compare',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'compare' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'url'     => array( 'type' => 'string' ),
						'code'    => array( 'type' => 'string' ),
						'profile' => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * Local checks on this install.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_report() {
		$report = Checker::run();

		return \rest_ensure_response(
			array(
				'report'  => $report->to_array(),
				'profile' => SiteProfile::gather( true )->to_array(),
			)
		);
	}

	/**
	 * Compare this site with a destination.
	 *
	 * Takes either a pairing code to fetch live facts, or a pasted profile when the destination
	 * cannot be reached.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function compare( $request ) {
		$blob = (string) $request->get_param( 'profile' );

		if ( '' !== $blob ) {
			$destination = SiteProfile::decode( $blob );

			if ( null === $destination ) {
				return \rest_ensure_response(
					array(
						'ok'    => false,
						'error' => 'That does not look like a profile from this plugin. Copy the whole code, including the NFDSM1- prefix.',
					)
				);
			}

			if ( $destination->is_stale() ) {
				return \rest_ensure_response(
					array(
						'ok'    => false,
						'error' => 'That profile is more than a week old. Generate a fresh one on the destination.',
					)
				);
			}

			return $this->respond( $destination, 'pasted' );
		}

		$result = Pairing::fetch_profile( (string) $request->get_param( 'url' ), (string) $request->get_param( 'code' ) );

		if ( isset( $result['error'] ) ) {
			return \rest_ensure_response(
				array(
					'ok'          => false,
					'error'       => $result['error'],
					'unreachable' => ! empty( $result['unreachable'] ),
				)
			);
		}

		return $this->respond( $result['profile'], 'paired' );
	}

	/**
	 * Build the comparison response.
	 *
	 * @param SiteProfile $destination Destination profile.
	 * @param string      $via         How the profile was obtained.
	 *
	 * @return \WP_REST_Response
	 */
	protected function respond( SiteProfile $destination, $via ) {
		$source     = SiteProfile::gather( true );
		$comparison = new Compatibility( $source, $destination );
		$report     = $comparison->check();

		return \rest_ensure_response(
			array(
				'ok'          => true,
				'via'         => $via,
				'destination' => array(
					'site_url'   => $destination->get( 'site_url', '' ),
					'wp_version' => $destination->get( 'wordpress.version', '' ),
					'php'        => $destination->get( 'php.version', '' ),
					'free_bytes' => $destination->get( 'host.free_bytes', null ),
					'minted_at'  => $destination->get( 'minted_at', 0 ),
				),
				'report'      => $report->to_array(),
			)
		);
	}
}
