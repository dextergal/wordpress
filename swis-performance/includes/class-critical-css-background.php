<?php
/**
 * Classes for Background and Async processing.
 *
 * This file contains classes and methods that extend \SWIS\Background_Process and
 * \SWIS\Async_Request to generate of critical CSS.
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
 * Generates critical CSS in background/async mode.
 *
 * @see Background_Process
 */
class Critical_CSS_Background extends Background_Process {

	/**
	 * The action name used to trigger this class extension.
	 *
	 * @access protected
	 * @var string $action
	 */
	protected $action = 'generate_css';

	/**
	 * The queue name for this class extension.
	 *
	 * @access protected
	 * @var string $action
	 */
	protected $active_queue = 'swis_generate_css';

	/**
	 * Batch size limit. Set to 1 in order to do things slowly.
	 *
	 * @var int
	 * @access protected
	 */
	protected $limit = 1;


	/**
	 * Runs task for an item from the generate CSS queue.
	 *
	 * @access protected
	 *
	 * @param array $item The id of the post/page.
	 * @return bool|array If the item is not complete, return it. False indicates completion.
	 */
	protected function task( $item ) {
		\session_write_close();
		swis()->settings->debug_message( '<b>' . __METHOD__ . '()</b>' );

		if ( empty( $item['page_url'] ) ) {
			return false;
		}
		if ( \get_transient( 'swis_generate_css_paused' ) ) {
			return $page;
		}

		swis()->settings->debug_message( "generating CCSS (background) for {$item['page_url']}" );

		if ( ! empty( $item['params']['status'] ) && ( 'JOB_QUEUED' === $item['params']['status'] || 'JOB_ONGOING' === $item['params']['status'] ) ) {
			swis()->settings->debug_message( 'pending job, sleeping for 8' );
			if ( swis()->settings->function_exists( 'sleep' ) ) {
				sleep( 8 );
			}
		}

		$item = swis()->critical_css->generate( $item );

		if ( ! empty( $item['params']['status'] ) && 'failed' === $item['params']['status'] && strpos( $item['params']['error'], 'Too many concurrent requests' ) ) {
			swis()->settings->debug_message( 'going too fast, sleeping for 5' );
			if ( swis()->settings->function_exists( 'sleep' ) ) {
				sleep( 5 );
			}
		}
		swis()->settings->debug_log();
		if (
			! empty( $item['params']['status'] ) &&
			'JOB_DONE' !== $item['params']['status'] &&
			'exists' !== $item['params']['status'] &&
			'failed' !== $item['params']['status'] &&
			'invalid_key' !== $item['params']['status']
		) {
			return $item;
		}

		return false;
	}

	/**
	 * Runs failure routine for critical CSS generation.
	 *
	 * @access protected
	 *
	 * @param array $item The id of the page and how many attempts have been made.
	 */
	protected function failure( $item ) {
		return;
	}

	/**
	 * Complete.
	 */
	protected function complete() {
		swis()->settings->debug_message( '<b>' . __METHOD__ . '()</b>' );
		parent::complete();
		do_action( 'swis_clear_site_cache' );
	}
}
