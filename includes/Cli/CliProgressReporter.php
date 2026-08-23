<?php
/**
 * Progress on the command line.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Cli;

use NewfoldLabs\WP\SiteMigrator\Core\Progress\ProgressReporter;

/**
 * Turns core progress events into WP-CLI output.
 *
 * All knowledge of how progress is displayed lives here, not in the core.
 */
class CliProgressReporter implements ProgressReporter {

	/**
	 * Seconds between advance lines, so a long stage does not flood the terminal.
	 */
	const THROTTLE = 2.0;

	/**
	 * When the last advance line was printed.
	 *
	 * @var float
	 */
	protected $last = 0;

	/**
	 * A stage has begun.
	 *
	 * @param string $stage Machine-readable stage name.
	 * @param int    $total Total units of work.
	 *
	 * @return void
	 */
	public function start( $stage, $total = 0 ) {
		\WP_CLI::log( \sprintf( '  %s ...', $stage ) );
	}

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
	public function advance( $stage, $done, $total = 0, $message = '' ) {
		$now = \microtime( true );

		if ( $now - $this->last < self::THROTTLE ) {
			return;
		}

		$this->last = $now;

		\WP_CLI::log( \sprintf( '    %s: %d', '' === $message ? $stage : $message, $done ) );
	}

	/**
	 * Something went wrong but the run continues.
	 *
	 * @param string $message Human-readable warning.
	 *
	 * @return void
	 */
	public function warn( $message ) {
		\WP_CLI::warning( $message );
	}

	/**
	 * A stage has completed.
	 *
	 * @param string $stage Machine-readable stage name.
	 *
	 * @return void
	 */
	public function finish( $stage ) {
		$this->last = 0;
	}
}
