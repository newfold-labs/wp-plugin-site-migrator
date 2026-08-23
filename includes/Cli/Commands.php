<?php
/**
 * WP-CLI adapter.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Cli;

use NewfoldLabs\WP\SiteMigrator\Core\Export\Exporter;
use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageReader;

/**
 * Minimal command surface over the core.
 *
 * This is a harness, not the v3 product. Its job is to give `Core/` a second consumer from the
 * day `Core/` exists: a lint rule can stop `$_POST` appearing in the core, but only a real
 * second caller stops the core being quietly shaped around one transport's assumptions. It also
 * makes the round-trip test a shell script instead of a browser harness.
 *
 * Flags, machine-readable output, progress bars and exit-code contracts are phase 6.
 */
class Commands {

	/**
	 * Register the commands with WP-CLI.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! \defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'site-migrator export', array( __CLASS__, 'export' ) );
		\WP_CLI::add_command( 'site-migrator verify', array( __CLASS__, 'verify' ) );
		\WP_CLI::add_command( 'site-migrator import', array( __CLASS__, 'import' ) );
	}

	/**
	 * Export this site into a package directory.
	 *
	 * ## OPTIONS
	 *
	 * [--to=<dir>]
	 * : Directory to write the package into. Defaults to the plugin's storage directory.
	 *
	 * [--budget=<seconds>]
	 * : Stop and checkpoint after this many seconds per step, then continue. Defaults to no
	 * limit, which is right under WP-CLI where max_execution_time is 0.
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-migrator export --to=/tmp/mysite
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function export( $args, $assoc_args ) {
		$dir    = isset( $assoc_args['to'] ) ? $assoc_args['to'] : \nfd_sm_storage_path() . 'package';
		$budget = isset( $assoc_args['budget'] ) ? (float) $assoc_args['budget'] : 0;

		$exporter = new Exporter( $dir );
		$exporter->set_progress( new CliProgressReporter() );

		if ( $exporter->is_resumable() ) {
			\WP_CLI::log( 'Resuming an interrupted export.' );
		}

		$started = \microtime( true );

		try {
			$state = $exporter->run( $budget );
		} catch ( \Exception $e ) {
			\WP_CLI::error( $e->getMessage() );

			return;
		}

		\WP_CLI::success(
			\sprintf(
				'Exported %d files, %s, in %.1fs to %s',
				$state['files'],
				\size_format( $state['bytes'] ),
				\microtime( true ) - $started,
				$exporter->dir()
			)
		);
	}

	/**
	 * Check a package against its manifest.
	 *
	 * ## OPTIONS
	 *
	 * <dir>
	 * : The package directory.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function verify( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Give me a package directory.' );

			return;
		}

		$reader   = new PackageReader( $args[0] );
		$problems = $reader->verify();

		if ( ! empty( $problems ) ) {
			foreach ( $problems as $problem ) {
				\WP_CLI::log( '  ' . $problem );
			}

			\WP_CLI::error( \sprintf( '%d problem(s) found.', \count( $problems ) ) );

			return;
		}

		$summary = $reader->inspect();

		\WP_CLI::log( \sprintf( 'Source:   %s', \nfd_sm_data_get( $summary, 'source.site_url', '?' ) ) );
		\WP_CLI::log( \sprintf( 'Created:  %s', \nfd_sm_data_get( $summary, 'created_at', '?' ) ) );
		\WP_CLI::log(
			\sprintf(
				'Contents: %d files, %s',
				(int) \nfd_sm_data_get( $summary, 'totals.files', 0 ),
				\size_format( (int) \nfd_sm_data_get( $summary, 'totals.bytes', 0 ) )
			)
		);

		\WP_CLI::success( 'Package verified.' );
	}

	/**
	 * Import a package into this site.
	 *
	 * ## OPTIONS
	 *
	 * <dir>
	 * : The package directory.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function import( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		\WP_CLI::error(
			'Import is not built yet. It arrives in phase 4a, where round-trip green is the '
			. 'exit criterion. See docs/implementation-plan.md.'
		);
	}
}
