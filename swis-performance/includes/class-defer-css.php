<?php
/**
 * Class and methods to defer CSS.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enables plugin to filter CSS tags and defer them.
 */
final class Defer_CSS extends Page_Parser {

	/**
	 * A list of user-defined exclusions, populated by validate_user_exclusions().
	 *
	 * @access protected
	 * @var array $user_exclusions
	 */
	protected $user_exclusions = array();

	/**
	 * Register actions and filters for CSS Defer.
	 */
	function __construct() {
		if ( false !== strpos( add_query_arg( null, null ), 'swis_ccss' ) ) {
			return;
		}
		if ( false !== strpos( add_query_arg( null, null ), 'swis_test_ccss' ) ) {
			return;
		}
		if ( ! $this->get_option( 'defer_css' ) ) {
			return;
		}
		parent::__construct();
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		$uri = add_query_arg( null, null );
		$this->debug_message( "request uri is $uri" );

		/**
		 * Allow pre-empting CSS defer by page.
		 *
		 * @param bool Whether to skip parsing the page.
		 * @param string $uri The URL of the page.
		 */
		if ( apply_filters( 'swis_skip_css_defer_by_page', false, $uri ) ) {
			return;
		}

		// Overrides for user exclusions.
		add_filter( 'swis_skip_css_defer', array( $this, 'skip_css_defer' ), 10, 2 );

		// Get all the script/css urls and rewrite them (if enabled).
		add_filter( 'style_loader_tag', array( $this, 'defer_css' ), 20 );
		add_filter( 'swis_elements_link_tag', array( $this, 'defer_css' ) );

		$this->validate_user_exclusions();
	}

	/**
	 * Validate the user-defined exclusions.
	 */
	function validate_user_exclusions() {
		$user_exclusions = $this->get_option( 'defer_css_exclude' );
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
	}

	/**
	 * Exclude CSS from being processed based on user specified list.
	 *
	 * @param boolean $skip Whether SWIS should skip processing.
	 * @param string  $tag The CSS link tag HTML.
	 * @return boolean True to skip the resource, unchanged otherwise.
	 */
	function skip_css_defer( $skip, $tag ) {
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
	 * Rewrites a CSS link tag to be deferred.
	 *
	 * @param string $tag HTML for the CSS resource.
	 * @return string The deferred version of the resource, if it was allowed.
	 */
	function defer_css( $tag ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! $this->is_frontend() ) {
			return $tag;
		}
		if ( strpos( $tag, 'admin-bar.min.css' ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'async' ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'defer' ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'asset-clean' ) ) {
			return $tag;
		}
		if ( apply_filters( 'swis_skip_css_defer', false, $tag ) ) {
			return $tag;
		}
		if ( false === strpos( $tag, 'preload' ) && false === strpos( $tag, 'data-swis' ) ) {
			$this->debug_message( trim( $tag ) );
			$async_tag = str_replace( " media='all'", " media='print' data-swis='loading' onload='this.media=\"all\";this.dataset.swis=\"loaded\"'", $tag );
			if ( $tag === $async_tag ) {
				$async_tag = str_replace( " media=''", " media='print' data-swis='loading' onload='this.media=\"all\";this.dataset.swis=\"loaded\"'", $tag );
			}
			// If we got a new tag, let's go!
			if ( $async_tag && $tag !== $async_tag ) {
				// Run it through for preloading if possible.
				$async_tag = $this->preload_css( $async_tag, $tag );
				$this->debug_message( 'tag altered, replacing' . trim( $async_tag ) );
				return $async_tag . '<noscript>' . trim( $tag ) . "</noscript>\n";
			}
		}
		return $tag;
	}

	/**
	 * Modify an async link/CSS tag for preloading.
	 *
	 * @param string $async_tag The async version of a <link...> tag.
	 * @param string $tag The original version of a <link...> tag.
	 * @return string The tag with a preloader added, if applicable.
	 */
	function preload_css( $async_tag, $tag ) {
		if ( false !== strpos( $tag, "rel='stylesheet'" ) ) {
			$allowed_to_preload = array(
				'avada-styles/',              // Avada dynamic CSS.
				'bb-plugin/cache/',           // Beaver Builder dynamic CSS.
				'bb-plugin/css/',             // Beaver Builder plugin CSS.
				'brizy/public/',              // Brizy dynamic CSS.
				'brizy-pro/public/',          // Brizy Pro dynamic CSS.
				'dist/block-library/',        // Gutenberg (stock WP) CSS.
				'build/block-library/',       // Gutenberg (plugin) CSS.
				'elementor/assets/css',       // Elementor plugin stock CSS.
				'elementor/css',              // Elementor dynamic CSS.
				'elementor-pro/assets/css',   // Elementor Pro stock CSS.
				'fusion-builder/',            // Avada Builder stock CSS.
				'fusion-core/',               // Avada Core stock CSS.
				'fusion-styles/',             // Avada dynamic CSS.
				'generateblocks/style',       // GenerateBlocks dynamic CSS.
				'component-framework/oxygen', // Oxygen plugin CSS.
				'/oxygen/css/',               // Oxygen dynamic CSS.
				'siteorigin-panels/css/',     // SiteOrigin plugin CSS.
				'td-composer/assets',         // TagDiv Composer (builder from Newspaper Theme).
				'wp-content/themes/',         // Theme CSS.
			);
			$allowed_to_preload = apply_filters( 'swis_defer_css_preload_list', $allowed_to_preload );
			foreach ( $allowed_to_preload as $allowed ) {
				if ( empty( $allowed ) ) {
					continue;
				}
				if ( false !== strpos( $tag, $allowed ) ) {
					$async_tag = str_replace( array( " rel='stylesheet'", " id='" ), array( " rel='preload' as='style'", " data-id='" ), $tag ) . $async_tag;
				}
			}
		}
		return $async_tag;
	}
}
