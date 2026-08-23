<?php
/**
 * Structured preflight result.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Preflight;

/**
 * The outcome of a set of checks.
 *
 * Replaces a boolean smuggled through a filter chain. The old design returned true/false and
 * kept diagnostics in a static array, so a check that could not run was indistinguishable from
 * a check that passed (findings 2.6, 4.6). Here every check reports one of three outcomes and
 * **a check that could not complete counts as blocking**, not as a pass.
 */
class Report {

	const PASS  = 'pass';
	const WARN  = 'warn';
	const BLOCK = 'block';

	/**
	 * Recorded checks, keyed by id.
	 *
	 * @var array
	 */
	protected $checks = array();

	/**
	 * Record a check result.
	 *
	 * @param string $id      Machine-readable check id.
	 * @param string $status  One of pass, warn, block.
	 * @param string $label   Human-readable summary.
	 * @param array  $context Extra detail for the UI.
	 *
	 * @return Report
	 */
	public function add( $id, $status, $label, array $context = array() ) {
		$this->checks[ $id ] = array(
			'id'      => $id,
			'status'  => $status,
			'label'   => $label,
			'context' => $context,
		);

		return $this;
	}

	/**
	 * Record a passing check.
	 *
	 * @param string $id      Check id.
	 * @param string $label   Summary.
	 * @param array  $context Extra detail.
	 *
	 * @return Report
	 */
	public function pass( $id, $label, array $context = array() ) {
		return $this->add( $id, self::PASS, $label, $context );
	}

	/**
	 * Record a warning.
	 *
	 * @param string $id      Check id.
	 * @param string $label   Summary.
	 * @param array  $context Extra detail.
	 *
	 * @return Report
	 */
	public function warn( $id, $label, array $context = array() ) {
		return $this->add( $id, self::WARN, $label, $context );
	}

	/**
	 * Record a blocking failure.
	 *
	 * @param string $id      Check id.
	 * @param string $label   Summary.
	 * @param array  $context Extra detail.
	 *
	 * @return Report
	 */
	public function block( $id, $label, array $context = array() ) {
		return $this->add( $id, self::BLOCK, $label, $context );
	}

	/**
	 * Record a check that could not be completed.
	 *
	 * This is the whole point of the class. An indeterminate result is treated as blocking,
	 * because the alternative — assuming success — is what let a dead backend read as a
	 * successful compatibility check until the migration had already been attempted.
	 *
	 * @param string $id     Check id.
	 * @param string $label  Summary.
	 * @param string $reason Why the check could not run.
	 *
	 * @return Report
	 */
	public function indeterminate( $id, $label, $reason ) {
		return $this->add(
			$id,
			self::BLOCK,
			$label,
			array(
				'indeterminate' => true,
				'reason'        => $reason,
			)
		);
	}

	/**
	 * Whether anything blocks.
	 *
	 * @return bool
	 */
	public function is_blocked() {
		foreach ( $this->checks as $check ) {
			if ( self::BLOCK === $check['status'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks with the given status.
	 *
	 * @param string $status One of pass, warn, block.
	 *
	 * @return array
	 */
	public function with_status( $status ) {
		$out = array();

		foreach ( $this->checks as $check ) {
			if ( $status === $check['status'] ) {
				$out[] = $check;
			}
		}

		return $out;
	}

	/**
	 * One check by id.
	 *
	 * @param string $id Check id.
	 *
	 * @return array|null
	 */
	public function get( $id ) {
		return isset( $this->checks[ $id ] ) ? $this->checks[ $id ] : null;
	}

	/**
	 * The whole report, ready for JSON.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'ok'       => ! $this->is_blocked(),
			'blocking' => $this->with_status( self::BLOCK ),
			'warnings' => $this->with_status( self::WARN ),
			'passed'   => $this->with_status( self::PASS ),
		);
	}
}
