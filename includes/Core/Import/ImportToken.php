<?php
/**
 * Authority that outlives the users table.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

/**
 * A single-purpose, time-limited credential held in a file rather than the database.
 *
 * The import replaces the users table, and with it every session token WordPress has. The
 * browser's cookie may or may not still authenticate on the next request — it depends on
 * whether the account behind it survived the merge under the same ID, and whether its
 * `session_tokens` meta came across. Neither is a thing to bet the second half of a migration
 * on: if the cookie stops working mid-run, `manage_options` fails, the step requests 403, and
 * the import stalls with the database already swapped.
 *
 * So the browser is given a token at the start, and the step endpoints accept it *instead of*
 * the cookie. It lives in the protected storage directory, so it survives the swap the same way
 * the checkpoint does, and it is deleted when the import finishes.
 *
 * It is not a general credential. It authorises exactly the import endpoints, for a bounded
 * window, on a site that is mid-migration anyway.
 */
class ImportToken {

	const NAME = 'token.json';

	/**
	 * How long a token stays valid, in seconds.
	 */
	const TTL = 21600;

	/**
	 * The header a client presents it in.
	 */
	const HEADER = 'x-nfd-sm-import';

	/**
	 * Mint a token, replacing any existing one.
	 *
	 * @param int $user_id The account the import is being run as.
	 *
	 * @return string The token to hand to the client. Never stored in this form.
	 */
	public static function issue( $user_id ) {
		$token = \bin2hex( \random_bytes( 32 ) );

		$payload = array(
			'hash'    => \wp_hash_password( $token ),
			'user_id' => (int) $user_id,
			'issued'  => \time(),
			'expires' => \time() + self::TTL,
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		\file_put_contents( self::path(), \wp_json_encode( $payload ), LOCK_EX );
		@\chmod( self::path(), 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $token;
	}

	/**
	 * Check a presented token.
	 *
	 * @param string $token Token from the client.
	 *
	 * @return int The user ID it authorises, or 0.
	 */
	public static function verify( $token ) {
		$token = (string) $token;

		if ( '' === $token ) {
			return 0;
		}

		$payload = self::read();

		if ( empty( $payload ) ) {
			return 0;
		}

		if ( \time() > (int) $payload['expires'] ) {
			self::revoke();

			return 0;
		}

		// Constant-time by way of the password hasher, which is also why the token is stored
		// hashed: the storage directory is protected, but a file that leaks should not hand
		// over a working credential.
		if ( ! \wp_check_password( $token, $payload['hash'] ) ) {
			return 0;
		}

		return (int) $payload['user_id'];
	}

	/**
	 * Whether a token is currently outstanding.
	 *
	 * @return bool
	 */
	public static function exists() {
		$payload = self::read();

		return ! empty( $payload ) && \time() <= (int) $payload['expires'];
	}

	/**
	 * Destroy the token.
	 *
	 * @return void
	 */
	public static function revoke() {
		if ( \file_exists( self::path() ) ) {
			\unlink( self::path() );
		}
	}

	/**
	 * Read the stored payload.
	 *
	 * @return array Empty when absent or unreadable.
	 */
	protected static function read() {
		$path = self::path();

		if ( ! \is_readable( $path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$decoded = \json_decode( \file_get_contents( $path ), true );

		if ( ! \is_array( $decoded ) || empty( $decoded['hash'] ) || empty( $decoded['expires'] ) ) {
			return array();
		}

		return $decoded;
	}

	/**
	 * Where the token lives.
	 *
	 * @return string
	 */
	protected static function path() {
		return ImportCheckpoint::state_dir() . DIRECTORY_SEPARATOR . self::NAME;
	}
}
