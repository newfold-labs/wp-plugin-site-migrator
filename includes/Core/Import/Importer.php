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
	 * Claim the import window: keep the plugin loadable, and mint authority that outlives the swap.
	 *
	 * Only the browser needs either of these. Under WP-CLI the plugin is loaded from disk on
	 * every invocation and there is no cookie session to lose, which is why the core ran to
	 * green without them and why this is the first time they are exercised.
	 *
	 * @param int $user_id The account running the import.
	 *
	 * @return array `token`, and whether the loader is in place.
	 */
	public function begin( $user_id ) {
		$installed = Loader::install();
		$token     = ImportToken::issue( $user_id );

		return array(
			'token'  => $token,
			'loader' => $installed,
		);
	}

	/**
	 * Give up the import window.
	 *
	 * Part of every exit path rather than a cleanup step, because the thing being released is
	 * a credential and a file in `mu-plugins`.
	 *
	 * @return void
	 */
	protected function release() {
		ImportToken::revoke();
		Loader::remove();
	}

	/**
	 * What this package would do to this site, without doing any of it.
	 *
	 * Everything here is read-only. It is the last screen before the only irreversible action
	 * in the product, so it has to describe the real thing: the merge plan comes from the same
	 * function the merge itself runs, not from a second implementation of the same rules.
	 *
	 * @return array
	 *
	 * @throws \RuntimeException If the package cannot be read.
	 */
	public function preview() {
		$problems = $this->package->verify();

		if ( ! empty( $problems ) ) {
			return array(
				'ok'       => false,
				'problems' => $problems,
			);
		}

		$manifest = $this->package->manifest();
		$report   = $this->compatibility( $manifest );

		return array(
			'ok'         => ! $report->is_blocked(),
			'problems'   => array(),
			'package'    => $this->package->inspect(),
			'report'     => $report->to_array(),
			'users'      => $this->user_plan( $manifest ),
			'manual'     => $this->manual_steps(),
			'target'     => $this->target(),
			'has_backup' => $this->previous_backup_present(),
		);
	}

	/**
	 * Whether a previous import's tables are still retained.
	 *
	 * @return bool
	 */
	protected function previous_backup_present() {
		global $wpdb;

		$swap = new Swap(
			self::STAGE_PREFIX . $wpdb->prefix,
			$wpdb->prefix,
			self::BACKUP_PREFIX . $wpdb->prefix
		);

		return $swap->has_backup();
	}

	/**
	 * What the users merge would do.
	 *
	 * @param Manifest $manifest Package manifest.
	 *
	 * @return array
	 */
	protected function user_plan( Manifest $manifest ) {
		global $wpdb;

		$recorded = (array) $manifest->get( 'users', array() );

		if ( ! empty( $recorded['truncated'] ) ) {
			return array(
				'available' => false,
				'total'     => (int) \nfd_sm_data_get( $recorded, 'total', 0 ),
				'reason'    => 'The source has too many accounts to list them individually here. '
					. 'The merge itself is unaffected.',
			);
		}

		$source = array();

		foreach ( (array) \nfd_sm_data_get( $recorded, 'list', array() ) as $user ) {
			$source[ (int) $user['ID'] ] = $user;
		}

		if ( empty( $source ) ) {
			return array(
				'available' => false,
				'total'     => 0,
				'reason'    => 'This package was built before the plugin recorded the source\'s accounts, '
					. 'so the merge cannot be previewed. It will still run correctly.',
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT ID, user_login, user_email, user_nicename, display_name FROM `{$wpdb->users}` ORDER BY ID ASC",
			ARRAY_A
		);

		$dest = array();

		foreach ( (array) $rows as $row ) {
			$dest[ (int) $row['ID'] ] = $row;
		}

		$plan = UserMerger::plan( $source, $dest );

		$plan['available'] = true;
		$plan['total']     = \count( $source );
		$plan['acting']    = (int) $this->options['acting_user'];

		return $plan;
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

		// A run that has been rolled back or kept is a record of what happened, not a claim on
		// the site, and holding on to it is what stops the next import. From the same package
		// the step below reports the old run as already done and nothing happens at all; from a
		// different one `assert_not_busy()` refuses. A run that is finished but *undecided* is a
		// different matter and stays: its backup tables are the only copy of this site as it was.
		if ( ImportCheckpoint::is_settled( $state ) ) {
			$this->checkpoint->clear();

			$state = $this->checkpoint->load();
		}

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

		// The last moment at which the plugin and theme directories still hold only what this
		// site installed itself. The next stage writes into them.
		$state['code_before'] = AddedCode::snapshot();

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
	 * They go, and starting this import is the consent for that. The rollback window used to be
	 * thirty days of refusing to import at all, which protected a backup nobody had asked to keep
	 * at the cost of blocking the migration somebody *was* asking for — and the way out of it, on
	 * a screen the user had already left, was not discoverable. So the window is no longer a
	 * clock: a backup lasts until its import is kept, or until the next migration begins.
	 *
	 * The import being run right now is still fully reversible. What is given up is the ability to
	 * reach back past it to the one before, which is a step no part of the UI ever offered.
	 *
	 * @param Swap  $swap  Swap bound to this run's prefixes.
	 * @param array $state Import state, modified in place.
	 *
	 * @return void
	 */
	protected function check_previous_backup( Swap $swap, array &$state ) {
		if ( ! $swap->has_backup() ) {
			return;
		}

		$dropped = $swap->discard_backup();

		$state['notes'][] = \sprintf(
			'Discarded %d table(s) a previous import had kept. Starting this migration is what '
			. 'ended that one\'s rollback window.',
			$dropped
		);
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

		if ( ! empty( $state['rolled_back'] ) ) {
			throw new \RuntimeException( 'This import was undone, so there is nothing to keep.' );
		}

		if ( empty( $state['live_prefix'] ) ) {
			throw new \RuntimeException( 'No import has run on this site, so there is nothing to confirm.' );
		}

		// Saved before the drop, not after, because the drop cannot be undone and the save can
		// be repeated. Interrupted the other way round — which is how this site was found with
		// no backup tables and a checkpoint still offering to roll back to them — the run keeps
		// promising an undo that has nothing left to undo. This order can only leave tables a
		// later import will discard anyway.
		$state['confirmed_at'] = \gmdate( 'c' );

		$this->checkpoint->save( $state );

		return $this->swap( $state )->discard_backup();
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

		if ( ! empty( $state['migrator_skipped'] ) ) {
			$state['notes'][] = \sprintf(
				'Left %d file(s) in the package alone: they would have overwritten the migrator '
					. 'itself, which is running this import.',
				(int) $state['migrator_skipped']
			);
		}

		// Taken here rather than at rollback: by then the answer would include anything the
		// user installed after the import, and rollback would delete that too.
		if ( ! empty( $state['code_before'] ) ) {
			$state['code_added'] = AddedCode::added( (array) $state['code_before'] );
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

		$acting_final = (int) \nfd_sm_data_get( $state, 'users.acting_id', 0 );

		foreach ( $fixups->run( $this->target(), $acting_final ) as $note ) {
			$state['notes'][] = $note;
		}

		if ( ! $this->options['keep_backup'] ) {
			$dropped = $swap->discard_backup();

			$state['notes'][] = \sprintf( 'Discarded %d backup table(s) as requested.', $dropped );
		} else {
			$state['notes'][] = \sprintf(
				'The previous site is kept in tables prefixed %s, so this import can be rolled back '
				. 'until you keep it or start another migration.',
				$state['backup_prefix']
			);
		}

		$state['manual'] = $this->manual_steps();

		$this->progress->finish( ImportCheckpoint::STAGE_FIXUPS );

		$this->release();

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

		// Said separately from the check below, because "already undone" and "never swapped in"
		// are different situations and only one of them is a mistake. A finished import stays
		// on `stage: done` after it is rolled back, so anything that routes by that alone lands
		// back on the decision screen and the second press arrives here.
		if ( ! empty( $state['rolled_back'] ) ) {
			throw new \RuntimeException( 'This import has already been undone.' );
		}

		if ( empty( $state['live_prefix'] ) || empty( $state['swapped'] ) ) {
			throw new \RuntimeException( 'This import has not been swapped in, so there is nothing to undo.' );
		}

		$swap = $this->swap( $state );

		$swap->rollback( $state );

		// The migrated tables are back under the staging prefix, holding a full copy of the
		// source database that nothing can now reach. Rollback means undo, so they go.
		$dropped = $swap->discard_staged();

		// And so do the plugins and themes the package installed. The database is the
		// destination's own again, so neither `active_plugins` nor `stylesheet` names any of
		// them — but the files are still on disk, and a must-use plugin or a drop-in among them
		// is still being loaded. Removed after the rename rather than before it: until that
		// statement runs the rollback can still fail, and a failed rollback that had already
		// deleted files would leave the site on the imported database with pieces of it missing.
		$removed = AddedCode::remove(
			isset( $state['code_added'] ) ? (array) $state['code_added'] : array(),
			isset( $state['code_before'] ) ? (array) $state['code_before'] : array()
		);

		$state['code_removed'] = $removed;

		$this->release();

		\wp_cache_flush();

		$state['notes'][] = \sprintf(
			'Rolled back: the site\'s own tables are live again, and %d imported table(s) were discarded.',
			$dropped
		);

		if ( ! empty( $removed ) ) {
			$state['notes'][] = \sprintf(
				'Removed %d plugin(s) and theme(s) the import installed: %s.',
				\count( $removed ),
				\implode( ', ', $removed )
			);
		}

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

		$this->release();
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
		global $wpdb;

		return array(
			'site_url'    => '' !== $this->options['site_url'] ? $this->options['site_url'] : \get_site_url(),
			'home_url'    => '' !== $this->options['home_url'] ? $this->options['home_url'] : \get_home_url(),
			'abspath'     => \rtrim( ABSPATH, '/\\' ),
			'content_dir' => \rtrim( \WP_CONTENT_DIR, '/\\' ),
			'prefix'      => $wpdb->prefix,
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
					(string) \nfd_sm_data_get( $recorded, 'schema_version', 'unknown' ),
					SiteProfile::SCHEMA
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
