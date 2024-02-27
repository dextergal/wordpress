<?php
/**
 * Begin Critical CSS generation via Async processing.
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
 * Handles an async request to begin CSS generation.
 *
 * @see \SWIS\Async_Request
 */
class Critical_CSS_Async extends Async_Request {

	/**
	 * The action name used to trigger this class extension.
	 *
	 * @access protected
	 * @var string $action
	 */
	protected $action = 'swis_generate_css_async';

	/**
	 * Handles the critical CSS async request.
	 *
	 * Called via a POST request to start the generation process.
	 */
	protected function handle() {
		session_write_close();
		swis()->settings->debug_message( '<b>' . __METHOD__ . '()</b>' );
		check_ajax_referer( $this->identifier, 'nonce' );
		swis()->settings->debug_message( 'next up, getting URLs' );

		swis()->critical_css->get_urls();
		swis()->critical_css_background->dispatch();
	}
}
