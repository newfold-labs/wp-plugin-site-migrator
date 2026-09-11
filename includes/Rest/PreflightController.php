<?php
/**
 * Preflight and compatibility routes.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Rest;

use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Checker;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Compatibility;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Destination;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Pairing;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\SiteProfile;
use NewfoldLabs\WP\SiteMigrator\Core\Transfer\Link;

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

		\register_rest_route(
			$this->namespace,
			'/preflight/destination',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_destination' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'forget_destination' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Separate from `/preflight/destination` on purpose. That one is read on every arrival
		// at the compatibility screen and has to be instant; this one crosses the network to
		// another server, so it is what the button does, not what the page load does.
		\register_rest_route(
			$this->namespace,
			'/preflight/destination/reach',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'reach_destination' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * The destination this site is already paired with, compared afresh.
	 *
	 * The comparison is recomputed rather than stored: it is a statement about two sites, and
	 * one of them is this one, which the user may well have just changed in order to fix
	 * whatever was blocking.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_destination() {
		$saved = Destination::load();

		if ( null === $saved ) {
			return \rest_ensure_response( array( 'saved' => false ) );
		}

		$profile = new SiteProfile( $saved['profile'] );

		if ( $profile->is_stale() ) {
			return \rest_ensure_response(
				array(
					'saved' => false,
					'stale' => true,
					'url'   => $saved['url'],
				)
			);
		}

		$response = $this->comparison( $profile, $saved['via'] );

		return \rest_ensure_response(
			\array_merge(
				$response,
				array(
					'saved'      => true,
					'url'        => $saved['url'],
					'fetched_at' => (int) $saved['fetched_at'],
				)
			)
		);
	}

	/**
	 * Is the paired destination still there?
	 *
	 * The comparison this screen shows is recomputed from facts the destination reported once,
	 * and re-reading those facts needs a fresh pairing code. Nothing in that round trip touches
	 * the network, so a destination that has moved, expired a certificate or been taken down
	 * produced a clean verdict and no hint that anything was wrong.
	 *
	 * `Pairing::reach()` says only whether anything answered — see its note on how little a 200
	 * or a 404 proves — which is why this endpoint reports and does not decide. An unreachable
	 * destination does not block packaging: a package can be downloaded and carried by hand, and
	 * it is the *direct transfer* that needs one site to be able to open a connection to the
	 * other.
	 *
	 * @return \WP_REST_Response
	 */
	public function reach_destination() {
		$saved = Destination::load();

		if ( null === $saved ) {
			return \rest_ensure_response( array( 'saved' => false ) );
		}

		return \rest_ensure_response(
			\array_merge(
				array(
					'saved' => true,
					'url'   => $saved['url'],
				),
				Pairing::reach( $saved['url'] )
			)
		);
	}

	/**
	 * Forget the paired destination, so the next pairing starts clean.
	 *
	 * @return \WP_REST_Response
	 */
	public function forget_destination() {
		Destination::forget();

		// The link is a credential for that destination and nothing else. Keeping it past the
		// site it belongs to would leave an Offer button pointing at a pairing the user has just
		// said they are done with.
		Link::revoke();

		return \rest_ensure_response( array( 'saved' => false ) );
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
		// Remembered on the way past, so stepping back to this screen does not cost the user
		// another trip to the other site for a code that only lives fifteen minutes.
		Destination::save( $destination, $destination->get( 'site_url', '' ), $via );

		return \rest_ensure_response( $this->comparison( $destination, $via ) );
	}

	/**
	 * Compare this site against a destination profile.
	 *
	 * @param SiteProfile $destination Destination profile.
	 * @param string      $via         How the profile was obtained.
	 *
	 * @return array
	 */
	protected function comparison( SiteProfile $destination, $via ) {
		$source     = SiteProfile::gather( true );
		$comparison = new Compatibility( $source, $destination );
		$report     = $comparison->check();

		return array(
			'ok'          => true,
			'via'         => $via,
			'destination' => array(
				'site_url'   => $destination->get( 'site_url', '' ),
				'wp_version' => $destination->get( 'wp.version', '' ),
				'php'        => $destination->get( 'php.version', '' ),
				'free_bytes' => $destination->get( 'host.free_bytes', null ),
				'minted_at'  => $destination->get( 'minted_at', 0 ),
			),
			'report'      => $report->to_array(),
		);
	}
}
