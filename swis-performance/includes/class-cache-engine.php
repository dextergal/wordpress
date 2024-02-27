<?php
/**
 * SWIS Cache Engine
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caching Engine for both delivering and storing cache files.
 */
final class Cache_Engine {

	/**
	 * Engine status.
	 *
	 * @var bool $started
	 */
	public static $started = false;

	/**
	 * Specific HTTP request headers from current request.
	 *
	 * @var array $request_headers
	 */
	public static $request_headers = array();

	/**
	 * Caching settings.
	 *
	 * @var array $settings
	 */
	public static $settings = array();

	/**
	 * Request URI.
	 *
	 * @var string $request_uri
	 */
	public static $request_uri = '';

	/**
	 * Start the cache engine.
	 *
	 * @return bool True if engine started, false otherwise.
	 */
	public static function start() {
		self::get_request_uri();
		if ( self::should_start() ) {
			new self();
		}
		return self::$started;
	}

	/**
	 * Constructor, does the actual work of firing things up, called by self::start().
	 */
	public function __construct() {
		// Get the request headers.
		self::$request_headers = self::get_request_headers();

		// Get settings from disk if core WP index file.
		if ( self::is_index() ) {
			self::$settings = Disk_Cache::get_settings();
			// Get settings from database otherwise (in late start).
		} elseif ( class_exists( '\SWIS\Cache' ) ) {
			self::$settings = swis()->cache->get_settings();
		}
		self::get_request_uri();

		// Set engine status.
		if ( ! empty( self::$settings ) ) {
			self::debug_message( 'cache engine started' );
			self::$started = true;
		} else {
			self::debug_message( 'cache settings missing' );
		}
	}

	/**
	 * Adds information to the in-memory debug log (wrapper for static class).
	 *
	 * @param string $message Debug information to add to the log.
	 */
	public static function debug_message( $message ) {
		if ( function_exists( 'swis' ) && class_exists( '\SWIS\Cache' ) && is_object( swis()->cache ) ) {
			swis()->cache->debug_message( $message );
		}
	}

	/**
	 * Check if engine should start.
	 *
	 * @return bool True if engine should start, false otherwise.
	 */
	public static function should_start() {
		self::debug_message( __METHOD__ );
		// Check if engine is running already.
		if ( self::$started ) {
			self::debug_message( 'cache engine already running' );
			return false;
		}

		// Check if AJAX request in early start.
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX && ! class_exists( '\SWIS\Cache' ) ) {
			self::debug_message( 'AJAX request, and cache class does not exist yet' );
			return false;
		}

		// Check if REST API request.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			self::debug_message( 'rest request, not starting' );
			return false;
		}

		// Check if XMLRPC request.
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			self::debug_message( 'xmlrpc request, not starting' );
			return false;
		}

		// Check request URI.
		if ( self::$request_uri && str_replace( array( '.ico', '.txt', '.xml', '.xsl', '.map' ), '', self::$request_uri ) !== self::$request_uri ) {
			self::debug_message( 'disallowed file extension, not starting' );
			return false;
		}

		return true;
	}

	/**
	 * Start output buffering.
	 */
	public static function start_buffering() {
		self::debug_message( 'cache buffer starting' );
		ob_start( 'self::end_buffering' );
	}

	/**
	 * End output buffering and cache page if applicable.
	 *
	 * @param string $contents  Contents of a page from the output buffer.
	 * @param int    $phase Bitmask of PHP_OUTPUT_HANDLER_* constants.
	 * @return string Content of a page from the output buffer.
	 */
	private static function end_buffering( $contents, $phase ) {
		if ( $phase & PHP_OUTPUT_HANDLER_FINAL || $phase & PHP_OUTPUT_HANDLER_END ) {
			if ( self::is_cacheable( $contents ) && ! self::bypass_cache() ) {
				Disk_Cache::cache_page( $contents );
			}
		}
		return $contents;
	}

	/**
	 * Remove any unexpected characters from a given HTTP request header.
	 *
	 * @param string $header The value of an HTTP request header.
	 * @return string The sanitized and unslashed header value.
	 */
	public static function sanitize_header( $header ) {
		$header = trim( preg_replace( '#[^\w\s/=;+,:\*\.\(\)-]#', '', stripslashes( $header ) ) );
		return $header;
	}

	/**
	 * Get needed HTTP request headers from current request.
	 *
	 * @return array A list of HTTP request headers from this request.
	 */
	private static function get_request_headers() {
		$request_headers = ( function_exists( 'apache_request_headers' ) ) ? apache_request_headers() : array();

		$request_headers = array(
			'Accept'             => ( isset( $request_headers['Accept'] ) ) ? $request_headers['Accept'] : ( ( isset( $_SERVER['HTTP_ACCEPT'] ) ) ? $_SERVER['HTTP_ACCEPT'] : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'Accept-Encoding'    => ( isset( $request_headers['Accept-Encoding'] ) ) ? $request_headers['Accept-Encoding'] : ( ( isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'Host'               => ( isset( $request_headers['Host'] ) ) ? $request_headers['Host'] : ( ( isset( $_SERVER['HTTP_HOST'] ) ) ? $_SERVER['HTTP_HOST'] : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'If-Modified-Since'  => ( isset( $request_headers['If-Modified-Since'] ) ) ? $request_headers['If-Modified-Since'] : ( ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'User-Agent'         => ( isset( $request_headers['User-Agent'] ) ) ? $request_headers['User-Agent'] : ( ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) ? $_SERVER['HTTP_USER_AGENT'] : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'X-Forwarded-Proto'  => ( isset( $request_headers['X-Forwarded-Proto'] ) ) ? $request_headers['X-Forwarded-Proto'] : ( ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'X-Forwarded-Scheme' => ( isset( $request_headers['X-Forwarded-Scheme'] ) ) ? $request_headers['X-Forwarded-Scheme'] : ( ( isset( $_SERVER['HTTP_X_FORWARDED_SCHEME'] ) ) ? $_SERVER['HTTP_X_FORWARDED_SCHEME'] : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);

		// Clean things up just to be safe, runs the headers through stripslashes and a very restrictive whitelist of characters.
		array_walk( $request_headers, array( 'self', 'sanitize_header' ) );
		return $request_headers;
	}

	/**
	 * Get request URI.
	 */
	public static function get_request_uri() {
		if ( self::$request_uri ) {
			return;
		}
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			self::$request_uri = trim( stripslashes( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
	}

	/**
	 * Check if installation directory index.
	 *
	 * @return  bool  true if installation directory index, false otherwise
	 */
	private static function is_index() {
		if ( isset( $_SERVER['SCRIPT_NAME'] ) && strtolower( basename( $_SERVER['SCRIPT_NAME'] ) ) === 'index.php' ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return true;
		}
		self::debug_message( 'not from index.php' );
		// NOTE: Only keeping this here if we need to debug something.
		if ( false && isset( $_SERVER['SCRIPT_NAME'] ) ) {
			self::debug_message( 'from ' . stripslashes( $_SERVER['SCRIPT_NAME'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		return false;
	}

	/**
	 * Check if page can be cached.
	 *
	 * @param string $contents Contents of a page from the output buffer.
	 * @return bool True if page contents are cacheable, false otherwise.
	 */
	private static function is_cacheable( $contents ) {
		$has_html_tag       = ( stripos( $contents, '<html' ) !== false );
		$has_html5_doctype  = preg_match( '/^<!DOCTYPE.+html\s*>/i', ltrim( $contents ) );
		$has_xsl_stylesheet = ( stripos( $contents, '<xsl:stylesheet' ) !== false || stripos( $contents, '<?xml-stylesheet' ) !== false );

		if ( $has_html_tag ) {
			self::debug_message( 'has html tag' );
		}
		if ( $has_html5_doctype ) {
			self::debug_message( 'has html DOCTYPE' );
		}
		if ( $has_xsl_stylesheet ) {
			self::debug_message( 'has xsl/xml stylesheet' );
		}
		if ( $has_html_tag && $has_html5_doctype && ! $has_xsl_stylesheet ) {
			return true;
		}

		return false;
	}

	/**
	 * Check permalink structure.
	 *
	 * @return bool True if request URI does not match permalink structure or if plain, false otherwise.
	 */
	private static function is_wrong_permalink_structure() {
		if ( empty( self::$settings ) ) {
			self::debug_message( 'could not check permalink structure, no settings' );
			return false;
		}
		// Check if trailing slash is set and missing (ignoring root index and file extensions).
		if ( 'has_trailing_slash' === self::$settings['permalink_structure'] ) {
			if ( self::$request_uri && preg_match( '/\/[^\.\/\?]+(\?.*)?$/', self::$request_uri ) ) {
				self::debug_message( 'should have trailing slash, but does not' );
				return true;
			}
		}

		// Check if trailing slash is not set and appended (ignoring root index and file extensions).
		if ( 'no_trailing_slash' === self::$settings['permalink_structure'] ) {
			if ( self::$request_uri && preg_match( '/\/[^\.\/\?]+\/(\?.*)?$/', self::$request_uri ) ) {
				self::debug_message( 'should not have trailing slash, but does' );
				return true;
			}
		}

		// Check if custom permalink structure is not set.
		if ( 'plain' === self::$settings['permalink_structure'] ) {
			self::debug_message( 'plain permalinks, no good' );
			return true;
		}

		return false;
	}

	/**
	 * Check if page is excluded from cache.
	 *
	 * @return bool True if page is excluded from the cache, false otherwise.
	 */
	private static function is_excluded() {
		self::debug_message( __METHOD__ );
		// If page path is excluded.
		if ( ! empty( self::$settings['exclusions'] ) && is_array( self::$settings['exclusions'] ) ) {
			$uri = parse_url( self::$request_uri, PHP_URL_PATH );
			foreach ( self::$settings['exclusions'] as $exclusion ) {
				if ( $exclusion && false !== strpos( $uri, $exclusion ) ) {
					self::debug_message( "$uri is excluded by $exclusion" );
					return true;
				}
			}
		}

		// If query string excluded.
		if ( ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// Set regex matching query strings that should bypass the cache.
			if ( ! empty( self::$settings['excluded_query_strings'] ) ) {
				$query_string_regex = self::$settings['excluded_query_strings'];
			} else {
				$query_string_regex = '/^(?!(fbclid|ref|mc_(cid|eid)|utm_(source|medium|campaign|term|content|expid)|gclid|fb_(action_ids|action_types|source)|age-verified|usqp|cn-reloaded|_ga|_ke)).+$/';
			}

			$query_string = parse_url( self::$request_uri, PHP_URL_QUERY );

			if ( preg_match( $query_string_regex, $query_string ) ) {
				self::debug_message( 'excluded by query string regex' );
				return true;
			}
		}

		// If cookie excluded.
		if ( ! empty( $_COOKIE ) ) {
			// Set regex matching cookies that should bypass the cache.
			if ( ! empty( self::$settings['excluded_cookies'] ) ) {
				$cookies_regex = self::$settings['excluded_cookies'];
			} else {
				$cookies_regex = '/^(wp-postpass|wordpress_logged_in|comment_author|edd_cart_messages|edd_items_in_cart|woocommerce_items_in_cart|wp_woocommerce_session)/';
			}
			// Bypass cache if an excluded cookie is found.
			foreach ( $_COOKIE as $key => $value ) {
				if ( preg_match( $cookies_regex, $key ) ) {
					self::debug_message( 'excluded by cookie regex' );
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if this is a search page.
	 *
	 * @return bool True if it is, false if it ain't.
	 */
	private static function is_search() {
		if ( apply_filters( 'swis_cache_exclude_search', is_search() ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Check if cache should be bypassed.
	 *
	 * @return bool True if cache should be bypassed, false otherwise.
	 */
	private static function bypass_cache() {
		// Bypass cache hook.
		if ( apply_filters( 'bypass_cache', false ) || apply_filters( 'swis_bypass_cache', false ) ) {
			self::debug_message( 'bypassed by filter' );
			return true;
		}

		// Check request method.
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			self::debug_message( 'bypassed because request_method !== GET' );
			return true;
		}

		// Check HTTP status code.
		if ( http_response_code() !== 200 ) {
			self::debug_message( 'bypassed because http response code is ' . http_response_code() );
			return true;
		}

		// Check DONOTCACHEPAGE constant.
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			self::debug_message( 'bypassed via DONOTCACHEPAGE' );
			return true;
		}

		// Check conditional tags.
		if ( self::is_wrong_permalink_structure() || self::is_excluded() ) {
			self::debug_message( 'bypassed by permalink check or exclusion' );
			return true;
		}

		global $wp_query;
		// Check conditional tags when output buffering has ended.
		if ( class_exists( 'WP' ) ) {
			if ( is_admin() || ! isset( $wp_query ) || self::is_search() || is_feed() || is_trackback() || is_robots() || is_preview() || is_customize_preview() || post_password_required() ) {
				if ( is_admin() ) {
					self::debug_message( 'bypassed for is_admin()' );
				}
				if ( self::is_search() ) {
					self::debug_message( 'bypassed for is_search()' );
				}
				if ( is_feed() ) {
					self::debug_message( 'bypassed for is_feed()' );
				}
				if ( is_trackback() ) {
					self::debug_message( 'bypassed for is_trackback()' );
				}
				if ( is_robots() ) {
					self::debug_message( 'bypassed for is_robots()' );
				}
				if ( is_preview() ) {
					self::debug_message( 'bypassed for is_preview()' );
				}
				if ( is_customize_preview() ) {
					self::debug_message( 'bypassed for is_customize_preview()' );
				}
				if ( post_password_required() ) {
					self::debug_message( 'bypassed for post_password_required()' );
				}
				return true;
			}
		}

		return false;
	}


	/**
	 * Deliver cache.
	 *
	 * @return bool False if cached page was not delivered. Dies otherwise.
	 */
	public static function deliver_cache() {
		$cache_file = Disk_Cache::get_cache_file();
		if ( Disk_Cache::cache_exists( $cache_file ) && ! Disk_Cache::cache_expired( $cache_file ) && ! self::bypass_cache() ) {
			header( 'X-Cache-Handler: swis-cache-engine' );

			// Check modified since with cached file and return 304 if no difference.
			if ( ! empty( self::$request_headers['If-Modified-Since'] ) && strtotime( self::$request_headers['If-Modified-Since'] >= filemtime( $cache_file ) ) ) {
				header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? stripslashes( $_SERVER['SERVER_PROTOCOL'] ) : 'HTTP/1.1' ) . ' 304 Not Modified', true, 304 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				exit;
			}

			readfile( $cache_file );
			exit;
		}
		return false;
	}
}
