<?php
/**
 * Export routes.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Rest;

use NewfoldLabs\WP\SiteMigrator\Core\Export\Exporter;
use NewfoldLabs\WP\SiteMigrator\Core\Package\Manifest;
use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageReader;

/**
 * The browser drives the export through these.
 *
 * There is no scheduler. The old design queued work on wp-cron and had the UI poll a status
 * option, which meant the progress bar reported on a queue that could stall silently. Here the
 * browser calls `step` in a loop: the thing reporting progress is the thing doing the work, so
 * it cannot lie about it.
 */
class ExportController extends Controller {

	/**
	 * Seconds of work per step request.
	 */
	const BUDGET = 12;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		\register_rest_route(
			$this->namespace,
			'/export/step',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'step' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/export/state',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'state' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/export/pause',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pause' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'paused' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/export/manifest',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'manifest' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/export/verify',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'verify' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/export/cancel',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'cancel' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/export/download',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'download' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'file' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Where this site's package lives.
	 *
	 * @return string
	 */
	protected function package_dir() {
		return \nfd_sm_storage_path() . 'package';
	}

	/**
	 * Advance the export.
	 *
	 * @return \WP_REST_Response
	 */
	public function step() {
		$exporter = new Exporter( $this->package_dir() );

		try {
			$state = $exporter->step( self::BUDGET );
		} catch ( \Exception $e ) {
			return \rest_ensure_response(
				array(
					'done'  => false,
					'error' => $e->getMessage(),
				)
			);
		}

		return \rest_ensure_response( $state );
	}

	/**
	 * What the export is doing, so a reloaded tab can pick it back up.
	 *
	 * @return \WP_REST_Response
	 */
	public function state() {
		$dir      = $this->package_dir();
		$exporter = new Exporter( $dir );
		$reader   = new PackageReader( $dir );

		return \rest_ensure_response(
			\array_merge(
				$exporter->snapshot(),
				array(
					'exists'      => \is_dir( $dir ),
					'in_progress' => $exporter->is_resumable(),
					'complete'    => $reader->is_complete(),
					'paused'      => (bool) \get_option( NFD_SM_PAUSED_OPTION, false ),
				)
			)
		);
	}

	/**
	 * Record that a human stopped the export, or started it again.
	 *
	 * Pausing is an instruction, not a state of the browser tab, so it outlives the tab. A
	 * reloaded page that resumed on its own would be overruling the last thing the user
	 * actually said, and on a site where each step is a minute of disk that is not a small
	 * thing to get wrong.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function pause( $request ) {
		$paused = (bool) $request->get_param( 'paused' );

		if ( $paused ) {
			\update_option( NFD_SM_PAUSED_OPTION, 1, false );
		} else {
			\delete_option( NFD_SM_PAUSED_OPTION );
		}

		return \rest_ensure_response( array( 'paused' => $paused ) );
	}

	/**
	 * The finished package's manifest and part list.
	 *
	 * @return \WP_REST_Response
	 */
	public function manifest() {
		$reader = new PackageReader( $this->package_dir() );

		if ( ! $reader->is_complete() ) {
			return \rest_ensure_response(
				array(
					'complete' => false,
					'error'    => 'No finished package yet.',
				)
			);
		}

		return \rest_ensure_response(
			array(
				'complete' => true,
				'package'  => $reader->inspect(),
			)
		);
	}

	/**
	 * Re-hash the package and compare it against its own manifest.
	 *
	 * Its own route rather than part of `manifest`, because it reads every byte of the package
	 * — for a large site, tens of seconds — and the file list it was holding up needs none of
	 * that. Now the list is on screen immediately and this answers alongside it.
	 *
	 * Worth doing at all because the checksums were taken when each volume was closed, and
	 * everything that can happen to a file afterwards happens quietly.
	 *
	 * @return \WP_REST_Response
	 */
	public function verify() {
		$reader = new PackageReader( $this->package_dir() );

		if ( ! $reader->is_complete() ) {
			return \rest_ensure_response(
				array(
					'complete' => false,
					'error'    => 'No finished package yet.',
				)
			);
		}

		$problems = $reader->verify();

		return \rest_ensure_response(
			array(
				'complete' => true,
				'verified' => empty( $problems ),
				'problems' => $problems,
			)
		);
	}

	/**
	 * Throw the package away.
	 *
	 * @return \WP_REST_Response
	 */
	public function cancel() {
		\nfd_sm_delete_directory( $this->package_dir() );
		\delete_option( NFD_SM_PAUSED_OPTION );

		return \rest_ensure_response( array( 'cancelled' => true ) );
	}

	/**
	 * Stream one file out of the package.
	 *
	 * Never served as a static file. The package holds the whole database, so it is behind an
	 * authenticated endpoint, the storage directory denies web access outright, and the
	 * requested path is resolved and checked to be inside the package before anything is read
	 * — otherwise `file=../../../wp-config.php` would be a working request.
	 *
	 * Range requests are honoured so an interrupted download resumes instead of restarting.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_Error|void
	 */
	public function download( $request ) {
		$dir  = \realpath( $this->package_dir() );
		$file = (string) $request->get_param( 'file' );

		if ( false === $dir ) {
			return $this->not_found();
		}

		$path = \realpath( $dir . DIRECTORY_SEPARATOR . \ltrim( $file, '/\\' ) );

		if ( false === $path || 0 !== \strpos( $path, $dir . DIRECTORY_SEPARATOR ) || ! \is_file( $path ) ) {
			return $this->not_found();
		}

		$this->stream( $path, $request->get_header( 'range' ) );
	}

	/**
	 * Work out which bytes to send.
	 *
	 * Kept separate from the sending so the arithmetic can be tested without a response: an
	 * off-by-one here corrupts a resumed download in a way that only shows up as a broken zip
	 * much later.
	 *
	 * @param int    $size  File size in bytes.
	 * @param string $range Raw Range header, if any.
	 *
	 * @return array `start`, `end` and `status` — 200, 206, or 416 when unsatisfiable.
	 */
	public static function resolve_range( $size, $range ) {
		$whole = array(
			'start'  => 0,
			'end'    => $size - 1,
			'status' => 200,
		);

		if ( ! $range || ! \preg_match( '/bytes=(\d*)-(\d*)/', (string) $range, $m ) ) {
			return $whole;
		}

		// A suffix range, `bytes=-500`, means the last 500 bytes rather than a start of 0.
		if ( '' === $m[1] ) {
			if ( '' === $m[2] ) {
				return $whole;
			}

			$length = (int) $m[2];

			if ( $length <= 0 ) {
				return array(
					'start'  => 0,
					'end'    => 0,
					'status' => 416,
				);
			}

			$start = \max( 0, $size - $length );

			return array(
				'start'  => $start,
				'end'    => $size - 1,
				'status' => 206,
			);
		}

		$start = (int) $m[1];
		$end   = '' === $m[2] ? $size - 1 : (int) $m[2];

		if ( $start > $end || $start >= $size ) {
			return array(
				'start'  => 0,
				'end'    => 0,
				'status' => 416,
			);
		}

		return array(
			'start'  => $start,
			'end'    => \min( $end, $size - 1 ),
			'status' => 206,
		);
	}

	/**
	 * Send a file, honouring a byte range.
	 *
	 * @param string $path  Absolute path, already validated.
	 * @param string $range Raw Range header, if any.
	 *
	 * @return void
	 */
	protected function stream( $path, $range ) {
		$size     = (int) \filesize( $path );
		$resolved = self::resolve_range( $size, $range );

		if ( 416 === $resolved['status'] ) {
			\status_header( 416 );
			\header( 'Content-Range: bytes */' . $size );
			exit;
		}

		$start  = $resolved['start'];
		$end    = $resolved['end'];
		$status = $resolved['status'];
		$length = ( $end - $start ) + 1;

		\status_header( $status );
		\header( 'Content-Type: application/octet-stream' );
		\header( 'Content-Disposition: attachment; filename="' . \basename( $path ) . '"' );
		\header( 'Content-Length: ' . $length );
		\header( 'Accept-Ranges: bytes' );
		\header( 'X-Content-Type-Options: nosniff' );

		if ( 206 === $status ) {
			\header( \sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
		}

		while ( \ob_get_level() ) {
			\ob_end_clean();
		}

		$handle = \fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $handle ) {
			exit;
		}

		\fseek( $handle, $start );
		$remaining = $length;

		while ( $remaining > 0 && ! \feof( $handle ) ) {
			$chunk = \fread( $handle, (int) \min( 1048576, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput
			\flush();
			$remaining -= \strlen( $chunk );
		}

		\fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
