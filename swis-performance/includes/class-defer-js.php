<?php
/**
 * Class and methods to defer JS.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enables plugin to filter JS tags and defer them.
 */
final class Defer_JS extends Page_Parser {

	/**
	 * A list of user-defined exclusions, populated by validate_user_exclusions().
	 *
	 * @access protected
	 * @var array $user_exclusions
	 */
	protected $user_exclusions = array();

	/**
	 * Register actions and filters for JS Defer.
	 */
	function __construct() {
		if ( ! $this->get_option( 'defer_js' ) ) {
			return;
		}
		parent::__construct();
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		$uri = add_query_arg( null, null );
		$this->debug_message( "request uri is $uri" );

		/**
		 * Allow pre-empting JS defer by page.
		 *
		 * @param bool Whether to skip parsing the page.
		 * @param string $uri The URL of the page.
		 */
		if ( apply_filters( 'swis_skip_js_defer_by_page', false, $uri ) ) {
			return;
		}

		// Start an output buffer before any output starts.
		add_filter( $this->prefix . 'filter_page_output', array( $this, 'filter_page_output' ) );

		// Overrides for user exclusions.
		add_filter( 'swis_skip_js_defer', array( $this, 'skip_js_defer' ), 10, 2 );

		// Get all the script urls and rewrite them (if enabled).
		add_filter( 'script_loader_tag', array( $this, 'defer_scripts' ), 20 );
		add_filter( 'swis_elements_script_tag', array( $this, 'defer_scripts' ) );

		$this->validate_user_exclusions();
	}

	/**
	 * Validate the user-defined exclusions.
	 */
	function validate_user_exclusions() {
		$user_exclusions = $this->get_option( 'defer_js_exclude' );
		if ( ! empty( $user_exclusions ) ) {
			if ( is_string( $user_exclusions ) ) {
				$user_exclusions = array( $user_exclusions );
			}
			if ( is_array( $user_exclusions ) ) {
				foreach ( $user_exclusions as $exclusion ) {
					if ( ! is_string( $exclusion ) ) {
						continue;
					}
					$this->user_exclusions[] = $exclusion;
				}
			}
		}
		$this->user_exclusions[] = '/js/dist/i18n.';
		$this->user_exclusions[] = '/js/dist/hooks.';
		$this->user_exclusions[] = '/js/tinymce/';
		$this->user_exclusions[] = '/js/dist/vendor/lodash.';
		$this->user_exclusions[] = '/js/domaincheck';
		$this->user_exclusions[] = 'facetwp/assets/js/dist/front';
	}

	/**
	 * Parse page content looking for jQuery script tag to rewrite.
	 *
	 * @param string $content The HTML content to parse.
	 * @return string The filtered HTML content.
	 */
	function filter_page_output( $content ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! $this->is_frontend() ) {
			return $content;
		}
		if ( $this->is_json( $content ) ) {
			return $content;
		}
		if ( $this->get_option( 'defer_jquery_safe' ) && false === strpos( $content, 'jQuery' ) ) {
			preg_match( "#<script\s+(?:type='text/javascript'\s+)?src='[^']+?/jquery(\.min)?\.js[^']*?'[^>]*?>#is", $content, $jquery_tags );
			if ( ! empty( $jquery_tags[0] ) && false === strpos( $jquery_tags[0], 'defer' ) && false === strpos( $jquery_tags[0], 'async' ) ) {
				$deferred_jquery = str_replace( '>', ' defer>', $jquery_tags[0] );
				if ( $deferred_jquery && $deferred_jquery !== $jquery_tags[0] ) {
					$content = str_replace( $jquery_tags[0], $deferred_jquery, $content );
				}
			}
		}
		return $content;
	}

	/**
	 * Exclude JS from being processed based on user specified list.
	 *
	 * @param boolean $skip Whether SWIS should skip processing.
	 * @param string  $tag The script tag HTML.
	 * @return boolean True to skip the resource, unchanged otherwise.
	 */
	function skip_js_defer( $skip, $tag ) {
		if ( $this->user_exclusions ) {
			foreach ( $this->user_exclusions as $exclusion ) {
				if ( false !== strpos( $tag, $exclusion ) ) {
					$this->debug_message( __METHOD__ . "(); user excluded $tag via $exclusion" );
					return true;
				}
			}
		}
		return $skip;
	}

	/**
	 * Rewrites a script tag to be deferred.
	 *
	 * @param string $tag URL to the script.
	 * @return string The deferred version of the resource, if it was allowed.
	 */
	function defer_scripts( $tag ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! $this->is_frontend() ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'async' ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'defer' ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'jquery.js' ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'jquery.min.js' ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'asset-clean' ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'data-cfasync' ) ) {
			return $tag;
		}
		if ( apply_filters( 'swis_skip_js_defer', false, $tag ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'lazysizes.min.js' ) ) {
			return str_replace( '></script', ' async></script', $tag );
		}
		$this->debug_message( trim( $tag ) );
		// If we don't have the ending script tag, usually from \SWIS\Element_Filter.
		if ( false === strpos( $tag, '</script>' ) ) {
			$deferred_tag = str_replace( '>', ' defer>', $tag );
		} else {
			$deferred_tag = str_replace( '></script', ' defer></script', $tag );
		}
		if ( $deferred_tag && $deferred_tag !== $tag ) {
			$this->debug_message( trim( $deferred_tag ) );
			return $deferred_tag;
		}
		$this->debug_message( 'unchanged' );
		return $tag;
	}
}
