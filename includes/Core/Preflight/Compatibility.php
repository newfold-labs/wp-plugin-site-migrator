<?php
/**
 * Compares a source with a destination.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Preflight;

/**
 * Turns two site profiles into gates and warnings.
 *
 * Pure comparison: it takes two profiles and produces a Report. It does not know how either
 * profile was obtained, which is what lets the same code back the live paired check, the
 * pasted-blob fallback, and the destination's authoritative re-check before it writes.
 */
class Compatibility {

	/**
	 * Source profile.
	 *
	 * @var SiteProfile
	 */
	protected $source;

	/**
	 * Destination profile.
	 *
	 * @var SiteProfile
	 */
	protected $destination;

	/**
	 * Constructor.
	 *
	 * @param SiteProfile $source      The site being moved.
	 * @param SiteProfile $destination The site receiving it.
	 */
	public function __construct( SiteProfile $source, SiteProfile $destination ) {
		$this->source      = $source;
		$this->destination = $destination;
	}

	/**
	 * Run every gate.
	 *
	 * @return Report
	 */
	public function check() {
		$report = new Report();

		$this->check_wordpress( $report );
		$this->check_multisite( $report );
		$this->check_php( $report );
		$this->check_collations( $report );
		$this->check_space( $report );
		$this->check_swap( $report );
		$this->check_extensions( $report );
		$this->check_environment( $report );

		return $report;
	}

	/**
	 * WordPress and schema versions.
	 *
	 * Core is not carried in a package, so the destination's own copy has to be able to run the
	 * source's content. WordPress migrates its schema forward on upgrade and has no downgrade
	 * path: a database stamped at a newer db_version than the running core is simply believed.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected function check_wordpress( Report $report ) {
		$src = (string) $this->source->get( 'wp.version', '' );
		$dst = (string) $this->destination->get( 'wp.version', '' );

		if ( '' === $src || '' === $dst ) {
			$report->indeterminate( 'wp_version', 'WordPress version', 'One of the sites did not report its version.' );
		} elseif ( \version_compare( $dst, $src, '<' ) ) {
			$report->block(
				'wp_version',
				\sprintf( 'The destination runs WordPress %s, older than this site\'s %s.', $dst, $src ),
				array(
					'source'      => $src,
					'destination' => $dst,
					'fix'         => 'Update WordPress on the destination, then check again.',
				)
			);
		} else {
			$report->pass( 'wp_version', \sprintf( 'WordPress %s to %s.', $src, $dst ) );
		}

		$src_db = (int) $this->source->get( 'wp.db_version', 0 );
		$dst_db = (int) $this->destination->get( 'wp.db_version', 0 );

		if ( $src_db > 0 && $dst_db > 0 && $dst_db < $src_db ) {
			$report->block(
				'db_version',
				'The destination\'s database schema is older than this site\'s.',
				array(
					'source'      => $src_db,
					'destination' => $dst_db,
					'fix'         => 'Update WordPress on the destination so its schema is at least as new.',
				)
			);
		} elseif ( $src_db > 0 && $dst_db > 0 ) {
			$report->pass( 'db_version', 'Database schema is compatible.' );
		}
	}

	/**
	 * Single site to single site, or multisite to multisite.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected function check_multisite( Report $report ) {
		$src = (bool) $this->source->get( 'wp.is_multisite', false );
		$dst = (bool) $this->destination->get( 'wp.is_multisite', false );

		if ( $src !== $dst ) {
			$report->block(
				'multisite',
				$src
					? 'This is a multisite network and the destination is a single site.'
					: 'The destination is a multisite network and this is a single site.',
				array( 'fix' => 'Multisite migration is not supported yet.' )
			);

			return;
		}

		if ( $src ) {
			$report->block( 'multisite', 'Multisite migration is not supported yet.' );

			return;
		}

		$report->pass( 'multisite', 'Both are single sites.' );
	}

	/**
	 * PHP version.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected function check_php( Report $report ) {
		$src = (string) $this->source->get( 'php.version', '' );
		$dst = (string) $this->destination->get( 'php.version', '' );

		if ( '' === $src || '' === $dst ) {
			$report->indeterminate( 'php_version', 'PHP version', 'One of the sites did not report its PHP version.' );

			return;
		}

		$required = (string) $this->source->get( 'php.requires', '' );

		if ( '' !== $required && \version_compare( $dst, $required, '<' ) ) {
			$report->block(
				'php_version',
				\sprintf( 'The destination runs PHP %s, but a plugin on this site needs %s or newer.', $dst, $required ),
				array( 'fix' => 'Raise the destination\'s PHP version.' )
			);

			return;
		}

		$src_major = \implode( '.', \array_slice( \explode( '.', $src ), 0, 2 ) );
		$dst_major = \implode( '.', \array_slice( \explode( '.', $dst ), 0, 2 ) );

		if ( \version_compare( $dst_major, $src_major, '>' ) ) {
			$report->warn(
				'php_version',
				\sprintf( 'The destination runs PHP %s and this site runs %s.', $dst_major, $src_major ),
				array( 'detail' => 'Older plugin code sometimes breaks on a newer PHP. Check the front page after the move.' )
			);

			return;
		}

		if ( \version_compare( $dst, $src, '<' ) ) {
			$report->warn(
				'php_version',
				\sprintf( 'The destination runs an older PHP (%s) than this site (%s).', $dst, $src ),
				array( 'detail' => 'Code written for the newer version may not run.' )
			);

			return;
		}

		$report->pass( 'php_version', \sprintf( 'PHP %s to %s.', $src, $dst ) );
	}

	/**
	 * Text encoding.
	 *
	 * The commonest hard failure when moving between hosts of different vintage: a collation
	 * the source uses simply does not exist on the destination, and the import dies partway
	 * with "Unknown collation".
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected function check_collations( Report $report ) {
		$needed    = (array) $this->source->get( 'database.collations_used', array() );
		$available = (array) $this->destination->get( 'database.collations', array() );

		if ( empty( $needed ) || empty( $available ) ) {
			$report->warn( 'collation', 'Text encoding could not be compared.', array( 'detail' => 'It will be checked again before anything is written.' ) );

			return;
		}

		$missing = \array_values( \array_diff( $needed, $available ) );

		if ( empty( $missing ) ) {
			$report->pass( 'collation', 'Text encoding is supported on the destination.' );

			return;
		}

		$downgradable = true;

		foreach ( $missing as $collation ) {
			if ( 0 !== \strpos( $collation, 'utf8mb4' ) ) {
				$downgradable = false;
				break;
			}
		}

		if ( ! $downgradable ) {
			$report->block(
				'collation',
				'The destination does not support this site\'s text encoding.',
				array(
					'missing' => $missing,
					'fix'     => 'The destination needs a newer database server.',
				)
			);

			return;
		}

		if ( ! \in_array( 'utf8mb4_unicode_ci', $available, true ) ) {
			$report->block(
				'collation',
				'The destination cannot store four-byte characters such as emoji.',
				array(
					'missing' => $missing,
					'detail'  => 'Converting would silently truncate emoji and much CJK text.',
				)
			);

			return;
		}

		$report->warn(
			'collation',
			'Text encoding will be adjusted for the destination.',
			array(
				'missing' => $missing,
				'detail'  => 'An equivalent encoding is available, so nothing is lost.',
			)
		);
	}

	/**
	 * Free space, counting the rollback copy of the database.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected function check_space( Report $report ) {
		$free   = $this->destination->get( 'host.free_bytes', null );
		$needed = (int) $this->source->get( 'estimate.total_bytes', 0 );

		if ( null === $free ) {
			$report->warn( 'disk_space', 'Free space on the destination could not be measured.' );

			return;
		}

		if ( $needed <= 0 ) {
			$report->pass( 'disk_space', \sprintf( '%s free on the destination.', \size_format( $free ) ) );

			return;
		}

		// Package, plus the unpacked copy, plus a second copy of the database kept for rollback.
		$required = (int) ( $needed * 2 ) + (int) $this->source->get( 'estimate.database_bytes', 0 );

		// A partial measurement is a floor, not a total, so only one direction is conclusive.
		// Free space below the floor definitely will not fit and blocks. Free space above it
		// proves nothing, so it warns rather than passing — refusing outright would reject a
		// migration that may be perfectly fine, and running out of room mid-export is a clean,
		// resumable stop rather than damage.
		if ( ! $this->source->get( 'estimate.complete', true ) && $free >= $required ) {
			$report->warn(
				'disk_space',
				\sprintf( 'The destination has %s free; this site needs at least %s.', \size_format( $free ), \size_format( $required ) ),
				array( 'detail' => 'This site was too large to measure fully, so that is a minimum.' )
			);

			return;
		}

		if ( $free < $required ) {
			$report->block(
				'disk_space',
				\sprintf( 'The destination needs about %s free and has %s.', \size_format( $required ), \size_format( $free ) ),
				array( 'fix' => 'Free up space on the destination, then check again.' )
			);

			return;
		}

		$report->pass( 'disk_space', \sprintf( '%s free, about %s needed.', \size_format( $free ), \size_format( $required ) ) );
	}

	/**
	 * Whether the destination can do the instant switchover.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected function check_swap( Report $report ) {
		$can = $this->destination->get( 'database.can_rename', null );

		if ( true === $can ) {
			$report->pass( 'atomic_swap', 'The destination supports an instant, reversible switchover.' );

			return;
		}

		if ( null === $can ) {
			$report->warn( 'atomic_swap', 'Could not tell whether the destination supports an instant switchover.' );

			return;
		}

		$report->warn(
			'atomic_swap',
			'The destination cannot rename tables, so the switchover will not be instant.',
			array( 'detail' => 'A full backup is taken first instead, and the site is briefly inconsistent during the import.' )
		);
	}

	/**
	 * PHP extensions the source has and the destination does not.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected function check_extensions( Report $report ) {
		$src = (array) $this->source->get( 'php.extensions', array() );
		$dst = (array) $this->destination->get( 'php.extensions', array() );

		if ( empty( $src ) || empty( $dst ) ) {
			return;
		}

		if ( ! \in_array( 'zip', $dst, true ) ) {
			$report->block(
				'zip',
				'The destination does not have PHP\'s zip extension, which is needed to unpack the site.',
				array( 'fix' => 'Ask the host to enable the zip extension.' )
			);
		}

		$missing = \array_values( \array_diff( $src, $dst ) );
		$missing = \array_diff( $missing, array( 'zip' ) );

		if ( ! empty( $missing ) ) {
			$report->warn(
				'php_extensions',
				\sprintf( 'The destination is missing: %s.', \implode( ', ', $missing ) ),
				array( 'missing' => \array_values( $missing ) )
			);
		}
	}

	/**
	 * Things that describe the environment rather than the site.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected function check_environment( Report $report ) {
		$src_prefix = (string) $this->source->get( 'wp.prefix', '' );
		$dst_prefix = (string) $this->destination->get( 'wp.prefix', '' );

		if ( '' !== $src_prefix && '' !== $dst_prefix && $src_prefix !== $dst_prefix ) {
			$report->warn(
				'table_prefix',
				\sprintf( 'Table prefix differs: %s here, %s there.', $src_prefix, $dst_prefix ),
				array( 'detail' => 'Handled automatically. Mentioned because it shows up in support questions.' )
			);
		}

		$src_soft = (string) $this->source->get( 'host.software', '' );
		$dst_soft = (string) $this->destination->get( 'host.software', '' );

		if ( '' !== $src_soft && '' !== $dst_soft && \strtok( $src_soft, '/' ) !== \strtok( $dst_soft, '/' ) ) {
			$report->warn(
				'server_software',
				\sprintf( 'Different web server: %s here, %s there.', \strtok( $src_soft, '/' ), \strtok( $dst_soft, '/' ) ),
				array( 'detail' => 'Rewrite rules may need re-creating by hand after the move.' )
			);
		}

		if ( ! (bool) $this->destination->get( 'wp.content_std', true ) ) {
			$report->warn( 'content_dir', 'The destination keeps wp-content in a non-standard place.' );
		}
	}
}
