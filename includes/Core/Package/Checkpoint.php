<?php
/**
 * Resumable run state, stored on disk.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Package;

/**
 * Where a run got to, so the next step can continue from it.
 *
 * On disk, never in the database. Two reasons: the import half replaces the database
 * underneath itself, so state kept there disappears mid-run; and the previous design kept it
 * in a single autoloaded option, which loaded the whole blob on every request (finding 3.3).
 *
 * The write order matters. A checkpoint is saved only *after* the state it describes has been
 * flushed to disk, so it always points at or behind reality. Resuming may repeat a little work.
 * It can never skip any.
 */
class Checkpoint {

	const SCHEMA = 3;
	const NAME   = 'checkpoint.json';

	const STAGE_DATABASE = 'database';
	const STAGE_FILES    = 'files';
	const STAGE_FINALIZE = 'finalize';
	const STAGE_DONE     = 'done';

	/**
	 * Absolute path to the checkpoint file.
	 *
	 * @var string
	 */
	protected $path;

	/**
	 * Run state.
	 *
	 * @var array
	 */
	protected $state;

	/**
	 * Constructor.
	 *
	 * @param string $dir Package directory.
	 */
	public function __construct( $dir ) {
		$this->path  = \rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . static::NAME;
		$this->state = static::defaults();
	}

	/**
	 * A fresh state.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'schema'       => self::SCHEMA,
			'stage'        => self::STAGE_DATABASE,
			'part_index'   => 0,
			'list_offset'  => 0,
			'volume'       => 1,
			'volume_bytes' => 0,
			'large_file'   => '',
			'large_offset' => 0,
			'files_done'   => 0,
			'bytes_done'   => 0,
			'parts'        => array(),
			'large'        => array(),
			'large_meta'   => array(),
			'database'     => array(
				'query_offset' => 0,
				'table_index'  => 0,
				'table_offset' => 0,
				'table_rows'   => 0,
			),
		);
	}

	/**
	 * Whether a checkpoint exists on disk.
	 *
	 * @return bool
	 */
	public function exists() {
		return \is_readable( $this->path );
	}

	/**
	 * Read the checkpoint from disk, falling back to a fresh state.
	 *
	 * A checkpoint written by a schema we do not understand is discarded rather than guessed at.
	 *
	 * @return array
	 */
	public function load() {
		if ( ! $this->exists() ) {
			$this->state = static::defaults();

			return $this->state;
		}

		$raw     = \file_get_contents( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$decoded = \json_decode( $raw, true );

		if ( ! \is_array( $decoded ) || ! isset( $decoded['schema'] ) || static::SCHEMA !== (int) $decoded['schema'] ) {
			$this->state = static::defaults();

			return $this->state;
		}

		$this->state = \array_merge( static::defaults(), $decoded );

		return $this->state;
	}

	/**
	 * Write the checkpoint to disk.
	 *
	 * Written through a temporary file and renamed, so a crash mid-write cannot leave a
	 * truncated checkpoint that would be discarded on resume.
	 *
	 * @param array $state Run state.
	 *
	 * @return void
	 */
	public function save( array $state ) {
		$this->state           = $state;
		$this->state['schema'] = static::SCHEMA;

		$temp = $this->path . '.tmp';

		\file_put_contents( $temp, \wp_json_encode( $this->state ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		\rename( $temp, $this->path );
	}

	/**
	 * Remove the checkpoint. Its absence is what marks a run finished.
	 *
	 * @return void
	 */
	public function clear() {
		if ( $this->exists() ) {
			\unlink( $this->path );
		}
	}

	/**
	 * Current in-memory state.
	 *
	 * @return array
	 */
	public function state() {
		return $this->state;
	}
}
