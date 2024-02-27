<?php
/**
 * Class and methods to start an HTML buffer for parsing by other classes.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enables plugin to filter HTML through a variety of functions.
 */
class Buffer {

	/**
	 * Register hook function to startup buffer.
	 */
	function __construct() {
		add_action( 'template_redirect', array( $this, 'buffer_start' ) );
	}

	/**
	 * Starts an output buffer and registers the callback function to do HTML parsing.
	 */
	function buffer_start() {
		ob_start( array( $this, 'filter_page_output' ) );
	}

	/**
	 * Parse page content through filter functions.
	 *
	 * @param string $buffer The HTML content to parse.
	 * @return string The filtered HTML content.
	 */
	function filter_page_output( $buffer ) {
		return apply_filters( 'swis_filter_page_output', $buffer );
	}
}
