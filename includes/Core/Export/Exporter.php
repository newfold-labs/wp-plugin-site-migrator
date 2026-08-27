<?php
/**
 * Export orchestration.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Export;

use NewfoldLabs\WP\SiteMigrator\Core\Package\Checkpoint;
use NewfoldLabs\WP\SiteMigrator\Core\Package\Manifest;
use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageWriter;
use NewfoldLabs\WP\SiteMigrator\Core\Progress\NullProgressReporter;
use NewfoldLabs\WP\SiteMigrator\Core\Progress\ProgressReporter;

/**
 * Drives an export, one bounded step at a time.
 *
 * `step( $budget )` is the execution contract for the whole core. It does as much work as it
 * can within the budget, persists a checkpoint, and returns. A caller loops until the returned
 * state says done. A budget of 0 means "no limit", which is how the CLI runs it — one call,
 * one complete export.
 *
 * This class must stay transport-agnostic: no WP_CLI, no WP_REST_Request, no superglobals, no
 * output. Two callers exist so that stays true rather than merely intended.
 */
class Exporter {

	/**
	 * Default loose-storage threshold, 64MB.
	 */
	const LOOSE_THRESHOLD = 67108864;

	/**
	 * How long a run lock is trusted before it is treated as abandoned.
	 *
	 * Longer than any single step should take, short enough that a killed request does not
	 * block the next one for long.
	 */
	const LOCK_SECONDS = 120;

	/**
	 * Default volume limit, 128MB.
	 *
	 * This is the unit of writing, not just of splitting. Each volume is opened, filled and
	 * closed exactly once, so the limit sets both how much disk traffic one close costs and how
	 * long it takes — which is what keeps a step inside a shared host's execution budget. It was
	 * 1GB when volumes were only a splitting convenience; at that size a single close is a
	 * gigabyte of writing, and any host that cannot finish it never finishes the export at all.
	 */
	const VOLUME_LIMIT = 134217728;

	/**
	 * Package writer.
	 *
	 * @var PackageWriter
	 */
	protected $package;

	/**
	 * Checkpoint store.
	 *
	 * @var Checkpoint
	 */
	protected $checkpoint;

	/**
	 * Progress reporter.
	 *
	 * @var ProgressReporter
	 */
	protected $progress;

	/**
	 * Part descriptions.
	 *
	 * @var array
	 */
	protected $specs;

	/**
	 * Constructor.
	 *
	 * @param string $dir Absolute package directory.
	 */
	public function __construct( $dir ) {
		$this->package    = new PackageWriter( $dir );
		$this->checkpoint = new Checkpoint( $dir );
		$this->progress   = new NullProgressReporter();
		$this->specs      = PartSpecs::all();
	}

	/**
	 * Attach a progress reporter.
	 *
	 * A setter rather than a nullable constructor argument: the PHP 5.6 floor rules out the
	 * `?ProgressReporter` syntax, and an untyped nullable parameter is deprecated from 8.4.
	 *
	 * @param ProgressReporter $progress Progress reporter.
	 *
	 * @return Exporter
	 */
	public function set_progress( ProgressReporter $progress ) {
		$this->progress = $progress;

		return $this;
	}

	/**
	 * Package directory.
	 *
	 * @return string
	 */
	public function dir() {
		return $this->package->dir();
	}

	/**
	 * Whether an export is already in progress in this directory.
	 *
	 * @return bool
	 */
	public function is_resumable() {
		return $this->checkpoint->exists();
	}

	/**
	 * What the export looks like right now, without advancing it.
	 *
	 * A reloaded tab has to be able to draw the progress it already made before it asks for
	 * more of it. Without this the screen renders zeroes until the first step returns, which on
	 * a large site is half a minute of a page that looks like it has lost the run.
	 *
	 * @return array The same shape a step returns, plus `running`.
	 */
	public function snapshot() {
		$state = $this->checkpoint->load();

		return \array_merge(
			$this->report( $state, false ),
			array( 'running' => $this->is_running() )
		);
	}

	/**
	 * Run the export to completion.
	 *
	 * @param float $budget Seconds per step, or 0 for no limit.
	 *
	 * @return array Final state.
	 */
	public function run( $budget = 0 ) {
		do {
			$state = $this->step( $budget );
		} while ( ! $state['done'] );

		return $state;
	}

	/**
	 * Advance the export by at most one budget's worth of work.
	 *
	 * @param float $budget Seconds, or 0 for no limit.
	 *
	 * @return array State: `done`, `stage`, `files`, `bytes`, `manifest`.
	 */
	public function step( $budget = 0 ) {
		$deadline = $budget > 0 ? \microtime( true ) + (float) $budget : 0;

		$this->package->prepare();

		// Pausing in the browser abandons the request rather than waiting for it, because a
		// step cannot be interrupted once it is inside a zip close. The step it abandoned is
		// still running, and pressing Resume a second later would start a second one on the
		// same archive. The lock is what makes abandoning safe.
		if ( ! $this->claim() ) {
			return $this->report( $this->checkpoint->load(), false, 'busy' );
		}

		$state = $this->checkpoint->load();

		// A run that still has work to do is a run that is rewriting this directory, so the
		// previous manifest has to go before any of it happens — see `PackageWriter::invalidate()`.
		// It is done here rather than in `prepare()` because only the checkpoint knows whether
		// this step is going to write anything: a finished export steps into nothing at all, and
		// its manifest must survive.
		if ( Checkpoint::STAGE_DONE !== $state['stage'] ) {
			$this->package->invalidate();
		}

		// A run that has not written anything yet is starting from nothing, so the directory has
		// to be empty before it does. Overwriting by name is not enough: what the previous run
		// wrote and this one does not reach stays behind. Guarded on the checkpoint rather than
		// on the directory, because a resumed export must find its parts exactly where it left
		// them — see `PackageWriter::reset()`.
		if ( $this->is_fresh( $state ) ) {
			$this->package->reset();
		}

		if ( Checkpoint::STAGE_DATABASE === $state['stage'] ) {
			$this->step_database( $state, $deadline );
		} elseif ( Checkpoint::STAGE_FILES === $state['stage'] ) {
			$this->step_files( $state, $deadline );
		}

		if ( Checkpoint::STAGE_FINALIZE === $state['stage'] ) {
			$report = $this->finalize( $state );

			$this->release();

			return $report;
		}

		$this->checkpoint->save( $state );
		$this->release();

		return $this->report( $state, false );
	}

	/**
	 * Take the run lock, if nothing else holds it.
	 *
	 * A stale lock is one whose holder is no longer running — a request killed by the host, or a
	 * browser tab that went away mid-step. It is taken over rather than waited on, because
	 * nothing would ever release it.
	 *
	 * @return bool
	 */
	protected function claim() {
		if ( $this->is_running() ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		\file_put_contents( $this->lock_path(), (string) \time(), LOCK_EX );

		return true;
	}

	/**
	 * Whether a step is running right now.
	 *
	 * The lock is the only honest answer available. Pausing abandons the request rather than
	 * waiting for it, so the browser that asked for the step is often gone while the step is
	 * still finishing; nothing on the client side knows that, and the checkpoint will not say
	 * so until the step ends.
	 *
	 * @return bool
	 */
	public function is_running() {
		$path = $this->lock_path();

		if ( ! \is_readable( $path ) ) {
			return false;
		}

		$held = (int) \file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $held > 0 && ( \time() - $held ) < self::LOCK_SECONDS;
	}

	/**
	 * Give the run lock back.
	 *
	 * @return void
	 */
	protected function release() {
		$path = $this->lock_path();

		if ( \file_exists( $path ) ) {
			\unlink( $path );
		}
	}

	/**
	 * Where the run lock lives.
	 *
	 * @return string
	 */
	protected function lock_path() {
		return $this->package->path( 'export.lock' );
	}

	/**
	 * Dump the database.
	 *
	 * @param array $state    Run state, modified in place.
	 * @param float $deadline Unix timestamp to stop by.
	 *
	 * @return void
	 */
	protected function step_database( array &$state, $deadline ) {
		$this->progress->start( Checkpoint::STAGE_DATABASE );

		$exporter = new DatabaseExporter();
		$target   = $this->package->path( DatabaseExporter::FILE );

		$complete = $exporter->step( $target, $state['database'], $deadline );

		if ( $complete ) {
			$this->progress->finish( Checkpoint::STAGE_DATABASE );
			$state['stage'] = Checkpoint::STAGE_FILES;
		} else {
			$this->progress->advance(
				Checkpoint::STAGE_DATABASE,
				(int) $state['database']['table_index'],
				0,
				'Dumping the database'
			);
		}
	}

	/**
	 * Collect the file parts.
	 *
	 * @param array $state    Run state, modified in place.
	 * @param float $deadline Unix timestamp to stop by.
	 *
	 * @return void
	 */
	protected function step_files( array &$state, $deadline ) {
		$collector = new FileCollector(
			$this->package,
			$this->loose_threshold(),
			$this->volume_limit()
		);

		$total = \count( $this->specs );

		while ( $state['part_index'] < $total ) {
			$spec = $this->specs[ $state['part_index'] ];
			$list = $this->package->list_path( $spec->name() );

			if ( ! \file_exists( $list ) ) {
				$this->progress->start( $spec->name() );

				// The walk already counts what it finds. Keeping the answer is what lets the
				// progress bar be a real fraction rather than an animation: without it the UI
				// can only say "still going", and on a site where this takes many minutes
				// that is indistinguishable from "stuck".
				$totals = $collector->prepare( $spec );

				$state['plan'][ $spec->name() ] = array(
					'files' => (int) $totals['files'],
					'bytes' => (int) $totals['bytes'],
				);

				foreach ( (array) $totals['links'] as $link ) {
					$state['skipped_links'][] = $link;
				}

				foreach ( (array) $totals['skipped'] as $path ) {
					$state['skipped_paths'][] = $path;
				}
			}

			$complete = $collector->step( $spec, $state, $deadline );

			if ( ! $complete ) {
				$this->progress->advance(
					$spec->name(),
					(int) $state['files_done'],
					0,
					'Packaging ' . $spec->name()
				);

				return;
			}

			$this->progress->finish( $spec->name() );

			++$state['part_index'];
			$state['list_offset']  = 0;
			$state['volume']       = 1;
			$state['volume_bytes'] = 0;

			if ( $deadline > 0 && \microtime( true ) >= $deadline ) {
				return;
			}
		}

		$state['stage'] = Checkpoint::STAGE_FINALIZE;
	}

	/**
	 * Write the manifest and clean up.
	 *
	 * @param array $state Run state.
	 *
	 * @return array
	 */
	protected function finalize( array $state ) {
		$this->progress->start( Checkpoint::STAGE_FINALIZE );

		$manifest = Manifest::for_this_site();

		$database = DatabaseExporter::FILE;
		$manifest->set_database(
			$database,
			$this->package->size( $database ),
			$this->package->checksum( $database )
		);

		// Sizes and checksums were taken as each volume and each loose file was written. They
		// are read back here rather than recomputed, so finalize stays a bookkeeping step
		// instead of a second pass over the whole package.
		foreach ( $state['parts'] as $relative => $part ) {
			$manifest->add_part(
				array(
					'name'   => $part['name'],
					'prefix' => $part['prefix'],
					'file'   => $relative,
					'bytes'  => isset( $part['bytes'] ) ? (int) $part['bytes'] : $this->package->size( $relative ),
					'sha256' => isset( $part['sha256'] ) ? $part['sha256'] : $this->package->checksum( $relative ),
					'files'  => (int) $part['files'],
				)
			);
		}

		foreach ( $state['large'] as $relative ) {
			$in_package = PackageWriter::LARGE_DIR . '/' . \ltrim( $relative, '/' );
			$meta       = isset( $state['large_meta'][ $relative ] ) ? $state['large_meta'][ $relative ] : array();

			$manifest->add_large(
				array(
					'path'   => $relative,
					'file'   => $in_package,
					'bytes'  => isset( $meta['bytes'] ) ? (int) $meta['bytes'] : $this->package->size( $in_package ),
					'sha256' => isset( $meta['sha256'] ) ? $meta['sha256'] : $this->package->checksum( $in_package ),
				)
			);
		}

		// Named, and capped, so a site that symlinks a thousand things does not turn the
		// manifest into a list of them. The count is what matters; the names help the few
		// people who need to act on it.
		$links   = (array) $state['skipped_links'];
		$skipped = isset( $state['skipped_paths'] ) ? (array) $state['skipped_paths'] : array();

		$manifest->set_skipped_links( \array_slice( $links, 0, 50 ), \count( $links ) );
		$manifest->set_skipped_paths( \array_slice( $skipped, 0, 50 ), \count( $skipped ) );

		$this->package->finalize( $manifest );
		$this->progress->finish( Checkpoint::STAGE_FINALIZE );

		$state['stage'] = Checkpoint::STAGE_DONE;

		return $this->report( $state, true );
	}

	/**
	 * Shape the state for a caller.
	 *
	 * @param array  $state  Run state.
	 * @param bool   $done   Whether the export finished.
	 * @param string $status Non-empty when the step did nothing, and why.
	 *
	 * @return array
	 */
	protected function report( array $state, $done, $status = '' ) {
		$part = isset( $this->specs[ $state['part_index'] ] ) ? $this->specs[ $state['part_index'] ]->name() : '';
		$plan = isset( $state['plan'] ) ? (array) $state['plan'] : array();

		// Everything the walk has counted so far, plus what is still unwalked expressed as a
		// count of parts. Deliberately not a single invented percentage: parts are walked as
		// they are reached, so a total for the whole site does not exist until the last one
		// starts, and inventing one would mean a bar that jumps backwards.
		$planned_files = 0;
		$planned_bytes = 0;

		foreach ( $plan as $counted ) {
			$planned_files += (int) $counted['files'];
			$planned_bytes += (int) $counted['bytes'];
		}

		return array(
			'done'          => (bool) $done,
			'status'        => $status,
			'stage'         => $state['stage'],
			'part'          => $part,
			'part_index'    => (int) $state['part_index'],
			'part_count'    => \count( $this->specs ),
			// The parts in the order they will be walked, so the screen can show what is done
			// and what is still queued rather than only what is happening now.
			'parts'         => $this->part_names(),
			// And the last few volumes actually written, which is the only honest form an
			// activity log can take here: these are files on disk, with the sizes recorded as
			// each one closed.
			'written'       => $this->recent( $state ),
			'files'         => (int) $state['files_done'],
			'bytes'         => (int) $state['bytes_done'],
			'planned_files' => $planned_files,
			'planned_bytes' => $planned_bytes,
			'skipped_links' => \count( (array) ( isset( $state['skipped_links'] ) ? $state['skipped_links'] : array() ) ),
			'skipped_paths' => \count( (array) ( isset( $state['skipped_paths'] ) ? $state['skipped_paths'] : array() ) ),
			'manifest'      => $done ? $this->package->path( Manifest::NAME ) : '',
		);
	}

	/**
	 * Every part's name, in walk order.
	 *
	 * @return array
	 */
	protected function part_names() {
		$names = array();

		foreach ( $this->specs as $spec ) {
			$names[] = $spec->name();
		}

		return $names;
	}

	/**
	 * The volumes most recently written.
	 *
	 * Bounded hard: a 2.2GB site produced 117 of them, and this travels on every step of a run
	 * the browser polls continuously.
	 *
	 * @param array $state Run state.
	 *
	 * @return array Each entry `file` and `bytes`.
	 */
	protected function recent( array $state ) {
		$parts  = isset( $state['parts'] ) ? (array) $state['parts'] : array();
		$recent = array();

		foreach ( \array_slice( $parts, -4, 4, true ) as $relative => $part ) {
			$recent[] = array(
				'file'  => (string) $relative,
				'bytes' => isset( $part['bytes'] ) ? (int) $part['bytes'] : 0,
			);
		}

		return $recent;
	}

	/**
	 * Loose-storage threshold in bytes.
	 *
	 * @return int
	 */
	protected function loose_threshold() {
		/**
		 * Filter the size at or above which a file is stored loose rather than zipped.
		 *
		 * @param int $bytes Threshold in bytes.
		 */
		return (int) \apply_filters( 'nfd_sm_loose_threshold', self::LOOSE_THRESHOLD );
	}

	/**
	 * Volume limit in bytes.
	 *
	 * @return int
	 */
	protected function volume_limit() {
		/**
		 * Filter the size at which a part is split into another volume.
		 *
		 * @param int $bytes Volume limit in bytes.
		 */
		return (int) \apply_filters( 'nfd_sm_volume_limit', self::VOLUME_LIMIT );
	}

	/**
	 * Whether this step is the first of a run that has written nothing yet.
	 *
	 * Every one of these is at its initial value only before the first step; the database offset
	 * moves on the first write, so a resume can never be mistaken for a fresh start.
	 *
	 * @param array $state Checkpoint state.
	 *
	 * @return bool
	 */
	protected function is_fresh( array $state ) {
		if ( Checkpoint::STAGE_DATABASE !== $state['stage'] ) {
			return false;
		}

		$database = isset( $state['database'] ) ? (array) $state['database'] : array();

		return empty( $state['parts'] )
			&& empty( $state['large'] )
			&& 0 === (int) $state['part_index']
			&& 0 === (int) \nfd_sm_data_get( $database, 'query_offset', 0 )
			&& 0 === (int) \nfd_sm_data_get( $database, 'table_index', 0 );
	}
}
