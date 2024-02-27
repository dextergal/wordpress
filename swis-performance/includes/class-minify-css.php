<?php
/**
 * Class and methods to minify CSS.
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
 * Enables plugin to filter CSS tags and minify the stylesheets.
 */
final class Minify_CSS extends Page_Parser {

	/**
	 * A list of user-defined exclusions, populated by validate_user_exclusions().
	 *
	 * @access protected
	 * @var array $user_exclusions
	 */
	protected $user_exclusions = array();

	/**
	 * The directory to store the minifed CSS files.
	 *
	 * @access protected
	 * @var string $cache_dir
	 */
	protected $cache_dir = '';

	/**
	 * Register actions and filters for CSS Minify.
	 */
	function __construct() {
		if ( ! $this->get_option( 'minify_css' ) ) {
			return;
		}
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			return;
		}
		parent::__construct();
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		$uri = add_query_arg( null, null );
		$this->debug_message( "request uri is $uri" );

		/**
		 * Allow pre-empting CSS minify by page.
		 *
		 * @param bool Whether to skip parsing the page.
		 * @param string $uri The URL of the page.
		 */
		if ( apply_filters( 'swis_skip_css_minify_by_page', false, $uri ) ) {
			return;
		}

		$this->cache_dir = $this->content_dir . 'cache/css/';
		if ( ! is_dir( $this->cache_dir ) ) {
			if ( ! wp_mkdir_p( $this->cache_dir ) ) {
				add_action( 'admin_notices', array( $this, 'requirements_failed' ) );
				return;
			}
		}
		if ( ! is_writable( $this->cache_dir ) ) {
			add_action( 'admin_notices', array( $this, 'requirements_failed' ) );
			return;
		}
		$this->cache_dir_url = $this->content_url . 'cache/css/';

		// Overrides for user exclusions.
		add_filter( 'swis_skip_css_minify', array( $this, 'skip_css_minify' ), 10, 2 );

		// Get all the stylesheet URLs and minify them (if necessary).
		add_filter( 'style_loader_src', array( $this, 'minify_styles' ) );
		add_filter( 'swis_elements_link_href', array( $this, 'minify_styles' ) );

		// Minify any custom CSS.
		add_filter( 'wp_get_custom_css', array( $this, 'minify_raw_css' ), 20 );

		$this->validate_user_exclusions();
	}

	/**
	 * Display a notice that the plugin requirements check failed.
	 */
	public function requirements_failed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		// Permission check, can't do much without a writable cache directory.
		echo '<div class="notice notice-warning"><p>' .
			sprintf(
				/* translators: 1: SWIS Performance 2: 755 3: wp-content/swis/ 4: file permissions */
				esc_html__( '%1$s requires write permissions (%2$s) in the %3$s directory. Please change the %4$s or set SWIS_CONTENT_DIR to a writable location.', 'swis-performance' ),
				'<strong>SWIS Performance</strong>',
				'<code>755</code>',
				'<code>wp-content/swis/</code>',
				'<a href="https://wordpress.org/support/article/changing-file-permissions/" target="_blank">' . esc_html__( 'file permissions', 'swis-performance' ) . '</a>'
			) .
		'</p></div>';
	}

	/**
	 * Validate the user-defined exclusions.
	 */
	function validate_user_exclusions() {
		$this->user_exclusions = array(
			'.min.',
			'.min-',
			'-min.',
			'autoptimize',
			'/bb-plugin/cache/',
			'brizy/public/editor',
			'/cache/et/',
			'/cache/min/',
			'/cache/wpfc',
			'/comet-cache/',
			'cornerstone/assets/',
			'Divi/includes/builder/',
			'/Divi/style.css',
			'/et-cache/',
			'jch-optimize',
			'/siteground-optimizer-assets/',
		);

		$user_exclusions = $this->get_option( 'minify_css_exclude' );
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
	 * @param string  $url The script URL.
	 * @return boolean True to skip the resource, unchanged otherwise.
	 */
	function skip_css_minify( $skip, $url ) {
		if ( $this->user_exclusions ) {
			foreach ( $this->user_exclusions as $exclusion ) {
				if ( false !== strpos( $url, $exclusion ) ) {
					$this->debug_message( __METHOD__ . "(); excluded $url via $exclusion" );
					return true;
				}
			}
		}
		return $skip;
	}

	/**
	 * Purge CSS cache.
	 */
	function purge_cache() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$this->clear_dir( $this->cache_dir );
	}

	/**
	 * Get the absolute path from a URL.
	 *
	 * @param string $url The resource URL to translate to a local path.
	 * @return string|bool The file path, if found, or false.
	 */
	function get_local_path( $url ) {
		// Need to strip query strings.
		$url_parts = explode( '?', $url );
		if ( ! is_array( $url_parts ) || empty( $url_parts[0] ) ) {
			return false;
		}
		$url  = $url_parts[0];
		$file = $this->url_to_path_exists( $url );
		if ( ! $file ) {
			$local_domain = $this->parse_url( $this->content_url, PHP_URL_HOST );
			if ( false === strpos( $url, $local_domain ) ) {
				$remote_domain      = $this->parse_url( $url, PHP_URL_HOST );
				$possible_local_url = str_replace( $remote_domain, $local_domain, $url );
				$file               = $this->url_to_path_exists( $possible_local_url );
			}
		}
		return $file;
	}

	/**
	 * Build a path to cache the file on disk after minification.
	 *
	 * @param string $file The absolute path to the original file.
	 * @param string $mod_time The modification time of the file.
	 * @return string The location to store the minified file in the cache.
	 */
	function get_cache_path( $file, $mod_time ) {
		if ( 0 === strpos( $file, WP_CONTENT_DIR ) ) {
			$path_to_keep = str_replace( WP_CONTENT_DIR, '', $file );
		} elseif ( 0 === strpos( $file, ABSPATH ) ) {
			$path_to_keep = str_replace( ABSPATH, '', $file );
		} else {
			return false;
		}
		$extension = pathinfo( $file, PATHINFO_EXTENSION );
		if ( $extension && $mod_time ) {
			$path_to_keep = preg_replace( "/\.$extension/", "-$mod_time.min.$extension", $path_to_keep );
		} elseif ( $mod_time ) {
			$path_to_keep = $path_to_keep . "-$mod_time.min.css";
		}
		$cache_file = $this->cache_dir . ltrim( $path_to_keep, '/\\' );
		$cache_dir  = dirname( $cache_file );
		if ( ! is_dir( $cache_dir ) ) {
			if ( ! wp_mkdir_p( $cache_dir ) ) {
				return false;
			}
		}
		if ( ! is_writable( $cache_dir ) ) {
			return false;
		}
		return $cache_file;
	}

	/**
	 * Build a URL for the cached file.
	 *
	 * @param string $file The absolute path to the original file.
	 * @param string $mod_time The modification time of the file.
	 * @param string $query_string The query string from the original URL. If none, defaults to null.
	 * @return string The location to store the minified file in the cache.
	 */
	function get_cache_url( $file, $mod_time, $query_string = null ) {
		if ( 0 === strpos( $file, WP_CONTENT_DIR ) ) {
			$path_to_keep = str_replace( WP_CONTENT_DIR, '', $file );
		} elseif ( 0 === strpos( $file, ABSPATH ) ) {
			$path_to_keep = str_replace( ABSPATH, '', $file );
		} else {
			return false;
		}
		$extension = pathinfo( $file, PATHINFO_EXTENSION );
		if ( $extension && $mod_time ) {
			$path_to_keep = preg_replace( "/\.$extension/", "-$mod_time.min.$extension", $path_to_keep );
		} elseif ( $mod_time ) {
			$path_to_keep = $path_to_keep . "-$mod_time.min.css";
		}
		$cache_url = $this->cache_dir_url . ltrim( $path_to_keep, '/\\' ) . ( $query_string ? "?$query_string" : '' );
		return $cache_url;
	}

	/**
	 * Minifies scripts if necessary.
	 *
	 * @param string $url URL to the script.
	 * @return string The minified URL for the resource, if it was allowed.
	 */
	function minify_styles( $url ) {
		if ( ! $this->is_frontend() ) {
			return $url;
		}
		if ( apply_filters( 'swis_skip_css_minify', false, $url ) ) {
			return $url;
		}
		if ( ! $this->function_exists( 'filemtime' ) ) {
			return $url;
		}
		$file = $this->get_local_path( $url );
		if ( ! $file ) {
			return $url;
		}
		$mod_time   = filemtime( $file );
		$cache_file = $this->get_cache_path( $file, $mod_time );
		$cache_url  = $this->get_cache_url( $file, $mod_time, $this->parse_url( $url, PHP_URL_QUERY ) );
		if ( $cache_file && ! $this->is_file( $cache_file ) ) {
			$minifier = new Minify\CSS( $file );
			$minifier->minify( $cache_file );
		}
		if ( $this->is_file( $cache_file ) && ! empty( $cache_url ) ) {
			return $cache_url;
		}
		return $url;
	}

	/**
	 * Minifies raw CSS code.
	 *
	 * @param string $css CSS to minify.
	 * @return string The minified CSS.
	 */
	function minify_raw_css( $css ) {
		if ( ! $this->is_frontend() ) {
			return $css;
		}
		if ( ! empty( $css ) ) {
			$minifier = new Minify\CSS( $css );
			$new_css  = $minifier->minify();
			if ( ! empty( $new_css ) ) {
				$css = $new_css;
			}
		}
		return $css;
	}
}
