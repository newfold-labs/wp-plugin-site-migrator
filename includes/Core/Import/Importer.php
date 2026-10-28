<?php
/**
 * Import orchestration.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

use NewfoldLabs\WP\SiteMigrator\Core\Export\ConfigScanner;
use NewfoldLabs\WP\SiteMigrator\Core\Export\Selection;
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
	 * @param array  $options `mode`, `acting_user`, `site_url`, `home_url`, `keep_backup`, and
	 *                        `fix_php` -- true or false to decide whether safe syntax fixes run,
	 *                        null to keep whatever the recorded run chose.
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
				'fix_php'     => null,
			),
			$options
		);
	}

	/**
	 * Record whether the next run applies safe syntax fixes.
	 *
	 * The browser's steps are separate requests that carry only the package, so the choice made on
	 * the review screen has to live in the checkpoint. Only a run that has not yet claimed the site
	 * takes it: one already restoring files was checked and planned under the choice it began with.
	 *
	 * @param bool $wanted Whether to fix.
	 *
	 * @return void
	 */
	public function choose_fixes( $wanted ) {
		$state = $this->checkpoint->load();

		if ( ImportCheckpoint::is_settled( $state ) ) {
			$this->checkpoint->clear();

			$state = $this->checkpoint->load();
		}

		if ( '' !== $state['package'] ) {
			return;
		}

		$state['fix_php'] = (bool) $wanted;

		$this->checkpoint->save( $state );
	}

	/**
	 * Whether this run applies safe syntax fixes: this request's choice, else the recorded one.
	 *
	 * @param array $state Import state.
	 *
	 * @return bool
	 */
	protected function fixes_wanted( array $state ) {
		if ( null !== $this->options['fix_php'] ) {
			return (bool) $this->options['fix_php'];
		}

		return ! empty( $state['fix_php'] );
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
	 * Whether this package has already been imported and the result is still undecided.
	 *
	 * The run being guarded against is the one that finished and has been neither rolled back nor
	 * kept: its `nfdold_` tables are the only copy of this site as it was, and importing over them
	 * destroys the way back. A **settled** run makes no such claim — it is finished business, and
	 * the same reason `step()` forgets one before it starts.
	 *
	 * Without that distinction the refusal fired on a rolled-back run and told the user to "roll it
	 * back first", which they had just done. Found by pulling a second package after undoing the
	 * first: a linked pull always stages into the same directory, so the path matched.
	 *
	 * @return bool
	 */
	public function is_complete() {
		if ( ! $this->checkpoint->exists() ) {
			return false;
		}

		$state = $this->checkpoint->load();

		if ( $this->dir !== $state['package'] || ImportCheckpoint::STAGE_DONE !== $state['stage'] ) {
			return false;
		}

		return ! ImportCheckpoint::is_settled( $state );
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

		// Version numbers and plugin headers between them missed the failure that actually
		// happened: a theme calling `create_function()`, which PHP 8.0 removed. A theme
		// declares no `Requires PHP` for the header gate to read, and comparing 7.4 to 8.5 only
		// ever produced a "heads up". Read on the destination rather than recorded at export,
		// so a package built before this existed is still checked -- including one already
		// sitting on a server.
		$code = new CodeCompatibility( $this->dir, $manifest );
		$code->check( $report, '', ! empty( $this->options['fix_php'] ) );

		// And what the database switches on that will not be here to run. Said in plugin names
		// rather than in part names, because "uploads and plugins were left out" does not read
		// as "the contact form and the page layouts will be gone".
		$plugins = new PluginPresence( $this->dir, $manifest );

		// The warning `PluginPresence` exists to give -- "the database switches these on and the
		// code will not be here" -- is about a database that is arriving. None is, so this site's
		// own `active_plugins` is the one that will still be in force, naming plugins that are
		// still installed.
		if ( $manifest->has_database() && ! $manifest->is_partial_database() ) {
			$plugins->check( $report );
		} elseif ( $manifest->is_partial_database() ) {
			$report->pass(
				'partial_database',
				'This package merges a few tables and settings into this site. Your content, users and '
					. 'everything else here stay as they are.'
			);
		} else {
			$report->pass(
				'files_only',
				'This package carries no database. Your content, users and settings stay exactly as they are.'
			);
		}

		return array(
			'ok'         => ! $report->is_blocked(),
			'problems'   => array(),
			'package'    => $this->package->inspect(),
			'report'     => $report->to_array(),
			'users'      => $manifest->has_database() && ! $manifest->is_partial_database()
				? $this->user_plan( $manifest )
				: array(),
			'manual'     => $this->manual_steps(),
			'target'     => $this->target(),
			'files_only' => ! $manifest->has_database(),
			'partial'    => $manifest->is_partial_database(),
			'merging'    => $manifest->is_partial_database()
				? array(
					'tables'  => \array_values( (array) \nfd_sm_data_get( $manifest->get( 'database', array() ), 'tables', array() ) ),
					'options' => \array_values( (array) \nfd_sm_data_get( $manifest->get( 'database', array() ), 'options', array() ) ),
				)
				: array(),
			// A code-only import replaces nothing, so a previous import's backup is not in its
			// way and is not spent by it. The screen only warns about what starting this run
			// would cost.
			'has_backup' => $manifest->has_database()
				&& ! $manifest->is_partial_database()
				&& $this->previous_backup_present(),
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
		$state['files_only']    = ! $manifest->has_database();
		$state['partial']       = $manifest->is_partial_database();

		if ( $state['partial'] ) {
			$declared = (array) $manifest->get( 'database', array() );

			$state['carry_tables']  = \array_values( (array) \nfd_sm_data_get( $declared, 'tables', array() ) );
			$state['carry_options'] = \array_values( (array) \nfd_sm_data_get( $declared, 'options', array() ) );
		}

		// Only a package that carries data needs somewhere to put it. A code-only one never
		// reaches the staging prefix or the swap, so the source's prefix is not something it has
		// to have recorded.
		if ( '' === $state['source_prefix'] && ! $state['files_only'] ) {
			throw new \RuntimeException( 'The package does not record the source table prefix.' );
		}

		// A previous attempt's staging tables would be merged with this one's, producing a
		// database that is half of each.
		$swap    = $this->swap( $state );
		$dropped = $swap->discard_staged();

		if ( $dropped > 0 ) {
			$state['notes'][] = \sprintf( 'Cleared %d table(s) left by an earlier attempt.', $dropped );
		}

		// And only a package that replaces the site spends the previous import's undo. Discarding
		// the backup is the price of *this* migration replacing the one before it -- a code-only
		// import replaces nothing, touches no table, and has no business ending somebody else's
		// way back.
		if ( ! $state['files_only'] ) {
			$this->check_previous_backup( $swap, $state );
		}

		// The originals an earlier import's fixes kept belong to a site that is about to be
		// replaced again, which is the same reasoning that just discarded its tables.
		SyntaxFixer::clear_backups();

		// Decided from the package here rather than trusted from the review screen, with the same
		// scan, so a run can only fix what that screen said it would.
		$state['fix_php']   = $this->fixes_wanted( $state );
		$state['php_fixes'] = $state['fix_php'] ? ( new CodeCompatibility( $this->dir, $manifest ) )->fixable_files() : array();
		$state['php_fixed'] = array();

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

		// Cut first: a table this site kept, holding a foreign key the previous swap left pointing
		// at those backups, makes them undroppable -- and a drop that fails here used to be
		// reported as a success and then read, on the next visit, as a migration still undecided.
		$swap->detach_backup_references();

		$dropped = $swap->discard_backup();
		$left    = $swap->remaining( $swap->backup_prefix() );

		$state['notes'][] = \sprintf(
			'Discarded %d table(s) a previous import had kept. Starting this migration is what '
			. 'ended that one\'s rollback window.',
			$dropped
		);

		if ( ! empty( $left ) ) {
			$state['notes'][] = \sprintf(
				'%d of them could not be dropped and are still here: %s.',
				\count( $left ),
				\implode( ', ', \array_slice( $left, 0, 5 ) ) . ( \count( $left ) > 5 ? ', …' : '' )
			);
		}
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

		// A code-only run replaced nothing, so there are no backup tables to let go of. Keeping
		// it is still a decision worth recording -- it is what settles the run, and an unsettled
		// one holds up the next migration.
		if ( ! empty( $state['files_only'] ) ) {
			return 0;
		}

		// A merge replaced a named few tables, so keeping it lets go of a named few copies --
		// never `nfdold_` as a whole, which on this site may also hold what a full import before
		// it put there.
		if ( ! empty( $state['partial'] ) ) {
			$replaced = \array_values(
				\array_diff(
					isset( $state['swapped_names'] ) ? (array) $state['swapped_names'] : array(),
					isset( $state['added_names'] ) ? (array) $state['added_names'] : array()
				)
			);

			return \count( $this->swap( $state )->clear_backup_names( $replaced ) );
		}

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

		$restorer = new FileRestorer(
			$this->dir,
			$this->package->manifest(),
			\array_fill_keys( (array) $state['php_fixes'], true )
		);

		$complete = $restorer->step( $state, $deadline );

		foreach ( $restorer->refused() as $path ) {
			$state['refused'][] = $path;
		}

		// Keyed by file, because a step that dies before its checkpoint is written restores and
		// fixes the same entry again on the next one.
		foreach ( $restorer->fixed() as $record ) {
			$state['php_fixed'][ $record['file'] ] = \count( $record['changes'] );
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

		if ( ! empty( $state['php_fixed'] ) ) {
			$state['notes'][] = \sprintf(
				'Fixed syntax PHP %1$s no longer accepts in %2$d file(s), %3$d change(s) in all. The originals are kept in %4$s.',
				\implode( '.', \array_slice( \explode( '.', PHP_VERSION ), 0, 2 ) ),
				\count( $state['php_fixed'] ),
				\array_sum( $state['php_fixed'] ),
				SyntaxFixer::backup_dir()
			);
		}

		$unfixed = \array_diff( (array) $state['php_fixes'], \array_keys( (array) $state['php_fixed'] ) );

		// Found fixable at precheck and not fixed as written -- a backup that could not be saved,
		// or an entry that was never written. Each is code that will not run here.
		if ( ! empty( $unfixed ) ) {
			$state['notes'][] = \sprintf(
				'%d file(s) that were to be fixed were left as the package had them, and will not run on this PHP: %s',
				\count( $unfixed ),
				\implode( ', ', \array_slice( $unfixed, 0, 10 ) ) . ( \count( $unfixed ) > 10 ? ', …' : '' )
			);
		}

		// Taken here rather than at rollback: by then the answer would include anything the
		// user installed after the import, and rollback would delete that too.
		if ( ! empty( $state['code_before'] ) ) {
			$state['code_added'] = AddedCode::added( (array) $state['code_before'] );
		}

		$this->progress->finish( ImportCheckpoint::STAGE_FILES );

		// Five stages exist to move a database safely and there is no database. Straight to
		// fixups, which is where a run says what it did -- and notably *not* through the swap,
		// which with nothing staged would rename this site's live tables into the backup set and
		// put nothing back in their place.
		$state['stage'] = empty( $state['files_only'] )
			? ImportCheckpoint::STAGE_DATABASE
			: ImportCheckpoint::STAGE_FIXUPS;
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

		// Said rather than dropped quietly: a constraint the source enforced is not enforced here.
		// See `DatabaseBase::strip_foreign_keys()` for why they cannot travel.
		if ( ! empty( $state['foreign_keys'] ) ) {
			$tables = \array_keys( (array) $state['foreign_keys'] );

			$state['notes'][] = \sprintf(
				'Did not carry %d foreign key(s), from %s. The tables and their indexes arrived; what is '
					. 'gone is the database enforcing those relationships, which WordPress itself never relies on.',
				\array_sum( (array) $state['foreign_keys'] ),
				\implode( ', ', \array_slice( $tables, 0, 5 ) ) . ( \count( $tables ) > 5 ? ', …' : '' )
			);
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

		// No users table is in a partial dump, and the destination's own is not being replaced,
		// so there is nobody to reconcile with anybody. Validation is skipped for the same
		// reason: it asks whether the staged copy is a whole site, which this deliberately is not.
		$state['stage'] = empty( $state['partial'] )
			? ImportCheckpoint::STAGE_USERS
			: ImportCheckpoint::STAGE_SWAP;
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

		if ( ! empty( $state['partial'] ) ) {
			$this->swap( $state )->execute_only( $state, $this->carried_names( $state ) );
		} else {
			$this->swap( $state )->execute( $state );
		}

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

		// A code-only import has nothing to repair: no swap happened, so no view was dropped, no
		// foreign key was left pointing at a backup, `active_plugins` still reads the way this
		// site wrote it, and the session belongs to a user this import never touched. Running
		// `Fixups` anyway would be repairing damage that was not done.
		if ( ! empty( $state['files_only'] ) ) {
			$this->finish_files_only( $state );

			return;
		}

		if ( ! empty( $state['partial'] ) ) {
			$this->finish_partial( $state );

			return;
		}

		$swap = $this->swap( $state );
		$swap->recreate_views( (array) $state['views'], $state );

		// A table this site already had, that the package does not carry, keeps its foreign keys
		// through the rename -- and they now name the backup copy of the parent rather than the
		// one this site is about to serve. Cut here, while the reason is still in view: left in
		// place they hold a plugin to the replaced site and make the backup undroppable.
		$cut = $swap->detach_backup_references();

		if ( ! empty( $cut ) ) {
			$state['notes'][] = \sprintf(
				'Removed %d foreign key(s) that the swap left pointing at the replaced tables: %s. The '
					. 'rows are untouched; only the database-level rule is gone.',
				\count( $cut ),
				\implode( ', ', \array_slice( $cut, 0, 5 ) ) . ( \count( $cut ) > 5 ? ', …' : '' )
			);
		}

		$fixups = new Fixups();

		$acting_final = (int) \nfd_sm_data_get( $state, 'users.acting_id', 0 );

		foreach ( $fixups->run( $this->target(), $acting_final ) as $note ) {
			$state['notes'][] = $note;
		}

		if ( ! $this->options['keep_backup'] ) {
			$dropped = $swap->discard_backup();
			$left    = $swap->remaining( $swap->backup_prefix() );

			$state['notes'][] = \sprintf( 'Discarded %d backup table(s) as requested.', $dropped );

			// Counted by looking, so a table that refused to go is said rather than assumed away.
			if ( ! empty( $left ) ) {
				$state['notes'][] = \sprintf(
					'%d backup table(s) could not be dropped and are still here: %s.',
					\count( $left ),
					\implode( ', ', \array_slice( $left, 0, 5 ) ) . ( \count( $left ) > 5 ? ', …' : '' )
				);
			}
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
	 * The carried tables as bare names, with the source's prefix and this site's both taken off.
	 *
	 * The dump names them with the source's prefix and they were staged under this site's staging
	 * prefix, so neither end can be assumed. What `Swap` wants is the name in the middle.
	 *
	 * @param array $state Import state.
	 *
	 * @return array
	 */
	protected function carried_names( array $state ) {
		$names  = array();
		$source = (string) $state['source_prefix'];
		$live   = (string) $state['live_prefix'];

		foreach ( (array) \nfd_sm_data_get( $state, 'carry_tables', array() ) as $table ) {
			$name = (string) $table;

			if ( '' !== $source && 0 === \strpos( $name, $source ) ) {
				$name = \substr( $name, \strlen( $source ) );
			} elseif ( '' !== $live && 0 === \strpos( $name, $live ) ) {
				$name = \substr( $name, \strlen( $live ) );
			}

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return \array_values( \array_unique( $names ) );
	}

	/**
	 * Close a run that merged a few tables and a few settings into a site that stays itself.
	 *
	 * None of `Fixups` runs. Every repair it makes is a repair for a site that was replaced: the
	 * plugin list, the theme, the session, the views, the foreign keys left pointing at a backup.
	 * Here the destination's own `wp_options` is still in place and still correct, and the one
	 * thing that had to reach it -- the chosen plugins' settings -- is written row by row, with
	 * every previous value recorded so the undo is exact.
	 *
	 * @param array $state Import state, modified in place.
	 *
	 * @return void
	 */
	protected function finish_partial( array &$state ) {
		$swapped = isset( $state['swapped_names'] ) ? (array) $state['swapped_names'] : array();
		$added   = isset( $state['added_names'] ) ? (array) $state['added_names'] : array();
		$names   = (array) \nfd_sm_data_get( $state, 'carry_options', array() );

		$written = array();

		if ( ! empty( $names ) ) {
			$staged  = $state['stage_prefix'] . 'options';
			$merger  = new OptionMerger( $staged );
			$written = $merger->merge( $names, $state );
		}

		$state['notes'][] = \sprintf(
			'Merged into this site rather than replacing it: %d table(s) and %d setting(s) arrived, '
				. 'and everything else here — posts, pages, users, and every other plugin — is untouched.',
			\count( $swapped ),
			\count( $written )
		);

		if ( ! empty( $swapped ) ) {
			$replaced = \array_values( \array_diff( $swapped, $added ) );

			$state['notes'][] = \sprintf(
				'Tables: %s.%s',
				\implode( ', ', \array_slice( $swapped, 0, 10 ) ) . ( \count( $swapped ) > 10 ? ', …' : '' ),
				empty( $replaced )
					? ' None of them were here before.'
					: \sprintf(
						' %d of them replaced a table this site already had, which is kept for the undo.',
						\count( $replaced )
					)
			);
		}

		// The staged copy of the source's options table has done its one job. It is not swapped
		// in and never could be -- it holds a handful of another site's rows -- so it goes now
		// rather than sitting in the database looking like a migration that stopped half way.
		$dropped = $this->swap( $state )->discard_staged();

		if ( $dropped > 0 && empty( $written ) && ! empty( $names ) ) {
			$state['notes'][] = 'No settings were written: the package declared some, and the dump held none of them.';
		}

		$state['manual'] = $this->manual_steps();

		$this->progress->finish( ImportCheckpoint::STAGE_FIXUPS );

		$this->release();

		\wp_cache_flush();

		$state['finished_at'] = \gmdate( 'c' );
		$state['stage']       = ImportCheckpoint::STAGE_DONE;
	}

	/**
	 * Close a run that carried code and no data.
	 *
	 * Deliberately plain about what did *not* happen. A plugin that arrives without its tables and
	 * its settings is a plugin that has never been set up, and one that WordPress has not been
	 * told to switch on: `active_plugins` lives in `wp_options`, which is this site's own and was
	 * not written to. Saying it here is the difference between a user expecting a configured
	 * plugin and a user knowing to go and activate one.
	 *
	 * @param array $state Import state, modified in place.
	 *
	 * @return void
	 */
	protected function finish_files_only( array &$state ) {
		$added = isset( $state['code_added'] ) ? (array) $state['code_added'] : array();

		$state['notes'][] = \sprintf(
			'This package carried code only. %d plugin(s) and theme(s) arrived; the database was not '
				. 'touched, so this site keeps its own content, users and settings.',
			\count( $added )
		);

		if ( ! empty( $added ) ) {
			$state['notes'][] = \sprintf(
				'Nothing new is switched on: %s %s on disk and inactive until you activate %s on the '
					. 'Plugins or Themes screen.',
				\implode( ', ', \array_slice( $added, 0, 10 ) ) . ( \count( $added ) > 10 ? ', …' : '' ),
				1 === \count( $added ) ? 'is' : 'are',
				1 === \count( $added ) ? 'it' : 'them'
			);
		}

		// Undoing this one is a file deletion, not a rename, and `AddedCode` already knows
		// exactly which files were not here before the run started.
		$state['notes'][] = 'Undoing it removes those files again and changes nothing else.';

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

		// What the source deliberately did not send. The destination cannot work this out for
		// itself — a package with no `uploads` part looks exactly like a site with no media —
		// and the difference decides whether the screen of broken images that follows is a bug
		// or the plan somebody made ten minutes earlier.
		$contents = (array) $manifest->get( 'contents', array() );

		if ( ! empty( $contents ) && empty( $contents['everything'] ) ) {
			$left_out = new Selection( (array) \nfd_sm_data_get( $contents, 'selection', array() ) );
			$said     = $left_out->describe();

			if ( ! empty( $said ) ) {
				$steps[] = array(
					'id'    => 'contents',
					'label' => 'The source left this out of the package on purpose: ' . \implode( '; ', $said )
						. '. Whatever this site already has in those places is kept, and the database that '
						. 'arrives will still refer to files the package did not carry.',
				);
			}
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

		// A code-only run never swaps, so the usual test for "did this actually happen" would
		// refuse to undo the one kind of import whose undo is simplest: the files it added, and
		// nothing else. It is still refused before it has finished, because `code_added` is only
		// taken once the files stage completes.
		if ( ! empty( $state['files_only'] ) ) {
			return $this->rollback_files_only( $state );
		}

		if ( ! empty( $state['partial'] ) ) {
			return $this->rollback_partial( $state );
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
	 * Undo a merge.
	 *
	 * Three things in the order that keeps each one recoverable if the next fails: the tables go
	 * back by rename, the settings go back row by row from the values recorded before they were
	 * overwritten, and the files that arrived are deleted last -- the same order, and the same
	 * reasoning, as the whole-site rollback.
	 *
	 * A table this site never had before the import is not "put back": there is nothing to put.
	 * It returns to the staging prefix with the rest and is dropped there, which leaves the
	 * database holding exactly what it held before.
	 *
	 * @param array $state Import state.
	 *
	 * @return array The state as saved.
	 *
	 * @throws \RuntimeException If nothing was ever swapped in.
	 */
	protected function rollback_partial( array $state ) {
		if ( empty( $state['swapped'] ) ) {
			throw new \RuntimeException( 'This import has not been swapped in, so there is nothing to undo.' );
		}

		$swap = $this->swap( $state );

		$swap->rollback_only( $state );

		$dropped = $swap->discard_staged();

		$restored = OptionMerger::restore(
			isset( $state['options_before'] ) ? (array) $state['options_before'] : array()
		);

		$removed = AddedCode::remove(
			isset( $state['code_added'] ) ? (array) $state['code_added'] : array(),
			isset( $state['code_before'] ) ? (array) $state['code_before'] : array()
		);

		$state['code_removed'] = $removed;

		$this->release();

		\wp_cache_flush();

		$state['notes'][] = \sprintf(
			'Undone: %d imported table(s) discarded, %d setting(s) put back as they were, and this site\'s '
				. 'own content never moved.',
			$dropped,
			$restored
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
	 * Undo an import that only ever wrote files.
	 *
	 * `AddedCode` is the whole of it: the difference between the plugin and theme directories as
	 * they were at precheck and as they were when the files stage finished. What the destination
	 * already had and the package overwrote stays, for the reason it always stays -- its own copy
	 * is gone by then, and deleting it would turn an incomplete undo into a destructive one.
	 *
	 * @param array $state Import state.
	 *
	 * @return array The state as saved.
	 *
	 * @throws \RuntimeException If the run never got as far as writing anything.
	 */
	protected function rollback_files_only( array $state ) {
		if ( ImportCheckpoint::STAGE_DONE !== $state['stage'] ) {
			throw new \RuntimeException( 'This import has not finished, so there is nothing to undo yet.' );
		}

		$removed = AddedCode::remove(
			isset( $state['code_added'] ) ? (array) $state['code_added'] : array(),
			isset( $state['code_before'] ) ? (array) $state['code_before'] : array()
		);

		$state['code_removed'] = $removed;
		$state['rolled_back']  = true;

		$this->release();

		\wp_cache_flush();

		$state['notes'][] = empty( $removed )
			? 'Undone: the package had added nothing this site did not already have, and the database was never touched.'
			: \sprintf(
				'Undone: removed %d plugin(s) and theme(s) the import installed, and nothing else changed. %s.',
				\count( $removed ),
				\implode( ', ', $removed )
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

		return $compatibility->check( $manifest->has_database() );
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
