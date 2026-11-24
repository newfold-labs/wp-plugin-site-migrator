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
	 * How parts will be written: `ZipArchive`, or zlib where the extension is missing.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected static function check_zip( Report $report ) {
		$backend = \NewfoldLabs\WP\SiteMigrator\Core\Package\ZipWriter::backend();

		if ( 'zip' === $backend ) {
			$report->pass( 'zip', 'PHP can create zip archives.' );

			return;
		}

		// A warning rather than a pass. The zlib writer produces the same archives and every
		// volume is read back before it counts, but it is the younger of the two paths, and the
		// person exporting should know which one their package came out of.
		if ( 'zlib' === $backend ) {
			$report->warn(
				'zip',
				'PHP\'s zip extension is not available, so the package will be built with zlib instead.',
				array( 'detail' => 'Every volume is read back and checked as it is written. Enabling the zip extension makes this the usual path again.' )
			);

			return;
		}

		$report->block(
			'zip',
			'PHP has neither the zip extension nor zlib, so a package cannot be built here.',
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

		if ( ! Storage::is_writable( $path ) ) {
			// Somebody with only wp-admin cannot fix a permission, so the block offers somewhere
			// else instead: whichever alternates could actually be created right now.
			$options = Storage::options();
			$fix     = 'Check the permissions on wp-content/uploads.';

			if ( $options ) {
				$fix = 'Ask your host to make wp-content/uploads writable, or keep the migration files in one of the places below.';
			}

			$report->block(
				'storage',
				\is_dir( $path ) ? 'The storage directory is not writable.' : 'The storage directory could not be created.',
				array(
					'path'         => $path,
					'fix'          => $fix,
					'alternatives' => $options,
				)
			);

			return;
		}

		if ( Storage::TEMP === Storage::kind( $path ) ) {
			$report->warn(
				'storage',
				'Migration files are kept in the server\'s temporary directory.',
				array(
					'path'   => $path,
					'detail' => 'Hosts clear this from time to time. Send or download the package soon after it is built.',
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
	 * Storage moved out of uploads (`Storage`) has a URL only when it sits inside a folder this
	 * site maps to one. Otherwise there is no address to ask, and the answer is a pass: a web
	 * server cannot serve what is outside the folders it serves.
	 *
	 * @param Report $report Report to add to.
	 *
	 * @return void
	 */
	protected static function check_storage_reachable( Report $report ) {
		$path = \nfd_sm_storage_path();
		$base = Storage::url( $path );

		if ( null === $base ) {
			$report->warn(
				'storage_reachable',
				'The uploads URL could not be worked out, so the package directory could not be tested.'
			);

			return;
		}

		if ( '' === $base ) {
			$report->pass( 'storage_reachable', 'Migration files are kept outside the folders this site serves.' );

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

		$probe = \trailingslashit( $base ) . 'index.php';

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
				'This site could not fetch its own storage directory over the web, so whether a package would be reachable from the web is unknown.',
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
				'The storage directory answered, but with something other than the expected file, so whether a package would be reachable is unclear.',
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
						. \untrailingslashit( $path ) . ' in the server configuration, then check again.',
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
