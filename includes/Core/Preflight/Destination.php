<?php
/**
 * The destination this source has been paired with.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Preflight;

use NewfoldLabs\WP\SiteMigrator\Utils\Options;

/**
 * Remembers which site we are migrating to, and what it told us about itself.
 *
 * Pairing costs the user a trip to the other site to fetch a code that expires in fifteen
 * minutes. Making them do that again because they stepped back one screen to re-read the
 * address would be charging them for our forgetfulness.
 *
 * What is stored is the destination's **facts**, not the verdict about them. A verdict goes
 * stale — this site's own PHP version or free space can change between screens — so the
 * comparison is recomputed from these facts every time it is asked for, against a freshly
 * gathered profile of this site. Storing the answer instead of the inputs is how a compatibility
 * report ends up describing a site that no longer exists.
 *
 * The pairing **code is deliberately not kept**. It is a credential for reading another site,
 * its whole life is fifteen minutes, and the profile it was used to fetch is the part worth
 * having. Reading the destination again is a fresh code, and the screen says so.
 */
class Destination {

	/**
	 * Key within the plugin option.
	 */
	const OPTION = 'destination';

	/**
	 * Remember a destination.
	 *
	 * @param SiteProfile $profile What the destination reported.
	 * @param string      $url     Its address.
	 * @param string      $via     'paired' or 'pasted'.
	 *
	 * @return void
	 */
	public static function save( SiteProfile $profile, $url, $via ) {
		Options::set(
			self::OPTION,
			array(
				'profile'    => $profile->to_array(),
				'url'        => \esc_url_raw( (string) $url ),
				'via'        => 'pasted' === $via ? 'pasted' : 'paired',
				'fetched_at' => \time(),
			)
		);
	}

	/**
	 * Forget it, so the next pairing starts clean.
	 *
	 * @return void
	 */
	public static function forget() {
		Options::delete( self::OPTION );
	}

	/**
	 * The stored record, or null if there is none.
	 *
	 * @return array|null
	 */
	public static function load() {
		$saved = (array) Options::get( self::OPTION, array() );

		if ( empty( $saved['profile'] ) || ! \is_array( $saved['profile'] ) ) {
			return null;
		}

		return \array_merge(
			array(
				'url'        => '',
				'via'        => 'paired',
				'fetched_at' => 0,
			),
			$saved
		);
	}

	/**
	 * The stored profile, or null if there is none.
	 *
	 * @return SiteProfile|null
	 */
	public static function profile() {
		$saved = self::load();

		return null === $saved ? null : new SiteProfile( $saved['profile'] );
	}
}
