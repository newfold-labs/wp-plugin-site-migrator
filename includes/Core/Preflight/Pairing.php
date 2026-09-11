<?php
/**
 * Pairing between a source and a destination.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Preflight;

use NewfoldLabs\WP\SiteMigrator\Core\Transfer\Link;

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

		// The one moment both ends have proved themselves: somebody stood on the destination to
		// mint the code, and somebody stood here to type it. A token minted now is what lets the
		// destination ask this site for the package later without a second code being carried
		// back. It is offered, not required — a destination running an older version ignores the
		// header, says nothing about it, and the migration falls back to the pasted key.
		$token = Link::issue( $url );
		$last  = array( 'error' => 'The destination did not answer with a profile.' );

		foreach ( self::endpoints( $url ) as $endpoint ) {
			$attempt = self::ask( $endpoint, $code, $token );

			if ( isset( $attempt['profile'] ) || ! empty( $attempt['final'] ) ) {
				unset( $attempt['final'] );

				// Kept only if the other end says it kept its half. A destination on an older
				// version ignores the header and answers without `linked`, and a source holding a
				// token nothing can present would show an Offer button for a handover that could
				// never happen — worse than not offering it, because the failure is silent and
				// on the other site.
				if ( empty( $attempt['linked'] ) ) {
					Link::revoke();
				}

				return $attempt;
			}

			$last = $attempt;
		}

		// Nothing was paired, so nothing should be left holding a token for a site that never
		// took it.
		Link::revoke();

		unset( $last['final'] );

		return $last;
	}

	/**
	 * Is the destination still answering at all?
	 *
	 * Reading its *profile* needs a code, and a code lives fifteen minutes on the other site, so
	 * a source that paired days ago cannot re-read the facts it is comparing against. It can
	 * still ask whether anything is there — and it should, because the alternative is what the
	 * screen used to do: recompute a verdict from remembered facts and present it as a fresh
	 * answer about a site that has since moved, expired its certificate, or gone off the air.
	 *
	 * The probe is `pairing/profile` with **no code**, which the endpoint short-circuits before
	 * `redeem()` — so it costs the destination nothing and, importantly, does not spend one of
	 * the ten attempts that protect a code somebody is about to use for real.
	 *
	 * What comes back is narrower than it looks, and the caller must not overstate it. A 404 is
	 * what that endpoint gives *everyone* without a code, deliberately, so an answer proves only
	 * that something served HTTP at that address — not that it is still the destination, and not
	 * that this plugin is still active there. A transport error, on the other hand, is real
	 * evidence: this site tried to reach that one and could not.
	 *
	 * @param string $url Destination site URL.
	 *
	 * @return array `reachable`, plus `error` and `status` where they apply.
	 */
	public static function reach( $url ) {
		$url = \esc_url_raw( \trim( (string) $url ) );

		if ( '' === $url ) {
			return array(
				'reachable' => false,
				'error'     => 'There is no destination address to check.',
			);
		}

		$error = 'The destination did not answer.';

		// Both bases, for the reason `endpoints()` gives: a site on plain permalinks answers
		// only the query form, and calling the other one unreachable would be this site's
		// mistake reported as the other site's fault.
		foreach ( self::endpoints( $url ) as $endpoint ) {
			$response = \wp_remote_get(
				$endpoint,
				array(
					'timeout'   => 15,
					'sslverify' => true,
					'headers'   => array( 'X-NFD-SM-From' => \get_site_url() ),
				)
			);

			if ( \is_wp_error( $response ) ) {
				$error = $response->get_error_message();

				continue;
			}

			return array(
				'reachable' => true,
				'status'    => (int) \wp_remote_retrieve_response_code( $response ),
			);
		}

		return array(
			'reachable' => false,
			'error'     => $error,
		);
	}

	/**
	 * Where the destination's REST API might be, best guess first.
	 *
	 * A site with pretty permalinks serves both forms; a site without them serves only
	 * `?rest_route=`, and answers `/wp-json/…` with a redirect to its home page — which arrives
	 * as HTML and reads as "the plugin is not installed there". Plain permalinks are the default
	 * on a fresh install and common on exactly the hosts this plugin exists for, so pairing
	 * against them failed for a reason the message did not name (finding 3.17). The query form
	 * works in both cases, so it goes first; the path form stays as a fallback for a host that
	 * blocks the query form.
	 *
	 * @param string $url Destination site URL.
	 *
	 * @return array Absolute URLs.
	 */
	protected static function endpoints( $url ) {
		$endpoints = array();

		foreach ( \nfd_sm_rest_bases( $url ) as $base ) {
			$endpoints[] = \nfd_sm_rest_url( $base, 'pairing/profile' );
		}

		return $endpoints;
	}

	/**
	 * Ask one endpoint for the profile.
	 *
	 * An answer in JSON came from the REST API, so it is the destination's real answer and no
	 * other URL will do better — those are marked `final`. Anything else means we probably
	 * knocked on the wrong door.
	 *
	 * @param string $endpoint Absolute URL.
	 * @param string $code     Pairing code.
	 * @param string $token    Link token to leave behind, when the destination will keep one.
	 *
	 * @return array `profile` on success, or `error` plus `final`.
	 */
	protected static function ask( $endpoint, $code, $token = '' ) {
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
					Link::HEADER       => (string) $token,
				),
			)
		);

		if ( \is_wp_error( $response ) ) {
			return array(
				'error'       => 'Could not reach the destination: ' . $response->get_error_message(),
				'unreachable' => true,
				'final'       => true,
			);
		}

		$status = (int) \wp_remote_retrieve_response_code( $response );
		$json   = false !== \strpos( (string) \wp_remote_retrieve_header( $response, 'content-type' ), 'json' );

		if ( 404 === $status ) {
			return array(
				'error' => 'The destination did not accept that code. Check it is correct and has not expired, then generate a new one.',
				'final' => $json,
			);
		}

		if ( 200 !== $status ) {
			return array(
				'error' => \sprintf( 'The destination answered with status %d.', $status ),
				'final' => $json,
			);
		}

		$body = \json_decode( \wp_remote_retrieve_body( $response ), true );

		if ( ! \is_array( $body ) || ! isset( $body['profile'] ) ) {
			return array(
				'error' => 'The destination answered, but not with a profile. Is the plugin installed and up to date there?',
				'final' => false,
			);
		}

		return array(
			'profile' => new SiteProfile( $body['profile'] ),
			// Whether the other end kept the token. An older destination simply does not say,
			// which reads as false and sends the user down the manual path — the right answer
			// for a site that cannot be asked anything later.
			'linked'  => ! empty( $body['linked'] ),
		);
	}
}
