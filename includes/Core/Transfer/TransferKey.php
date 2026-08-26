<?php
/**
 * The credential that lets a destination pull this site's package.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Transfer;

/**
 * Issues, verifies and revokes the key a destination presents to read this site's package.
 *
 * This is the first time the plugin hands site content to a network caller, so it is worth
 * being explicit about what stands between the package and the internet.
 *
 * **It is minted by the source, on purpose.** Pairing runs the other way — the destination mints
 * a code so a site cannot be *targeted* by a stranger. Here the source mints, so a site's content
 * cannot be *read* by one. Each site only ever gives out a credential that someone stood on it and
 * asked for, and neither direction of the migration can be started from the outside.
 *
 * **The key is long and the hash is fast, which is the opposite of the pairing code.** A pairing
 * code is twelve characters because a human reads it aloud, so it is stored under
 * `wp_hash_password()` and rate limited hard — the guessing has to be made expensive. This key is
 * 48 characters of `random_bytes()`; there is nothing to guess and nothing to look up, and the
 * verification is paid on every one of the hundreds of requests a multi-gigabyte pull makes. A
 * SHA-256 compared with `hash_equals()` still means a database read does not hand anybody a
 * working key, which is the property that mattered.
 *
 * **It expires by idleness, not by clock.** A 20GB transfer over a slow link is hours of work, so
 * a fixed fifteen minutes would fail every large migration — the one case this exists for. Each
 * accepted request pushes the expiry out; a transfer nobody is running dies on its own.
 *
 * **It binds to the first caller.** After a destination has used it, another address presenting
 * the same key is refused. A key that leaks — pasted into a support ticket, caught in a
 * screenshot — is useless the moment the real transfer is under way, and the source's screen
 * names who claimed it so the user can see whether that is who they expected.
 */
class TransferKey {

	/**
	 * Header the key travels in.
	 */
	const HEADER = 'X-NFD-SM-Transfer';

	/**
	 * Seconds of idleness before the key dies.
	 */
	const TTL = 21600;

	/**
	 * Failed presentations tolerated within a window.
	 */
	const MAX_ATTEMPT = 10;

	/**
	 * Length of the failure window, in seconds.
	 */
	const WINDOW = 300;

	/**
	 * Issue a key, replacing any outstanding one.
	 *
	 * @return string The key to show the user.
	 */
	public static function issue() {
		$key = \bin2hex( \random_bytes( 24 ) );

		self::save(
			array(
				'hash'       => \hash( 'sha256', $key ),
				'issued_at'  => \time(),
				'expires'    => \time() + self::TTL,
				'attempts'   => 0,
				'window'     => \time(),
				'claimed_by' => '',
				'claimed_ip' => '',
				'claimed_at' => 0,
				'last_seen'  => 0,
				'sent'       => 0,
			)
		);

		return $key;
	}

	/**
	 * Withdraw the key, whether or not a transfer is using it.
	 *
	 * @return void
	 */
	public static function revoke() {
		\delete_option( NFD_SM_TRANSFER_KEY_OPTION );
	}

	/**
	 * What the source's screen shows about the transfer it is serving.
	 *
	 * Never includes the hash, and there is no way to ask for the key back: it is shown once,
	 * when it is issued.
	 *
	 * @return array
	 */
	public static function status() {
		$state = self::load();

		if ( empty( $state ) ) {
			return array(
				'active'  => false,
				'claimed' => false,
			);
		}

		return array(
			'active'     => self::live( $state ),
			'claimed'    => '' !== $state['claimed_ip'],
			'claimed_by' => $state['claimed_by'],
			'claimed_at' => (int) $state['claimed_at'],
			'issued_at'  => (int) $state['issued_at'],
			'expires'    => (int) $state['expires'],
			'last_seen'  => (int) $state['last_seen'],
			'sent'       => (int) $state['sent'],
		);
	}

	/**
	 * Check a presented key and, if it is good, claim it for this caller.
	 *
	 * The caller's address and site URL are passed in rather than read from the environment,
	 * because nothing under `Core/` may touch a superglobal.
	 *
	 * @param string $key  Presented key.
	 * @param string $ip   Caller's address.
	 * @param string $from Site URL the caller claims, recorded for display only.
	 *
	 * @return bool
	 */
	public static function verify( $key, $ip, $from = '' ) {
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

		// Refusing everything for the rest of the window, rather than destroying the key, is
		// deliberate: a key that a stranger could burn by presenting garbage would hand them a
		// way to stop a migration they cannot otherwise touch.
		if ( (int) $state['attempts'] >= self::MAX_ATTEMPT ) {
			return false;
		}

		$key = self::normalise( $key );

		if ( '' === $key || ! \hash_equals( $state['hash'], \hash( 'sha256', $key ) ) ) {
			++$state['attempts'];
			self::save( $state );

			return false;
		}

		if ( '' !== $state['claimed_ip'] && $state['claimed_ip'] !== (string) $ip ) {
			++$state['attempts'];
			self::save( $state );

			return false;
		}

		if ( '' === $state['claimed_ip'] ) {
			$state['claimed_ip'] = (string) $ip;
			$state['claimed_by'] = \esc_url_raw( (string) $from );
			$state['claimed_at'] = \time();
		}

		$state['attempts']  = 0;
		$state['last_seen'] = \time();
		$state['expires']   = \time() + self::TTL;

		self::save( $state );

		return true;
	}

	/**
	 * Record bytes handed over, for the progress the source's screen shows.
	 *
	 * Called after the response has been sent, so a stream that the client abandons halfway is
	 * not counted as delivered.
	 *
	 * @param int $bytes Bytes written to the client.
	 *
	 * @return void
	 */
	public static function sent( $bytes ) {
		$state = self::load();

		if ( empty( $state ) ) {
			return;
		}

		$state['sent']      = (int) $state['sent'] + \max( 0, (int) $bytes );
		$state['last_seen'] = \time();

		self::save( $state );
	}

	/**
	 * Strip the decoration a key picks up in transit.
	 *
	 * Copying from a screen brings spaces and line breaks with it, and some clipboards insert a
	 * dash. None of that is part of the key, and a user who pasted it correctly should not be
	 * told they got it wrong.
	 *
	 * @param string $key Presented key.
	 *
	 * @return string Empty when it is not the right shape at all.
	 */
	public static function normalise( $key ) {
		$key = \strtolower( \preg_replace( '/[^0-9A-Fa-f]/', '', (string) $key ) );

		return 48 === \strlen( $key ) ? $key : '';
	}

	/**
	 * Whether a stored key is still within its idle window.
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
	 * @return array Empty when no key is outstanding.
	 */
	protected static function load() {
		$state = \get_option( NFD_SM_TRANSFER_KEY_OPTION, array() );

		if ( ! \is_array( $state ) || empty( $state['hash'] ) ) {
			return array();
		}

		return \array_merge(
			array(
				'hash'       => '',
				'issued_at'  => 0,
				'expires'    => 0,
				'attempts'   => 0,
				'window'     => 0,
				'claimed_by' => '',
				'claimed_ip' => '',
				'claimed_at' => 0,
				'last_seen'  => 0,
				'sent'       => 0,
			),
			$state
		);
	}

	/**
	 * Write the state back.
	 *
	 * Never autoloaded: it is read on two screens and on the transfer's own requests, and it
	 * would otherwise sit in every page load on both sites.
	 *
	 * @param array $state State.
	 *
	 * @return void
	 */
	protected static function save( array $state ) {
		\update_option( NFD_SM_TRANSFER_KEY_OPTION, $state, false );
	}
}
