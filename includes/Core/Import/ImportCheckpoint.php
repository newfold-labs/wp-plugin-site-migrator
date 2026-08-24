<?php
/**
 * Resumable import state, stored on disk.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

use NewfoldLabs\WP\SiteMigrator\Core\Package\Checkpoint;

/**
 * Where an import got to.
 *
 * On disk for the reason that motivates the whole design: the import replaces the database
 * underneath itself, so state kept in an option disappears at the swap. Everything needed to
 * resume, roll back, or explain what happened has to survive that instant.
 *
 * In the destination's own storage directory, never in the package. A package is a read-only
 * artifact that may be on a share, a mounted volume, or a directory the web user cannot write
 * to — and state written into it travels with it. Copy such a package and the copy arrives
 * carrying somebody else's finished import, which is read as "already done" and reported as a
 * success that never ran.
 *
 * One file per site rather than one per package, because an import replaces the whole site:
 * two of them running at once is not a thing to support, it is a thing to refuse. The package
 * it belongs to is recorded inside so the refusal can say which.
 */
class ImportCheckpoint extends Checkpoint {

	const SCHEMA = 1;
	const NAME   = 'import-checkpoint.json';
	const DIR    = 'import';

	/**
	 * Constructor.
	 *
	 * @param string $dir Directory to store the checkpoint in. Defaults to this site's own
	 *                    storage directory, which is where it belongs.
	 */
	public function __construct( $dir = '' ) {
		parent::__construct( '' !== $dir ? $dir : self::state_dir() );
	}

	/**
	 * The destination's own state directory.
	 *
	 * @return string
	 */
	public static function state_dir() {
		$dir = \rtrim( \nfd_sm_storage_path(), '/\\' ) . DIRECTORY_SEPARATOR . self::DIR;

		if ( ! \is_dir( $dir ) ) {
			\wp_mkdir_p( $dir );
		}

		$index = $dir . DIRECTORY_SEPARATOR . 'index.php';

		if ( ! \file_exists( $index ) ) {
			\file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	const STAGE_PRECHECK  = 'precheck';
	const STAGE_FILES     = 'files';
	const STAGE_DATABASE  = 'database';
	const STAGE_TRANSFORM = 'transform';
	const STAGE_USERS     = 'users';
	const STAGE_VALIDATE  = 'validate';
	const STAGE_SWAP      = 'swap';
	const STAGE_FIXUPS    = 'fixups';
	const STAGE_DONE      = 'done';

	/**
	 * The stages in the order they run.
	 *
	 * Files before the database, so the destructive step is also the last one: an import that
	 * dies during file restore has changed nothing a user cannot see.
	 *
	 * @return array
	 */
	public static function stages() {
		return array(
			self::STAGE_PRECHECK,
			self::STAGE_FILES,
			self::STAGE_DATABASE,
			self::STAGE_TRANSFORM,
			self::STAGE_USERS,
			self::STAGE_VALIDATE,
			self::STAGE_SWAP,
			self::STAGE_FIXUPS,
		);
	}

	/**
	 * A fresh state.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'schema'        => self::SCHEMA,
			'stage'         => self::STAGE_PRECHECK,
			'started_at'    => \gmdate( 'c' ),

			// Which package this run belongs to, so a second one cannot resume into it.
			'package'       => '',

			// Prefixes are decided once, at precheck, and then never recomputed: the live
			// prefix changes meaning the moment the swap runs.
			'live_prefix'   => '',
			'stage_prefix'  => '',
			'backup_prefix' => '',
			'source_prefix' => '',

			// File restore.
			'part_index'    => 0,
			'entry_index'   => 0,
			'large_index'   => 0,
			'large_offset'  => 0,
			'files_done'    => 0,
			'bytes_done'    => 0,

			// Database load.
			'query_offset'  => 0,
			'statements'    => 0,
			'tables'        => array(),
			'views'         => array(),

			// Search and replace.
			'sr_table'      => 0,
			'sr_offset'     => 0,
			'sr_changed'    => 0,

			// What happened, for the completion report.
			'notes'         => array(),
			'refused'       => array(),
			'manual'        => array(),
			'compatibility' => array(),
			'live_views'    => array(),
			'users'         => array(),
			'swapped'       => false,
			'rolled_back'   => false,
			'error'         => '',
		);
	}
}
