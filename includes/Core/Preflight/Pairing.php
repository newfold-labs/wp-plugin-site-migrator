<?php
/**
 * Pairing between a source and a destination.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Preflight;

/**
 * Issues and redeems the single-use code that lets a source read a destination's profile.
 *
 * The compatibility check is a few hundred bytes, so it does not have to inherit the package's
 * manual transfer. The user copies a short code once and the source reads live facts — which
 * also makes the check re-runnable, and most blocked gates are fixable in a minute.
 *
 * The code is minted by the **destination**. A site therefore cannot be targeted for
 * overwriting unless someone has stood on it and asked for a code; the source has nothing to
 * authenticate with otherwise. That property is worth more than any warning in the UI.
 */
class Pairing {

	const OPTION      = 'pairing';
	const TTL         = 900;
	const MAX_ATTEMPT = 10;
	const WINDOW      = 300;

	/**
	 * Issue a code, replacing any outstanding one.
	 *
	 * Only the hash is stored, so a database read does not hand over a working code.
	 *
	 * @return string The code to show the user.
	 */
	public static function issue() {
		$raw  = \strtoupper( \bin2hex( \random_bytes( 6 ) ) );
		$code = \substr( $raw, 0, 4 ) . '-' . \substr( $raw, 4, 4 ) . '-' . \substr( $raw, 8, 4 );

		\NewfoldLabs\WP\SiteMigrator\Utils\Options::set(
			self::OPTION,
			array(
				'hash'     => \wp_hash_password( $code ),
				'expires'  => \time() + self::TTL,
				'attempts' => 0,
				'window'   => \time(),
				'paired'   => '',
			)
		);

		return $code;
	}

	/**
	 * Forget the outstanding code.
	 *
	 * @return void
	 */
	public static function revoke() {
		\NewfoldLabs\WP\SiteMigrator\Utils\Options::delete( self::OPTION );
	}

	/**
	 * The current pairing state, without the hash.
	 *
	 * @return array
	 */
	public static function status() {
		$state = (array) \NewfoldLabs\WP\SiteMigrator\Utils\Options::get( self::OPTION, array() );

		if ( empty( $state ) ) {
			return array(
				'active'  => false,
				'paired'  => '',
				'expires' => 0,
			);
		}

		return array(
			'active'  => isset( $state['expires'] ) && $state['expires'] > \time(),
			'paired'  => isset( $state['paired'] ) ? $state['paired'] : '',
			'expires' => isset( $state['expires'] ) ? (int) $state['expires'] : 0,
		);
	}

	/**
	 * Check a presented code.
	 *
	 * Rate limited, because the code is short enough to guess if you are allowed to try
	 * indefinitely.
	 *
	 * @param string $code Presented code.
	 * @param string $from Site URL of the caller, recorded when it matches.
	 *
	 * @return bool
	 */
	public static function redeem( $code, $from = '' ) {
		$state = (array) \NewfoldLabs\WP\SiteMigrator\Utils\Options::get( self::OPTION, array() );

		if ( empty( $state ) || ! isset( $state['hash'], $state['expires'] ) ) {
			return false;
		}

		if ( $state['expires'] < \time() ) {
			self::revoke();

			return false;
		}

		$window   = isset( $state['window'] ) ? (int) $state['window'] : 0;
		$attempts = isset( $state['attempts'] ) ? (int) $state['attempts'] : 0;

		if ( ( \time() - $window ) > self::WINDOW ) {
			$window   = \time();
			$attempts = 0;
		}

		if ( $attempts >= self::MAX_ATTEMPT ) {
			return false;
		}

		$state['attempts'] = $attempts + 1;
		$state['window']   = $window;

		if ( ! \wp_check_password( \trim( (string) $code ), $state['hash'] ) ) {
			\NewfoldLabs\WP\SiteMigrator\Utils\Options::set( self::OPTION, $state );

			return false;
		}

		$state['attempts'] = 0;
		$state['paired']   = \esc_url_raw( $from );
		\NewfoldLabs\WP\SiteMigrator\Utils\Options::set( self::OPTION, $state );

		return true;
	}

	/**
	 * Fetch a destination's profile, from the source side.
	 *
	 * @param string $url  Destination site URL.
	 * @param string $code Pairing code.
	 *
	 * @return array `profile` on success, or `error` describing why not.
	 */
	public static function fetch_profile( $url, $code ) {
		$url = \esc_url_raw( \trim( (string) $url ) );

		if ( '' === $url ) {
			return array( 'error' => 'Give me the destination\'s address.' );
		}

		$endpoint = \trailingslashit( $url ) . 'wp-json/nfd-site-migrator/v1/pairing/profile';

		$response = \wp_remote_get(
			$endpoint,
			array(
				'timeout'   => 20,
				// Always verify. Tying this to the local site's own scheme, as the previous
				// code did, means an HTTP site silently accepts any certificate (finding 2.5).
				'sslverify' => true,
				'headers'   => array(
					'X-NFD-SM-Pairing' => \trim( (string) $code ),
					'X-NFD-SM-From'    => \get_site_url(),
				),
			)
		);

		if ( \is_wp_error( $response ) ) {
			return array(
				'error'       => 'Could not reach the destination: ' . $response->get_error_message(),
				'unreachable' => true,
			);
		}

		$status = (int) \wp_remote_retrieve_response_code( $response );

		if ( 404 === $status ) {
			return array(
				'error' => 'The destination did not accept that code. Check it is correct and has not expired, then generate a new one.',
			);
		}

		if ( 200 !== $status ) {
			return array( 'error' => \sprintf( 'The destination answered with status %d.', $status ) );
		}

		$body = \json_decode( \wp_remote_retrieve_body( $response ), true );

		if ( ! \is_array( $body ) || ! isset( $body['profile'] ) ) {
			return array( 'error' => 'The destination answered, but not with a profile. Is the plugin installed and up to date there?' );
		}

		$profile = new SiteProfile( $body['profile'] );

		return array( 'profile' => $profile );
	}
}
