<?php
/**
 * Class to test Async processing.
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
 * Handles an async request used to test the viability of using async requests
 * elsewhere.
 *
 * During a plugin update, an async request is sent with a specific string
 * value to validate that nothing is blocking admin-ajax.php requests from
 * the server to itself. Once verified, full background/parallel processing
 * can be used.
 *
 * @see \SWIS\Async_Request
 */
class Test_Async_Request extends Async_Request {

	/**
	 * The action name used to trigger this class extension.
	 *
	 * @access protected
	 * @var string $action
	 */
	protected $action = 'swis_test_async';

	/**
	 * Handles the test async request.
	 *
	 * Called via a POST request to verify that nothing is blocking or altering requests from the server to itself.
	 */
	protected function handle() {
		session_write_close();
		swis()->settings->debug_message( '<b>' . __METHOD__ . '()</b>' );
		check_ajax_referer( $this->identifier, 'nonce' );
		if ( empty( $_POST['swis_test_verify'] ) ) {
			return;
		}
		$item = sanitize_key( $_POST['swis_test_verify'] );
		swis()->settings->debug_message( "testing async handling, received $item" );
		if ( swis()->settings->detect_wpsf_location_lock() ) {
			swis()->settings->debug_message( 'detected location lock, not enabling background opt' );
			return;
		}
		if ( '949c34123cf2a4e4ce2f985135830df4a1b2adc24905f53d2fd3f5df5b162932' !== $item ) {
			swis()->settings->debug_message( 'wrong item received, not enabling background opt' );
			return;
		}
		swis()->settings->set_option( 'background_processing', true );
	}
}
