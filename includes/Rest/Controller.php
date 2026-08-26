<?php
/**
 * Shared REST controller behaviour.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Rest;

/**
 * Base for this plugin's controllers.
 */
abstract class Controller extends \WP_REST_Controller {

	/**
	 * The namespace of this controller's routes.
	 *
	 * @var string
	 */
	protected $namespace = 'nfd-site-migrator/v1';

	/**
	 * Only administrators.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permission() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden_context',
				\__( 'Sorry, you are not allowed to access this endpoint.', 'nfd-site-migrator' ),
				array( 'status' => \rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * A 404 shaped exactly like WordPress's own "no such route".
	 *
	 * Used where confirming that an endpoint exists would itself leak something. A 401 would
	 * tell a scanner the plugin is installed and worth probing; this does not.
	 *
	 * @return \WP_Error
	 */
	protected function not_found() {
		return new \WP_Error(
			'rest_no_route',
			\__( 'No route was found matching the URL and request method.', 'nfd-site-migrator' ),
			array( 'status' => 404 )
		);
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

		$this->streamed( $length - \max( 0, $remaining ) );
		exit;
	}

	/**
	 * Note that bytes went out, once they have.
	 *
	 * Nothing here needs it; the direct transfer does, so that the source's screen can show what
	 * the destination has actually taken. It is called after the last write rather than before
	 * the first, so a download the client abandons halfway is not reported as delivered.
	 *
	 * @param int $bytes Bytes written to the client.
	 *
	 * @return void
	 */
	protected function streamed( $bytes ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	}
}
