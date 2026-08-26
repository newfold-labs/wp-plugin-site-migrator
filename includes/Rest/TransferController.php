<?php
/**
 * Direct transfer routes, source side.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Rest;

use NewfoldLabs\WP\SiteMigrator\Core\Transfer\Offer;
use NewfoldLabs\WP\SiteMigrator\Core\Transfer\TransferKey;

/**
 * How a destination reads this site's package without a human carrying it.
 *
 * Two of these routes are public, which is true of nothing else in the plugin except
 * `/pairing/profile`, and they hand over rather more than a profile. What guards them is
 * `TransferKey`: minted only by an administrator standing on this site, hashed at rest, bound to
 * the first caller that uses it, expiring on idleness, and revocable from the screen that issued
 * it. A request that fails any of that gets the same 404 WordPress gives for a route that does
 * not exist — a 401 would confirm to a scanner that a site holds a package worth attacking.
 *
 * The file route serves only what the manifest names, which is narrower than the package
 * directory the authenticated download route works within.
 */
class TransferController extends Controller {

	/**
	 * Bytes handed over by the request in flight, for `streamed()`.
	 *
	 * @var bool
	 */
	protected $metered = false;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$admin = array( $this, 'check_permission' );
		$keyed = array( $this, 'check_transfer_key' );

		\register_rest_route(
			$this->namespace,
			'/transfer/key',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'issue' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke' ),
					'permission_callback' => $admin,
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/transfer/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'status' ),
					'permission_callback' => $admin,
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/transfer/package',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'package' ),
					'permission_callback' => $keyed,
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/transfer/file',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'file' ),
					'permission_callback' => $keyed,
					'args'                => array(
						'file' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Accept a request bearing a live transfer key.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_transfer_key( $request ) {
		$key = (string) $request->get_header( TransferKey::HEADER );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! TransferKey::verify( $key, $ip, (string) $request->get_header( 'X-NFD-SM-From' ) ) ) {
			return $this->not_found();
		}

		$this->metered = true;

		return true;
	}

	/**
	 * Mint a key and show it once.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function issue() {
		$offer = new Offer();

		if ( ! $offer->is_ready() ) {
			return new \WP_Error(
				'nfd_sm_no_package',
				'There is no finished package here to send yet.',
				array( 'status' => 400 )
			);
		}

		$key = TransferKey::issue();

		return \rest_ensure_response(
			\array_merge(
				array(
					'ok'       => true,
					// The only time this is ever returned. `status` reports on the key without
					// being able to reproduce it, because only its hash is kept.
					'key'      => $key,
					'site_url' => \get_site_url(),
				),
				TransferKey::status()
			)
		);
	}

	/**
	 * Withdraw the key.
	 *
	 * @return \WP_REST_Response
	 */
	public function revoke() {
		TransferKey::revoke();

		return \rest_ensure_response(
			\array_merge( array( 'ok' => true ), TransferKey::status() )
		);
	}

	/**
	 * What the source's screen shows about a transfer in progress.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		$offer = new Offer();

		return \rest_ensure_response(
			\array_merge(
				TransferKey::status(),
				array(
					'ready'    => $offer->is_ready(),
					'bytes'    => $offer->is_ready() ? (int) \nfd_sm_data_get( $offer->summary(), 'bytes', 0 ) : 0,
					'site_url' => \get_site_url(),
				)
			)
		);
	}

	/**
	 * The list of files a destination has to fetch.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function package() {
		$offer = new Offer();

		if ( ! $offer->is_ready() ) {
			// Not `not_found()`: the key was good, so the caller has earned a real answer. This
			// is the source saying "come back when the export has finished", which is a thing
			// the destination's screen can usefully say out loud.
			return new \WP_Error(
				'nfd_sm_no_package',
				'The source has no finished package yet.',
				array( 'status' => 409 )
			);
		}

		return \rest_ensure_response(
			\array_merge(
				array( 'site_url' => \get_site_url() ),
				$offer->summary()
			)
		);
	}

	/**
	 * Stream one file of the package to the destination.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_Error|void
	 */
	public function file( $request ) {
		$offer = new Offer();
		$path  = $offer->resolve( (string) $request->get_param( 'file' ) );

		if ( '' === $path ) {
			return $this->not_found();
		}

		$this->stream( $path, $request->get_header( 'range' ) );
	}

	/**
	 * Count what actually went out, so the source can show the transfer moving.
	 *
	 * @param int $bytes Bytes written to the client.
	 *
	 * @return void
	 */
	protected function streamed( $bytes ) {
		if ( $this->metered ) {
			TransferKey::sent( $bytes );
		}
	}
}
