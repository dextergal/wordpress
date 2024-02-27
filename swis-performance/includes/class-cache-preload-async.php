<?php
/**
 * Begin Cache Preload via Async processing.
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
 * Handles an async request to begin preloading.
 *
 * Dual-purpose handler that tackles preloading for the entire site, and also for a given URL.
 *
 * @see \SWIS\Async_Request
 */
class Cache_Preload_Async extends Async_Request {

	/**
	 * The action name used to trigger this class extension.
	 *
	 * @access protected
	 * @var string $action
	 */
	protected $action = 'swis_cache_preload_async';

	/**
	 * Handles the preload async request.
	 *
	 * Called via a POST request to start the preloader.
	 */
	protected function handle() {
		session_write_close();
		swis()->settings->debug_message( '<b>' . __METHOD__ . '()</b>' );
		check_ajax_referer( $this->identifier, 'nonce' );
		swis()->settings->debug_message( 'next up, getting URLs' );

		if ( ! empty( $_POST['swis_preload_url'] ) ) {
			swis()->cache_preload_background->push_to_queue( sanitize_text_field( wp_unslash( $_POST['swis_preload_url'] ) ) );
			set_transient( 'swis_cache_preload_total', (int) swis()->cache_preload_background->count_queue(), DAY_IN_SECONDS );
			swis()->cache_preload_background->dispatch();
		} else {
			swis()->cache_preload->get_urls();
			swis()->cache_preload_background->dispatch();
		}
	}
}
