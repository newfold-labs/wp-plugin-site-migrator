<?php
/**
 * Import routes.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Rest;

use NewfoldLabs\WP\SiteMigrator\Core\Import\ImportToken;
use NewfoldLabs\WP\SiteMigrator\Core\Import\Importer;
use NewfoldLabs\WP\SiteMigrator\Core\Import\Loader;
use NewfoldLabs\WP\SiteMigrator\Core\Import\Upload;
use NewfoldLabs\WP\SiteMigrator\Core\Import\UserMerger;

/**
 * The browser drives the import through these, the same way it drives the export.
 *
 * One difference matters. Halfway through, the users table is replaced, and with it every
 * session WordPress knows about. From that instant the cookie behind these requests may or may
 * not still authenticate. So the step endpoints accept an import token *as well as* the cookie,
 * minted before the first write and held in a file that the swap cannot touch.
 */
class ImportController extends Controller {

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
		// Core registers `rest_cookie_check_errors` on this filter at priority 100, so anything
		// meaning to overrule it has to run after that — at 99 this saw a null result, did
		// nothing, and the error was raised immediately afterwards.
		\add_filter( 'rest_authentication_errors', array( $this, 'allow_token_past_nonce' ), 200 );

		$admin = array( $this, 'check_permission' );
		$run   = array( $this, 'check_import_permission' );

		$routes = array(
			'/import/sources'       => array( \WP_REST_Server::READABLE, 'sources', $admin ),
			'/import/upload/state'  => array( \WP_REST_Server::CREATABLE, 'upload_state', $admin ),
			'/import/upload/chunk'  => array( \WP_REST_Server::CREATABLE, 'upload_chunk', $admin ),
			'/import/upload/verify' => array( \WP_REST_Server::CREATABLE, 'upload_verify', $admin ),
			'/import/upload/reset'  => array( \WP_REST_Server::CREATABLE, 'upload_reset', $admin ),
			'/import/preview'       => array( \WP_REST_Server::CREATABLE, 'preview', $admin ),
			'/import/start'         => array( \WP_REST_Server::CREATABLE, 'start', $admin ),
			'/import/step'          => array( \WP_REST_Server::CREATABLE, 'step', $run ),
			'/import/state'         => array( \WP_REST_Server::READABLE, 'state', $run ),
			'/import/rollback'      => array( \WP_REST_Server::CREATABLE, 'rollback', $run ),
			'/import/confirm'       => array( \WP_REST_Server::CREATABLE, 'confirm', $run ),
			'/import/cancel'        => array( \WP_REST_Server::CREATABLE, 'cancel', $run ),
		);

		foreach ( $routes as $path => $route ) {
			list( $methods, $callback, $permission ) = $route;

			\register_rest_route(
				$this->namespace,
				$path,
				array(
					array(
						'methods'             => $methods,
						'callback'            => array( $this, $callback ),
						'permission_callback' => $permission,
					),
				)
			);
		}
	}

	/**
	 * Let a token-bearing request through WordPress's cookie nonce check.
	 *
	 * The nonce is derived from the acting user and their session. The swap replaces the users
	 * table, so from that instant the nonce the browser is holding no longer validates and
	 * WordPress rejects the request in `rest_cookie_check_errors()` — which runs *before* any
	 * permission callback, so the import token never gets a chance to speak. The import then
	 * stalls one stage after the point of no return, which is the worst place for it to stop.
	 *
	 * Clearing the error costs nothing in safety: it requires presenting a 64-character secret
	 * that was minted for this one import and handed only to the browser that started it. That
	 * is a stronger claim than the nonce it is standing in for, not a weaker one.
	 *
	 * @param \WP_Error|null|true $errors Current authentication result.
	 *
	 * @return \WP_Error|null|true
	 */
	public function allow_token_past_nonce( $errors ) {
		if ( ! \is_wp_error( $errors ) || 'rest_cookie_invalid_nonce' !== $errors->get_error_code() ) {
			return $errors;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		$header = isset( $_SERVER['HTTP_X_NFD_SM_IMPORT'] ) ? \wp_unslash( $_SERVER['HTTP_X_NFD_SM_IMPORT'] ) : '';
		$token  = \sanitize_text_field( $header );

		if ( '' === $token || ImportToken::verify( $token ) < 1 ) {
			return $errors;
		}

		return true;
	}

	/**
	 * Allow either an administrator's cookie or a live import token.
	 *
	 * The token is not a way around the capability check; it is a way of surviving the moment
	 * the capability check stops working through no fault of the user's. It is minted only by
	 * an administrator, only for one import, and only for these endpoints.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_import_permission( $request ) {
		if ( \current_user_can( 'manage_options' ) ) {
			return true;
		}

		$user_id = ImportToken::verify( (string) $request->get_header( ImportToken::HEADER ) );

		if ( $user_id > 0 ) {
			// Restored so anything downstream that asks who is acting gets an answer. The
			// capability itself is not granted — the token is the authority here.
			\wp_set_current_user( $user_id );

			return true;
		}

		return new \WP_Error(
			'rest_forbidden_context',
			\__( 'Sorry, you are not allowed to access this endpoint.', 'nfd-site-migrator' ),
			array( 'status' => \rest_authorization_required_code() )
		);
	}

	/**
	 * Packages this site can import from.
	 *
	 * @return \WP_REST_Response
	 */
	public function sources() {
		return \rest_ensure_response(
			array(
				'uploaded'   => Upload::dir(),
				'chunk_size' => Upload::chunk_size(),
				'discovered' => Upload::discover(),
			)
		);
	}

	/**
	 * How much of each file has already arrived.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function upload_state( $request ) {
		$files = (array) $request->get_param( 'files' );

		return \rest_ensure_response(
			array(
				'chunk_size' => Upload::chunk_size(),
				'received'   => Upload::received( \array_map( 'strval', $files ) ),
			)
		);
	}

	/**
	 * Receive one chunk.
	 *
	 * The chunk travels as the raw request body rather than as a multipart field: multipart is
	 * governed by `upload_max_filesize`, which on the hosts this exists for is the smaller of
	 * the two limits, and it adds encoding overhead to bytes that are already binary.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload_chunk( $request ) {
		$relative = (string) $request->get_param( 'path' );
		$offset   = (int) $request->get_param( 'offset' );
		$body     = $request->get_body();

		if ( '' === $relative ) {
			return new \WP_Error( 'nfd_sm_no_path', 'No path was given for this chunk.', array( 'status' => 400 ) );
		}

		if ( '' === $body ) {
			return new \WP_Error(
				'nfd_sm_empty_chunk',
				'That chunk arrived empty. The server may have refused its size.',
				array( 'status' => 400 )
			);
		}

		try {
			$total = Upload::chunk( $relative, $offset, $body );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'nfd_sm_chunk_failed', $e->getMessage(), array( 'status' => 400 ) );
		}

		return \rest_ensure_response(
			array(
				'path'     => $relative,
				'received' => $total,
			)
		);
	}

	/**
	 * Check the assembled upload against the manifest it carries.
	 *
	 * @return \WP_REST_Response
	 */
	public function upload_verify() {
		$problems = Upload::verify();

		return \rest_ensure_response(
			array(
				'ok'       => empty( $problems ),
				'problems' => $problems,
			)
		);
	}

	/**
	 * Throw away a part-finished upload.
	 *
	 * @return \WP_REST_Response
	 */
	public function upload_reset() {
		Upload::reset();

		return \rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Describe what an import would do, without doing any of it.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( $request ) {
		$importer = $this->importer( $request );

		try {
			return \rest_ensure_response( $importer->preview() );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'nfd_sm_preview_failed', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Claim the import window and hand the browser its token.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function start( $request ) {
		$importer = $this->importer( $request );

		if ( $request->get_param( 'restart' ) ) {
			$importer->restart();
		}

		$claim = $importer->begin( \get_current_user_id() );

		return \rest_ensure_response(
			array(
				'ok'     => true,
				'token'  => $claim['token'],
				'loader' => $claim['loader'],
				'dir'    => $this->dir( $request ),
			)
		);
	}

	/**
	 * Advance the import.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function step( $request ) {
		$importer = $this->importer( $request );

		// A step runs arbitrary WordPress hooks — the swap fires `update_option`, the fixups run
		// core's own upgrade routine, and any plugin still loaded can be listening. One `echo`
		// or one displayed notice anywhere in there lands in front of the JSON, and the browser
		// reports "the response is not a valid JSON response" while the import is halfway past
		// the point of no return. Catch the output instead, and say what it was.
		\ob_start();

		try {
			$state = $importer->step( self::BUDGET );
		} catch ( \Exception $e ) {
			\ob_end_clean();

			return \rest_ensure_response(
				array(
					'done'  => false,
					'error' => $e->getMessage(),
				)
			);
		}

		$stray = \trim( (string) \ob_get_clean() );

		if ( '' !== $stray ) {
			$state['notes'][] = \sprintf(
				'Something on this site printed output during the %s step, which was discarded: %s',
				$state['stage'],
				\substr( \wp_strip_all_tags( $stray ), 0, 500 )
			);
		}

		$state['nonce'] = \wp_create_nonce( 'wp_rest' );

		return \rest_ensure_response( $state );
	}

	/**
	 * Where the import got to.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function state( $request ) {
		$importer = $this->importer( $request );
		$state    = $importer->state();

		return \rest_ensure_response(
			array(
				'stage'       => $state['stage'],
				'package'     => $state['package'],
				'in_progress' => '' !== $state['package'] && 'done' !== $state['stage'],
				'complete'    => 'done' === $state['stage'],
				'swapped'     => ! empty( $state['swapped'] ),
				'rolled_back' => ! empty( $state['rolled_back'] ),
				'files'       => (int) $state['files_done'],
				'rows'        => (int) $state['statements'],
				'notes'       => (array) $state['notes'],
				'manual'      => (array) $state['manual'],
				'users'       => (array) $state['users'],
				'refused'     => (array) $state['refused'],
				'error'       => (string) $state['error'],
				'token_live'  => ImportToken::exists(),
				'loader'      => Loader::installed(),
				'site_url'    => \get_site_url(),
				'nonce'       => \wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Undo a completed import.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rollback( $request ) {
		$importer = $this->importer( $request );

		try {
			$state = $importer->rollback();
		} catch ( \Exception $e ) {
			return new \WP_Error( 'nfd_sm_rollback_failed', $e->getMessage(), array( 'status' => 400 ) );
		}

		return \rest_ensure_response(
			array(
				'ok'    => true,
				'notes' => (array) $state['notes'],
			)
		);
	}

	/**
	 * Keep a completed import, discarding what it replaced.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function confirm( $request ) {
		$importer = $this->importer( $request );

		try {
			$dropped = $importer->confirm();
		} catch ( \Exception $e ) {
			return new \WP_Error( 'nfd_sm_confirm_failed', $e->getMessage(), array( 'status' => 400 ) );
		}

		return \rest_ensure_response(
			array(
				'ok'      => true,
				'dropped' => $dropped,
			)
		);
	}

	/**
	 * Abandon an import that has not been swapped in.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function cancel( $request ) {
		$importer = $this->importer( $request );
		$importer->cancel();

		return \rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * The package directory a request is about.
	 *
	 * A path may be given, but only one that this site itself offered: the client chooses from
	 * `sources`, it does not name an arbitrary directory. Anything unrecognised falls back to
	 * the upload staging directory rather than being read.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return string
	 */
	protected function dir( $request ) {
		$wanted = (string) $request->get_param( 'dir' );

		if ( '' === $wanted ) {
			return Upload::dir();
		}

		$real = \realpath( $wanted );

		if ( false === $real ) {
			return Upload::dir();
		}

		if ( \realpath( Upload::dir() ) === $real ) {
			return Upload::dir();
		}

		foreach ( Upload::discover() as $candidate ) {
			if ( \realpath( $candidate['path'] ) === $real ) {
				return $candidate['path'];
			}
		}

		return Upload::dir();
	}

	/**
	 * An importer bound to this request's package and options.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return Importer
	 */
	protected function importer( $request ) {
		$requested = (string) $request->get_param( 'mode' );
		$mode      = UserMerger::MODE_REPLACE === $requested ? UserMerger::MODE_REPLACE : UserMerger::MODE_MERGE;

		return new Importer(
			$this->dir( $request ),
			array(
				'mode'        => $mode,
				'acting_user' => \get_current_user_id(),
				'keep_backup' => true,
			)
		);
	}
}
