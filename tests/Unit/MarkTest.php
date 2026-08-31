<?php
/**
 * The logo exists twice, so it is checked twice.
 *
 * The masthead draws it as an inline React SVG; the admin menu needs a base64 data URI, and takes
 * nothing else. Neither form can be generated from the other -- one is a tile with a white glyph
 * on it, the other is a tile with the glyph knocked *out* of it, because WordPress gives a
 * background-image menu icon no hover or current state and a hole picks those up from the item
 * behind it.
 *
 * What can be checked is that the geometry has not drifted. Two hand-maintained copies of a path
 * eventually become two different logos, and the one nobody looks at is the one in the sidebar.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;

class MarkTest extends TestCase {

	/**
	 * The shapes that make up the glyph, as they are written in both files.
	 *
	 * @return array
	 */
	public function shapes() {
		return array(
			array( 'x="15.8" y="10.5" width="10.4" height="11" rx="2.9"' ),
			array( 'M15.8 15.1h10.4' ),
			array( 'M7.6 13.2h5M5.4 18.8h7' ),
		);
	}

	/**
	 * @dataProvider shapes
	 *
	 * @param string $shape One piece of the glyph.
	 */
	public function test_the_menu_icon_draws_the_same_glyph_as_the_masthead( $shape ) {
		$this->assertStringContainsString( $shape, $this->menu_svg() );
		$this->assertStringContainsString( $shape, $this->masthead_source() );
	}

	/**
	 * The tile is the same rounded square in both.
	 */
	public function test_the_tile_is_the_same_rounded_square() {
		$tile = 'width="32" height="32" rx="9"';

		$this->assertStringContainsString( $tile, $this->menu_svg() );
		$this->assertStringContainsString( $tile, $this->masthead_source() );
	}

	/**
	 * WordPress accepts exactly one encoding here, and a menu icon it cannot read is a blank
	 * square in the sidebar rather than an error anywhere.
	 */
	public function test_the_menu_icon_is_a_base64_svg_data_uri() {
		$uri = \nfd_sm_menu_icon();

		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $uri );
		$this->assertStringStartsWith( '<svg', $this->menu_svg() );
		$this->assertStringEndsWith( '</svg>', $this->menu_svg() );
	}

	/**
	 * Decoded menu icon.
	 *
	 * @return string
	 */
	protected function menu_svg() {
		return (string) \base64_decode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			\substr( \nfd_sm_menu_icon(), \strlen( 'data:image/svg+xml;base64,' ) ),
			true
		);
	}

	/**
	 * The React component, read as text. There is no JS runtime here, and the point is the
	 * literal path data rather than anything it renders to.
	 *
	 * @return string
	 */
	protected function masthead_source() {
		return (string) \file_get_contents( __DIR__ . '/../../src/components/Masthead.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
