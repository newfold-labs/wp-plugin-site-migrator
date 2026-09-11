<?php
/**
 * Pairing routes.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Rest;

use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Pairing;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\SiteProfile;
use NewfoldLabs\WP\SiteMigrator\Core\Transfer\LinkedSource;

/**
 * Issue a code here; let a paired source read this site's profile.
 */
class PairingController extends Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		\register_rest_route(
			$this->namespace,
			'/pairing/code',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'issue' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/pairing/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'status' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// The cross-site endpoint. Deliberately public, because the caller is another server
		// with no WordPress session here. Authentication is the pairing code in the header, and
		// anything without a valid one gets a 404 rather than a 401 — see below.
		\register_rest_route(
			$this->namespace,
			'/pairing/profile',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'profile' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Mint a code for the user to carry to the source.
	 *
	 * @return \WP_REST_Response
	 */
	public function issue() {
		return \rest_ensure_response(
			array(
				'code'     => Pairing::issue(),
				'site_url' => \get_site_url(),
				'expires'  => \time() + Pairing::TTL,
			)
		);
	}

	/**
	 * Forget the outstanding code.
	 *
	 * @return \WP_REST_Response
	 */
	public function revoke() {
		Pairing::revoke();

		return \rest_ensure_response( array( 'revoked' => true ) );
	}

	/**
	 * Whether a code is outstanding, and who has used it.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		return \rest_ensure_response( Pairing::status() );
	}

	/**
	 * Hand this site's profile to a correctly paired source.
	 *
	 * The response is facts about a server: versions, limits, free space. No credentials, no
	 * salts, no content. That is still worth protecting — an open endpoint on every install of
	 * this plugin would be a version-disclosure oracle, letting anyone scan for sites running
	 * software with known vulnerabilities.
	 *
	 * So a bad or missing code returns a 404 identical to WordPress's own "no such route".
	 * A 401 would confirm the endpoint, and therefore the plugin, exists.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function profile( $request ) {
		$code = (string) $request->get_header( 'x-nfd-sm-pairing' );
		$from = (string) $request->get_header( 'x-nfd-sm-from' );

		if ( '' === $code || ! Pairing::redeem( $code, $from ) ) {
			return $this->not_found();
		}

		// Kept only now, after the code has been redeemed. The token is worth nothing by itself
		// — presenting it to the source asks one question and gets a 404 until somebody there
		// offers a package — but a route that stored one for any caller would let a stranger
		// point this site at a source of their choosing, and the whole design of pairing is that
		// neither half of a migration can be started from outside.
		$linked = LinkedSource::remember( $from, (string) $request->get_header( 'x-nfd-sm-link' ) );

		return \rest_ensure_response(
			array(
				'profile' => SiteProfile::gather()->to_array(),
				// So the source knows whether it can offer the package directly or has to show a
				// key to carry. An older destination omits this, which reads as false.
				'linked'  => $linked,
			)
		);
	}
}
