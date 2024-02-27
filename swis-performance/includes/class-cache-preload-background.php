<?php
/**
 * Classes for Background and Async processing.
 *
 * This file contains classes and methods that extend \SWIS\Background_Process and
 * \SWIS\Async_Request to allow cache preloading.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The (grand)parent \SWIS\Async_Request class file.
 */
require_once( SWIS_PLUGIN_PATH . 'vendor/wp-async-request.php' );

/**
 * The parent \SWIS\Background_Process class file.
 */
require_once( SWIS_PLUGIN_PATH . 'includes/class-background-process.php' );

/**
 * Processes cache preloading in background/async mode.
 *
 * @see Background_Process
 */
class Cache_Preload_Background extends Background_Process {

	/**
	 * The action name used to trigger this class extension.
	 *
	 * @access protected
	 * @var string $action
	 */
	protected $action = 'cache_preload';

	/**
	 * The queue name for this class extension.
	 *
	 * @access protected
	 * @var string $action
	 */
	protected $active_queue = 'swis_cache_preload';

	/**
	 * Runs task for an item from the preloader queue.
	 *
	 * @access protected
	 *
	 * @param array $item The id of the post/page.
	 * @return bool|array If the item is not complete, return it. False indicates completion.
	 */
	protected function task( $item ) {
		session_write_close();

		$url = $item['page_url'];
		swis()->settings->debug_message( "background preloading $url" );

		swis()->cache_preload->preload( $url );

		return false;
	}

	/**
	 * Runs failure routine for cache preload (maybe not).
	 *
	 * @access protected
	 *
	 * @param array $item The id of the page and how many attempts have been made.
	 */
	protected function failure( $item ) {
		// TODO: not sure we will even bother detecting failures...
		return;
	}
}
