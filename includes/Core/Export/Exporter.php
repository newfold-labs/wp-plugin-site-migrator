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
		$state = $this->checkpoint->load();

		if ( Checkpoint::STAGE_DATABASE === $state['stage'] ) {
			$this->step_database( $state, $deadline );
		} elseif ( Checkpoint::STAGE_FILES === $state['stage'] ) {
			$this->step_files( $state, $deadline );
		}

		if ( Checkpoint::STAGE_FINALIZE === $state['stage'] ) {
			return $this->finalize( $state );
		}

		$this->checkpoint->save( $state );

		return $this->report( $state, false );
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
				$collector->prepare( $spec );
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

		$this->package->finalize( $manifest );
		$this->progress->finish( Checkpoint::STAGE_FINALIZE );

		$state['stage'] = Checkpoint::STAGE_DONE;

		return $this->report( $state, true );
	}

	/**
	 * Shape the state for a caller.
	 *
	 * @param array $state Run state.
	 * @param bool  $done  Whether the export finished.
	 *
	 * @return array
	 */
	protected function report( array $state, $done ) {
		return array(
			'done'     => (bool) $done,
			'stage'    => $state['stage'],
			'part'     => isset( $this->specs[ $state['part_index'] ] ) ? $this->specs[ $state['part_index'] ]->name() : '',
			'files'    => (int) $state['files_done'],
			'bytes'    => (int) $state['bytes_done'],
			'manifest' => $done ? $this->package->path( Manifest::NAME ) : '',
		);
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
}
