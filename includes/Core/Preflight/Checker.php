<?php
/**
 * Local checks on this install.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Preflight;

/**
 * Can this site export at all?
 *
 * Distinct from Compatibility, which compares two sites. These are the questions answerable
 * without knowing where the site is going, and they fail closed: a check that cannot run
 * blocks rather than passing.
 */
class Checker {

	/**
	 * Run every local check.
	 *
	 * @return Report
	 */
	public static function run() {
		$report = new Report();

		self::check_zip( $report );
		self::check_storage( $report );
		self::check_space( $report );
		self::check_database( $report );
		self::check_multisite( $report );
		self::check_limits( $report );

		return $report;
	}

	/**
	 * ZipArchive is how parts are written.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected static function check_zip( Report $report ) {
		if ( \class_exists( 'ZipArchive' ) ) {
			$report->pass( 'zip', 'PHP can create zip archives.' );

			return;
		}

		$report->block(
			'zip',
			'PHP\'s zip extension is not available, so a package cannot be built.',
			array( 'fix' => 'Ask your host to enable the zip extension.' )
		);
	}

	/**
	 * Somewhere to write the package.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected static function check_storage( Report $report ) {
		$path = \nfd_sm_storage_path();

		if ( ! \is_dir( $path ) ) {
			$report->block( 'storage', 'The storage directory could not be created.', array( 'path' => $path ) );

			return;
		}

		$probe  = $path . 'write-probe-' . \wp_generate_password( 8, false );
		$wrote  = @\file_put_contents( $probe, 'probe' ); // phpcs:ignore
		$exists = \is_readable( $probe );

		if ( $exists ) {
			@\unlink( $probe ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		if ( false === $wrote || ! $exists ) {
			$report->block(
				'storage',
				'The storage directory is not writable.',
				array(
					'path' => $path,
					'fix'  => 'Check the permissions on wp-content/uploads.',
				)
			);

			return;
		}

		$report->pass( 'storage', 'The storage directory is writable.' );
	}

	/**
	 * Enough room to build a package.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected static function check_space( Report $report ) {
		if ( ! \function_exists( 'disk_free_space' ) ) {
			$report->indeterminate(
				'disk_space',
				'Free space',
				'disk_free_space() is disabled on this host, so free space cannot be measured.'
			);

			return;
		}

		$free = @\disk_free_space( \nfd_sm_storage_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $free ) {
			$report->indeterminate( 'disk_space', 'Free space', 'Free space could not be read.' );

			return;
		}

		$profile  = SiteProfile::gather( true );
		$estimate = (int) $profile->get( 'estimate.total_bytes', 0 );

		if ( ! $profile->get( 'estimate.complete', true ) && $free >= $estimate ) {
			$report->warn(
				'disk_space',
				\sprintf( 'At least %s is needed and %s is free.', \size_format( $estimate ), \size_format( $free ) ),
				array( 'detail' => 'This site is large enough that it was measured only partially, so the figure is a minimum.' )
			);

			return;
		}

		if ( $estimate > 0 && $free < $estimate ) {
			$report->block(
				'disk_space',
				\sprintf( 'About %s is needed to build the package and %s is free.', \size_format( $estimate ), \size_format( $free ) ),
				array( 'fix' => 'Free up space, then check again.' )
			);

			return;
		}

		$report->pass( 'disk_space', \sprintf( '%s free.', \size_format( $free ) ) );
	}

	/**
	 * The database can be read.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected static function check_database( Report $report ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! \extension_loaded( 'mysqli' ) ) {
			$report->block( 'database', 'The database cannot be read through mysqli.' );

			return;
		}

		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! \is_array( $tables ) || empty( $tables ) ) {
			$report->indeterminate( 'database', 'Database', 'No tables were returned, which should not happen.' );

			return;
		}

		$report->pass( 'database', \sprintf( '%d tables found.', \count( $tables ) ) );
	}

	/**
	 * Multisite is out of scope for now.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected static function check_multisite( Report $report ) {
		if ( \is_multisite() ) {
			$report->block(
				'multisite',
				'This is a multisite network, which is not supported yet.',
				array( 'detail' => 'Only single sites can be exported at the moment.' )
			);

			return;
		}

		$report->pass( 'multisite', 'Single site.' );
	}

	/**
	 * Host limits worth knowing about, none of them fatal.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected static function check_limits( Report $report ) {
		$max = (int) \ini_get( 'max_execution_time' );

		if ( $max > 0 && $max < 10 ) {
			$report->warn(
				'execution_time',
				\sprintf( 'This host stops PHP after %d seconds.', $max ),
				array( 'detail' => 'The export works in smaller pieces, so it will simply take more of them.' )
			);
		} else {
			$report->pass( 'execution_time', 0 === $max ? 'No execution time limit.' : \sprintf( '%d second execution limit.', $max ) );
		}

		$memory = SiteProfile::bytes( \ini_get( 'memory_limit' ) );

		if ( $memory > 0 && $memory < 134217728 ) {
			$report->warn( 'memory', \sprintf( 'PHP memory limit is %s.', \size_format( $memory ) ) );
		}
	}
}
