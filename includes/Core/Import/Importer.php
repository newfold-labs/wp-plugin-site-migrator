<?php
/**
 * Import orchestration.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

use NewfoldLabs\WP\SiteMigrator\Core\Export\ConfigScanner;
use NewfoldLabs\WP\SiteMigrator\Core\Package\Manifest;
use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageReader;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\Compatibility;
use NewfoldLabs\WP\SiteMigrator\Core\Preflight\SiteProfile;
use NewfoldLabs\WP\SiteMigrator\Core\Progress\NullProgressReporter;
use NewfoldLabs\WP\SiteMigrator\Core\Progress\ProgressReporter;

/**
 * Drives an import, one bounded step at a time.
 *
 * The counterpart of Exporter, and the same contract: `step( $budget )` does as much as it can
 * inside the budget, writes a checkpoint, and returns. Budget 0 means no limit, which is how
 * the CLI runs it.
 *
 * The stage order is the design. Files are restored before the database, so the destructive
 * step is last; the database is loaded into staging tables, so it is not destructive either;
 * and everything that could still fail — the merge, the uniqueness check, the verification —
 * happens while the live site is untouched. By the time anything irreversible runs, the only
 * remaining operation is a rename.
 *
 * Transport-agnostic, like the rest of Core: no WP_CLI, no REST, no superglobals, no output.
 */
class Importer {

	/**
	 * Prefix the arriving tables are built under.
	 */
	const STAGE_PREFIX = 'nfdimp_';

	/**
	 * Prefix the replaced tables are kept under.
	 */
	const BACKUP_PREFIX = 'nfdold_';

	/**
	 * How long backup tables are kept, in days.
	 */
	const BACKUP_DAYS = 30;

	/**
	 * Absolute package directory.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Package reader.
	 *
	 * @var PackageReader
	 */
	protected $package;

	/**
	 * Checkpoint store.
	 *
	 * @var ImportCheckpoint
	 */
	protected $checkpoint;

	/**
	 * Progress reporter.
	 *
	 * @var ProgressReporter
	 */
	protected $progress;

	/**
	 * Run options.
	 *
	 * @var array
	 */
	protected $options;

	/**
	 * Constructor.
	 *
	 * @param string $dir     Absolute package directory.
	 * @param array  $options `mode`, `acting_user`, `site_url`, `home_url`, `keep_backup`.
	 */
	public function __construct( $dir, array $options = array() ) {
		$this->dir        = \rtrim( $dir, '/\\' );
		$this->package    = new PackageReader( $this->dir );
		$this->checkpoint = new ImportCheckpoint();
		$this->progress   = new NullProgressReporter();

		$this->options = \array_merge(
			array(
				'mode'        => UserMerger::MODE_MERGE,
				'acting_user' => \get_current_user_id(),
				'site_url'    => '',
				'home_url'    => '',
				'keep_backup' => true,
			),
			$options
		);
	}

	/**
	 * Attach a progress reporter.
	 *
	 * @param ProgressReporter $progress Progress reporter.
	 *
	 * @return Importer
	 */
	public function set_progress( ProgressReporter $progress ) {
		$this->progress = $progress;

		return $this;
	}

	/**
	 * Whether an import from this package is already under way.
	 *
	 * @return bool
	 */
	public function is_resumable() {
		if ( ! $this->checkpoint->exists() ) {
			return false;
		}

		$state = $this->checkpoint->load();

		return $this->dir === $state['package'] && ImportCheckpoint::STAGE_DONE !== $state['stage'];
	}

	/**
	 * Whether this package has already been imported into this site.
	 *
	 * @return bool
	 */
	public function is_complete() {
		if ( ! $this->checkpoint->exists() ) {
			return false;
		}

		$state = $this->checkpoint->load();

		return $this->dir === $state['package'] && ImportCheckpoint::STAGE_DONE === $state['stage'];
	}

	/**
	 * Forget any recorded run, so the next step starts from the beginning.
	 *
	 * @return void
	 */
	public function restart() {
		$this->checkpoint->clear();
	}

	/**
	 * Current state.
	 *
	 * @return array
	 */
	public function state() {
		return $this->checkpoint->load();
	}

	/**
	 * Run to completion.
	 *
	 * @param float $budget Seconds per step, or 0 for no limit.
	 *
	 * @return array Final state.
	 */
	public function run( $budget = 0 ) {
		do {
			$state = $this->step( $budget );

			// A failed stage leaves the run neither done nor advancing. Looping on that spins
			// forever, retrying a step that has already decided it cannot proceed.
			if ( '' !== $state['error'] ) {
				return $state;
			}
		} while ( ! $state['done'] );

		return $state;
	}

	/**
	 * Advance the import by at most one budget's worth of work.
	 *
	 * @param float $budget Seconds, or 0 for no limit.
	 *
	 * @return array
	 *
	 * @throws \RuntimeException If the package cannot be read.
	 */
	public function step( $budget = 0 ) {
		$deadline = $budget > 0 ? \microtime( true ) + (float) $budget : 0;

		$state = $this->checkpoint->load();

		if ( ImportCheckpoint::STAGE_DONE === $state['stage'] && $state['package'] === $this->dir ) {
			return $this->report( $state, true );
		}

		// Cleared before the attempt, so a retry that succeeds does not report the failure
		// that came before it.
		$state['error'] = '';

		try {
			$this->assert_not_busy( $state );
			$this->advance( $state, $deadline );
		} catch ( \Exception $e ) {
			$state['error'] = $e->getMessage();

			$this->checkpoint->save( $state );

			return $this->report( $state, false );
		}

		$done = ImportCheckpoint::STAGE_DONE === $state['stage'];

		$this->checkpoint->save( $state );

		return $this->report( $state, $done );
	}

	/**
	 * Refuse to run a second package on top of a run that has already staged data.
	 *
	 * The binding is recorded when precheck succeeds rather than when a run is first attempted,
	 * so a package that was rejected before anything was written does not lock the site out of
	 * trying a different one.
	 *
	 * @param array $state Import state.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If another package is mid-import.
	 */
	protected function assert_not_busy( array $state ) {
		if ( '' === $state['package'] || $state['package'] === $this->dir ) {
			return;
		}

		throw new \RuntimeException(
			\sprintf(
				'An import from %s is already under way on this site. Finish it, roll it back, or '
					. 'cancel it before importing a different package.',
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an exception message, not output: it reaches a terminal or a JSON field, never an HTML page.
				$state['package']
			)
		);
	}

	/**
	 * Run whichever stage is current, and move on when it finishes.
	 *
	 * @param array $state    Import state, modified in place.
	 * @param float $deadline Unix timestamp to stop by.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If a stage fails.
	 */
	protected function advance( array &$state, $deadline ) {
		switch ( $state['stage'] ) {
			case ImportCheckpoint::STAGE_PRECHECK:
				$this->stage_precheck( $state );
				break;

			case ImportCheckpoint::STAGE_FILES:
				$this->stage_files( $state, $deadline );
				break;

			case ImportCheckpoint::STAGE_DATABASE:
				$this->stage_database( $state, $deadline );
				break;

			case ImportCheckpoint::STAGE_TRANSFORM:
				$this->stage_transform( $state, $deadline );
				break;

			case ImportCheckpoint::STAGE_USERS:
				$this->stage_users( $state );
				break;

			case ImportCheckpoint::STAGE_VALIDATE:
				$this->stage_validate( $state );
				break;

			case ImportCheckpoint::STAGE_SWAP:
				$this->stage_swap( $state );
				break;

			case ImportCheckpoint::STAGE_FIXUPS:
				$this->stage_fixups( $state );
				break;
		}
	}

	/**
	 * Verify the package, decide the prefixes, and check compatibility against live facts.
	 *
	 * This is the last of the three compatibility checkpoints and the only one that matters for
	 * safety: the pairing handshake ran before packaging, possibly days ago, against a
	 * destination that has been updated since.
	 *
	 * @param array $state Import state, modified in place.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the package or the destination is unusable.
	 */
	protected function stage_precheck( array &$state ) {
		global $wpdb;

		$this->progress->start( ImportCheckpoint::STAGE_PRECHECK );

		$problems = $this->package->verify();

		if ( ! empty( $problems ) ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an exception message, not output: it reaches a terminal or a JSON field, never an HTML page.
				'This package is not usable: ' . \implode( ' ', $problems )
			);
		}

		$manifest = $this->package->manifest();
		$source   = (array) $manifest->get( 'source', array() );

		$report = $this->compatibility( $manifest );

		if ( $report->is_blocked() ) {
			$reasons = array();

			foreach ( $report->with_status( \NewfoldLabs\WP\SiteMigrator\Core\Preflight\Report::BLOCK ) as $check ) {
				$reasons[] = $check['label'];
			}

			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an exception message, not output: it reaches a terminal or a JSON field, never an HTML page.
				'This site cannot accept the package: ' . \implode( ' ', $reasons )
			);
		}

		$state['live_prefix']   = $wpdb->prefix;
		$state['stage_prefix']  = self::STAGE_PREFIX . $wpdb->prefix;
		$state['backup_prefix'] = self::BACKUP_PREFIX . $wpdb->prefix;
		$state['source_prefix'] = isset( $source['table_prefix'] ) ? $source['table_prefix'] : '';
		$state['compatibility'] = $report->to_array();

		if ( '' === $state['source_prefix'] ) {
			throw new \RuntimeException( 'The package does not record the source table prefix.' );
		}

		// A previous attempt's staging tables would be merged with this one's, producing a
		// database that is half of each.
		$swap    = $this->swap( $state );
		$dropped = $swap->discard_staged();

		if ( $dropped > 0 ) {
			$state['notes'][] = \sprintf( 'Cleared %d table(s) left by an earlier attempt.', $dropped );
		}

		$this->check_previous_backup( $swap, $state );

		// Recorded only now, on the far side of every check: from here on the run owns the site.
		$state['package'] = $this->dir;

		$this->progress->finish( ImportCheckpoint::STAGE_PRECHECK );

		$state['stage'] = ImportCheckpoint::STAGE_FILES;
	}

	/**
	 * Deal with the tables a previous import replaced.
	 *
	 * The swap would fail on them anyway — `RENAME TABLE` refuses a destination that exists, and
	 * it refuses the whole statement, so the failure is safe but the message is a wall of SQL.
	 * Better to meet it here, where the situation can be described.
	 *
	 * Inside the retention window this is a refusal, because those tables are the only copy of
	 * the site as it was before the last migration and dropping them is not ours to decide. Past
	 * it, the window has expired and they go ([D8](../docs/implementation-plan.md)).
	 *
	 * @param Swap  $swap  Swap bound to this run's prefixes.
	 * @param array $state Import state, modified in place.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If a previous import is still inside its rollback window.
	 */
	protected function check_previous_backup( Swap $swap, array &$state ) {
		if ( ! $swap->has_backup() ) {
			return;
		}

		if ( $this->backup_expired() ) {
			$dropped = $swap->discard_backup();

			$state['notes'][] = \sprintf(
				'Discarded %d table(s) from an import older than %d days, which is past its rollback window.',
				$dropped,
				self::BACKUP_DAYS
			);

			return;
		}

		throw new \RuntimeException(
			\sprintf(
				'This site still holds the tables a previous import replaced, under the prefix %s, and '
				. 'they are the only copy of what was here before it. Roll that import back, or confirm '
				. 'it succeeded to discard them, before importing again.',
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an exception message, not output: it reaches a terminal or a JSON field, never an HTML page.
				$state['backup_prefix']
			)
		);
	}

	/**
	 * Whether the retained tables are past their rollback window.
	 *
	 * @return bool
	 */
	protected function backup_expired() {
		$previous = $this->checkpoint->load();

		if ( empty( $previous['swapped_at'] ) ) {
			// No record of when it happened. Treat that as inside the window: refusing is
			// recoverable, dropping somebody's only backup is not.
			return false;
		}

		$age = \time() - (int) \strtotime( $previous['swapped_at'] );

		return $age > ( self::BACKUP_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Discard the tables a completed import replaced, ending its rollback window.
	 *
	 * @return int How many were dropped.
	 *
	 * @throws \RuntimeException If no import has been swapped in.
	 */
	public function confirm() {
		$state = $this->checkpoint->load();

		if ( empty( $state['live_prefix'] ) ) {
			throw new \RuntimeException( 'No import has run on this site, so there is nothing to confirm.' );
		}

		$dropped = $this->swap( $state )->discard_backup();

		$state['confirmed_at'] = \gmdate( 'c' );

		$this->checkpoint->save( $state );

		return $dropped;
	}

	/**
	 * Restore the file half.
	 *
	 * @param array $state    Import state, modified in place.
	 * @param float $deadline Unix timestamp to stop by.
	 *
	 * @return void
	 */
	protected function stage_files( array &$state, $deadline ) {
		$this->progress->start( ImportCheckpoint::STAGE_FILES );

		$restorer = new FileRestorer( $this->dir, $this->package->manifest() );

		$complete = $restorer->step( $state, $deadline );

		foreach ( $restorer->refused() as $path ) {
			$state['refused'][] = $path;
		}

		if ( ! empty( $state['refused'] ) ) {
			$state['refused'] = \array_values( \array_unique( $state['refused'] ) );
		}

		if ( ! $complete ) {
			$this->progress->advance(
				ImportCheckpoint::STAGE_FILES,
				(int) $state['files_done'],
				0,
				'Restoring files'
			);

			return;
		}

		$this->progress->finish( ImportCheckpoint::STAGE_FILES );

		$state['stage'] = ImportCheckpoint::STAGE_DATABASE;
	}

	/**
	 * Load the dump into staging tables.
	 *
	 * @param array $state    Import state, modified in place.
	 * @param float $deadline Unix timestamp to stop by.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the dump is missing.
	 */
	protected function stage_database( array &$state, $deadline ) {
		$this->progress->start( ImportCheckpoint::STAGE_DATABASE );

		$source = $this->dir . '/' . DatabaseImporter::FILE;

		if ( ! \is_readable( $source ) ) {
			throw new \RuntimeException( 'The package has no database dump.' );
		}

		$importer = new DatabaseImporter();
		$complete = $importer->step( $source, $state, $deadline );

		if ( $importer->is_lossy() ) {
			$state['notes'][] = 'This server does not support utf8mb4, so four-byte characters '
				. '(emoji, and some CJK) were dropped from text as it was written.';
		}

		if ( ! $complete ) {
			$this->progress->advance(
				ImportCheckpoint::STAGE_DATABASE,
				(int) $state['statements'],
				0,
				'Loading the database'
			);

			return;
		}

		$this->progress->finish( ImportCheckpoint::STAGE_DATABASE );

		$state['stage'] = ImportCheckpoint::STAGE_TRANSFORM;
	}

	/**
	 * Rewrite the source's URLs and paths.
	 *
	 * @param array $state    Import state, modified in place.
	 * @param float $deadline Unix timestamp to stop by.
	 *
	 * @return void
	 */
	protected function stage_transform( array &$state, $deadline ) {
		$this->progress->start( ImportCheckpoint::STAGE_TRANSFORM );

		$source = (array) $this->package->manifest()->get( 'source', array() );
		$pairs  = SearchReplace::pairs( $source, $this->target() );

		$replacer = new SearchReplace( \array_values( $state['tables'] ), $pairs );

		if ( ! $replacer->step( $state, $deadline ) ) {
			$this->progress->advance(
				ImportCheckpoint::STAGE_TRANSFORM,
				(int) $state['sr_changed'],
				0,
				'Rewriting URLs'
			);

			return;
		}

		$this->progress->finish( ImportCheckpoint::STAGE_TRANSFORM );

		$state['stage'] = ImportCheckpoint::STAGE_USERS;
	}

	/**
	 * Reconcile the users.
	 *
	 * @param array $state Import state, modified in place.
	 *
	 * @return void
	 */
	protected function stage_users( array &$state ) {
		$this->progress->start( ImportCheckpoint::STAGE_USERS );

		$merger = new UserMerger(
			$state['stage_prefix'],
			$state['live_prefix'],
			$state['source_prefix'],
			(int) $this->options['acting_user'],
			$this->options['mode']
		);

		$state['users'] = $merger->run();

		$this->progress->finish( ImportCheckpoint::STAGE_USERS );

		$state['stage'] = ImportCheckpoint::STAGE_VALIDATE;
	}

	/**
	 * Last look before anything irreversible.
	 *
	 * @param array $state Import state, modified in place.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the staged database is not fit to swap in.
	 */
	protected function stage_validate( array &$state ) {
		$this->progress->start( ImportCheckpoint::STAGE_VALIDATE );

		$problems = $this->swap( $state )->verify();

		if ( ! empty( $problems ) ) {
			throw new \RuntimeException(
				'The imported data did not pass verification, so nothing was swapped in: '
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an exception message, not output: it reaches a terminal or a JSON field, never an HTML page.
					. \implode( ' ', $problems )
			);
		}

		$this->progress->finish( ImportCheckpoint::STAGE_VALIDATE );

		$state['stage'] = ImportCheckpoint::STAGE_SWAP;
	}

	/**
	 * Switch the staged tables in.
	 *
	 * @param array $state Import state, modified in place.
	 *
	 * @return void
	 */
	protected function stage_swap( array &$state ) {
		$this->progress->start( ImportCheckpoint::STAGE_SWAP );

		$this->swap( $state )->execute( $state );

		$this->progress->finish( ImportCheckpoint::STAGE_SWAP );

		$state['stage'] = ImportCheckpoint::STAGE_FIXUPS;
	}

	/**
	 * Repair everything the swap left inconsistent.
	 *
	 * @param array $state Import state, modified in place.
	 *
	 * @return void
	 */
	protected function stage_fixups( array &$state ) {
		$this->progress->start( ImportCheckpoint::STAGE_FIXUPS );

		$swap = $this->swap( $state );
		$swap->recreate_views( (array) $state['views'], $state );

		$fixups = new Fixups();

		foreach ( $fixups->run( $this->target() ) as $note ) {
			$state['notes'][] = $note;
		}

		if ( ! $this->options['keep_backup'] ) {
			$dropped = $swap->discard_backup();

			$state['notes'][] = \sprintf( 'Discarded %d backup table(s) as requested.', $dropped );
		} else {
			$state['notes'][] = \sprintf(
				'The previous site is kept in tables prefixed %s, so this import can be rolled back '
				. 'for the next %d days.',
				$state['backup_prefix'],
				self::BACKUP_DAYS
			);
		}

		$state['manual'] = $this->manual_steps();

		$this->progress->finish( ImportCheckpoint::STAGE_FIXUPS );

		$state['finished_at'] = \gmdate( 'c' );
		$state['stage']       = ImportCheckpoint::STAGE_DONE;
	}

	/**
	 * The things a person has to decide, which the import will not decide for them.
	 *
	 * Everything here describes the *environment* rather than the site, so copying it across can
	 * actively break the destination — a source `.htaccess` applied to an nginx host does nothing,
	 * and applied to a differently configured Apache host can 500 it. Capture and report, never
	 * copy ([§9.7](../docs/implementation-plan.md)).
	 *
	 * @return array
	 */
	protected function manual_steps() {
		$manifest = $this->package->manifest();
		$source   = (array) $manifest->get( 'source', array() );
		$config   = (array) $manifest->get( 'wp_config', array() );
		$steps    = array();

		$block = ConfigScanner::to_block( $config );

		if ( '' !== $block ) {
			$steps[] = array(
				'id'    => 'wp-config',
				'label' => 'The source had settings in its wp-config.php that this site does not. Nothing '
					. 'has been written to your wp-config.php — add any of these yourself, above the '
					. '"That\'s all, stop editing" line.',
				'block' => $block,
			);
		}

		if ( ! empty( $config['redacted'] ) ) {
			$steps[] = array(
				'id'    => 'wp-config-redacted',
				'label' => 'The source also defined these, and their values were deliberately not carried '
					. '(they are credentials, salts, paths, or look like secrets): '
					. \implode( ', ', (array) $config['redacted'] ) . '.',
			);
		}

		$their_server = isset( $source['server'] ) ? $source['server'] : '';
		$our_server   = isset( $_SERVER['SERVER_SOFTWARE'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';

		if ( '' !== $their_server && '' !== $our_server && $their_server !== $our_server ) {
			$steps[] = array(
				'id'    => 'server',
				'label' => \sprintf(
					'The source ran on %s and this server is %s. Any custom .htaccess rules that came '
					. 'across may not apply here, and may need translating.',
					$their_server,
					$our_server
				),
			);
		}

		$theirs = (array) \nfd_sm_data_get( $manifest->to_array(), 'profile.php.extensions', array() );
		$ours   = \get_loaded_extensions();
		$absent = \array_diff( $theirs, $ours );

		if ( ! empty( $absent ) ) {
			$steps[] = array(
				'id'    => 'extensions',
				'label' => 'The source had PHP extensions this server does not: ' . \implode( ', ', $absent )
					. '. A plugin that depends on one will not work until it is installed.',
			);
		}

		return $steps;
	}

	/**
	 * Undo a completed import.
	 *
	 * @return array State.
	 *
	 * @throws \RuntimeException If there is nothing to roll back to.
	 */
	public function rollback() {
		$state = $this->checkpoint->load();

		if ( empty( $state['live_prefix'] ) || empty( $state['swapped'] ) ) {
			throw new \RuntimeException( 'This import has not been swapped in, so there is nothing to undo.' );
		}

		$swap = $this->swap( $state );

		$swap->rollback( $state );

		// The migrated tables are back under the staging prefix, holding a full copy of the
		// source database that nothing can now reach. Rollback means undo, so they go.
		$dropped = $swap->discard_staged();

		\wp_cache_flush();

		$state['notes'][] = \sprintf(
			'Rolled back: the site\'s own tables are live again, and %d imported table(s) were discarded.',
			$dropped
		);

		$this->checkpoint->save( $state );

		return $state;
	}

	/**
	 * Remove the staging tables and the checkpoint, leaving the site untouched.
	 *
	 * @return void
	 */
	public function cancel() {
		$state = $this->checkpoint->load();

		if ( ! empty( $state['stage_prefix'] ) && empty( $state['swapped'] ) ) {
			$this->swap( $state )->discard_staged();
		}

		$this->checkpoint->clear();
	}

	/**
	 * A Swap bound to this run's prefixes.
	 *
	 * @param array $state Import state.
	 *
	 * @return Swap
	 */
	protected function swap( array $state ) {
		return new Swap( $state['stage_prefix'], $state['live_prefix'], $state['backup_prefix'] );
	}

	/**
	 * The destination facts the rewrite aims at.
	 *
	 * @return array
	 */
	protected function target() {
		return array(
			'site_url'    => '' !== $this->options['site_url'] ? $this->options['site_url'] : \get_site_url(),
			'home_url'    => '' !== $this->options['home_url'] ? $this->options['home_url'] : \get_home_url(),
			'abspath'     => \rtrim( ABSPATH, '/\\' ),
			'content_dir' => \rtrim( \WP_CONTENT_DIR, '/\\' ),
		);
	}

	/**
	 * Compare the package's source profile with this site as it is right now.
	 *
	 * @param Manifest $manifest Package manifest.
	 *
	 * @return \NewfoldLabs\WP\SiteMigrator\Core\Preflight\Report
	 *
	 * @throws \RuntimeException If the package describes its source in a format we do not read.
	 */
	protected function compatibility( Manifest $manifest ) {
		$recorded = (array) $manifest->get( 'profile', array() );

		// A profile whose shape this plugin does not know cannot be compared against. Reading it
		// anyway means every lookup silently returns its default, which the gates then report as
		// indeterminate — true, but not the reason, and not something the user can act on.
		if ( ! empty( $recorded ) && SiteProfile::SCHEMA !== (int) \nfd_sm_data_get( $recorded, 'schema_version', 0 ) ) {
			throw new \RuntimeException(
				\sprintf(
					'This package was built by a different version of the plugin: it describes the source '
					. 'in format %s and this site reads format %d. Export it again from the source.',
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- exception message, not output.
					(string) \nfd_sm_data_get( $recorded, 'schema_version', 'unknown' ),
					SiteProfile::SCHEMA
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				)
			);
		}

		if ( empty( $recorded ) ) {
			// Built from what the manifest does record. Thinner than a real profile, so the
			// checks it cannot answer come back indeterminate — which the Report counts as
			// blocking rather than passing.
			$source   = (array) $manifest->get( 'source', array() );
			$recorded = array(
				'schema_version' => SiteProfile::SCHEMA,
				'wp'             => array(
					'version'      => isset( $source['wp_version'] ) ? $source['wp_version'] : '',
					'db_version'   => isset( $source['db_version'] ) ? (int) $source['db_version'] : 0,
					'is_multisite' => ! empty( $source['is_multisite'] ),
					'prefix'       => isset( $source['table_prefix'] ) ? $source['table_prefix'] : '',
				),
				'php'            => array( 'version' => isset( $source['php_version'] ) ? $source['php_version'] : '' ),
			);
		}

		$compatibility = new Compatibility( new SiteProfile( $recorded ), SiteProfile::gather() );

		return $compatibility->check();
	}

	/**
	 * Shape the state for a caller.
	 *
	 * @param array $state Import state.
	 * @param bool  $done  Whether the import finished.
	 *
	 * @return array
	 */
	protected function report( array $state, $done ) {
		return array(
			'done'    => (bool) $done,
			'stage'   => $state['stage'],
			'files'   => (int) $state['files_done'],
			'bytes'   => (int) $state['bytes_done'],
			'rows'    => (int) $state['statements'],
			'swapped' => ! empty( $state['swapped'] ),
			'error'   => (string) $state['error'],
			'notes'   => (array) $state['notes'],
			'manual'  => isset( $state['manual'] ) ? (array) $state['manual'] : array(),
			'users'   => (array) $state['users'],
			'refused' => isset( $state['refused'] ) ? (array) $state['refused'] : array(),
		);
	}
}
