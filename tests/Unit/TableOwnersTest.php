<?php
/**
 * Matching a site's tables to the plugins that name them.
 *
 * The scan is a heuristic and is treated as one everywhere it is used, so what these assert is
 * the shape of its honesty rather than cleverness: it finds the two ways a plugin really writes a
 * table name, it never returns a table the site does not have, and what it cannot claim is
 * reported as unclaimed instead of guessed at.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use NewfoldLabs\WP\SiteMigrator\Core\Export\TableOwners;
use PHPUnit\Framework\TestCase;

class TableOwnersTest extends TestCase {

	/**
	 * Where the fake plugins live for the current test.
	 *
	 * @var string
	 */
	protected $dir = '';

	protected function setUp(): void {
		parent::setUp();

		$this->dir = \sys_get_temp_dir() . '/nfd-owners-' . \uniqid();

		\mkdir( $this->dir, 0777, true );
	}

	protected function tearDown(): void {
		\Fixture::rmdir( $this->dir );

		parent::tearDown();
	}

	/**
	 * Write a one-file plugin.
	 *
	 * @param string $slug   Directory name.
	 * @param string $source Its PHP.
	 *
	 * @return string The directory.
	 */
	protected function plugin( $slug, $source ) {
		$dir = $this->dir . '/' . $slug;

		\mkdir( $dir, 0777, true );
		\file_put_contents( $dir . '/' . $slug . '.php', "<?php\n" . $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $dir;
	}

	/**
	 * Both spellings, and only tables the site really has.
	 */
	public function test_it_finds_the_two_ways_a_table_is_named() {
		$dir = $this->plugin(
			'acme-widgets',
			'$one = $wpdb->prefix . \'acme_log\';' . "\n"
				. '$two = "{$wpdb->prefix}acme_queue";' . "\n"
				. '$three = $wpdb->base_prefix."acme_network";' . "\n"
				. '$gone = $wpdb->prefix . \'acme_removed_in_v2\';'
		);

		$owners = new TableOwners(
			array( 'wp_posts', 'wp_acme_log', 'wp_acme_queue', 'wp_acme_network', 'wp_other' ),
			'wp_'
		);

		$this->assertSame(
			array( 'wp_acme_log', 'wp_acme_queue', 'wp_acme_network' ),
			$owners->owned_by( $dir )
		);
	}

	/**
	 * A name the code builds at runtime is not claimed, and is not lost either.
	 *
	 * This is the limit of the technique stated as a test. `$wpdb->prefix . $name` cannot be
	 * resolved without running the plugin, so the table it makes belongs to nobody as far as this
	 * is concerned -- and it turns up in `unclaimed()`, which is what the picker offers so that a
	 * person can tick it themselves.
	 */
	public function test_a_name_built_at_runtime_is_left_unclaimed() {
		$dir = $this->plugin(
			'mystery',
			'$table = $wpdb->prefix . $this->name;'
		);

		$owners = new TableOwners( array( 'wp_posts', 'wp_mystery_data' ), 'wp_' );
		$map    = $owners->map( array( 'mystery' => $dir ) );

		$this->assertSame( array(), $map );
		$this->assertSame( array( 'wp_mystery_data' ), $owners->unclaimed( $map ) );
	}

	/**
	 * Core's own tables are never offered as somebody's extras.
	 *
	 * A plugin naming `$wpdb->prefix . 'options'` -- which many do -- must not come out of this
	 * owning the options table. It is claimed like any other match, but the *unclaimed* list is
	 * what the picker offers as carryable extras, and core's tables are not extras.
	 */
	public function test_core_tables_are_not_offered_as_extras() {
		$owners = new TableOwners( array( 'wp_posts', 'wp_options', 'wp_acme_log' ), 'wp_' );

		$this->assertSame( array( 'wp_acme_log' ), $owners->unclaimed( array() ) );
	}

	/**
	 * Another install's tables in the same database are not this site's.
	 */
	public function test_a_table_under_a_different_prefix_is_not_matched() {
		$dir = $this->plugin( 'acme-widgets', '$one = $wpdb->prefix . \'acme_log\';' );

		$owners = new TableOwners( array( 'wp7_acme_log', 'wp_acme_log' ), 'wp_' );

		$this->assertSame( array( 'wp_acme_log' ), $owners->owned_by( $dir ) );
	}
	/**
	 * Settings are found the same way, and the same rule keeps them honest.
	 *
	 * A plugin names its options as literals because the options API takes nothing else, so this
	 * is a better signal than the table scan rather than a worse one. What it returns is still
	 * intersected with the options this site actually holds -- a key for a feature nobody switched
	 * on is not there to carry, and a key another plugin happens to share is claimed by both,
	 * which is why the picker shows the list rather than acting on it.
	 */
	public function test_it_finds_the_options_a_plugin_reads_and_writes() {
		$dir = $this->plugin(
			'acme-widgets',
			'add_option( \'acme_version\', 1 );' . "\n"
				. '$s = get_option( "acme_settings", array() );' . "\n"
				. 'update_site_option( \'acme_network_key\', $k );' . "\n"
				. '$dynamic = get_option( $this->key );'
		);

		$owners = new TableOwners( array(), 'wp_' );

		$this->assertSame(
			array( 'acme_settings', 'acme_version' ),
			$owners->options_in(
				$dir,
				array( 'siteurl', 'acme_settings', 'acme_version', 'acme_never_saved' )
			)
		);
	}

	/**
	 * And core's own options are never offered, however loudly a plugin names them.
	 *
	 * Found on a real run of the two-site test: a plugin calling `get_option( 'home' )` -- which
	 * is most of them -- came back owning `home`, and a checkbox reading "bring this plugin's
	 * settings" would have meant "adopt the other site's address". The save refuses it either
	 * way; this stops it being drawn at all.
	 */
	public function test_a_core_option_a_plugin_merely_reads_is_not_its_setting() {
		$dir = $this->plugin(
			'acme-widgets',
			'$where = get_option( \'home\' );' . "\n"
				. '$who = get_option( \'active_plugins\' );' . "\n"
				. '$mine = get_option( \'acme_settings\' );'
		);

		$owners = new TableOwners( array(), 'wp_' );

		$this->assertSame(
			array( 'acme_settings' ),
			$owners->options_in( $dir, array( 'home', 'active_plugins', 'acme_settings' ) )
		);
	}
}
