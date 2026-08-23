<?php
/**
 * Progress reporting contract.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Progress;

/**
 * How the core reports progress without knowing who is listening.
 *
 * Implementations must not assume a transport. The core calls these; the REST adapter turns
 * them into a JSON payload, the CLI adapter into a progress bar.
 */
interface ProgressReporter {

	/**
	 * A stage has begun.
	 *
	 * @param string $stage Machine-readable stage name.
	 * @param int    $total Total units of work, or 0 when not yet known.
	 *
	 * @return void
	 */
	public function start( $stage, $total = 0 );

	/**
	 * Report progress within the current stage.
	 *
	 * @param string $stage   Machine-readable stage name.
	 * @param int    $done    Units completed.
	 * @param int    $total   Total units, or 0 when not yet known.
	 * @param string $message Human-readable detail.
	 *
	 * @return void
	 */
	public function advance( $stage, $done, $total = 0, $message = '' );

	/**
	 * Something went wrong but the run continues.
	 *
	 * @param string $message Human-readable warning.
	 *
	 * @return void
	 */
	public function warn( $message );

	/**
	 * A stage has completed.
	 *
	 * @param string $stage Machine-readable stage name.
	 *
	 * @return void
	 */
	public function finish( $stage );
}
