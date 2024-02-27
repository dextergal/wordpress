<?php
/**
 * SWIS advanced cache, thanks to Cache Enabler!
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Set the SWIS_PLUGIN_DIR constant in your wp-config.php if the plugin resides somewhere other than WP_PLUGIN_DIR or WP_CONTENT_DIR/plugins
 */
if ( defined( 'SWIS_PLUGIN_DIR' ) ) {
	$swis_dir = SWIS_PLUGIN_DIR;
} else {
	$swis_dir = ( ( defined( 'WP_PLUGIN_DIR' ) ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins' ) . '/swis-performance';
}
if ( ! defined( 'SWIS_CONTENT_DIR' ) ) {
	define( 'SWIS_CONTENT_DIR', WP_CONTENT_DIR . '/swis/' );
}

$swis_cache_engine_file = $swis_dir . '/includes/class-cache-engine.php';
$swis_disk_cache_file   = $swis_dir . '/includes/class-disk-cache.php';

if ( is_file( $swis_cache_engine_file ) && is_file( $swis_disk_cache_file ) ) {
	require_once( $swis_cache_engine_file );
	require_once( $swis_disk_cache_file );
	if ( class_exists( '\SWIS\Cache_Engine' ) ) {
		$swis_engine_started = Cache_Engine::start();
		if ( $swis_engine_started ) {
			$swis_cache_delivered = Cache_Engine::deliver_cache();
			if ( ! $swis_cache_delivered ) {
				Cache_Engine::start_buffering();
			}
		}
	}
}
