<?php
/**
 * WP-CLI adapter.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Cli;

use NewfoldLabs\WP\SiteMigrator\Core\Export\Exporter;
use NewfoldLabs\WP\SiteMigrator\Core\Import\Importer;
use NewfoldLabs\WP\SiteMigrator\Core\Import\Upload;
use NewfoldLabs\WP\SiteMigrator\Core\Import\UserMerger;
use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageReader;
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
		\WP_CLI::add_command( 'site-migrator rollback', array( __CLASS__, 'rollback' ) );
		\WP_CLI::add_command( 'site-migrator cancel', array( __CLASS__, 'cancel' ) );
		\WP_CLI::add_command( 'site-migrator confirm', array( __CLASS__, 'confirm' ) );
		\WP_CLI::add_command( 'site-migrator offer', array( __CLASS__, 'offer' ) );
		\WP_CLI::add_command( 'site-migrator pull', array( __CLASS__, 'pull' ) );
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
	 * [--budget=<seconds>]
	 * : Stop and checkpoint after this many seconds per step, then continue. Defaults to no
	 * limit, which is right under WP-CLI where max_execution_time is 0.
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
			\WP_CLI::error( 'Give me a package directory.' );

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
			\WP_CLI::error(
				'This package has already been imported into this site. Roll it back first, or pass '
				. '--restart to run it again.'
			);

			return;
		}

		if ( $importer->is_resumable() ) {
			\WP_CLI::log( 'Resuming an interrupted import.' );
		} else {
			\WP_CLI::confirm(
				\sprintf(
					'This replaces every table on %s with the contents of that package. Continue?',
					\get_site_url()
				),
				$assoc_args
			);
		}

		$started = \microtime( true );
		$state   = $importer->run( $budget );

		foreach ( $state['refused'] as $path ) {
			\WP_CLI::warning( 'Refused an unsafe path in the package: ' . $path );
		}

		if ( '' !== $state['error'] ) {
			\WP_CLI::error( $state['error'] );

			return;
		}

		foreach ( $state['notes'] as $note ) {
			\WP_CLI::log( '  ' . $note );
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
		\WP_CLI::confirm( 'This puts the site back as it was before the import. Continue?', $assoc_args );

		// No package argument: the run is recorded against the site, not the package, and
		// naming the wrong directory should not be a way to roll back the wrong thing.
		$importer = new Importer( '' );

		try {
			$state = $importer->rollback();
		} catch ( \Exception $e ) {
			\WP_CLI::error( $e->getMessage() );

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
		\WP_CLI::confirm( 'This discards the staged import. The live site is not affected. Continue?', $assoc_args );

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
		\WP_CLI::confirm(
			'This drops the tables the import replaced. After it, the import cannot be rolled back. Continue?',
			$assoc_args
		);

		$importer = new Importer( '' );

		try {
			$dropped = $importer->confirm();
		} catch ( \Exception $e ) {
			\WP_CLI::error( $e->getMessage() );

			return;
		}

		\WP_CLI::success( \sprintf( 'Confirmed. Dropped %d retained table(s).', $dropped ) );
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
				\WP_CLI::error( \sprintf( 'No such user: %s', $assoc_args['as'] ) );
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
	 * [--status]
	 * : Report on the outstanding key instead of issuing one.
	 *
	 * [--revoke]
	 * : Withdraw the outstanding key.
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-migrator offer
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
			\WP_CLI::success( 'Withdrawn. Nothing can fetch the package now.' );

			return;
		}

		$status = TransferKey::status();

		if ( ! empty( $assoc_args['status'] ) ) {
			if ( empty( $status['active'] ) ) {
				\WP_CLI::log( 'No key is outstanding.' );

				return;
			}

			\WP_CLI::log(
				\sprintf(
					'Active until %s. Claimed by: %s. Sent so far: %s.',
					\gmdate( 'Y-m-d H:i:s', $status['expires'] ) . ' UTC',
					'' !== $status['claimed_by'] ? $status['claimed_by'] : 'nobody yet',
					\size_format( $status['sent'] )
				)
			);

			return;
		}

		$offer = new Offer();

		if ( ! $offer->is_ready() ) {
			\WP_CLI::error( 'There is no finished package here to send. Run `wp site-migrator export` first.' );

			return;
		}

		$summary = $offer->summary();

		\WP_CLI::log( \sprintf( 'Address: %s', \get_site_url() ) );
		\WP_CLI::log( \sprintf( 'Key:     %s', TransferKey::issue() ) );
		\WP_CLI::success(
			\sprintf(
				'%d files, %s, ready to be pulled.',
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
	 * [--budget=<seconds>]
	 * : Stop after this many seconds per step. Defaults to no limit, which is right under
	 * WP-CLI where max_execution_time is 0.
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-migrator pull https://old.example.com 2f52f7ce…
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

		if ( ! empty( $args[0] ) ) {
			$result = $puller->connect( $args[0], isset( $args[1] ) ? $args[1] : '' );

			if ( isset( $result['error'] ) ) {
				\WP_CLI::error( $result['error'] );

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
			\WP_CLI::error( 'Give me the source\'s address and a transfer key.' );

			return;
		}

		$started = \microtime( true );

		for ( ; ; ) {
			try {
				$state = $puller->step( $budget );
			} catch ( \Exception $e ) {
				\WP_CLI::error( $e->getMessage() );

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

			\WP_CLI::error( 'The package arrived but does not match its own checksums.' );

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
