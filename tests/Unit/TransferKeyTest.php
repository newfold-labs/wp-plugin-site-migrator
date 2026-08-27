<?php
/**
 * The credential that lets a destination read this site's package.
 *
 * The key is the only thing standing between a stranger and a full copy of the site — database
 * included — so the properties below are the ones worth pinning down: what it accepts, what it
 * refuses, and that refusing does not itself become a way to break a migration.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use NewfoldLabs\WP\SiteMigrator\Core\Transfer\TransferKey;
use PHPUnit\Framework\TestCase;

class TransferKeyTest extends TestCase {

	/**
	 * Temporary uploads directory for the current test.
	 *
	 * @var string
	 */
	protected $dir = '';

	protected function setUp(): void {
		parent::setUp();

		$this->dir = \Fixture::reset();
	}

	protected function tearDown(): void {
		\Fixture::rmdir( $this->dir );

		parent::tearDown();
	}

	/**
	 * A key is 48 hexadecimal characters, and no two are alike.
	 */
	public function test_issue_makes_a_long_random_key() {
		$first  = TransferKey::issue();
		$second = TransferKey::issue();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{48}$/', $first );
		$this->assertNotSame( $first, $second, 'Issuing again must not repeat the key.' );
	}

	/**
	 * Only the hash is kept, so the key cannot be read back out of the site.
	 *
	 * This is what makes "shown once" true rather than a UI convention.
	 */
	public function test_the_key_itself_is_never_stored() {
		$key   = TransferKey::issue();
		$state = \json_encode( \Fixture::$options );

		$this->assertStringNotContainsString( $key, (string) $state, 'The key must not be recoverable from storage.' );
		$this->assertArrayNotHasKey( 'key', TransferKey::status(), 'And status() must not hand it back either.' );
	}

	/**
	 * The right key is accepted; a wrong one of the same shape is not.
	 */
	public function test_verify_accepts_only_the_issued_key() {
		$key = TransferKey::issue();

		$this->assertTrue( TransferKey::verify( $key, '10.0.0.1' ) );
		$this->assertFalse( TransferKey::verify( \str_repeat( 'a', 48 ), '10.0.0.1' ) );
	}

	/**
	 * A revoked key stops working immediately.
	 */
	public function test_revoke_ends_it() {
		$key = TransferKey::issue();
		TransferKey::revoke();

		$this->assertFalse( TransferKey::verify( $key, '10.0.0.1' ) );
		$this->assertFalse( TransferKey::status()['active'] );
	}

	/**
	 * A key binds to the first caller, so one that leaks mid-transfer is already useless.
	 */
	public function test_a_key_binds_to_the_first_address_that_uses_it() {
		$key = TransferKey::issue();

		$this->assertTrue( TransferKey::verify( $key, '10.0.0.1' ), 'First caller claims it.' );
		$this->assertTrue( TransferKey::verify( $key, '10.0.0.1' ), 'And keeps it.' );
		$this->assertFalse( TransferKey::verify( $key, '10.0.0.2' ), 'A second address is refused.' );
	}

	/**
	 * Whoever claimed it is recorded, but only from the first contact.
	 */
	public function test_the_claimant_is_recorded_once() {
		$key = TransferKey::issue();

		TransferKey::verify( $key, '10.0.0.1', 'http://dest.test' );

		$status = TransferKey::status();

		$this->assertTrue( $status['claimed'] );
		$this->assertSame( 'http://dest.test', $status['claimed_by'] );
	}

	/**
	 * Formatting a key for a human does not stop it working.
	 */
	public function test_normalise_tolerates_how_people_paste_things() {
		$key = \str_repeat( 'ab', 24 );

		$this->assertSame( $key, TransferKey::normalise( $key ) );
		$this->assertSame( $key, TransferKey::normalise( \strtoupper( $key ) ), 'Case does not matter.' );
		$this->assertSame( $key, TransferKey::normalise( " {$key}\n" ), 'Nor does stray whitespace.' );
		$this->assertSame(
			$key,
			TransferKey::normalise( \implode( '-', \str_split( $key, 8 ) ) ),
			'Nor dashes somebody added to read it aloud.'
		);
	}

	/**
	 * Anything that is not 48 hex characters is not a key.
	 */
	public function test_normalise_refuses_the_wrong_shape() {
		$this->assertSame( '', TransferKey::normalise( '' ) );
		$this->assertSame( '', TransferKey::normalise( 'too-short' ) );
		$this->assertSame( '', TransferKey::normalise( \str_repeat( 'ab', 32 ) ), 'Too long is wrong too.' );
	}

	/**
	 * Presenting rubbish repeatedly must not destroy a legitimate key.
	 *
	 * The rate limit refuses for a window; it does not revoke. A key a stranger could burn by
	 * guessing at it would hand them a way to stop a migration they cannot otherwise touch.
	 */
	public function test_hammering_the_key_does_not_destroy_it() {
		$key = TransferKey::issue();

		for ( $i = 0; $i < TransferKey::MAX_ATTEMPT + 5; $i++ ) {
			TransferKey::verify( \str_repeat( 'f', 48 ), '10.0.0.9' );
		}

		$this->assertTrue( TransferKey::status()['active'], 'The key survives being guessed at.' );
	}

	/**
	 * Bytes sent are counted, which is what the source screen reports.
	 */
	public function test_sent_bytes_accumulate() {
		TransferKey::issue();

		TransferKey::sent( 100 );
		TransferKey::sent( 250 );

		$this->assertSame( 350, TransferKey::status()['sent'] );
	}
}
