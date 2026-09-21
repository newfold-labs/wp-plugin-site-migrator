<?php
/**
 * Export routes.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Rest;

use NewfoldLabs\WP\SiteMigrator\Core\Export\Exporter;
use NewfoldLabs\WP\SiteMigrator\Core\Export\PartSpecs;
use NewfoldLabs\WP\SiteMigrator\Core\Export\Selection;
use NewfoldLabs\WP\SiteMigrator\Core\Export\TableOwners;
use NewfoldLabs\WP\SiteMigrator\Core\Package\Manifest;
use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageReader;
use NewfoldLabs\WP\SiteMigrator\Core\Package\PackageWriter;

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
			'/export/contents',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'contents' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'choose' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/export/belongings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'belongings' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'cursor' => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'offset' => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'budget' => array(
							'type'    => 'number',
							'default' => 5,
						),
						'deep'   => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
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
		return \nfd_sm_package_path();
	}

	/**
	 * What can be left out, and what currently is.
	 *
	 * @return \WP_REST_Response
	 */
	public function contents() {
		$selection = Selection::current();

		return \rest_ensure_response(
			array(
				'parts'     => $this->labelled_parts(),
				'tables'    => $this->tables(),
				// The screen needs it to tell `acme_log` and `wp_acme_log` apart the same way
				// `Selection::skips_table()` does — a selection saved from the CLI may hold either.
				'prefix'    => \nfd_sm_table_prefix(),
				'flags'     => Selection::database_flags(),
				'selection' => $selection->to_array(),
				'leaving'   => $selection->describe(),
				'locked'    => $this->run_in_progress(),
			)
		);
	}

	/**
	 * What each installed plugin and theme appears to own in the database, a few at a time.
	 *
	 * Its own endpoint rather than part of `/export/contents`, because it is the expensive one:
	 * answering means reading plugin code, and the screen only needs it in the one case where
	 * somebody has said the database is not travelling.
	 *
	 * **And it is stepped, like everything else here that does bulk work.** A real thirteen-plugin
	 * site takes 43 seconds to read even with tests and `node_modules` skipped, which no request
	 * survives — the first version of this returned nothing at all on such a site and left the
	 * picker spinning for ever. Each call now scans for a few seconds and hands back a cursor;
	 * the screen asks again until `done`, showing what it has so far. A directory finished in full
	 * is remembered for a day, so coming back to the screen costs nothing.
	 *
	 * What comes back is a suggestion and is labelled as one on the screen. `TableOwners` says how
	 * it is arrived at and what it cannot see.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function belongings( $request ) {
		$budget = (float) $request->get_param( 'budget' );
		$budget = $budget > 0 ? \min( $budget, 20.0 ) : 5.0;

		$deep = ! empty( $request->get_param( 'deep' ) );

		$owners  = new TableOwners( $this->table_names(), '', $budget, $deep );
		$present = $this->option_names();
		$cached  = $this->remembered( $deep );

		$queue  = $this->scan_queue();
		$cursor = (int) $request->get_param( 'cursor' );
		$offset = (int) $request->get_param( 'offset' );
		$found  = array();
		$done   = true;

		foreach ( $queue as $index => $entry ) {
			if ( $index < $cursor ) {
				continue;
			}

			$key = $entry['part'] . '/' . $entry['slug'];

			// Finished earlier, by an earlier request or an earlier visit to the screen. A
			// plugin's code changes when it is updated, and a suggestion a day stale is still a
			// suggestion -- the cache holds for `DAY_IN_SECONDS` and nothing depends on it.
			if ( isset( $cached[ $key ] ) && 0 === $offset ) {
				if ( ! empty( $cached[ $key ]['tables'] ) || ! empty( $cached[ $key ]['options'] ) ) {
					$found[ $entry['part'] ][ $entry['slug'] ] = $cached[ $key ];
				}

				++$cursor;
				continue;
			}

			$scan = $owners->scan_from( $entry['dir'], $offset );
			$one  = $owners->resolve( $scan['tables'], $scan['options'], $present );

			if ( $scan['next'] > 0 ) {
				// Stopped part way through this one. Its partial findings are not reported: half
				// a plugin's tables read as "this is what it owns", which is worse than waiting.
				$offset = (int) $scan['next'];
				$done   = false;
				break;
			}

			if ( ! empty( $one['tables'] ) || ! empty( $one['options'] ) ) {
				$found[ $entry['part'] ][ $entry['slug'] ] = $one;
			}

			$cached[ $key ] = $one;
			$offset         = 0;
			++$cursor;

			if ( ! $owners->is_complete() ) {
				$done = false;
				break;
			}
		}

		$this->remember( $cached, $deep );

		if ( $cursor >= \count( $queue ) ) {
			$done = true;
		}

		// Taken from everything known, not from this request's share of it: the last call in a
		// stepped scan sees only the plugins it read itself, and unclaimed means "no plugin
		// claimed it", which is a question about all of them.
		$claimed = array();

		foreach ( $cached as $one ) {
			$claimed[] = isset( $one['tables'] ) ? $one['tables'] : array();
		}

		return \rest_ensure_response(
			array(
				'belongs'   => $found,
				// Everything the scan could not attribute, so a table a plugin builds a name for
				// at runtime is offered rather than quietly left behind. Only meaningful once
				// every plugin has had its turn.
				'unclaimed' => $done ? $owners->unclaimed( $claimed ) : array(),
				'done'      => $done,
				'cursor'    => $cursor,
				'offset'    => $offset,
				'total'     => \count( $queue ),
				'deep'      => $deep,
				'scanning'  => $done || ! isset( $queue[ $cursor ] ) ? '' : $queue[ $cursor ]['slug'],
			)
		);
	}

	/**
	 * The directories to read, in a fixed order, so a cursor means the same thing twice.
	 *
	 * @return array List of `part`, `slug`, `dir`.
	 */
	protected function scan_queue() {
		$queue = array();

		foreach ( $this->code_directories() as $part => $children ) {
			foreach ( $children as $slug => $dir ) {
				$queue[] = array(
					'part' => $part,
					'slug' => $slug,
					'dir'  => $dir,
				);
			}
		}

		return $queue;
	}

	/**
	 * What earlier passes worked out, kept for a day.
	 *
	 * A transient rather than the options facade: this is a cache of something derivable, it is
	 * allowed to disappear, and it must not travel in the one option a package carries. The quick
	 * read and the thorough one are kept apart, so asking for the second does not have to throw
	 * away the first and cannot be answered with it.
	 *
	 * @param bool $deep Whether to read the thorough scan's cache.
	 *
	 * @return array Map of `part/slug` => `tables` and `options`.
	 */
	protected function remembered( $deep = false ) {
		$cached = \get_transient( $deep ? 'nfd_sm_belongings_deep' : 'nfd_sm_belongings' );

		return \is_array( $cached ) ? $cached : array();
	}

	/**
	 * Keep what this pass worked out.
	 *
	 * @param array $found Map of `part/slug` => `tables` and `options`.
	 * @param bool  $deep  Whether this was the thorough read, which is kept separately.
	 *
	 * @return void
	 */
	protected function remember( array $found, $deep = false ) {
		\set_transient(
			$deep ? 'nfd_sm_belongings_deep' : 'nfd_sm_belongings',
			$found,
			DAY_IN_SECONDS
		);
	}

	/**
	 * Every plugin and theme directory on this site, keyed by the part it belongs to.
	 *
	 * @return array Map of part name => map of slug => absolute directory.
	 */
	protected function code_directories() {
		$dirs = array();

		foreach ( PartSpecs::all() as $spec ) {
			$name = $spec->name();

			if ( 'plugins' !== $name && 'mu-plugins' !== $name && 0 !== \strpos( $name, 'themes' ) ) {
				continue;
			}

			$root = \rtrim( $spec->root(), '/\\' );

			foreach ( PartSpecs::children( $spec ) as $child ) {
				$dirs[ $name ][ $child ] = $root . DIRECTORY_SEPARATOR . $child;
			}
		}

		return $dirs;
	}

	/**
	 * This site's table names.
	 *
	 * @return array
	 */
	protected function table_names() {
		global $wpdb;

		$rows = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return \is_array( $rows ) ? $rows : array();
	}

	/**
	 * This site's option names.
	 *
	 * @return array
	 */
	protected function option_names() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col( "SELECT `option_name` FROM `{$wpdb->options}`" );

		return \is_array( $rows ) ? $rows : array();
	}

	/**
	 * The catalog, with a human name beside each slug where one exists.
	 *
	 * Additive and keyed by slug, because the slug stays the identity: it is what the `Selection`
	 * refuses, what the manifest records and what `--set` takes. The label is only what the screen
	 * draws, so a part with nothing to add carries an empty map and the screen falls back to the
	 * slug by itself.
	 *
	 * @return array
	 */
	protected function labelled_parts() {
		$parts  = PartSpecs::catalog();
		$labels = \nfd_sm_content_labels();

		foreach ( $parts as $index => $part ) {
			$parts[ $index ]['labels'] = isset( $labels[ $part['name'] ] )
				? $labels[ $part['name'] ]
				: array();
		}

		return $parts;
	}

	/**
	 * Save a selection.
	 *
	 * **Refused while a run is under way.** The export writes the selection into its checkpoint
	 * before the first byte and every step of it reads that copy, so a save mid-run would not
	 * change the package being built — it would only make the screen describe a package nobody is
	 * making. Saying so is better than accepting a change that quietly does nothing.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function choose( $request ) {
		if ( $this->run_in_progress() ) {
			return new \WP_Error(
				'nfd_sm_export_running',
				\__( 'An export is already under way. Cancel it before changing what goes into the package.', 'nfd-site-migrator' ),
				array( 'status' => 409 )
			);
		}

		$selection = Selection::store( (array) $request->get_param( 'selection' ) );
		$stale     = $this->invalidate_mismatched_package( $selection );

		return \rest_ensure_response(
			array(
				'saved'     => true,
				'selection' => $selection->to_array(),
				'leaving'   => $selection->describe(),
				'rebuild'   => $stale,
			)
		);
	}

	/**
	 * Take the manifest off a finished package the new selection no longer describes.
	 *
	 * The manifest is what marks a package complete, and it carries the `contents` block the
	 * destination reads to say what was deliberately left out. So a package built under one
	 * selection is not merely out of date once a different one is saved — it is *misdescribed*:
	 * the source would offer it, and the destination would announce "uploads were left out on
	 * purpose" for a run the user had just asked to include everything.
	 *
	 * Invalidating is also what makes the screens do the right thing. `Exporting` starts a run
	 * only when the package is not already complete — correct, or every visit to the step would
	 * re-package a finished site — so without this, pressing *Save and build the package* saved
	 * the selection and then handed back the previous package without building anything.
	 *
	 * Only a genuine difference counts. Opening the screen and pressing save with nothing changed
	 * must not destroy an hour of packaging, and the bytes are left alone either way: this removes
	 * the manifest, and `PackageWriter::reset()` clears the directory when the next run starts.
	 *
	 * @param Selection $selection The selection just stored.
	 *
	 * @return bool Whether a package was invalidated.
	 */
	protected function invalidate_mismatched_package( Selection $selection ) {
		$reader = new PackageReader( $this->package_dir() );

		if ( ! $reader->is_complete() ) {
			return false;
		}

		$built = (array) $reader->manifest()->get( 'contents.selection', array() );

		if ( self::canonical( ( new Selection( $built ) )->to_array() ) === self::canonical( $selection->to_array() ) ) {
			return false;
		}

		$writer = new PackageWriter( $this->package_dir() );

		return (bool) $writer->invalidate();
	}

	/**
	 * A selection in a fixed order, so two equal ones compare equal.
	 *
	 * Both sides of the comparison come from `Selection::to_array()`, but the order inside its
	 * maps and lists follows whatever order the input arrived in — the stored copy was built from
	 * a form, the other from JSON read back out of a manifest. Sorting first means the answer is
	 * about what was chosen and not about how it was typed.
	 *
	 * @param array $data Selection array.
	 *
	 * @return array The same data, recursively sorted.
	 */
	protected static function canonical( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( \is_array( $value ) ) {
				$data[ $key ] = self::canonical( $value );
			}
		}

		if ( \array_keys( $data ) === \range( 0, \count( $data ) - 1 ) ) {
			\sort( $data );

			return $data;
		}

		\ksort( $data );

		return $data;
	}

	/**
	 * Whether an export has started and not finished.
	 *
	 * A finished package is not in the way: the next export rewrites the directory from empty,
	 * which is what `PackageWriter::reset()` is for.
	 *
	 * @return bool
	 */
	protected function run_in_progress() {
		$exporter = new Exporter( $this->package_dir() );

		if ( $exporter->is_running() ) {
			return true;
		}

		$state = $exporter->snapshot();

		return $exporter->is_resumable() && ! empty( $state['stage'] ) && 'done' !== $state['stage'];
	}

	/**
	 * The tables a user may leave behind, with the ones they may not marked.
	 *
	 * Sizes come from `SHOW TABLE STATUS`, which is one query and an estimate — good enough to
	 * tell a 3GB log table from a 40KB settings table, which is the whole question being asked
	 * here. It is the one place a number is cheap enough to be worth showing.
	 *
	 * @return array
	 */
	protected function tables() {
		global $wpdb;

		$prefix = \nfd_sm_table_prefix();
		$rows   = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables = array();

		if ( ! \is_array( $rows ) ) {
			return $tables;
		}

		foreach ( $rows as $row ) {
			$name = isset( $row['Name'] ) ? (string) $row['Name'] : '';

			if ( '' === $name || 0 !== \strpos( $name, $prefix ) ) {
				continue;
			}

			$tables[] = array(
				'name'     => $name,
				'bytes'    => (int) \nfd_sm_data_get( $row, 'Data_length', 0 ) + (int) \nfd_sm_data_get( $row, 'Index_length', 0 ),
				'rows'     => (int) \nfd_sm_data_get( $row, 'Rows', 0 ),
				'required' => Selection::is_required_table( $name ),
			);
		}

		return $tables;
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
}
