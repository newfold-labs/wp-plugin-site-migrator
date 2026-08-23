<?php

namespace BluehostSiteMigrator\MigrationChecks;

use BluehostSiteMigrator\Utils\Options;

/**
 * The all in one migration compatibility checker
 */
class Checker {

	/**
	 * Store the results from the various checks.
	 *
	 * @var array
	 */
	public static $results = array();

	/**
	 * Run migration checks.
	 *
	 * @return bool True if migration is possible, false otherwise.
	 */
	public static function run() {
		$can_we_migrate = apply_filters( 'bluehost_site_migrator_can_migrate', true );
		Options::set( 'isCompatible', $can_we_migrate );

		return $can_we_migrate;
	}

		/**
		 * Register migration checks.
		 */
	public static function register() {
		add_filter( 'bluehost_site_migrator_can_migrate', array( __CLASS__, 'has_disk_free_space' ), 5 );
		add_filter( 'bluehost_site_migrator_can_migrate', array( __CLASS__, 'has_disk_total_space' ), 5 );
		add_filter( 'bluehost_site_migrator_can_migrate', array( __CLASS__, 'is_content_directory_writable' ), 5 );
		add_filter( 'bluehost_site_migrator_can_migrate', array( __CLASS__, 'is_not_multisite' ), 5 );
	}

	/**
	 * Check if the disk_free_space function is present. We rely on this for generating the site migration package.
	 *
	 * @param bool $can_migrate Whether or not we can migrate the site.
	 *
	 * @return bool
	 */
	public static function has_disk_free_space( $can_migrate ) {
		$has_disk_free_space = function_exists( 'disk_free_space' );

		self::$results['has_disk_free_space'] = $has_disk_free_space;

		return $can_migrate ? $has_disk_free_space : $can_migrate;
	}

	/**
	 * Check if the disk_total_space function is present. We rely on this for generating the site migration package.
	 *
	 * @param bool $can_migrate Whether or not we can migrate the site.
	 *
	 * @return bool
	 */
	public static function has_disk_total_space( $can_migrate ) {
		$has_disk_total_space = function_exists( 'disk_total_space' );

		self::$results['has_disk_total_space'] = $has_disk_total_space;

		return $can_migrate ? $has_disk_total_space : $can_migrate;
	}

	/**
	 * Check if the content directory is writable. If not, we don't have anywhere we can reliably store the site migration package.
	 *
	 * @param bool $can_migrate Whether or not we can migrate the site.
	 *
	 * @return bool
	 */
	public static function is_content_directory_writable( $can_migrate ) {
		$is_writeable                  = wp_is_writable( WP_CONTENT_DIR );
		self::$results['is_writeable'] = $is_writeable;

		return $can_migrate ? $is_writeable : $can_migrate;
	}

	/**
	 * Check if the site is a multisite install. Currently, automated backups only work for standard installs.
	 *
	 * @param bool $can_migrate Whether or not we can migrate the site.
	 *
	 * @return bool
	 */
	public static function is_not_multisite( $can_migrate ) {
		$is_multisite                  = is_multisite();
		self::$results['is_multisite'] = $is_multisite;

		return $can_migrate ? ! $is_multisite : $can_migrate;
	}
}
