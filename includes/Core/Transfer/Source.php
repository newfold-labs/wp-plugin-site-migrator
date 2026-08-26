<?php
/**
 * The source this destination is pulling from.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Transfer;

/**
 * Remembers where the package is coming from, and what it is.
 *
 * Three things are worth knowing about this record.
 *
 * **It holds a credential for reading another site**, which nothing else in the plugin does —
 * `Destination` on the source side deliberately keeps the destination's facts and throws the
 * pairing code away. That is not an option here: a 20GB pull is hundreds of requests across hours
 * and there is nobody at the keyboard to re-enter anything. So the key is kept for exactly as long
 * as the transfer needs it and dropped the moment the last byte lands, no endpoint returns it, and
 * `nfd_sm_purge_all()` removes it on deactivation along with everything else.
 *
 * **It is not the progress.** How much has arrived is answered by looking at the files on disk
 * against the sizes the manifest declared — the same instinct as `Upload::received()`, and for the
 * same reason: the thing being measured should not also be the thing keeping score. So a pull step,
 * which can run for minutes, never writes here at all, and reconnecting does not lose a byte of
 * what already arrived.
 *
 * **It records which URL shape worked.** The source may be on plain permalinks, in which case only
 * `?rest_route=` answers; discovering that once per transfer rather than once per file is the
 * difference between one wasted request and several hundred.
 */
class Source {

	/**
	 * Store a connection, having already spoken to the other end.
	 *
	 * @param string $url     Source site URL.
	 * @param string $key     Transfer key.
	 * @param string $base    The REST base that answered.
	 * @param array  $summary What the source said it has: `site_url`, `created_at`, `bytes`, `files`.
	 *
	 * @return void
	 */
	public static function connect( $url, $key, $base, array $summary ) {
		self::save(
			array(
				'url'          => \esc_url_raw( (string) $url ),
				'key'          => (string) $key,
				'base'         => (string) $base,
				'site_url'     => (string) \nfd_sm_data_get( $summary, 'site_url', '' ),
				'created_at'   => (string) \nfd_sm_data_get( $summary, 'created_at', '' ),
				'bytes'        => (int) \nfd_sm_data_get( $summary, 'bytes', 0 ),
				'files'        => (array) \nfd_sm_data_get( $summary, 'files', array() ),
				'connected_at' => \time(),
			)
		);
	}

	/**
	 * Forget the source entirely.
	 *
	 * @return void
	 */
	public static function forget() {
		\delete_option( NFD_SM_TRANSFER_SOURCE_OPTION );
	}

	/**
	 * Drop the key but keep the record, once there is nothing left to fetch.
	 *
	 * The screen still wants to say where the package came from and when, and that is not a
	 * credential. The key is.
	 *
	 * @return void
	 */
	public static function complete() {
		$state = self::load();

		if ( empty( $state ) ) {
			return;
		}

		$state['key']       = '';
		$state['completed'] = \time();

		self::save( $state );
	}

	/**
	 * The full record, key included. For the puller only.
	 *
	 * @return array Empty when no source is connected.
	 */
	public static function load() {
		$state = \get_option( NFD_SM_TRANSFER_SOURCE_OPTION, array() );

		if ( ! \is_array( $state ) || empty( $state['url'] ) ) {
			return array();
		}

		return \array_merge(
			array(
				'url'          => '',
				'key'          => '',
				'base'         => '',
				'site_url'     => '',
				'created_at'   => '',
				'bytes'        => 0,
				'files'        => array(),
				'connected_at' => 0,
				'completed'    => 0,
			),
			$state
		);
	}

	/**
	 * The record as a screen may see it.
	 *
	 * @return array
	 */
	public static function status() {
		$state = self::load();

		if ( empty( $state ) ) {
			return array( 'connected' => false );
		}

		unset( $state['key'] );

		return \array_merge( array( 'connected' => true ), $state );
	}

	/**
	 * Whether a summary describes the same package as the one already connected.
	 *
	 * Reconnecting to the same source resumes; pointing at a different one has to start over,
	 * because the staging directory would otherwise hold half of each of two sites and the
	 * checksums would only notice at the end.
	 *
	 * @param array $summary What a source just reported.
	 *
	 * @return bool True when what is on disk belongs to this package.
	 */
	public static function matches( array $summary ) {
		$state = self::load();

		if ( empty( $state ) ) {
			return false;
		}

		return (string) \nfd_sm_data_get( $summary, 'site_url', '' ) === $state['site_url']
			&& (string) \nfd_sm_data_get( $summary, 'created_at', '' ) === $state['created_at'];
	}

	/**
	 * Write the record back, never autoloaded.
	 *
	 * @param array $state State.
	 *
	 * @return void
	 */
	protected static function save( array $state ) {
		\update_option( NFD_SM_TRANSFER_SOURCE_OPTION, $state, false );
	}
}
