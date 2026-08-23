<?php

/**
 * Function to open a file and throw an error when not possible
 *
 * @param string $file Path to the file to open
 * @param string $mode Mode in which to open the file
 *
 * @return resource
 *
 * @throws \Exception When we are unable to open the file.
 */
function nfd_sm_open( $file, $mode ) {
	$file_handle = fopen( $file, $mode );
	if ( false === $file_handle ) {
		throw new \Exception(
			sprintf( 'Unable to open %s with mode %s.', esc_xml( $file ), esc_xml( $mode ) )
		);
	}

	return $file_handle;
}

/**
 * Write contents to a file
 *
 * @param resource $handle  File handle to write to
 * @param string   $content Contents to write to the file
 *
 * @return integer
 *
 * @throws \Exception If unable to write.
 */
function nfd_sm_write( $handle, $content ) {
	$write_result = fwrite( $handle, $content );
	if ( false === $write_result ) {
		$meta = stream_get_meta_data( $handle );
		if ( $meta ) {
			throw new \Exception( sprintf( 'Unable to write to: %s.', esc_xml( $meta['uri'] ) ) );
		}
	} elseif ( null === $write_result ) {
		return strlen( $content );
	} elseif ( strlen( $content ) !== $write_result ) {
		$meta = stream_get_meta_data( $handle );
		if ( $meta ) {
			throw new \Exception( sprintf( 'Out of disk space. Unable to write to: %s', esc_xml( $meta['uri'] ) ) );
		}
	}

	return $write_result;
}

/**
 * Check whether blog ID is main site
 *
 * @param  integer $blog_id Blog ID
 * @return boolean
 */
function nfd_sm_is_mainsite( $blog_id = null ) {
	return null === $blog_id || 0 === $blog_id || 1 === $blog_id;
}

/**
 * Get WordPress table prefix by blog ID
 *
 * @param  integer $blog_id Blog ID
 * @return string
 */
function nfd_sm_table_prefix( $blog_id = null ) {
	global $wpdb;

	// Set base table prefix
	if ( nfd_sm_is_mainsite( $blog_id ) ) {
		return $wpdb->base_prefix;
	}

	return $wpdb->base_prefix . $blog_id . '_';
}

/**
 * Get the base storage directory
 */
function nfd_sm_storage_path() {
	$uploads   = wp_get_upload_dir();
	$directory = $uploads['basedir'] . DIRECTORY_SEPARATOR . 'nfd-site-migrator' . DIRECTORY_SEPARATOR;
	wp_mkdir_p( $directory );

	return $directory;
}

/**
 * Measure a directory, with a time budget and a cache.
 *
 * Walking a large uploads directory is slow — several seconds for a few gigabytes, minutes for
 * tens — and preflight runs on a page load. So the result is cached, and the walk gives up when
 * the budget runs out and says so rather than blocking the request indefinitely.
 *
 * @param string $path   Absolute directory.
 * @param float  $budget Seconds to spend, or 0 for no limit.
 *
 * @return array `bytes`, `files`, and `complete` — false when the budget ran out.
 */
function nfd_sm_measure_dir( $path, $budget = 3.0 ) {
	$path = realpath( $path );

	if ( ! $path || ! is_dir( $path ) ) {
		return array(
			'bytes'    => 0,
			'files'    => 0,
			'complete' => true,
		);
	}

	$key    = 'nfd_sm_dirsize_' . md5( $path );
	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$deadline = $budget > 0 ? microtime( true ) + $budget : 0;
	$bytes    = 0;
	$files    = 0;
	$complete = true;

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY,
		RecursiveIteratorIterator::CATCH_GET_CHILD
	);

	foreach ( $iterator as $item ) {
		if ( ! $item->isFile() ) {
			continue;
		}

		$bytes += (int) $item->getSize();
		++$files;

		// Checking the clock on every file would itself be a cost; every 512 is plenty.
		if ( $deadline > 0 && 0 === ( $files % 512 ) && microtime( true ) >= $deadline ) {
			$complete = false;
			break;
		}
	}

	$result = array(
		'bytes'    => $bytes,
		'files'    => $files,
		'complete' => $complete,
	);

	// A partial measurement is worth caching too, briefly: it stops every request paying the
	// same budget, and it is refreshed often enough to stay useful.
	set_transient( $key, $result, $complete ? 900 : 120 );

	return $result;
}

/**
 * Size of a directory in bytes.
 *
 * @param string $path Absolute directory.
 *
 * @return int
 */
function nfd_sm_get_dir_size( $path ) {
	$measured = nfd_sm_measure_dir( $path, 0 );

	return $measured['bytes'];
}

/**
 * Get a value from an object or an array.  Allows the ability to fetch a nested value from a
 * heterogeneous multidimensional collection using dot notation.
 *
 * @param array|object $data        Data to fetch from.
 * @param string       $key         Key as a string, optionally using dot notation.
 * @param mixed        $default_val The fallback value if a value doesn't exist.
 *
 * @return mixed
 */
function nfd_sm_data_get( $data, $key, $default_val = null ) {
	$value = $default_val;
	if ( is_array( $data ) && array_key_exists( $key, $data ) ) {
		$value = $data[ $key ];
	} elseif ( is_object( $data ) && property_exists( $data, $key ) ) {
		$value = $data->$key;
	} else {
		$segments = explode( '.', $key );
		foreach ( $segments as $segment ) {
			if ( is_array( $data ) && array_key_exists( $segment, $data ) ) {
				$value = $data = $data[ $segment ]; // phpcs:ignore
			} elseif ( is_object( $data ) && property_exists( $data, $segment ) ) {
				$value = $data = $data->$segment; // phpcs:ignore
			} else {
				$value = $default_val;
				break;
			}
		}
	}

	return $value;
}

/**
 * Get WordPress plugins directory
 *
 * @return string
 */
function nfd_sm_plugins_dir() {
	return untrailingslashit( WP_PLUGIN_DIR );
}

/**
 * Get WordPress themes directory
 *
 * @return array
 */
function nfd_sm_themes_dir() {
	$theme_dirs = array();
	foreach ( search_theme_directories() as $theme_name => $theme_info ) {
		if ( isset( $theme_info['theme_root'] ) ) {
			if ( ! in_array( $theme_info['theme_root'], $theme_dirs, true ) ) {
				$theme_dirs[] = untrailingslashit( $theme_info['theme_root'] );
			}
		}
	}

	return $theme_dirs;
}

/**
 * Get WordPress uploads directory
 *
 * @return string
 */
function nfd_sm_uploads_dir() {
	$upload_dir = wp_upload_dir();
	if ( $upload_dir ) {
		if ( isset( $upload_dir['basedir'] ) ) {
			return untrailingslashit( $upload_dir['basedir'] );
		}
	}
}

/**
 * Get the mu plugins directory for WordPress
 *
 * @return string
 */
function nfd_sm_mu_plugins_dir() {
	$mu_plugins_dir = WPMU_PLUGIN_DIR;
	if ( is_dir( $mu_plugins_dir ) ) {
		return untrailingslashit( $mu_plugins_dir );
	}
}

/**
 * A function to recursively purge a directory
 *
 * @param string $dir The directory path.
 */
function nfd_sm_delete_directory( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return false;
	}

	$files = scandir( $dir );
	foreach ( $files as $file ) {
		if ( '.' !== $file && '..' !== $file ) {
			$path = $dir . '/' . $file;
			if ( is_dir( $path ) ) {
				nfd_sm_delete_directory( $path ); // Recursively delete subdirectories
			} else {
				unlink( $path ); // Delete individual files
			}
		}
	}

	return rmdir( $dir ); // Remove the empty directory
}

/**
 * Purge Migration related things
 */
function nfd_sm_purge_all() {
	// Delete the options
	foreach ( NFD_SM_OPTIONS_LIST as $option ) {
		delete_option( $option );
	}

	// Delete the migration data
	nfd_sm_delete_directory( nfd_sm_storage_path() );

	// Delete the transient
	delete_transient( NFD_SM_CAN_MIGRATE_TRANSIENT );
}

/**
 * Express a path relative to a root directory, with forward slashes.
 *
 * @param string $root Absolute root directory.
 * @param string $path Absolute path inside the root.
 *
 * @return string Relative path, or the basename when $path is not below $root.
 */
function nfd_sm_relative_path( $root, $path ) {
	$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
	$path = str_replace( '\\', '/', $path );

	if ( 0 === strpos( $path, $root ) ) {
		return substr( $path, strlen( $root ) );
	}

	return basename( $path );
}
