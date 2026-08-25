<?php
/**
 * The plugins and themes an import brought with it.
 *
 * @package NewfoldLabs\WP\SiteMigrator
 */

namespace NewfoldLabs\WP\SiteMigrator\Core\Import;

use NewfoldLabs\WP\SiteMigrator\Core\Export\PartSpecs;

/**
 * Tracks what a package installs, so rollback can take it away again.
 *
 * The database half of a rollback is exact: the rename puts the destination's own tables back
 * and everything they hold — posts, settings, accounts, `active_plugins`, `stylesheet` — is as
 * it was. The file half is not undone at all, and for plugins and themes that shows. Every one
 * the package carried is still on disk, so the Plugins and Themes screens of a site that has
 * just been "put back" list a stack of things it never had. Two of the four places are worse
 * than untidy: a must-use plugin is loaded from disk with no reference to any option, and so is
 * a drop-in, so those keep *running* after the rollback that was supposed to remove them.
 *
 * Only the four places WordPress installs into are tracked. The rest of what a package carries
 * is content rather than something the site lists as installed, and `uploads` in particular is
 * the one directory where deleting on rollback would destroy work: a media file added after the
 * import is indistinguishable, from the filesystem, from one the package brought.
 *
 * All that can honestly be undone is what was **added**. A plugin or theme the destination
 * already had and the package overwrote cannot be put back — its own copy was written over
 * during the files stage, long before the swap — and deleting it would turn an incomplete
 * rollback into a destructive one. So the names present are recorded at precheck, before the
 * first file is written; the difference is taken once the files stage finishes; and rollback
 * removes exactly that difference and nothing else.
 *
 * Names rather than paths, and a diff rather than a log of what was written: the write path is
 * bounded by a per-file cost that has already been measured (see CLAUDE.md), and a site can
 * hold a hundred thousand files. A handful of `scandir()` calls of one directory each cost
 * nothing and answer the same question.
 */
class AddedCode {

	/**
	 * Slots holding things the site lists as plugins.
	 *
	 * @var array
	 */
	protected static $plugin_slots = array( 'plugins', 'mu-plugins', 'dropins' );

	/**
	 * What is installed now, in each place a package can install into.
	 *
	 * Entries, not plugins: a single-file plugin like `hello.php` is as much an addition as a
	 * directory, and the difference has to see both.
	 *
	 * @return array Slot name to sorted list of entry names.
	 */
	public static function snapshot() {
		$out = array();

		foreach ( self::slots() as $slot => $dir ) {
			$out[ $slot ] = self::entries( $slot, $dir );
		}

		return $out;
	}

	/**
	 * What is installed now that was not installed when the snapshot was taken.
	 *
	 * @param array $before A snapshot from before the files stage.
	 *
	 * @return array Slot name to list of entry names, slots with nothing added omitted.
	 */
	public static function added( array $before ) {
		$out = array();

		foreach ( self::snapshot() as $slot => $names ) {
			$was = isset( $before[ $slot ] ) ? (array) $before[ $slot ] : array();
			$new = \array_values( \array_diff( $names, $was ) );

			if ( ! empty( $new ) ) {
				$out[ $slot ] = $new;
			}
		}

		return $out;
	}

	/**
	 * How many plugins and how many themes a difference holds.
	 *
	 * Counted rather than listed because this is for a sentence on a screen, and separated by
	 * kind because "3 plugins and a theme" is what somebody deciding whether to undo wants to
	 * know.
	 *
	 * @param array $added The result of `added()`.
	 *
	 * @return array `plugins` and `themes`.
	 */
	public static function counts( array $added ) {
		$counts = array(
			'plugins' => 0,
			'themes'  => 0,
		);

		foreach ( $added as $slot => $names ) {
			$kind = \in_array( $slot, self::$plugin_slots, true ) ? 'plugins' : 'themes';

			$counts[ $kind ] += \count( (array) $names );
		}

		return $counts;
	}

	/**
	 * Delete what the import added.
	 *
	 * The before-snapshot is passed in as well as the difference, and anything named in it is
	 * refused. The difference was computed from it in the first place, so this can only ever
	 * matter when the state file is wrong — but the cost is one array lookup and what it buys
	 * is that "something this site already had is never deleted" holds at the point of deletion
	 * rather than only at the point the list was written.
	 *
	 * @param array $added  The result of `added()`, as recorded in the import state.
	 * @param array $before The snapshot taken before the files stage.
	 *
	 * @return array Labels of what was removed, as `slot/name`.
	 */
	public static function remove( array $added, array $before = array() ) {
		$slots   = self::slots();
		$removed = array();

		foreach ( $added as $slot => $names ) {
			if ( ! isset( $slots[ $slot ] ) ) {
				continue;
			}

			$dir  = $slots[ $slot ];
			$root = \realpath( $dir );
			$kept = isset( $before[ $slot ] ) ? (array) $before[ $slot ] : array();

			if ( false === $root ) {
				continue;
			}

			foreach ( (array) $names as $name ) {
				if ( \in_array( $name, $kept, true ) ) {
					continue;
				}

				$path = self::resolve( $root, $name, $slot );

				if ( '' === $path ) {
					continue;
				}

				if ( self::delete( $path ) ) {
					$removed[] = $slot . '/' . $name;
				}
			}
		}

		return $removed;
	}

	/**
	 * Where a package can install something this site would then list as installed.
	 *
	 * The theme slots are named the way `PartSpecs` names its theme parts, and derived from the
	 * same function, because a site can register more than one theme root and the second one is
	 * not `wp-content/themes`.
	 *
	 * @return array Slot name to absolute directory.
	 */
	protected static function slots() {
		$slots   = array();
		$plugins = \rtrim( (string) \nfd_sm_plugins_dir(), '/\\' );
		$mu      = \rtrim( (string) \nfd_sm_mu_plugins_dir(), '/\\' );
		$content = \rtrim( WP_CONTENT_DIR, '/\\' );

		if ( '' !== $plugins ) {
			$slots['plugins'] = $plugins;
		}

		// `nfd_sm_mu_plugins_dir()` answers only for a directory that exists. It may not have
		// before the import, in which case everything in it now arrived with the package.
		$slots['mu-plugins'] = '' !== $mu ? $mu : \rtrim( WPMU_PLUGIN_DIR, '/\\' );

		if ( '' !== $content ) {
			$slots['dropins'] = $content;
		}

		$roots = \nfd_sm_themes_dir();
		$roots = \is_array( $roots ) ? $roots : array( $roots );
		$index = 0;

		foreach ( $roots as $root ) {
			$root = \rtrim( (string) $root, '/\\' );

			if ( '' === $root || ! \is_dir( $root ) ) {
				continue;
			}

			++$index;
			$slots[ 1 === $index ? 'themes' : 'themes-' . $index ] = $root;
		}

		return $slots;
	}

	/**
	 * The entry names in one slot.
	 *
	 * @param string $slot Slot name.
	 * @param string $dir  Absolute directory.
	 *
	 * @return array Sorted names.
	 */
	protected static function entries( $slot, $dir ) {
		if ( ! \is_dir( $dir ) ) {
			return array();
		}

		$found = \scandir( $dir );

		if ( false === $found ) {
			return array();
		}

		// wp-content holds the whole site. Only the filenames WordPress loads as drop-ins are
		// a plugin arriving; a directory appearing under it is uploads or languages, and not
		// this class's business.
		$only   = 'dropins' === $slot ? PartSpecs::dropins() : array();
		$ignore = self::ignored( $slot );
		$names  = array();

		foreach ( $found as $name ) {
			if ( '.' === $name || '..' === $name || \in_array( $name, $ignore, true ) ) {
				continue;
			}

			if ( ! empty( $only ) && ! \in_array( $name, $only, true ) ) {
				continue;
			}

			$names[] = $name;
		}

		\sort( $names );

		return $names;
	}

	/**
	 * Names that are this plugin's own doing and must never be tracked as the package's.
	 *
	 * Both are in the before-snapshot anyway when the import runs from the browser, which
	 * installs the loader before precheck. Under WP-CLI there is no loader at all. This is
	 * belt and braces on a list that deletes files.
	 *
	 * @param string $slot Slot name.
	 *
	 * @return array
	 */
	protected static function ignored( $slot ) {
		if ( 'plugins' === $slot ) {
			return array( \nfd_sm_plugin_dirname() );
		}

		if ( 'mu-plugins' === $slot ) {
			return array( Loader::NAME );
		}

		return array();
	}

	/**
	 * Turn a recorded name into a path that is safe to delete.
	 *
	 * The name came out of this site's own filesystem rather than out of a package, but it made
	 * a round trip through a JSON file on disk in between, and this is a delete. It is checked
	 * as though it were untrusted: one path segment, no traversal, and — for anything that is
	 * not itself a link — resolving to a direct child of the directory it was recorded under.
	 *
	 * @param string $root Absolute, resolved slot directory.
	 * @param string $name Recorded entry name.
	 * @param string $slot Slot name.
	 *
	 * @return string Absolute path, or an empty string when it must be refused.
	 */
	protected static function resolve( $root, $name, $slot ) {
		$name = (string) $name;

		if ( '' === $name || '.' === $name || '..' === $name ) {
			return '';
		}

		if ( \basename( $name ) !== $name || \in_array( $name, self::ignored( $slot ), true ) ) {
			return '';
		}

		$path = \rtrim( $root, '/\\' ) . '/' . $name;

		// A link is deleted as a link, so it is never resolved: what it points at is somebody
		// else's file.
		if ( \is_link( $path ) ) {
			return $path;
		}

		if ( ! \file_exists( $path ) ) {
			return '';
		}

		$real = \realpath( $path );

		if ( false === $real || \dirname( $real ) !== \rtrim( $root, '/\\' ) ) {
			return '';
		}

		return $real;
	}

	/**
	 * Delete a file, a link, or a directory and everything below it.
	 *
	 * `nfd_sm_delete_directory()` would do this, but it recurses on `is_dir()`, which follows a
	 * symlink: a link inside a plugin directory would have it delete the contents of whatever
	 * that link points at. The import never writes one, so this is about what was already on
	 * the destination, and the answer is to unlink the link and stop there.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return bool Whether it is gone.
	 */
	protected static function delete( $path ) {
		if ( \is_link( $path ) || ! \is_dir( $path ) ) {
			return \unlink( $path );
		}

		$children = \scandir( $path );

		if ( false === $children ) {
			return false;
		}

		foreach ( $children as $child ) {
			if ( '.' === $child || '..' === $child ) {
				continue;
			}

			self::delete( $path . '/' . $child );
		}

		return \rmdir( $path );
	}
}
