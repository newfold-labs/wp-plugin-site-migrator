<?php
/**
 * Progress reporter that discards everything.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Progress;

/**
 * Default reporter, so callers never have to null-check.
 */
class NullProgressReporter implements ProgressReporter {

	/**
	 * A stage has begun.
	 *
	 * @param string $stage Machine-readable stage name.
	 * @param int    $total Total units of work.
	 *
	 * @return void
	 */
	public function start( $stage, $total = 0 ) {}

	/**
	 * Report progress within the current stage.
	 *
	 * @param string $stage   Machine-readable stage name.
	 * @param int    $done    Units completed.
	 * @param int    $total   Total units.
	 * @param string $message Human-readable detail.
	 *
	 * @return void
	 */
	public function advance( $stage, $done, $total = 0, $message = '' ) {}

	/**
	 * Something went wrong but the run continues.
	 *
	 * @param string $message Human-readable warning.
	 *
	 * @return void
	 */
	public function warn( $message ) {}

	/**
	 * A stage has completed.
	 *
	 * @param string $stage Machine-readable stage name.
	 *
	 * @return void
	 */
	public function finish( $stage ) {}
}
