<?php
/**
 * The standing link a pairing leaves behind, source side.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Transfer;

/**
 * A token this site hands to the destination during pairing, so the second copy-paste can go.
 *
 * **Why there is a third credential rather than a reuse of the first.** The pairing code and the
 * transfer key point in opposite directions: the code authenticates *this site reading the
 * destination*, and lives fifteen minutes on the other server as a password hash that nothing can
 * read back; the key authenticates *the destination reading this site*, hours or days later,
 * across hundreds of requests. Neither can stand in for the other. What they do share is a
 * moment: at the instant pairing succeeds, both ends have proved themselves — somebody stood on
 * the destination to mint the code, and somebody stood here to type it. This token is minted in
 * that moment and is the only thing that outlives it.
 *
 * **It is worth nothing on its own.** Presenting it asks one question — *is there a package being
 * offered to me?* — and until an administrator on this site presses Offer the answer is a 404,
 * the same 404 an unknown caller gets. The token cannot start an export, cannot read a package,
 * and cannot be used to find out whether this site has one.
 *
 * **The key is minted when it is claimed, not when it is offered.** So an outstanding offer is a
 * flag and a timestamp, and there is still no raw transfer key stored anywhere on this site — the
 * promise `TransferKey` makes about only keeping a hash stays true, instead of being quietly
 * traded away for the convenience this class exists to provide.
 *
 * **It is deliberately not bound to an address**, which is the one place this differs from
 * `TransferKey`. A key binds on first use because a transfer is a single conversation with a
 * single machine; a link is a standing arrangement, and a destination behind more than one egress
 * address — a load balancer, a host that rotates outbound IPs — would find it refused for a
 * reason nobody could see, with re-pairing as the only cure. Instead the claim is single-use per
 * offer and the address that took it is recorded, so a claim by the wrong party is visible on the
 * source's own screen and is undone by withdrawing the key and offering again.
 */
class Link {

	/**
	 * Header the token travels in, in both directions.
	 */
	const HEADER = 'X-NFD-SM-Link';

	/**
	 * How long a link survives without being used.
	 *
	 * Thirty days rather than the key's six hours: this is the relationship between two sites,
	 * not one transfer. Every accepted request pushes it out again.
	 */
	const TTL = 2592000;

	/**
	 * Failed presentations tolerated within a window.
	 */
	const MAX_ATTEMPT = 10;

	/**
	 * Length of the failure window, in seconds.
	 */
	const WINDOW = 300;

	/**
	 * Mint a token for the destination we are pairing with.
	 *
	 * @param string $destination The destination's address, for this site's own screen.
	 *
	 * @return string The token to send. Only its hash is kept.
	 */
	public static function issue( $destination = '' ) {
		$token = \bin2hex( \random_bytes( 24 ) );

		self::save(
			array(
				'hash'        => \hash( 'sha256', $token ),
				'destination' => \esc_url_raw( (string) $destination ),
				'issued_at'   => \time(),
				'expires'     => \time() + self::TTL,
				'attempts'    => 0,
				'window'      => \time(),
				'last_seen'   => 0,
				'offered_at'  => 0,
				'handed_at'   => 0,
				'handed_to'   => '',
			)
		);

		return $token;
	}

	/**
	 * Forget the link entirely.
	 *
	 * @return void
	 */
	public static function revoke() {
		\delete_option( NFD_SM_LINK_OPTION );
	}

	/**
	 * Whether a link exists and has not expired.
	 *
	 * @return bool
	 */
	public static function exists() {
		$state = self::load();

		return ! empty( $state ) && self::live( $state );
	}

	/**
	 * What the source's screen shows about the link.
	 *
	 * Never the hash, and there is no way to ask for the token back: it was sent once, over the
	 * pairing request, and this site kept only enough to recognise it.
	 *
	 * @return array
	 */
	public static function status() {
		$state = self::load();

		if ( empty( $state ) || ! self::live( $state ) ) {
			return array(
				'linked'  => false,
				'offered' => false,
			);
		}

		return array(
			'linked'      => true,
			'destination' => $state['destination'],
			'linked_at'   => (int) $state['issued_at'],
			'offered'     => (int) $state['offered_at'] > 0,
			'offered_at'  => (int) $state['offered_at'],
			'handed_at'   => (int) $state['handed_at'],
			'handed_to'   => $state['handed_to'],
			'last_seen'   => (int) $state['last_seen'],
		);
	}

	/**
	 * Check a presented token.
	 *
	 * @param string $token Presented token.
	 *
	 * @return bool
	 */
	public static function verify( $token ) {
		$state = self::load();

		if ( empty( $state ) ) {
			return false;
		}

		if ( ! self::live( $state ) ) {
			self::revoke();

			return false;
		}

		if ( ( \time() - (int) $state['window'] ) > self::WINDOW ) {
			$state['window']   = \time();
			$state['attempts'] = 0;
		}

		// Refused for the rest of the window rather than destroyed, for the reason `TransferKey`
		// gives: a link a stranger could burn by presenting rubbish would hand them a way to
		// break a migration they cannot otherwise touch.
		if ( (int) $state['attempts'] >= self::MAX_ATTEMPT ) {
			return false;
		}

		$token = self::normalise( $token );

		if ( '' === $token || ! \hash_equals( $state['hash'], \hash( 'sha256', $token ) ) ) {
			++$state['attempts'];
			self::save( $state );

			return false;
		}

		$state['attempts']  = 0;
		$state['last_seen'] = \time();
		$state['expires']   = \time() + self::TTL;

		self::save( $state );

		return true;
	}

	/**
	 * Say that the finished package is available to the linked destination.
	 *
	 * @return bool False when there is no live link to offer it to.
	 */
	public static function offer() {
		$state = self::load();

		if ( empty( $state ) || ! self::live( $state ) ) {
			return false;
		}

		$state['offered_at'] = \time();
		$state['handed_at']  = 0;
		$state['handed_to']  = '';

		self::save( $state );

		return true;
	}

	/**
	 * Take the offer back, keeping the link.
	 *
	 * @return void
	 */
	public static function withdraw() {
		$state = self::load();

		if ( empty( $state ) ) {
			return;
		}

		$state['offered_at'] = 0;
		$state['handed_at']  = 0;
		$state['handed_to']  = '';

		self::save( $state );
	}

	/**
	 * Whether a package is currently on offer and nobody has taken it yet.
	 *
	 * @return bool
	 */
	public static function is_offered() {
		$state = self::load();

		return ! empty( $state ) && self::live( $state ) && (int) $state['offered_at'] > 0;
	}

	/**
	 * Record that the key has been handed over, once.
	 *
	 * The single use is what limits the damage a leaked token could do: the destination that is
	 * actually running the migration claims within seconds of the button being pressed, and a
	 * second claim on the same offer is refused. Pressing Offer again is a deliberate act on the
	 * source, which is where that decision belongs.
	 *
	 * @param string $to The caller's address, recorded for display only.
	 *
	 * @return bool False when there is nothing on offer, or it has already been taken.
	 */
	public static function claim( $to = '' ) {
		$state = self::load();

		if ( empty( $state ) || ! self::live( $state ) || (int) $state['offered_at'] < 1 ) {
			return false;
		}

		if ( (int) $state['handed_at'] > 0 ) {
			return false;
		}

		$state['handed_at'] = \time();
		$state['handed_to'] = \esc_url_raw( (string) $to );
		$state['last_seen'] = \time();

		self::save( $state );

		return true;
	}

	/**
	 * Strip the decoration a token picks up in transit.
	 *
	 * @param string $token Presented token.
	 *
	 * @return string Empty when it is not the right shape at all.
	 */
	public static function normalise( $token ) {
		$token = \strtolower( \preg_replace( '/[^0-9A-Fa-f]/', '', (string) $token ) );

		return 48 === \strlen( $token ) ? $token : '';
	}

	/**
	 * Whether a stored link is still within its window.
	 *
	 * @param array $state Stored state.
	 *
	 * @return bool
	 */
	protected static function live( array $state ) {
		return isset( $state['expires'] ) && (int) $state['expires'] > \time();
	}

	/**
	 * The stored state, with every key present.
	 *
	 * @return array Empty when there is no link.
	 */
	protected static function load() {
		$state = \get_option( NFD_SM_LINK_OPTION, array() );

		if ( ! \is_array( $state ) || empty( $state['hash'] ) ) {
			return array();
		}

		return \array_merge(
			array(
				'hash'        => '',
				'destination' => '',
				'issued_at'   => 0,
				'expires'     => 0,
				'attempts'    => 0,
				'window'      => 0,
				'last_seen'   => 0,
				'offered_at'  => 0,
				'handed_at'   => 0,
				'handed_to'   => '',
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
		\update_option( NFD_SM_LINK_OPTION, $state, false );
	}
}
