<?php
/**
 * The standing link a pairing leaves behind, destination side.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Transfer;

/**
 * Remembers the site that paired with this one, and the token it left.
 *
 * The mirror of `Link`, and the same relationship `Source` has to `TransferKey`: the site that
 * minted the secret keeps a hash, the site that has to present it keeps the secret. It is stored
 * only when a pairing code was redeemed successfully, so nothing a stranger sends can plant one.
 *
 * What it buys is the second copy-paste. Without it a destination has no way to ask the source
 * anything: pairing runs source-to-destination, so the destination knows who called but has
 * nothing to call back with. With it, the screen that used to be two empty fields can say *"this
 * site is offering a package — start"*.
 *
 * The asking is done here rather than in the REST layer because which URL shape answers is
 * something worth learning once and keeping — the same reason `Source` records its `base`. A
 * source on plain permalinks answers only `?rest_route=`, and finding that out on every poll is
 * a wasted request each time.
 */
class LinkedSource {

	/**
	 * Remember a source, having just been paired with by it.
	 *
	 * @param string $url   The source's address.
	 * @param string $token The token it sent.
	 *
	 * @return bool False when either is unusable.
	 */
	public static function remember( $url, $token ) {
		$url   = \esc_url_raw( \trim( (string) $url ) );
		$token = Link::normalise( $token );

		if ( '' === $url || '' === $token ) {
			return false;
		}

		self::save(
			array(
				'url'       => $url,
				'token'     => $token,
				'base'      => '',
				'linked_at' => \time(),
			)
		);

		return true;
	}

	/**
	 * Forget the source.
	 *
	 * @return void
	 */
	public static function forget() {
		\delete_option( NFD_SM_LINKED_SOURCE_OPTION );
	}

	/**
	 * Whether this site has a source it can ask.
	 *
	 * @return bool
	 */
	public static function exists() {
		$state = self::load();

		return ! empty( $state );
	}

	/**
	 * What a screen may see. Never the token.
	 *
	 * @return array
	 */
	public static function status() {
		$state = self::load();

		if ( empty( $state ) ) {
			return array( 'linked' => false );
		}

		return array(
			'linked'    => true,
			'url'       => $state['url'],
			'linked_at' => (int) $state['linked_at'],
		);
	}

	/**
	 * Ask the source whether it is offering a package.
	 *
	 * Costs the source nothing and hands over nothing: the answer is a description, and the
	 * credential that would let this site read the package is only minted when it is claimed.
	 *
	 * @return array `offered`, plus the summary when there is one, or `error`.
	 */
	public static function ask() {
		$state = self::load();

		if ( empty( $state ) ) {
			return array(
				'offered' => false,
				'error'   => 'This site has not been paired with a source.',
			);
		}

		$answer = self::request( $state, 'GET' );

		if ( isset( $answer['error'] ) ) {
			return \array_merge( array( 'offered' => false ), $answer );
		}

		return \array_merge(
			array( 'offered' => ! empty( $answer['body']['offered'] ) ),
			(array) $answer['body']
		);
	}

	/**
	 * Claim the offer, which is what mints the transfer key on the source.
	 *
	 * @return array `key` and `url` on success, or `error`.
	 */
	public static function claim() {
		$state = self::load();

		if ( empty( $state ) ) {
			return array( 'error' => 'This site has not been paired with a source.' );
		}

		$answer = self::request( $state, 'POST' );

		if ( isset( $answer['error'] ) ) {
			return $answer;
		}

		$key = (string) \nfd_sm_data_get( $answer['body'], 'key', '' );

		if ( '' === $key ) {
			return array( 'error' => 'The source did not hand over a key. Press Offer on it again.' );
		}

		return array(
			'key' => $key,
			'url' => $state['url'],
		);
	}

	/**
	 * Ask the source's handoff endpoint, remembering which URL shape worked.
	 *
	 * A 404 is what that endpoint gives everyone whose token it does not recognise, so it is
	 * reported as "nothing on offer" rather than as a broken site — the same reading
	 * `/pairing/profile` asks for on the other side.
	 *
	 * @param array  $state  Stored state.
	 * @param string $method GET to ask, POST to claim.
	 *
	 * @return array `body` on success, or `error`.
	 */
	protected static function request( array $state, $method ) {
		$bases = '' !== $state['base'] ? array( $state['base'] ) : \nfd_sm_rest_bases( $state['url'] );
		$last  = array( 'error' => 'The source did not answer.' );

		foreach ( $bases as $base ) {
			$response = \wp_remote_request(
				\nfd_sm_rest_url( $base, 'transfer/handoff' ),
				array(
					'method'    => $method,
					'timeout'   => 20,
					'sslverify' => true,
					'headers'   => array(
						Link::HEADER    => $state['token'],
						'X-NFD-SM-From' => \get_site_url(),
					),
				)
			);

			if ( \is_wp_error( $response ) ) {
				$last = array( 'error' => 'Could not reach the source: ' . $response->get_error_message() );

				continue;
			}

			$status = (int) \wp_remote_retrieve_response_code( $response );
			$json   = false !== \strpos( (string) \wp_remote_retrieve_header( $response, 'content-type' ), 'json' );
			$body   = \json_decode( \wp_remote_retrieve_body( $response ), true );

			if ( 200 === $status && \is_array( $body ) ) {
				if ( $state['base'] !== $base ) {
					$state['base'] = $base;
					self::save( $state );
				}

				return array( 'body' => $body );
			}

			// An answer in JSON came from the REST API, so it is the source's real answer and
			// trying the other URL shape would only ask the same server the same question.
			if ( $json ) {
				return array(
					'error'  => 404 === $status
						? 'The source is not offering a package to this site at the moment.'
						: \sprintf( 'The source answered with status %d.', $status ),
					'status' => $status,
				);
			}

			$last = array( 'error' => \sprintf( 'The source answered with status %d.', $status ) );
		}

		return $last;
	}

	/**
	 * The stored state, token included.
	 *
	 * @return array Empty when there is no linked source.
	 */
	protected static function load() {
		$state = \get_option( NFD_SM_LINKED_SOURCE_OPTION, array() );

		if ( ! \is_array( $state ) || empty( $state['url'] ) || empty( $state['token'] ) ) {
			return array();
		}

		return \array_merge(
			array(
				'url'       => '',
				'token'     => '',
				'base'      => '',
				'linked_at' => 0,
			),
			$state
		);
	}

	/**
	 * Write the state back, never autoloaded.
	 *
	 * @param array $state State.
	 *
	 * @return void
	 */
	protected static function save( array $state ) {
		\update_option( NFD_SM_LINKED_SOURCE_OPTION, $state, false );
	}
}
