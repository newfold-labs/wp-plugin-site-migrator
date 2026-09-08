<?php
/**
 * The version is in three files and has to say one thing.
 *
 * The plugin header is the source of truth -- it is what WordPress reads, what the updater
 * compares, and what wp.org publishes. `package.json` carries the same number for npm's benefit
 * and nothing breaks if it drifts, because build output is unversioned and cache busting comes
 * from the content hash in `nfd-site-migrator.asset.php`.
 *
 * `NFD_SM_VERSION` is the one that matters. It is stamped into every manifest and shown in the
 * masthead, so a package built by a plugin reporting one number while its header reports another
 * misdescribes itself to the destination that reads it -- and version drift is exactly the kind of
 * thing nobody notices until they are reading a manifest trying to work out why a migration went
 * wrong.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase {

	/**
	 * What the plugin header declares.
	 *
	 * @return string
	 */
	protected function header_version() {
		$source = (string) \file_get_contents( __DIR__ . '/../../nfd-site-migrator.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		\preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $source, $matches );

		return isset( $matches[1] ) ? \trim( $matches[1] ) : '';
	}

	/**
	 * The header carries a version at all, and one WordPress can compare.
	 */
	public function test_the_header_declares_a_version() {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+/', $this->header_version() );
	}

	/**
	 * And the constant a manifest records agrees with it.
	 */
	public function test_the_stamped_version_matches_the_header() {
		$this->assertSame( $this->header_version(), NFD_SM_VERSION );
	}

	/**
	 * npm's copy agrees too. Harmless if it drifts, and free to check.
	 */
	public function test_the_package_manifest_agrees() {
		$package = \json_decode(
			(string) \file_get_contents( __DIR__ . '/../../package.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions
			true
		);

		$this->assertSame( $this->header_version(), $package['version'] );
	}
}
