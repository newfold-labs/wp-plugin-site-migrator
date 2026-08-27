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
		self::check_storage_reachable( $report );
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
	 * Whether the package directory can be fetched over HTTP.
	 *
	 * A package holds `database.sql` -- every table, password hashes included -- at a predictable
	 * path under `uploads`. It is protected by an `.htaccess` and a `web.config`, which cover
	 * Apache and IIS. **nginx reads neither.** On an nginx host that protection is simply absent
	 * and the dump is a GET away for anyone who guesses the path -- finding 2.4 wearing a
	 * different hat. Hence measuring rather than inferring: asking the server what software it
	 * claims to be is one more thing to be wrong about, when the actual question -- can this be
	 * fetched? -- can just be asked.
	 *
	 * A probe that cannot run **warns rather than blocks**, deliberately breaking this file's
	 * usual rule that a check which could not run is a check that failed. Plenty of hosts refuse
	 * loopback HTTP to themselves, and being unable to reach yourself is not evidence that anyone
	 * else can either. Refusing to export on those hosts would be the wrong answer far more often
	 * than the right one. A fetch that *succeeds* is proof, and that is what blocks.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected static function check_storage_reachable( Report $report ) {
		$uploads = \wp_get_upload_dir();
		$base    = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		$path    = \nfd_sm_storage_path();
		$folder  = \basename( \untrailingslashit( $path ) );

		if ( '' === $base ) {
			$report->warn(
				'storage_reachable',
				'The uploads URL could not be worked out, so the package directory could not be tested.'
			);

			return;
		}

		// The silence file is the safest thing to ask for: it is written whenever the directory
		// is prepared, and gives nothing away if it is served.
		// `nfd_sm_storage_path()` writes the silence file as it creates the directory, so this is
		// present whenever there is anything to protect.
		if ( ! \is_file( \trailingslashit( $path ) . 'index.php' ) ) {
			$report->pass( 'storage_reachable', 'Nothing is stored here yet.' );

			return;
		}

		$probe = \trailingslashit( $base ) . $folder . '/index.php';

		$response = \wp_remote_get(
			$probe,
			array(
				'timeout'     => 10,
				'sslverify'   => true,
				'redirection' => 0,
			)
		);

		if ( \is_wp_error( $response ) ) {
			$report->warn(
				'storage_reachable',
				'This site could not fetch its own uploads directory, so whether a package would be reachable from the web is unknown.',
				array(
					'detail' => 'Many hosts stop a site calling itself, which is all this may mean. If this host runs nginx, '
						. 'check that ' . $probe . ' cannot be downloaded before you export.',
				)
			);

			return;
		}

		$code = (int) \wp_remote_retrieve_response_code( $response );
		$body = (string) \wp_remote_retrieve_body( $response );

		// A 200 on its own is not proof. Some hosts answer every unknown URL with a catch-all
		// landing page, and treating that as an exposed directory would refuse to export on a
		// site that is perfectly safe. What proves it is getting *this* file back: the silence
		// file is either executed, giving an empty body, or served as source, giving its own
		// comment. Anything else is a page the host substituted, which says nothing either way.
		$is_ours = '' === \trim( $body ) || false !== \strpos( $body, 'Silence is golden' );

		if ( 200 === $code && ! $is_ours ) {
			$report->warn(
				'storage_reachable',
				'The uploads directory answered, but with something other than the expected file, so whether a package would be reachable is unclear.',
				array( 'detail' => 'Check that ' . $probe . ' cannot be downloaded before you export.' )
			);

			return;
		}

		if ( 200 === $code ) {
			$report->block(
				'storage_reachable',
				'The package directory can be downloaded over the web, and a package holds this entire site\'s database.',
				array(
					'url' => $probe,
					'fix' => 'This server ignores the .htaccess the plugin writes, which is what nginx does. Deny web access to '
						. $folder . ' under uploads in the server configuration, then check again.',
				)
			);

			return;
		}

		$report->pass( 'storage_reachable', \sprintf( 'The package directory is not served over the web (%d).', $code ) );
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
