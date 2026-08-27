<?php
/**
 * The command line's machine contract.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Cli;

/**
 * How this surface talks to something that is not a person.
 *
 * Phase 6 promoted the CLI from a harness into a product, and the difference is almost entirely
 * here rather than in the commands: a script needs to know *what happened* without reading
 * English, needs the answer separated from the commentary, and must never be asked a question it
 * cannot answer.
 *
 * Three rules, each of which was being broken:
 *
 * - **Data on stdout, progress on stderr.** Both went to stdout, so `--format=json | jq` was fed
 *   a stream of progress lines with a JSON document somewhere inside it.
 * - **Exit codes that distinguish outcomes.** Every failure exited `1`, including "the budget ran
 *   out, call me again" — which is not a failure at all, and which a caller had no way to tell
 *   apart from a corrupt package.
 * - **Never prompt.** Confirmation prompts blocked forever when nothing was attached to answer
 *   them.
 */
class Output {

	/**
	 * Payload version for `--format=json`.
	 *
	 * Bumped when an existing field changes meaning or disappears. Adding a field does not bump
	 * it, because a consumer reading by name is unaffected by one it has never heard of.
	 */
	const SCHEMA = 1;

	/**
	 * It worked.
	 */
	const EXIT_OK = 0;

	/**
	 * It did not work, and nothing more specific applies.
	 */
	const EXIT_FAILURE = 1;

	/**
	 * The two sites cannot be migrated between as they stand.
	 *
	 * Distinct from a failure because the fix is on a server rather than in the command: raise a
	 * PHP version, free some disk, turn on an extension. Retrying unchanged will not help.
	 */
	const EXIT_INCOMPATIBLE = 2;

	/**
	 * A budget expired with work outstanding. Run the same command again.
	 *
	 * Emphatically not an error. Before this existed a caller could not tell a step that stopped
	 * politely from one that died, so a wrapper script either gave up on a migration that was
	 * going fine or looped on one that was not.
	 */
	const EXIT_RESUMABLE = 3;

	/**
	 * The package is missing, unreadable, or does not match its own manifest.
	 */
	const EXIT_INVALID_PACKAGE = 4;

	/**
	 * Formats that mean "something is going to parse this".
	 *
	 * @var array
	 */
	protected static $machine = array( 'json', 'csv', 'yaml' );

	/**
	 * The format this invocation asked for.
	 *
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return string One of table, json, csv, yaml.
	 */
	public static function format( array $assoc_args ) {
		$format = isset( $assoc_args['format'] ) ? \strtolower( (string) $assoc_args['format'] ) : 'table';

		return \in_array( $format, array( 'table', 'json', 'csv', 'yaml' ), true ) ? $format : 'table';
	}

	/**
	 * Whether the output of this invocation is being read by a program.
	 *
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return bool
	 */
	public static function is_machine( array $assoc_args ) {
		return \in_array( self::format( $assoc_args ), self::$machine, true );
	}

	/**
	 * Write a line of progress or commentary.
	 *
	 * Always stderr, including for a person: a terminal shows both, so nothing is lost, and it
	 * means the stdout contract does not depend on who is watching. Anything a caller might want
	 * to keep goes through `emit()` instead.
	 *
	 * @param string $message Line to write.
	 *
	 * @return void
	 */
	public static function progress( $message ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		\fwrite( \STDERR, $message . "\n" );
	}

	/**
	 * Write the answer.
	 *
	 * `json` carries the whole nested payload, because that is the form a program wants and
	 * flattening it would lose the structure that makes it worth reading. `table`, `csv` and
	 * `yaml` get the flat rows, which is the form a person or a spreadsheet wants.
	 *
	 * @param array $assoc_args Associative arguments.
	 * @param array $payload    Full nested result, for json.
	 * @param array $rows       Flat rows, for table/csv/yaml.
	 * @param array $fields     Column order for the flat forms.
	 *
	 * @return void
	 */
	public static function emit( array $assoc_args, array $payload, array $rows, array $fields ) {
		$format = self::format( $assoc_args );

		if ( 'json' === $format ) {
			$payload['schema'] = self::SCHEMA;

			\WP_CLI::line( (string) \wp_json_encode( $payload ) );

			return;
		}

		if ( empty( $rows ) ) {
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Stop, with a reason and a code.
	 *
	 * @param string $message Why.
	 * @param int    $code    One of the EXIT_* constants.
	 *
	 * @return void
	 */
	public static function fail( $message, $code = self::EXIT_FAILURE ) {
		// WP_CLI::error() takes an exit code in place of `true`, writes to stderr, and halts. The
		// halt is what makes every caller below able to stop at the failure rather than checking
		// a return value that was never checked.
		\WP_CLI::error( $message, (int) $code );
	}

	/**
	 * Ask, unless asking is impossible or forbidden.
	 *
	 * `--yes` answers it. Machine-readable output, or no terminal on stdin, means there is nobody
	 * to ask: those exit rather than blocking on a prompt nothing will ever answer. Interactive
	 * use is unchanged.
	 *
	 * @param string $question   What to ask.
	 * @param array  $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public static function confirm( $question, array $assoc_args ) {
		if ( isset( $assoc_args['yes'] ) ) {
			return;
		}

		if ( self::is_machine( $assoc_args ) || ! self::interactive() ) {
			self::fail( $question . ' Nothing here can answer that, so pass --yes to go ahead.' );

			return;
		}

		\WP_CLI::confirm( $question, $assoc_args );
	}

	/**
	 * Whether there is a person on the other end of stdin.
	 *
	 * @return bool
	 */
	protected static function interactive() {
		return \function_exists( 'posix_isatty' ) && \defined( 'STDIN' )
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- posix_isatty warns on a closed or redirected descriptor, which is the answer rather than a problem.
			? (bool) @\posix_isatty( \STDIN )
			: true;
	}

	/**
	 * Turn a compatibility report into rows a person can read.
	 *
	 * @param array $report `Report::to_array()`.
	 *
	 * @return array
	 */
	public static function report_rows( array $report ) {
		$rows = array();

		// Blocking first, then warnings, then passes: the reason somebody ran this is at the top
		// whether or not the terminal is tall enough for the rest.
		foreach ( array( 'blocking', 'warnings', 'passed' ) as $bucket ) {
			foreach ( (array) \nfd_sm_data_get( $report, $bucket, array() ) as $check ) {
				$rows[] = array(
					'check'  => (string) \nfd_sm_data_get( $check, 'id', '' ),
					'status' => (string) \nfd_sm_data_get( $check, 'status', '' ),
					'detail' => (string) \nfd_sm_data_get( $check, 'label', '' ),
				);
			}
		}

		return $rows;
	}
}
