<?php
/**
 * Direct transfer routes, source side.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Rest;

use NewfoldLabs\WP\SiteMigrator\Core\Transfer\Link;
use NewfoldLabs\WP\SiteMigrator\Core\Transfer\Offer;
use NewfoldLabs\WP\SiteMigrator\Core\Transfer\TransferKey;

/**
 * How a destination reads this site's package without a human carrying it.
 *
 * Four of these routes are public, which is true of nothing else in the plugin except
 * `/pairing/profile`, and two of them hand over rather more than a profile. What guards those is
 * `TransferKey`: minted only by an administrator standing on this site, hashed at rest, bound to
 * the first caller that uses it, expiring on idleness, and revocable from the screen that issued
 * it. A request that fails any of that gets the same 404 WordPress gives for a route that does
 * not exist — a 401 would confirm to a scanner that a site holds a package worth attacking.
 *
 * The file route serves only what the manifest names, which is narrower than the package
 * directory the authenticated download route works within.
 *
 * The other two are `/transfer/handoff`, guarded by `Link` instead. It is narrower again: a valid
 * token answers 404 unless an administrator on this site has offered the package, and even then a
 * `GET` returns only a description. The `POST` mints the transfer key and can be taken once per
 * offer, which is the whole of what the token is for — removing the second copy-paste, without
 * ever storing a key that could be collected by whoever asks first.
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
			'/transfer/link',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'link_status' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'link_offer' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'link_withdraw' ),
					'permission_callback' => $admin,
				),
			)
		);

		// The third public route, and the narrowest. It answers one question — is a package
		// being offered to the site holding this token — and a 404 to everybody else, including
		// a perfectly good token at a moment when nothing is on offer.
		\register_rest_route(
			$this->namespace,
			'/transfer/handoff',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handoff' ),
					'permission_callback' => array( $this, 'check_link' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'hand_over' ),
					'permission_callback' => array( $this, 'check_link' ),
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
	 * Accept a request bearing this site's link token.
	 *
	 * The token alone is not enough: it is refused whenever nothing is on offer, so a link that
	 * leaked cannot be used to find out whether this site has a package, let alone read one. And
	 * the refusal is the same 404 an unknown caller gets, for the reason every other public route
	 * here gives.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_link( $request ) {
		if ( ! Link::verify( (string) $request->get_header( 'x-nfd-sm-link' ) ) ) {
			return $this->not_found();
		}

		return true;
	}

	/**
	 * Whether a package is on offer, and what it is.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handoff() {
		$offer = new Offer();

		if ( ! Link::is_offered() || ! $offer->is_ready() ) {
			return $this->not_found();
		}

		$summary = $offer->summary();

		return \rest_ensure_response(
			array(
				'offered'    => true,
				'site_url'   => \get_site_url(),
				// The file list is the transfer's own business and is fetched with a key. What
				// the destination needs before it agrees to anything is how big this is, when it
				// was made, and what the source chose to leave out of it.
				'bytes'      => (int) \nfd_sm_data_get( $summary, 'bytes', 0 ),
				'count'      => \count( (array) \nfd_sm_data_get( $summary, 'files', array() ) ),
				'created_at' => (string) \nfd_sm_data_get( $summary, 'created_at', '' ),
				'contents'   => $offer->contents(),
			)
		);
	}

	/**
	 * Hand the transfer key to the linked destination, once.
	 *
	 * **The key is minted here, at the moment it is claimed.** An offer is a flag, not a stored
	 * secret, so this site still keeps nothing but a hash of a key it has issued — which is the
	 * promise `TransferKey` makes and the reason the offer flow does not quietly break it.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function hand_over( $request ) {
		$offer = new Offer();

		if ( ! $offer->is_ready() ) {
			return $this->not_found();
		}

		if ( ! Link::claim( (string) $request->get_header( 'x-nfd-sm-from' ) ) ) {
			return $this->not_found();
		}

		return \rest_ensure_response(
			array(
				'key'      => TransferKey::issue(),
				'site_url' => \get_site_url(),
			)
		);
	}

	/**
	 * What this site knows about the destination it paired with.
	 *
	 * @return \WP_REST_Response
	 */
	public function link_status() {
		return \rest_ensure_response( Link::status() );
	}

	/**
	 * Offer the finished package to the linked destination.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function link_offer() {
		$offer = new Offer();

		if ( ! $offer->is_ready() ) {
			return new \WP_Error(
				'nfd_sm_no_package',
				'There is no finished package here to offer yet.',
				array( 'status' => 400 )
			);
		}

		if ( ! Link::offer() ) {
			return new \WP_Error(
				'nfd_sm_not_linked',
				'This site is not linked to a destination. Pair with one, or show a key to paste by hand.',
				array( 'status' => 409 )
			);
		}

		return \rest_ensure_response( \array_merge( array( 'ok' => true ), Link::status() ) );
	}

	/**
	 * Take the offer back.
	 *
	 * The key, if one has already been claimed, is a separate thing and is withdrawn separately —
	 * a transfer under way is not stopped by changing your mind about offering it.
	 *
	 * @return \WP_REST_Response
	 */
	public function link_withdraw() {
		Link::withdraw();

		return \rest_ensure_response( \array_merge( array( 'ok' => true ), Link::status() ) );
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
