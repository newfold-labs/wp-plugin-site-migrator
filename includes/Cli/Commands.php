<?php
/**
 * WP-CLI adapter.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Cli;

use NewfoldLabs\WP\SiteMigrator\Core\Export\Exporter;
use NewfoldLabs\WP\SiteMigrator\Core\Export\PartSpecs;
use NewfoldLabs\WP\SiteMigrator\Core\Export\Selection;
use NewfoldLabs\WP\SiteMigrator\Core\Import\Importer;
use NewfoldLabs\WP\SiteMigrator\Core\Import\Upload;
use NewfoldLabs\WP\SiteMigrator\Core\Import\UserMerger;
use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageReader;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Checker;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Compatibility;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Pairing;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\SiteProfile;
use NewfoldLabs\WP\SiteMigrator\Core\Transfer\Link;
use NewfoldLabs\WP\SiteMigrator\Core\Transfer\LinkedSource;
use NewfoldLabs\WP\SiteMigrator\Core\Transfer\Offer;
use NewfoldLabs\WP\SiteMigrator\Core\Transfer\Puller;
use NewfoldLabs\WP\SiteMigrator\Core\Transfer\Source;
use NewfoldLabs\WP\SiteMigrator\Core\Transfer\TransferKey;

/**
 * Minimal command surface over the core.
 *
 * This is a harness, not the v3 product. Its job is to give `Core/` a second consumer from the
 * day `Core/` exists: a lint rule can stop `$_POST` appearing in the core, but only a real
 * second caller stops the core being quietly shaped around one transport's assumptions. It also
 * makes the round-trip test a shell script instead of a browser harness.
 *
 * Phase 6 promoted it: `preflight` and `inspect` joined the eight commands that were already
 * here, and the machine contract -- formats, exit codes, stdout/stderr, never prompting -- lives
 * in `Output`. The commands themselves stayed as they were, which is the point: a surface built
 * against `Core/` from the beginning needed additions, not a rewrite.
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

		\WP_CLI::add_command( 'site-migrator preflight', array( __CLASS__, 'preflight' ) );
		\WP_CLI::add_command( 'site-migrator export', array( __CLASS__, 'export' ) );
		\WP_CLI::add_command( 'site-migrator contents', array( __CLASS__, 'contents' ) );
		\WP_CLI::add_command( 'site-migrator inspect', array( __CLASS__, 'inspect' ) );
		\WP_CLI::add_command( 'site-migrator verify', array( __CLASS__, 'verify' ) );
		\WP_CLI::add_command( 'site-migrator import', array( __CLASS__, 'import' ) );
		\WP_CLI::add_command( 'site-migrator rollback', array( __CLASS__, 'rollback' ) );
		\WP_CLI::add_command( 'site-migrator cancel', array( __CLASS__, 'cancel' ) );
		\WP_CLI::add_command( 'site-migrator confirm', array( __CLASS__, 'confirm' ) );
		\WP_CLI::add_command( 'site-migrator offer', array( __CLASS__, 'offer' ) );
		\WP_CLI::add_command( 'site-migrator pull', array( __CLASS__, 'pull' ) );
	}

	/**
	 * Check whether this site can take part in a migration.
	 *
	 * With no arguments this reports the local gates only -- what this install can do on its own.
	 * With `--against` and a pairing code it also fetches the other site's facts over HTTP and
	 * runs the full comparison, which is the same check the export screen makes.
	 *
	 * ## OPTIONS
	 *
	 * [--against=<url>]
	 * : Address of the destination to compare against. Needs --code.
	 *
	 * [--code=<code>]
	 * : The pairing code that destination is showing.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml. Defaults to table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-migrator preflight
	 *     wp site-migrator preflight --against=https://new.example.com --code=A1B2-C3D4-E5F6
	 *     wp site-migrator preflight --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function preflight( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$against = isset( $assoc_args['against'] ) ? (string) $assoc_args['against'] : '';
		$code    = isset( $assoc_args['code'] ) ? (string) $assoc_args['code'] : '';

		if ( '' !== $against && '' === $code ) {
			Output::fail( 'Comparing against another site needs its pairing code too. Pass --code.' );

			return;
		}

		$local   = Checker::run();
		$report  = $local->to_array();
		$payload = array(
			'site'  => \get_site_url(),
			'local' => $report,
		);

		// The local gates are this site's own business and are reported whether or not there is
		// another site to compare with. A destination that cannot be reached is a failure of the
		// comparison, not of the checks that already ran.
		if ( '' !== $against ) {
			$answer = Pairing::fetch_profile( $against, $code );

			if ( isset( $answer['error'] ) ) {
				Output::fail( $answer['error'] );

				return;
			}

			$comparison = new Compatibility( SiteProfile::gather( true ), $answer['profile'] );
			$report     = $comparison->check()->to_array();

			$payload['against']       = $against;
			$payload['compatibility'] = $report;
		}

		Output::emit(
			$assoc_args,
			$payload,
			Output::report_rows( $report ),
			array( 'check', 'status', 'detail' )
		);

		if ( empty( $report['ok'] ) ) {
			Output::fail(
				'' === $against
					? 'This site cannot be migrated as it stands.'
					: 'These two sites cannot be migrated between as they stand.',
				Output::EXIT_INCOMPATIBLE
			);

			return;
		}

		Output::progress( 'Success: Nothing is blocking a migration.' );
	}

	/**
	 * Read a package's manifest without re-reading its bytes.
	 *
	 * The cheap counterpart to `verify`: what the package holds, where it came from and what was
	 * left out, answered from the manifest alone. `verify` re-reads every byte and checks it
	 * against that manifest, which on a large package is minutes rather than milliseconds.
	 *
	 * ## OPTIONS
	 *
	 * <dir>
	 * : The package directory.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml. Defaults to table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-migrator inspect /tmp/mysite
	 *     wp site-migrator inspect /tmp/mysite --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function inspect( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			Output::fail( 'Give me a package directory.', Output::EXIT_INVALID_PACKAGE );

			return;
		}

		$reader = new PackageReader( $args[0] );

		if ( ! $reader->is_complete() ) {
			Output::fail(
				'There is no finished package there: the manifest is missing, which is what marks one complete.',
				Output::EXIT_INVALID_PACKAGE
			);

			return;
		}

		$summary = $reader->inspect();

		$fields = array(
			'source'        => (string) \nfd_sm_data_get( $summary, 'source.site_url', '' ),
			'created'       => (string) \nfd_sm_data_get( $summary, 'created_at', '' ),
			'wordpress'     => (string) \nfd_sm_data_get( $summary, 'source.wp_version', '' ),
			'php'           => (string) \nfd_sm_data_get( $summary, 'source.php_version', '' ),
			'prefix'        => (string) \nfd_sm_data_get( $summary, 'source.table_prefix', '' ),
			'files'         => (string) (int) \nfd_sm_data_get( $summary, 'totals.files', 0 ),
			'bytes'         => \size_format( (int) \nfd_sm_data_get( $summary, 'totals.bytes', 0 ) ),
			'parts'         => (string) \count( (array) \nfd_sm_data_get( $summary, 'parts', array() ) ),
			'skipped_paths' => (string) (int) \nfd_sm_data_get( $summary, 'skipped_paths.total', 0 ),
			'skipped_links' => (string) (int) \nfd_sm_data_get( $summary, 'skipped_links.total', 0 ),
		);

		$rows = array();

		foreach ( $fields as $name => $value ) {
			$rows[] = array(
				'field' => $name,
				'value' => $value,
			);
		}

		Output::emit( $assoc_args, $summary, $rows, array( 'field', 'value' ) );
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
	 * [--max-time=<seconds>]
	 * : Give up the whole command after this long, leaving a checkpoint behind and exiting 3.
	 * Run the same command again to continue. Defaults to running until it finishes.
	 *
	 * [--all]
	 * : Package the whole site, ignoring what `wp site-migrator contents` has saved. Only
	 * applies to a run that has not started: an interrupted export always resumes with the
	 * choices it began with.
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-migrator export --to=/tmp/mysite
	 *     wp site-migrator export --max-time=60   # exits 3 if it needs longer
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function export( $args, $assoc_args ) {
		$dir    = isset( $assoc_args['to'] ) ? $assoc_args['to'] : \nfd_sm_storage_path() . 'package';
		$budget = isset( $assoc_args['budget'] ) ? (float) $assoc_args['budget'] : 0;

		// The local gates decide whether this site can be packaged at all, and until now `export`
		// never asked -- so a site whose package directory is downloadable over the web wrote a
		// full database dump into it and reported success. `preflight` blocked; the command that
		// does the writing did not.
		$gates = Checker::run();

		if ( $gates->is_blocked() ) {
			foreach ( Output::report_rows( $gates->to_array() ) as $row ) {
				if ( 'block' === $row['status'] ) {
					Output::progress( '  ' . $row['detail'] );
				}
			}

			Output::fail(
				'This site cannot be packaged as it stands. Run `wp site-migrator preflight` for the detail.',
				Output::EXIT_INCOMPATIBLE
			);

			return;
		}

		$exporter = new Exporter( $dir );
		$exporter->set_progress( new CliProgressReporter() );

		$selection = empty( $assoc_args['all'] ) ? Selection::current() : Selection::everything();
		$exporter->set_selection( $selection );

		if ( ! $selection->is_everything() ) {
			Output::progress( 'Leaving out: ' . \implode( '; ', $selection->describe() ) . '.' );
		}

		if ( $exporter->is_resumable() ) {
			Output::progress( 'Resuming an interrupted export.' );
		}

		$started = \microtime( true );

		try {
			$state = self::drive( $exporter, $budget, self::max_time( $assoc_args ) );
		} catch ( \Exception $e ) {
			Output::fail( $e->getMessage() );

			return;
		}

		// Same contract as the import: a budget that runs out is a pause, and the caller has to
		// be able to tell that from finishing.
		if ( empty( $state['done'] ) ) {
			Output::progress(
				\sprintf(
					'Stopped after %.1fs with work outstanding. Run the same command again to continue.',
					\microtime( true ) - $started
				)
			);

			\WP_CLI::halt( Output::EXIT_RESUMABLE );

			return;
		}

		Output::progress(
			\sprintf(
				'Success: Exported %d files, %s, in %.1fs to %s',
				$state['files'],
				\size_format( $state['bytes'] ),
				\microtime( true ) - $started,
				$exporter->dir()
			)
		);
	}

	/**
	 * Choose what an export puts in the package.
	 *
	 * With no options it prints what the next export will leave out. The selection is stored on
	 * the site, so a run started in the browser and a run started here package the same thing.
	 *
	 * ## OPTIONS
	 *
	 * [--list]
	 * : Print the parts and what can be ticked off inside each of them, instead of the current
	 * selection. These are the names `--set` expects.
	 *
	 * [--set=<file>]
	 * : Read a selection from a JSON file and store it. `-` reads standard input. The shape is
	 * `{"parts":{"uploads":false},"paths":{"plugins":["akismet"]},"database":{"skip_revisions":true}}`.
	 * Anything unrecognised, and any choice that would break the destination, is dropped.
	 *
	 * [--reset]
	 * : Forget the selection, so the next export carries the whole site.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml. Defaults to table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-migrator contents
	 *     wp site-migrator contents --list
	 *     echo '{"database":{"skip_revisions":true}}' | wp site-migrator contents --set=-
	 *     wp site-migrator contents --reset
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function contents( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! empty( $assoc_args['reset'] ) ) {
			Selection::forget();
			Output::progress( 'Success: the next export will carry the whole site.' );

			return;
		}

		if ( ! empty( $assoc_args['list'] ) ) {
			self::contents_catalog( $assoc_args );

			return;
		}

		if ( isset( $assoc_args['set'] ) ) {
			$raw = '-' === $assoc_args['set']
				// phpcs:ignore WordPress.WP.AlternativeFunctions
				? \file_get_contents( 'php://stdin' )
				// phpcs:ignore WordPress.WP.AlternativeFunctions
				: ( \is_readable( $assoc_args['set'] ) ? \file_get_contents( $assoc_args['set'] ) : false );

			if ( false === $raw ) {
				Output::fail( 'Could not read that file.', Output::EXIT_FAILURE );

				return;
			}

			$decoded = \json_decode( (string) $raw, true );

			if ( ! \is_array( $decoded ) ) {
				Output::fail( 'That file is not a JSON object.', Output::EXIT_FAILURE );

				return;
			}

			Selection::store( $decoded );
		}

		$selection = Selection::current();
		$leaving   = $selection->describe();

		Output::emit(
			$assoc_args,
			\array_merge(
				$selection->to_array(),
				array(
					'everything' => $selection->is_everything(),
					'leaving'    => $leaving,
				)
			),
			empty( $leaving )
				? array( array( 'leaving_out' => 'nothing, the whole site is packaged' ) )
				: \array_map(
					function ( $phrase ) {
						return array( 'leaving_out' => $phrase );
					},
					$leaving
				),
			array( 'leaving_out' )
		);
	}

	/**
	 * Print the choosable parts and their contents.
	 *
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	protected static function contents_catalog( array $assoc_args ) {
		$rows = array();

		foreach ( PartSpecs::catalog() as $part ) {
			$rows[] = array(
				'part'     => $part['name'],
				'prefix'   => $part['prefix'],
				'contains' => empty( $part['children'] )
					? '-'
					: \implode( ', ', \array_slice( $part['children'], 0, 12 ) )
						. ( \count( $part['children'] ) > 12 ? ', …' : '' ),
			);
		}

		Output::emit(
			$assoc_args,
			array( 'parts' => PartSpecs::catalog() ),
			$rows,
			array( 'part', 'prefix', 'contains' )
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
	 * [--format=<format>]
	 * : table, json, csv or yaml. Defaults to table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function verify( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			Output::fail( 'Give me a package directory.', Output::EXIT_INVALID_PACKAGE );

			return;
		}

		$reader   = new PackageReader( $args[0] );
		$problems = $reader->verify();

		if ( ! empty( $problems ) ) {
			Output::emit(
				$assoc_args,
				array(
					'ok'       => false,
					'problems' => $problems,
				),
				\array_map(
					function ( $problem ) {
						return array( 'problem' => $problem );
					},
					$problems
				),
				array( 'problem' )
			);

			Output::fail(
				\sprintf( '%d problem(s) found.', \count( $problems ) ),
				Output::EXIT_INVALID_PACKAGE
			);

			return;
		}

		$summary = $reader->inspect();

		Output::emit(
			$assoc_args,
			array(
				'ok'       => true,
				'problems' => array(),
				'package'  => $summary,
			),
			array(
				array(
					'source'  => (string) \nfd_sm_data_get( $summary, 'source.site_url', '' ),
					'created' => (string) \nfd_sm_data_get( $summary, 'created_at', '' ),
					'files'   => (string) (int) \nfd_sm_data_get( $summary, 'totals.files', 0 ),
					'bytes'   => \size_format( (int) \nfd_sm_data_get( $summary, 'totals.bytes', 0 ) ),
				),
			),
			array( 'source', 'created', 'files', 'bytes' )
		);

		Output::progress( 'Success: Package verified.' );
	}

	/**
	 * Import a package into this site.
	 *
	 * ## OPTIONS
	 *
	 * <dir>
	 * : The package directory.
	 *
	 * [--budget=<seconds>]
	 * : Stop and checkpoint after this many seconds per step, then continue. Defaults to no
	 * limit, which is right under WP-CLI where max_execution_time is 0.
	 *
	 * [--max-time=<seconds>]
	 * : Give up the whole command after this long, leaving a checkpoint behind and exiting 3.
	 * Run the same command again to continue. Never stops after the swap has begun.
	 *
	 * [--mode=<mode>]
	 * : `merge` keeps this site's user accounts alongside the source's. `replace` keeps only
	 * the source's, plus whoever is running the import. Defaults to merge.
	 *
	 * [--as=<user>]
	 * : Login or ID of the account whose administrator access is guaranteed afterwards.
	 * Defaults to this site's lowest-numbered administrator.
	 *
	 * [--url-to=<url>]
	 * : Rewrite the source's URLs to this one instead of this site's own.
	 *
	 * [--discard-backup]
	 * : Drop the replaced tables once the import finishes. Rollback is then impossible.
	 *
	 * [--restart]
	 * : Throw away a recorded run and start from the beginning.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-migrator import /tmp/mysite --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function import( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			Output::fail( 'Give me a package directory.', Output::EXIT_INVALID_PACKAGE );

			return;
		}

		$dir    = $args[0];
		$budget = isset( $assoc_args['budget'] ) ? (float) $assoc_args['budget'] : 0;

		$options = array(
			'mode'        => isset( $assoc_args['mode'] ) ? $assoc_args['mode'] : UserMerger::MODE_MERGE,
			'acting_user' => self::acting_user( $assoc_args ),
			'site_url'    => isset( $assoc_args['url-to'] ) ? $assoc_args['url-to'] : '',
			'home_url'    => isset( $assoc_args['url-to'] ) ? $assoc_args['url-to'] : '',
			'keep_backup' => ! isset( $assoc_args['discard-backup'] ),
		);

		$importer = new Importer( $dir, $options );
		$importer->set_progress( new CliProgressReporter() );

		if ( isset( $assoc_args['restart'] ) ) {
			$importer->restart();
		}

		if ( $importer->is_complete() ) {
			Output::fail(
				'This package has already been imported into this site. Roll it back first, or pass '
				. '--restart to run it again.'
			);

			return;
		}

		if ( $importer->is_resumable() ) {
			Output::progress( 'Resuming an interrupted import.' );
		} else {

			// Asked *before* the confirmation rather than after it, so a run that was never going
			// to work says so instead of first making somebody agree to it. `preview()` is the
			// same call the review screen makes, which is what keeps the two surfaces agreeing
			// about what counts as a blocker.
			$preview = $importer->preview();

			if ( ! empty( $preview['problems'] ) ) {
				foreach ( $preview['problems'] as $problem ) {
					Output::progress( '  ' . $problem );
				}

				Output::fail( 'That package cannot be read.', Output::EXIT_INVALID_PACKAGE );

				return;
			}

			if ( empty( $preview['ok'] ) ) {
				foreach ( Output::report_rows( (array) \nfd_sm_data_get( $preview, 'report', array() ) ) as $row ) {
					if ( 'block' === $row['status'] ) {
						Output::progress( '  ' . $row['detail'] );
					}
				}

				Output::fail(
					'This site cannot accept that package as it stands.',
					Output::EXIT_INCOMPATIBLE
				);

				return;
			}

			Output::confirm(
				\sprintf(
					'This replaces every table on %s with the contents of that package.',
					\get_site_url()
				),
				$assoc_args
			);
		}

		$started = \microtime( true );
		$state   = self::drive( $importer, $budget, self::max_time( $assoc_args ) );

		foreach ( $state['refused'] as $path ) {
			\WP_CLI::warning( 'Refused an unsafe path in the package: ' . $path );
		}

		if ( '' !== $state['error'] ) {
			Output::fail( $state['error'] );

			return;
		}

		// A budget that expires mid-import is the normal way to run this on a host with a time
		// limit, and it is not a failure: the checkpoint is written and the same command picks
		// up where it stopped. Exiting 0 would tell a wrapper script the migration had finished.
		if ( empty( $state['done'] ) ) {
			Output::progress(
				\sprintf(
					'Stopped after %.1fs with work outstanding. Run the same command again to continue.',
					\microtime( true ) - $started
				)
			);

			\WP_CLI::halt( Output::EXIT_RESUMABLE );

			return;
		}

		foreach ( $state['notes'] as $note ) {
			Output::progress( '  ' . $note );
		}

		self::report_users( $state['users'] );
		self::report_manual( $state['manual'] );

		\WP_CLI::success(
			\sprintf(
				'Imported %d files and %d rows in %.1fs. %s is now serving the migrated site.',
				$state['files'],
				$state['rows'],
				\microtime( true ) - $started,
				\get_site_url()
			)
		);
	}

	/**
	 * Undo the import this site last ran.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function rollback( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		Output::confirm( 'This puts the site back as it was before the import.', $assoc_args );

		// No package argument: the run is recorded against the site, not the package, and
		// naming the wrong directory should not be a way to roll back the wrong thing.
		$importer = new Importer( '' );

		try {
			$state = $importer->rollback();
		} catch ( \Exception $e ) {
			Output::fail( $e->getMessage() );

			return;
		}

		foreach ( (array) $state['notes'] as $note ) {
			\WP_CLI::log( $note );
		}

		\WP_CLI::success( 'Rolled back.' );
	}

	/**
	 * Abandon an import that has not been swapped in.
	 *
	 * Drops the staged tables and forgets the run. The live site is not touched, because an
	 * import that has not reached the swap has never touched it.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function cancel( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		Output::confirm( 'This discards the staged import. The live site is not affected.', $assoc_args );

		$importer = new Importer( '' );
		$importer->cancel();

		\WP_CLI::success( 'Cancelled.' );
	}

	/**
	 * Confirm a completed import, discarding the tables it replaced.
	 *
	 * This ends the rollback window. Nothing else on the site changes.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function confirm( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		Output::confirm(
			'This drops the tables the import replaced. After it, the import cannot be rolled back.',
			$assoc_args
		);

		$importer = new Importer( '' );

		try {
			$dropped = $importer->confirm();
		} catch ( \Exception $e ) {
			Output::fail( $e->getMessage() );

			return;
		}

		\WP_CLI::success( \sprintf( 'Confirmed. Dropped %d retained table(s).', $dropped ) );
	}

	/**
	 * How long this whole invocation may take.
	 *
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return float Seconds, or 0 for no limit.
	 */
	protected static function max_time( array $assoc_args ) {
		return isset( $assoc_args['max-time'] ) ? (float) $assoc_args['max-time'] : 0;
	}

	/**
	 * Step a run to completion, or until the clock runs out.
	 *
	 * `Exporter::run()` and `Importer::run()` both loop until they are finished, which is right
	 * for the default and useless to a caller that has to fit inside a window: `--budget` bounds
	 * each *step*, so even a budgeted run returns only once the whole thing is done. Nothing in
	 * `Core/` needed changing -- the loop simply moves out here, which is what having a second
	 * consumer of `step( $budget )` is for.
	 *
	 * Stopping is always safe. Every step writes its checkpoint before returning and both halves
	 * resume from it; on the import side the swap is a single statement that has either run or
	 * not, so there is no moment where stopping leaves a half-migrated site.
	 *
	 * @param object $runner   Anything with `step( $budget )` returning a state carrying `done`.
	 * @param float  $budget   Per-step budget, passed straight through.
	 * @param float  $max_time Wall clock for the whole run, or 0 for no limit.
	 *
	 * @return array The last state, whose `done` says whether it finished.
	 */
	protected static function drive( $runner, $budget, $max_time ) {
		$deadline = $max_time > 0 ? \microtime( true ) + $max_time : 0;

		do {
			// The clock can only be consulted between steps, so a deadline has to become the
			// step's own budget or it is never reached: with no `--budget` a single step runs the
			// entire export, and the check below then happens once, after everything is already
			// done. Whichever of the two is smaller wins, and the remaining time is always at
			// least one step -- "as much as fits" has a floor of one attempt here as well.
			$step = $budget;

			if ( $deadline > 0 ) {
				$remaining = $deadline - \microtime( true );
				$step      = $budget > 0 ? \min( $budget, $remaining ) : $remaining;

				if ( $step <= 0 ) {
					$step = 1;
				}
			}

			$state = $runner->step( $step );

			// A stage that failed leaves the run neither done nor advancing, and looping on that
			// spins forever. `Importer::run()` guards it the same way; the export signals failure
			// by throwing, so on that side there is nothing here to catch.
			if ( isset( $state['error'] ) && '' !== $state['error'] ) {
				return $state;
			}

			if ( $deadline > 0 && \microtime( true ) >= $deadline ) {
				return $state;
			}
		} while ( empty( $state['done'] ) );

		return $state;
	}

	/**
	 * Which account must still be an administrator when the import finishes.
	 *
	 * There is no logged-in user under WP-CLI, so one has to be chosen. The lowest-numbered
	 * administrator is the closest thing to "whoever owns this site".
	 *
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return int
	 */
	protected static function acting_user( array $assoc_args ) {
		if ( isset( $assoc_args['as'] ) ) {
			$user = \is_numeric( $assoc_args['as'] )
				? \get_user_by( 'id', (int) $assoc_args['as'] )
				: \get_user_by( 'login', $assoc_args['as'] );

			if ( ! $user ) {
				Output::fail( \sprintf( 'No such user: %s', $assoc_args['as'] ) );
			}

			return (int) $user->ID;
		}

		$admins = \get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		return empty( $admins ) ? 0 : (int) $admins[0];
	}

	/**
	 * Print the things the import will not decide for the user.
	 *
	 * @param array $steps Manual follow-ups.
	 *
	 * @return void
	 */
	protected static function report_manual( array $steps ) {
		if ( empty( $steps ) ) {
			return;
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Worth a look:' );

		foreach ( $steps as $step ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( '  ' . $step['label'] );

			if ( empty( $step['block'] ) ) {
				continue;
			}

			foreach ( \explode( "\n", \rtrim( $step['block'] ) ) as $line ) {
				\WP_CLI::log( '      ' . $line );
			}
		}

		\WP_CLI::log( '' );
	}

	/**
	 * Print what happened to the accounts.
	 *
	 * @param array $users UserMerger report.
	 *
	 * @return void
	 */
	protected static function report_users( array $users ) {
		if ( empty( $users ) ) {
			return;
		}

		foreach ( (array) \nfd_sm_data_get( $users, 'matched', array() ) as $match ) {
			\WP_CLI::log(
				\sprintf(
					'  user %s: kept as ID %d, signing in with this site\'s existing password',
					$match['email'],
					$match['source_id']
				)
			);
		}

		foreach ( (array) \nfd_sm_data_get( $users, 'login_changes', array() ) as $change ) {
			\WP_CLI::log( \sprintf( '  user %s now signs in as %s', $change['was'], $change['now'] ) );
		}

		foreach ( (array) \nfd_sm_data_get( $users, 'carried', array() ) as $carried ) {
			\WP_CLI::log( \sprintf( '  user %s carried over as ID %d', $carried['login'], $carried['new_id'] ) );
		}

		foreach ( (array) \nfd_sm_data_get( $users, 'renamed', array() ) as $renamed ) {
			\WP_CLI::warning( \sprintf( 'user %s renamed to %s: %s', $renamed['was'], $renamed['now'], $renamed['reason'] ) );
		}

		foreach ( (array) \nfd_sm_data_get( $users, 'demoted', array() ) as $demoted ) {
			\WP_CLI::warning(
				\sprintf( 'user %s held the role %s, which this content does not define; now %s', $demoted['login'], $demoted['was'], $demoted['now'] )
			);
		}
	}

	/**
	 * Offer this site's package to a destination, and print the key it needs.
	 *
	 * The key is printed once, because only its hash is stored — there is no command that could
	 * read it back. It binds to the first site that uses it and expires after six hours of
	 * nothing happening.
	 *
	 * ## OPTIONS
	 *
	 * [--link]
	 * : Offer it to the destination this site paired with, instead of printing a key. Nothing is
	 * minted and nothing is sent: the other site holds a token from the pairing, asks whether
	 * there is anything for it, and is given a key when it takes this one up.
	 *
	 * [--status]
	 * : Report on the outstanding key instead of issuing one.
	 *
	 * [--revoke]
	 * : Withdraw the outstanding key, and any standing offer with it.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml, for --status. Defaults to table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-migrator offer
	 *     wp site-migrator offer --link
	 *     wp site-migrator offer --status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function offer( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! empty( $assoc_args['revoke'] ) ) {
			TransferKey::revoke();
			Link::withdraw();
			\WP_CLI::success( 'Withdrawn. Nothing can fetch the package now.' );

			return;
		}

		if ( ! empty( $assoc_args['link'] ) ) {
			$offer = new Offer();

			if ( ! $offer->is_ready() ) {
				Output::fail(
					'There is no finished package here to offer. Run `wp site-migrator export` first.',
					Output::EXIT_INVALID_PACKAGE
				);

				return;
			}

			if ( ! Link::offer() ) {
				Output::fail(
					'This site is not linked to a destination. Pair with one first, or run this without --link to print a key.'
				);

				return;
			}

			$link = Link::status();

			\WP_CLI::success(
				\sprintf(
					'Offered to %s. Run `wp site-migrator pull --linked` there, or open its import screen.',
					'' !== $link['destination'] ? $link['destination'] : 'the paired destination'
				)
			);

			return;
		}

		$status = TransferKey::status();

		if ( ! empty( $assoc_args['status'] ) ) {
			$link = Link::status();

			if ( ! empty( $link['linked'] ) ) {
				Output::progress(
					\sprintf(
						'Linked to %s. %s',
						'' !== $link['destination'] ? $link['destination'] : 'a paired destination',
						empty( $link['offered'] )
							? 'Nothing is on offer to it.'
							: ( $link['handed_at'] > 0 ? 'It has collected a key.' : 'A package is on offer and has not been collected.' )
					)
				);
			}

			if ( empty( $status['active'] ) ) {
				Output::emit(
					$assoc_args,
					array( 'active' => false ),
					array( array( 'active' => 'no' ) ),
					array( 'active' )
				);

				Output::progress( 'No key is outstanding.' );

				return;
			}

			// Whether anybody has picked the key up is `claimed`, not `claimed_by`: the latter is
			// the address the caller volunteered, and a proxy that drops the header leaves it
			// empty on a transfer that is very much under way. Reporting "nobody yet" then
			// tells the user the opposite of what is happening.
			if ( empty( $status['claimed'] ) ) {
				$by = 'nobody yet';
			} elseif ( '' !== $status['claimed_by'] ) {
				$by = $status['claimed_by'];
			} else {
				$by = 'a site that did not say who it was';
			}

			Output::emit(
				$assoc_args,
				$status,
				array(
					array(
						'active'     => 'yes',
						'expires'    => \gmdate( 'Y-m-d H:i:s', $status['expires'] ) . ' UTC',
						'claimed_by' => $by,
						'sent'       => \size_format( $status['sent'] ),
					),
				),
				array( 'active', 'expires', 'claimed_by', 'sent' )
			);

			return;
		}

		$offer = new Offer();

		if ( ! $offer->is_ready() ) {
			Output::fail(
				'There is no finished package here to send. Run `wp site-migrator export` first.',
				Output::EXIT_INVALID_PACKAGE
			);

			return;
		}

		$summary = $offer->summary();
		$address = \get_site_url();

		// Issued once and never recoverable -- only a hash is kept -- so it goes through `emit()`
		// like any other answer. This is the single most scriptable line the CLI produces: a
		// wrapper pairing two sites reads the key from here and hands it to `pull` on the other.
		$key = TransferKey::issue();

		Output::emit(
			$assoc_args,
			array(
				'address' => $address,
				'key'     => $key,
				'files'   => \count( $summary['files'] ),
				'bytes'   => (int) $summary['bytes'],
			),
			array(
				array(
					'address' => $address,
					'key'     => $key,
				),
			),
			array( 'address', 'key' )
		);

		Output::progress(
			\sprintf(
				'Success: %d files, %s, ready to be pulled.',
				\count( $summary['files'] ),
				\size_format( $summary['bytes'] )
			)
		);
	}

	/**
	 * Fetch a package straight from the source it was exported on.
	 *
	 * The bytes land in the same staging directory an uploaded package would, so `import` picks
	 * up from here unchanged. Interrupting this is safe: rerun it and it continues from the byte
	 * it stopped at.
	 *
	 * ## OPTIONS
	 *
	 * [<url>]
	 * : The source site's address. Omit to continue a transfer already connected.
	 *
	 * [<key>]
	 * : The transfer key printed by `wp site-migrator offer` on the source.
	 *
	 * [--linked]
	 * : Take the package the paired source is offering, with no address and no key. Needs
	 * `wp site-migrator offer --link` to have been run there.
	 *
	 * [--budget=<seconds>]
	 * : Stop after this many seconds per step. Defaults to no limit, which is right under
	 * WP-CLI where max_execution_time is 0.
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-migrator pull https://old.example.com 2f52f7ce…
	 *     wp site-migrator pull --linked
	 *     wp site-migrator pull
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function pull( $args, $assoc_args ) {
		$puller = new Puller();
		$budget = isset( $assoc_args['budget'] ) ? (float) $assoc_args['budget'] : 0;

		// The link pairing left behind. Claiming is what mints the key on the source, so this is
		// the same single-use handover the browser does — there is nothing to paste here either.
		if ( ! empty( $assoc_args['linked'] ) && empty( $args[0] ) ) {
			$claim = LinkedSource::claim();

			if ( isset( $claim['error'] ) ) {
				Output::fail( $claim['error'] );

				return;
			}

			$args = array( $claim['url'], $claim['key'] );
		}

		if ( ! empty( $args[0] ) ) {
			$result = $puller->connect( $args[0], isset( $args[1] ) ? $args[1] : '' );

			if ( isset( $result['error'] ) ) {
				Output::fail( $result['error'] );

				return;
			}

			if ( $result['cleared'] > 0 ) {
				\WP_CLI::warning(
					\sprintf(
						'Removed %s of a different package that was staged here.',
						\size_format( $result['cleared'] )
					)
				);
			}

			\WP_CLI::log(
				\sprintf(
					'Connected to %s — %d files, %s.',
					$result['summary']['site_url'],
					\count( $result['summary']['files'] ),
					\size_format( $result['summary']['bytes'] )
				)
			);
		} elseif ( empty( Source::load() ) ) {
			Output::fail( 'Give me the source\'s address and a transfer key, or --linked if this site is paired with one.' );

			return;
		}

		$started = \microtime( true );

		for ( ; ; ) {
			try {
				$state = $puller->step( $budget );
			} catch ( \Exception $e ) {
				Output::fail( $e->getMessage() );

				return;
			}

			foreach ( $state['notes'] as $note ) {
				\WP_CLI::warning( $note );
			}

			\WP_CLI::log(
				\sprintf(
					'%d/%d files, %s of %s%s',
					$state['files_done'],
					$state['files_total'],
					\size_format( $state['bytes_done'] ),
					\size_format( $state['bytes_total'] ),
					'' !== $state['current'] ? ' — ' . $state['current'] : ''
				)
			);

			if ( $state['done'] ) {
				break;
			}
		}

		$reader   = new PackageReader( Upload::dir() );
		$problems = $reader->verify();

		if ( ! empty( $problems ) ) {
			foreach ( $problems as $problem ) {
				\WP_CLI::warning( $problem );
			}

			Output::fail(
				'The package arrived but does not match its own checksums.',
				Output::EXIT_INVALID_PACKAGE
			);

			return;
		}

		\WP_CLI::success(
			\sprintf(
				'Fetched %s in %.1fs and every file matches its checksum. Import it with `wp site-migrator import`.',
				\size_format( $state['bytes_total'] ),
				\microtime( true ) - $started
			)
		);
	}
}
