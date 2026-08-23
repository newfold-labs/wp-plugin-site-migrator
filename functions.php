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
 * Get a directory size.
 *
 * @param string $path The directory path.
 *
 * @return int
 */
function nfd_sm_get_dir_size( $path ) {
	$bytes = 0;
	$path  = realpath( $path );
	if ( $path && file_exists( $path ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) ) as $object ) {
			$bytes += $object->getSize();
		}
	}

	return $bytes;
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
