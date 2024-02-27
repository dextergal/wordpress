<?php
/**
 * Class and methods to find various elements from the HTML and allow filtering by other classes.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enables plugin to search the page content and filter various elements.
 */
final class Element_Filter extends Page_Parser {

	/**
	 * Register actions and filters for searching.
	 */
	function __construct() {
		parent::__construct();
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		// Hook onto the main output buffer filter.
		add_filter( $this->prefix . 'filter_page_output', array( $this, 'filter_page_output' ) );
	}

	/**
	 * Identify various elements in page content, and apply filters to them.
	 *
	 * @param string $content The page/post content.
	 * @return string The content, potentially altered.
	 */
	function filter_page_output( $content ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( $this->is_json( $content ) ) {
			return $content;
		}

		$search_buffer = preg_replace( '/<noscript.*?\/noscript>/s', '', $content );
		// Look for link elements (stylesheets, not hyperlinks or anchors).
		$links = $this->get_elements_from_html( $search_buffer, 'link' );
		if ( $this->is_iterable( $links ) ) {
			$this->debug_message( 'found ' . count( $links ) . ' CSS links to run through filters' );
			foreach ( $links as $index => $link ) {
				if ( false === strpos( $link, 'stylesheet' ) && false === strpos( $link, '.css' ) ) {
					continue;
				}
				$href = $this->get_attribute( $link, 'href' );
				if ( ! empty( $href ) ) {
					$this->debug_message( "running $href through filters" );
					$new_href = apply_filters( 'swis_elements_link_href', $href );
					if ( $new_href && $href !== $new_href ) {
						$this->debug_message( "changed to $new_href, updating" );
						$link = str_replace( $href, $new_href, $link );
					}
				}
				$this->debug_message( 'running link through filters:' );
				$this->debug_message( trim( $link ) );
				$link = apply_filters( 'swis_elements_link_tag', $link );
				if ( $link && $link !== $links[ $index ] ) {
					$this->debug_message( 'link modified:' );
					$this->debug_message( trim( $link ) );
					// Replace original element with modified version.
					$content = str_replace( $links[ $index ], $link, $content );
				}
			} // End foreach().
		} // End if();

		// Look for script elements (but we only want resources, not inline ones).
		$scripts = $this->get_elements_from_html( $search_buffer, 'script' );
		if ( $this->is_iterable( $scripts ) ) {
			$this->debug_message( 'found ' . count( $scripts ) . ' script tags to run through filters' );
			foreach ( $scripts as $index => $script ) {
				if ( false === strpos( $script, ' src' ) ) {
					continue;
				}
				$src = $this->get_attribute( $script, 'src' );
				if ( ! empty( $src ) ) {
					$this->debug_message( "running $src through filters" );
					$new_src = apply_filters( 'swis_elements_script_src', $src );
					if ( $new_src && $src !== $new_src ) {
						$this->debug_message( "changed to $new_src, updating" );
						$script = str_replace( $src, $new_src, $script );
					}
				}
				$this->debug_message( 'running script through filters:' );
				$this->debug_message( trim( $script ) );
				$script = apply_filters( 'swis_elements_script_tag', $script );
				if ( $script && $script !== $scripts[ $index ] ) {
					$this->debug_message( 'script modified:' );
					$this->debug_message( trim( $script ) );
					// Replace original element with modified version.
					$content = str_replace( $scripts[ $index ], $script, $content );
				}
			} // End foreach().
		} // End if();
		return $content;
	}
}
