<?php
/**
 * Uninstaller for plugin.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

swis_cleanup();

/**
 * Removes any binaries that have been installed in the wp-content/ewww/ folder.
 */
function swis_cleanup() {
	if ( ! class_exists( 'RecursiveIteratorIterator' ) ) {
		return;
	}
	if ( is_file( WP_CONTENT_DIR . '/advanced-cache.php' ) && is_writable( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
		$contents = file_get_contents( WP_CONTENT_DIR . '/advanced-cache.php' );
		if ( false !== strpos( $contents, 'SWIS_Performance' ) ) {
			unlink( WP_CONTENT_DIR . '/advanced-cache.php' );
		}
	}
	$cache_dir = WP_CONTENT_DIR . '/swis/';
	if ( ! is_dir( $cache_dir ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $cache_dir, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() ) {
			$path = $file->getPathname();
			if ( is_writable( $path ) ) {
				unlink( $path );
			}
		} elseif ( $file->isDir() ) {
			$path = $file->getPathname();
			if ( is_writable( $path ) ) {
				rmdir( $path );
			}
		}
	}
	if ( ! class_exists( 'FilesystemIterator' ) ) {
		return;
	}
	clearstatcache();
	$iterator = new FilesystemIterator( $cache_dir );
	if ( ! $iterator->valid() ) {
		rmdir( $cache_dir );
	}
}
