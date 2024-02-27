<?php
/**
 * Class and methods to optimize third-party fonts.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;
use MatthiasMullie\Minify;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enables plugin to inline and optimize fonts.
 */
final class Optimize_Fonts extends Base {

	/**
	 * The list of CSS (font) assets and associated information.
	 *
	 * @var array
	 */
	private $assets = array();

	/**
	 * Register actions and filters for font optimization.
	 */
	function __construct() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! $this->get_option( 'optimize_fonts' ) ) {
			return;
		}
		parent::__construct();
		if ( ! is_admin() ) {
			$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
			if ( current_user_can( $permissions ) && ! $this->get_option( 'optimize_fonts_css' ) ) {
				// Auto-detect fonts and save the CSS code/handles to the db.
				add_action( 'wp_head', array( $this, 'find_assets' ), 9999 );
				add_action( 'wp_footer', array( $this, 'find_assets' ), 9999 );
				add_action( 'wp_footer', array( $this, 'stash_css' ), 10000 );
			}
			if ( $this->get_option( 'optimize_fonts_css' ) ) {
				add_action( 'wp', array( $this, 'disable_assets' ) );
				add_action( 'wp_head', array( $this, 'inline_font_css' ) );
			}
		}
	}

	/**
	 * Inlines the font CSS in the <head>.
	 */
	function inline_font_css() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$font_css = $this->get_option( 'optimize_fonts_css' );
		if ( empty( $font_css ) || ! is_string( $font_css ) ) {
			return;
		}
		$minifier = new Minify\CSS( $font_css );
		echo "<style id='swis-font-css'>\n" . wp_kses( $minifier->minify(), 'strip' ) . "\n</style>\n";
	}

	/**
	 * Check if an asset is from an external site.
	 *
	 * @param string $url The asset URL.
	 * @return bool True for external asset, false for local asset.
	 */
	function is_external( $url ) {
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			return false;
		}
		$asset_url_parts = $this->parse_url( $url );
		$local_url_parts = $this->parse_url( get_site_url() );
		if ( ! empty( $asset_url_parts['host'] ) && ! empty( $local_url_parts['host'] ) && 0 === strcasecmp( $asset_url_parts['host'], $local_url_parts['host'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Get the contents of a CSS file.
	 *
	 * @param string $url The asset URL.
	 * @return string The CSS contents, but only if it consists solely of Google Font data.
	 */
	function get_font_css( $url ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$css      = '';
		$url_bits = explode( '?', $url );

		$site_url    = get_site_url();
		$site_domain = $this->parse_url( $site_url, PHP_URL_HOST );
		if ( false !== strpos( $url, $site_domain ) ) {
			$this->debug_message( "found $site_domain, replacing in $url" );
			$asset_path = trailingslashit( ABSPATH ) . str_replace( trailingslashit( $site_url ), '', $this->prepend_url_scheme( $url_bits[0] ) );
		} elseif ( '/' === substr( $url, 0, 1 ) && '/' !== substr( $url, 1, 1 ) ) {
			// Handle relative URLs like /wp-includes/css/something.css.
			$asset_path = ABSPATH . ltrim( $url, '/' );
		} else {
			// Check for CDN URLs by swapping domains.
			$asset_domain = $this->parse_url( $url, PHP_URL_HOST );
			// Swapping $asset_domain with $site_domain to get a local URL (possibly).
			$possible_url = str_replace( $asset_domain, $site_domain, $url );
			$this->debug_message( "swapped $asset_domain for $site_domain to find a file via $possible_url" );
			$url_bits   = explode( '?', $possible_url );
			$asset_path = trailingslashit( ABSPATH ) . str_replace( trailingslashit( $site_url ), '', $this->prepend_url_scheme( $url_bits[0] ) );
			$this->debug_message( "now we have $asset_path, we'll see if it is local" );
		}

		if ( $url !== $asset_path && is_file( $asset_path ) ) {
			$this->debug_message( "checking CSS from $asset_path" );
			$css = file_get_contents( $asset_path );
		} elseif ( strpos( $url, 'fonts.googleapis.com' ) ) {
			$url = add_query_arg( 'display', 'swap', $url );
			$this->debug_message( "getting CSS from $url" );
			$response = wp_remote_get( $url );
			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();
				$this->debug_message( "request for $url failed: $error_message (" . wp_remote_retrieve_response_code( $response ) . ')' );
				return $css;
			} elseif ( ! empty( $response['body'] ) ) {
				return $response['body'];
			}
			return $css;
		}
		// If there are no Google font URLs in the CSS, bail.
		if ( false === strpos( $css, 'fonts.gstatic.com' ) ) {
			$this->debug_message( "no Google Fonts in $url" );
			return '';
		}
		// Grok through the CSS for @font-face rules, and if there is anything extra, bail.
		$remaining_css = preg_replace( '/@font-face\s*?{[^}{]+?}/', '', $css );
		$minifier      = new Minify\CSS( $remaining_css );
		$remaining_css = $minifier->minify();
		if ( $remaining_css ) {
			$this->debug_message( "extra CSS found in $url" );
			return '';
		}
		$css = preg_replace( '/\s*?font-style:/', "\nfont-display: swap;\n    font-style:", $css );
		// At this point, we have a bit of CSS with nothing but @font-face rules, and confirmed Google font URLs.
		// Even if there are some local URLs, let's just inline them anyway.
		return $css;
	}
	/**
	 * Check to see which JS/CSS files have been registered for the current page.
	 */
	function find_assets() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$assets = wp_styles();

		foreach ( $assets->done as $handle ) {
			$url = $this->prepend_url_scheme( $assets->registered[ $handle ]->src );

			$asset = array(
				'url'      => $url,
				'external' => (int) $this->is_external( $url ),
			);

			$this->assets[ $handle ] = $asset;
		}
	}

	/**
	 * Make sure protocol-relative URLs like //www.example.com/wp-includes/script.js get a scheme added.
	 *
	 * @param string $url The URL to potentially fix.
	 * @return string The properly-schemed URL.
	 */
	function prepend_url_scheme( $url ) {
		if ( 0 === strpos( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http' ) . $url;
		}
		return $url;
	}

	/**
	 * Remove Google Font CSS files.
	 */
	function disable_assets() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$fonts_list = $this->get_option( 'optimize_fonts_list' );
		if ( $this->is_iterable( $fonts_list ) ) {
			foreach ( $fonts_list as $font_handle ) {
				swis()->slim->add_exclusion( $font_handle );
			}
		}
	}

	/**
	 * Go through the list of discovered CSS files for the current page, retrieve the font CSS, and record the CSS handles.
	 */
	function stash_css() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$font_css  = '';
		$font_list = array();
		if ( ! empty( $this->assets ) ) {
			foreach ( $this->assets as $handle => $asset ) {
				$css = '';
				if ( ! $asset['external'] ) {
					$css = $this->get_font_css( $asset['url'] );
					$this->debug_message( "retrieved CSS code for $handle with length: " . strlen( $css ) );
				} elseif ( strpos( $asset['url'], 'fonts.googleapis.com' ) ) {
					$css = $this->get_font_css( $asset['url'] );
					$this->debug_message( "retrieved CSS code for $handle with length: " . strlen( $css ) );
				} else {
					$css = $this->get_font_css( $asset['url'] );
					$this->debug_message( "retrieved CSS code for $handle with length: " . strlen( $css ) );
				}
				if ( ! empty( $css ) ) {
					$font_css   .= rtrim( $css ) . "\n";
					$font_list[] = $handle;
				}
			}
		}
		if ( $font_css && $font_list ) {
			$this->set_option( 'optimize_fonts_css', $font_css );
			$this->set_option( 'optimize_fonts_list', $font_list );
		}
	}
}
