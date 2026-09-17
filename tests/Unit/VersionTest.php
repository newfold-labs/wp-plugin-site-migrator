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
	 * The bootstrap's source, read rather than included: including it would run the plugin.
	 *
	 * @return string
	 */
	protected function bootstrap() {
		return (string) \file_get_contents( __DIR__ . '/../../nfd-site-migrator.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * The PHP floor is declared in four places and they have to say one thing.
	 *
	 * The header is what WordPress enforces on activation, `WP_Forge_Plugin_Check` is what the
	 * plugins screen enforces, phpcs is what CI enforces, and the guard is what the site itself
	 * enforces on every request. A guard that disagrees with the header either refuses a PHP the
	 * plugin supports or lets through one it does not, and the second is the failure the guard was
	 * added for.
	 */
	public function test_the_php_floor_agrees_everywhere() {
		$source = $this->bootstrap();

		\preg_match( '/^\s*\*\s*Requires PHP:\s*(.+)$/m', $source, $header );
		\preg_match( '/PHP_VERSION_ID\s*<\s*(\d+)/', $source, $guard );
		\preg_match( '/min_php_version\s*=\s*\'([^\']+)\'/', $source, $check );
		\preg_match(
			'/testVersion"\s+value="([0-9.]+)/',
			(string) \file_get_contents( __DIR__ . '/../../phpcs.xml' ), // phpcs:ignore WordPress.WP.AlternativeFunctions
			$phpcs
		);

		$floor = isset( $header[1] ) ? \trim( $header[1] ) : '';
		$id    = isset( $guard[1] ) ? (int) $guard[1] : 0;

		$this->assertMatchesRegularExpression( '/^\d+\.\d+$/', $floor );
		$this->assertSame(
			$floor,
			(int) \floor( $id / 10000 ) . '.' . (int) \floor( $id / 100 ) % 100,
			'the version guard does not match the header'
		);
		$this->assertSame( $floor, isset( $check[1] ) ? $check[1] : '' );
		$this->assertSame( $floor, isset( $phpcs[1] ) ? $phpcs[1] : '' );
	}

	/**
	 * And the guard comes before anything the old PHP would have to compile.
	 *
	 * A refusal placed after the autoloader is no refusal at all: the fatal happens while the file
	 * it is meant to prevent is being read.
	 */
	public function test_the_guard_runs_before_anything_is_required() {
		$source = $this->bootstrap();

		$guard = \strpos( $source, 'PHP_VERSION_ID' );
		$first = \strpos( $source, 'require ' );

		$this->assertIsInt( $guard );
		$this->assertIsInt( $first );
		$this->assertLessThan( $first, $guard );
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
