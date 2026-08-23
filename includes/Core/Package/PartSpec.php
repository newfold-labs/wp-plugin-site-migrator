<?php
/**
 * Description of one part of a package.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Package;

/**
 * What to collect, from where, and under what name.
 *
 * This replaces six near-identical archiver classes. Everything that differed between them is
 * data on this object; everything that was the same is now in FileCollector.
 */
class PartSpec {

	/**
	 * Part name, used for the file name and the manifest entry.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Absolute directory to collect from.
	 *
	 * @var string
	 */
	protected $root;

	/**
	 * Path prefix recorded inside the archive, relative to the WordPress install.
	 *
	 * @var string
	 */
	protected $prefix;

	/**
	 * Directory names, relative to the root, that must not be descended into.
	 *
	 * @var array
	 */
	protected $exclude_dirs = array();

	/**
	 * Paths relative to the root, which another part already collects.
	 *
	 * @var array
	 */
	protected $exclude_files = array();

	/**
	 * When non-empty, only these basenames are collected and no subdirectory is entered.
	 *
	 * @var array
	 */
	protected $allowlist = array();

	/**
	 * Whether to descend into subdirectories.
	 *
	 * @var bool
	 */
	protected $recursive = true;

	/**
	 * Constructor.
	 *
	 * @param string $name   Part name.
	 * @param string $root   Absolute directory to collect from.
	 * @param string $prefix Path prefix recorded inside the archive.
	 */
	public function __construct( $name, $root, $prefix ) {
		$this->name   = $name;
		$this->root   = \rtrim( $root, '/\\' );
		$this->prefix = \trim( $prefix, '/' );
	}

	/**
	 * Part name.
	 *
	 * @return string
	 */
	public function name() {
		return $this->name;
	}

	/**
	 * Absolute directory to collect from.
	 *
	 * @return string
	 */
	public function root() {
		return $this->root;
	}

	/**
	 * Path prefix recorded inside the archive.
	 *
	 * @return string
	 */
	public function prefix() {
		return $this->prefix;
	}

	/**
	 * Directories not to descend into.
	 *
	 * @return array
	 */
	public function excluded_dirs() {
		return $this->exclude_dirs;
	}

	/**
	 * Files another part already collects.
	 *
	 * @return array
	 */
	public function excluded_files() {
		return $this->exclude_files;
	}

	/**
	 * Do not collect these files; another part has them.
	 *
	 * Without this, a file claimed by a narrow part is also swept up by a broad one and is
	 * stored twice.
	 *
	 * @param array $files Paths relative to the root.
	 *
	 * @return PartSpec
	 */
	public function exclude_files( array $files ) {
		$this->exclude_files = $files;

		return $this;
	}

	/**
	 * The closed set of permitted basenames, empty when not in allowlist mode.
	 *
	 * @return array
	 */
	public function allowlist() {
		return $this->allowlist;
	}

	/**
	 * Whether subdirectories are entered.
	 *
	 * @return bool
	 */
	public function is_recursive() {
		return $this->recursive;
	}

	/**
	 * Whether only an explicit set of basenames is collected.
	 *
	 * @return bool
	 */
	public function is_allowlisted() {
		return ! empty( $this->allowlist );
	}

	/**
	 * Do not descend into these directory names.
	 *
	 * @param array $dirs Directory names relative to the root.
	 *
	 * @return PartSpec
	 */
	public function exclude_dirs( array $dirs ) {
		$this->exclude_dirs = $dirs;

		return $this;
	}

	/**
	 * Collect only these basenames, and do not descend.
	 *
	 * An allowlist is the only safe way to build a part from a directory that also holds files
	 * which must never leave the server. See finding 2.4: the previous exclusion-based approach
	 * compared absolute paths against basenames, matched nothing, and archived `wp-config.php`.
	 *
	 * @param array $names Permitted basenames.
	 *
	 * @return PartSpec
	 */
	public function only( array $names ) {
		$this->allowlist = $names;
		$this->recursive = false;

		return $this;
	}

	/**
	 * Collect the top level only.
	 *
	 * @return PartSpec
	 */
	public function shallow() {
		$this->recursive = false;

		return $this;
	}
}
