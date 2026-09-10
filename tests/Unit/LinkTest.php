<?php
/**
 * The standing link that removes the second copy-paste.
 *
 * Pairing proves both ends once: somebody minted a code on the destination, somebody typed it on
 * the source. The token minted in that moment is what lets the destination ask, later, whether
 * there is a package for it — so the questions worth pinning down are what it is worth on its own
 * (nothing), what it hands over and how often (a key, once per offer), and that a stranger
 * guessing at it cannot break a migration they cannot otherwise touch.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use NewfoldLabs\WP\SiteMigrator\Core\Transfer\Link;
use PHPUnit\Framework\TestCase;

class LinkTest extends TestCase {

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
	 * A token is long, random, and stored only as a hash.
	 */
	public function test_the_token_is_long_and_not_stored() {
		$token = Link::issue( 'https://destination.test' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{48}$/', $token );
		$this->assertNotSame( $token, Link::issue( 'https://destination.test' ) );

		$stored = \wp_json_encode( \get_option( NFD_SM_LINK_OPTION ) );

		$this->assertStringNotContainsString( $token, (string) $stored, 'The token itself must not be kept.' );
	}

	/**
	 * The right token is recognised and a wrong one is not.
	 */
	public function test_only_the_issued_token_verifies() {
		$token = Link::issue( 'https://destination.test' );

		$this->assertTrue( Link::verify( $token ) );
		$this->assertFalse( Link::verify( \str_repeat( 'a', 48 ) ) );
		$this->assertFalse( Link::verify( '' ) );
	}

	/**
	 * A link with nothing on offer hands over nothing.
	 *
	 * This is the property that makes the token safe to leave lying on another server: until an
	 * administrator here presses Offer, presenting it answers the same 404 a stranger gets.
	 */
	public function test_a_link_is_worth_nothing_until_a_package_is_offered() {
		Link::issue( 'https://destination.test' );

		$this->assertFalse( Link::is_offered() );
		$this->assertFalse( Link::claim( 'https://destination.test' ) );
	}

	/**
	 * The offer is claimable once, and pressing Offer again is what re-opens it.
	 */
	public function test_an_offer_is_claimed_once() {
		Link::issue( 'https://destination.test' );

		$this->assertTrue( Link::offer() );
		$this->assertTrue( Link::is_offered() );
		$this->assertTrue( Link::claim( 'https://destination.test' ) );
		$this->assertFalse( Link::claim( 'https://somewhere.else' ), 'A second claim on one offer is refused.' );

		$status = Link::status();

		$this->assertSame( 'https://destination.test', $status['handed_to'] );
		$this->assertGreaterThan( 0, $status['handed_at'] );

		Link::offer();

		$this->assertTrue( Link::claim( 'https://destination.test' ), 'Offering again re-opens it.' );
	}

	/**
	 * Withdrawing keeps the link and takes back the offer.
	 */
	public function test_withdrawing_keeps_the_link() {
		Link::issue( 'https://destination.test' );
		Link::offer();
		Link::withdraw();

		$this->assertFalse( Link::is_offered() );
		$this->assertTrue( Link::exists() );
	}

	/**
	 * Nothing can be offered when there is no link at all.
	 */
	public function test_nothing_is_offered_without_a_link() {
		$this->assertFalse( Link::offer() );
		$this->assertFalse( Link::exists() );
		$this->assertFalse( Link::status()['linked'] );
	}

	/**
	 * Guessing is rate limited, and a burst of wrong answers does not destroy the link.
	 *
	 * The second half matters more than the first: a token a stranger could burn by presenting
	 * rubbish would hand them a way to stop a migration they have no other access to.
	 */
	public function test_guessing_is_limited_and_does_not_burn_the_link() {
		$token = Link::issue( 'https://destination.test' );

		for ( $i = 0; $i < Link::MAX_ATTEMPT; $i++ ) {
			$this->assertFalse( Link::verify( \str_repeat( 'b', 48 ) ) );
		}

		$this->assertFalse( Link::verify( $token ), 'The window closes for everybody, including the real caller.' );
		$this->assertTrue( Link::exists(), 'But the link survives it.' );
	}

	/**
	 * A token that is not the right shape at all is refused before anything is compared.
	 */
	public function test_normalise_takes_decoration_and_refuses_nonsense() {
		$token = Link::issue( 'https://destination.test' );

		$this->assertSame( $token, Link::normalise( " {$token}\n" ) );
		$this->assertSame( '', Link::normalise( 'not-a-token' ) );
	}
}
