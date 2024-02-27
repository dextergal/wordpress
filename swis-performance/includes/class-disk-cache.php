<?php
/**
 * Class and methods for disk caching engine.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SWIS_CONTENT_DIR' ) ) {
	define( 'SWIS_CONTENT_DIR', WP_CONTENT_DIR . '/swis/' );
}

/**
 * Disk Caching Engine.
 */
final class Disk_Cache {

	/**
	 * The cache directory.
	 *
	 * @var string $cache_dir
	 */
	public static $cache_dir = SWIS_CONTENT_DIR . 'cache/html';

	/**
	 * The settings directory.
	 *
	 * @var string $settings_dir
	 */
	public static $settings_dir = SWIS_CONTENT_DIR . 'cache/settings';

	/**
	 * List of directories that have been cleared.
	 *
	 * @var array
	 */
	private static $dir_cleared = array();

	/**
	 * Configure system files.
	 */
	public static function setup() {
		self::debug_message( __METHOD__ );
		// Add advanced-cache.php drop-in.
		copy( dirname( SWIS_PLUGIN_FILE ) . '/assets/advanced-cache.php', WP_CONTENT_DIR . '/advanced-cache.php' );

		self::set_wp_cache_constant();
	}

	/**
	 * Clean system files.
	 */
	public static function clean() {
		self::debug_message( __METHOD__ );
		// Delete settings file.
		self::delete_settings_file();

		// Check if settings directory exists.
		if ( ! is_dir( self::$settings_dir ) ) {
			// Delete old advanced cache settings file(s).
			array_map( 'unlink', glob( SWIS_CONTENT_DIR . 'cache/advcache-*.json' ) );
			// Delete advanced-cache.php drop-in file.
			if ( is_file( WP_CONTENT_DIR . '/advanced-cache.php' ) && is_writable( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
				unlink( WP_CONTENT_DIR . '/advanced-cache.php' );
			}
			// Unset WP_CACHE constant in config file if set by SWIS.
			self::set_wp_cache_constant( false );
		}
	}

	/**
	 * Adds information to the in-memory debug log (wrapper for static class).
	 *
	 * @param string $message Debug information to add to the log.
	 */
	public static function debug_message( $message ) {
		if ( function_exists( 'swis' ) && class_exists( '\SWIS\Cache' ) ) {
			swis()->cache->debug_message( $message );
		}
	}

	/**
	 * Check if file exists, and that it is local rather than using a protocol like http:// or phar://
	 *
	 * @param string $file The path of the file to check.
	 * @return bool True if the file exists and is local, false otherwise.
	 */
	public static function is_file( $file ) {
		if ( false !== strpos( $file, '://' ) ) {
			return false;
		}
		if ( false !== strpos( $file, 'phar://' ) ) {
			return false;
		}
		$file   = realpath( $file );
		$wp_dir = realpath( ABSPATH );
		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$upload_dir = wp_get_upload_dir();
			$upload_dir = realpath( $upload_dir['basedir'] );
		}

		$content_dir = realpath( WP_CONTENT_DIR );
		if ( empty( $content_dir ) ) {
			$content_dir = $wp_dir;
		}
		if ( empty( $upload_dir ) ) {
			$upload_dir = $content_dir;
		}
		$plugin_dir = ( ( defined( 'WP_PLUGIN_DIR' ) ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins' ) . '/swis-performance';
		if ( defined( 'SWIS_PLUGIN_FILE' ) ) {
			$plugin_dir = dirname( SWIS_PLUGIN_FILE );
		}
		$plugin_content_dir = $content_dir;
		if ( defined( 'SWIS_CONTENT_DIR' ) ) {
			$plugin_content_dir = realpath( SWIS_CONTENT_DIR );
		}
		if (
			false === strpos( $file, $upload_dir ) &&
			false === strpos( $file, $content_dir ) &&
			false === strpos( $file, $wp_dir ) &&
			false === strpos( $file, $plugin_dir ) &&
			false === strpos( $file, $plugin_content_dir )
		) {
			return false;
		}
		return is_file( $file );
	}

	/**
	 * A wrapper for PHP's parse_url, prepending assumed scheme for network path
	 * URLs. PHP versions 5.4.6 and earlier do not correctly parse without scheme.
	 *
	 * @param string  $url The URL to parse.
	 * @param integer $component Retrieve specific URL component.
	 * @return mixed Result of parse_url.
	 */
	public static function parse_url( $url, $component = -1 ) {
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( false === strpos( $url, 'http' ) && '/' !== substr( $url, 0, 1 ) ) {
			$url = ( is_ssl() ? 'https://' : 'http://' ) . $url;
		}
		// Because encoded ampersands in the filename break things.
		$url = str_replace( '&#038;', '&', $url );
		return parse_url( $url, $component );
	}

	/**
	 * Store a cached page.
	 *
	 * @param string $page_contents The content of the page.
	 */
	public static function cache_page( $page_contents ) {
		self::debug_message( __METHOD__ );

		$page_contents = apply_filters( 'swis_cache_page_contents_before_store', $page_contents );

		// Save the data to disk.
		self::create_cache_file( $page_contents );
	}

	/**
	 * Check if a cached page exists.
	 *
	 * @param string $cache_file File path to potential cache page.
	 * @return bool True if page exists, false otherwise.
	 */
	public static function cache_exists( $cache_file ) {
		return is_readable( $cache_file );
	}

	/**
	 * Check if asset is expired.
	 *
	 * @param string $cache_file File path to existing cache page.
	 * @return bool True if expired, false otherwise.
	 */
	public static function cache_expired( $cache_file ) {
		// Check if an expiry time is set.
		if ( 0 === Cache_Engine::$settings['expires'] ) {
			return false;
		}

		$expires_seconds = 3600 * Cache_Engine::$settings['expires'];

		// Check if the asset is beyond the expiration time.
		if ( ( filemtime( $cache_file ) + $expires_seconds ) <= time() ) {
			self::debug_message( 'cache expired' );
			return true;
		}

		return false;
	}

	/**
	 * Clear the whole cache.
	 *
	 * @param string $clear_url Full URL to potentially cached page.
	 * @param string $clear_type Clear the `pagination` or the entire `dir` instead of only the cached `page`.
	 */
	public static function clear_cache( $clear_url = null, $clear_type = 'page' ) {
		self::debug_message( __METHOD__ );
		// Check if complete cache should be cleared.
		if ( empty( $clear_url ) ) {
			self::debug_message( 'no URL to clear' );
			return;
		}

		self::debug_message( "clearing cache for $clear_url" );
		// Get cache directory for URL.
		$dir = self::get_cache_file_dir( $clear_url );

		if ( ! is_dir( $dir ) ) {
			self::debug_message( "no dir found for $clear_url" );
			return;
		}

		if ( 'dir' === $clear_type ) {
			$clear_type = 'subpages';
		}

		self::debug_message( microtime( true ) );
		// Check if pagination needs to be cleared.
		if ( 'subpages' === $clear_type ) {
			self::clear_dir( $dir );
		} else {
			self::debug_message( "clearing all files in $dir" );
			// Delete all cache files in directory.
			$skip_child_dir = true;
			self::clear_dir( $dir, $skip_child_dir );

			if ( 'pagination' === $clear_type ) {
				// Get pagination base.
				$pagination_base = $GLOBALS['wp_rewrite']->pagination_base;
				if ( strlen( $pagination_base ) > 0 ) {
					$pagination_dir = trailingslashit( $dir ) . $pagination_base;
					// Clear pagination page(s) cache.
					self::clear_dir( $pagination_dir );
				}
			}
		}

		foreach ( self::$dir_cleared as $dir => $dir_objects ) {
			if ( false !== strpos( $dir, self::$cache_dir ) ) {
				self::debug_message( "checking for hooks to fire with $dir" );
				if ( swis()->cache->fire_page_cache_cleared_hook ) {
					self::debug_message( 'page cache cleared hook is in play' );
					if ( ! empty( preg_grep( '/index/', $dir_objects ) ) ) {
						// Run the page cache cleared hook.
						$page_cleared_url = parse_url( home_url(), PHP_URL_SCHEME ) . '://' . str_replace( self::$cache_dir . '/', '', $dir );
						$page_cleared_id  = url_to_postid( $page_cleared_url );
						do_action( 'swis_cache_by_url_cleared', $page_cleared_url, $page_cleared_id );
					}
				} else {
					// Full cache cleared hook.
					if ( $dir === self::$cache_dir ) {
						do_action( 'swis_complete_cache_cleared' );
					}
					// NOTE: kept untrailingslashit(), just in case.
					if ( untrailingslashit( self::get_cache_file_dir( home_url() ) ) === $dir ) {
						$site_cleared_url = home_url();
						$site_cleared_id  = get_current_blog_id();
						do_action( 'swis_site_cache_cleared', $site_cleared_url, $site_cleared_id );
					}
				}
				unset( self::$dir_cleared[ $dir ] );
			}
		}

		// Remove the parent dir if empty.
		self::delete_parent_dir( $dir );
	}

	/**
	 * Clear a cache directory.
	 *
	 * @param string $dir The directory path.
	 * @param bool   $skip_child_dir  Whether or not child directories should be skipped.
	 */
	private static function clear_dir( $dir, $skip_child_dir = false ) {
		self::debug_message( __METHOD__ );
		$dir = untrailingslashit( $dir );
		self::debug_message( microtime( true ) );

		if ( ! is_dir( $dir ) ) {
			return;
		}
		if ( ! is_readable( $dir ) ) {
			return;
		}
		if ( ! is_writable( $dir ) ) {
			return;
		}

		self::debug_message( "clearing cache for $dir" );

		$dir_objects = self::get_dir_objects( $dir );

		foreach ( $dir_objects as $dir_object ) {
			// Create the full path.
			$dir_object = trailingslashit( $dir ) . $dir_object;

			if ( is_dir( $dir_object ) && ! $skip_child_dir ) {
				self::debug_message( "calling clear_dir to clear $dir_object" );
				self::clear_dir( $dir_object );
			} elseif ( is_file( $dir_object ) && is_writable( $dir_object ) ) {
				self::debug_message( "removing $dir_object" );
				unlink( $dir_object );
			}
		}

		// Add this folder to the list of directories cleared.
		self::$dir_cleared[ $dir ] = $dir_objects;

		// Doing this to make sure the directory is empty before we try and delete it.
		clearstatcache();
		$dir_objects = self::get_dir_objects( $dir );

		// If the directory is empty now. No need to do error suppression here.
		if ( empty( $dir_objects ) ) {
			rmdir( $dir );
		}
		clearstatcache();
	}


	/**
	 * Save the asset to disk.
	 *
	 * @param string $page_contents The HTML content to be stored.
	 */
	private static function create_cache_file( $page_contents ) {
		self::debug_message( __METHOD__ );

		// Make sure we actually have something to cache.
		if ( ! is_string( $page_contents ) || 0 === strlen( $page_contents ) ) {
			return;
		}

		// Setup cache file.
		$new_cache_file      = self::get_cache_file();
		$new_cache_file_dir  = dirname( $new_cache_file );
		$new_cache_file_name = basename( $new_cache_file );

		// Append the cache signature.
		$page_contents .= self::get_cache_signature( $new_cache_file_name );

		// Create WebP-supported file.
		if ( false !== strpos( $new_cache_file_name, 'webp' ) && ! class_exists( 'ExactDN' ) && class_exists( '\SWIS\Cache_WebP' ) ) {
			self::debug_message( 'creating a WebP variant' );
			$cache_webp    = new Cache_WebP();
			$page_contents = $cache_webp->filter_webp_html( $page_contents );
		}

		if ( ! wp_mkdir_p( $new_cache_file_dir ) ) {
			return;
		}

		if ( ! is_readable( $new_cache_file_dir ) ) {
			self::debug_message( "Cannot access cache directory: $new_cache_file_dir" );
			return;
		}
		if ( ! is_writable( $new_cache_file_dir ) ) {
			self::debug_message( "Cannot write to directory: $new_cache_file_dir" );
			return;
		}

		self::debug_message( "caching to $new_cache_file" );
		// Write the file to disk.
		file_put_contents( $new_cache_file, $page_contents, LOCK_EX );
		clearstatcache();

		// Set permissions on the cached asset.
		$new_cache_file_stats = stat( $new_cache_file_dir );
		$new_cache_file_perms = $new_cache_file_stats['mode'] & 0007777;
		$new_cache_file_perms = $new_cache_file_perms & 0000666;
		chmod( $new_cache_file, $new_cache_file_perms );
		clearstatcache();
	}

	/**
	 * Record settings for advanced-cache.php to disk.
	 *
	 * @param array $settings Settings as an associative array.
	 */
	public static function create_settings_file( $settings ) {
		self::debug_message( __METHOD__ );
		if ( ! is_array( $settings ) || ! function_exists( 'home_url' ) ) {
			return;
		}
		// Get location of settings file.
		$new_settings_file = self::get_settings_file();

		$new_settings_file_contents  = '<?php' . PHP_EOL;
		$new_settings_file_contents .= '/**' . PHP_EOL;
		$new_settings_file_contents .= ' * SWIS Cache settings for ' . home_url() . PHP_EOL;
		$new_settings_file_contents .= ' * @generated ' . self::get_current_time() . PHP_EOL;
		$new_settings_file_contents .= ' */' . PHP_EOL;
		$new_settings_file_contents .= PHP_EOL;
		$new_settings_file_contents .= 'return ' . var_export( $settings, true ) . ';';

		// Create folder if needed.
		if ( ! wp_mkdir_p( dirname( $new_settings_file ) ) ) {
			self::debug_message( 'unable to create settings directory' );
			wp_die( 'Unable to create directory: ' . esc_html( dirname( $new_settings_file ) ) );
		}
		if ( ! is_writable( dirname( $new_settings_file ) ) ) {
			self::debug_message( 'cannot write to settings directory' );
			wp_die( 'Cannot write to directory: ' . esc_html( dirname( $new_settings_file ) ) );
		}

		self::debug_message( "saving cache settings to $new_settings_file" );
		file_put_contents( $new_settings_file, $new_settings_file_contents, LOCK_EX );

		return $new_settings_file;
	}

	/**
	 * Get the path for a cache file.
	 *
	 * @return string The file path to a new or potentially cached page
	 */
	public static function get_cache_file() {
		$cache_file = sprintf(
			'%s/%s',
			self::get_cache_file_dir(),
			self::get_cache_file_name()
		);
		return $cache_file;
	}

	/**
	 * Get the directory/path to a cached asset.
	 *
	 * @param string $url The full URL.
	 * @return string The path to a cached asset.
	 */
	public static function get_cache_file_dir( $url = null ) {
		self::debug_message( __METHOD__ );
		$cache_file_dir = '';

		// Validate the given URL.
		if ( $url && ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return $cache_file_dir;
		}

		$cache_file_dir = sprintf(
			'%s%s%s%s',
			self::$cache_dir,
			DIRECTORY_SEPARATOR,
			( $url ) ? self::parse_url( $url, PHP_URL_HOST ) : strtolower( Cache_Engine::$request_headers['Host'] ),
			self::parse_url( ( $url ) ? $url : Cache_Engine::$request_uri, PHP_URL_PATH )
		);

		$cache_file_dir = rtrim( $cache_file_dir, '/\\' );
		self::debug_message( $cache_file_dir );
		return $cache_file_dir;
	}

	/**
	 * Get file path for a cached page.
	 *
	 * @return string Path to the cached HTML file.
	 */
	private static function get_cache_file_name() {
		self::debug_message( __METHOD__ );

		$cache_keys      = self::get_cache_keys();
		$cache_file_name = $cache_keys['scheme'] . 'index' . $cache_keys['device'] . $cache_keys['webp'] . '.html';

		return $cache_file_name;
	}

	/**
	 * Get cache keys for generating a cache filename.
	 *
	 * @return array The keys needed to build the filename.
	 */
	private static function get_cache_keys() {
		self::debug_message( __METHOD__ );
		// Set the default cache keys.
		$cache_keys = array(
			'scheme'      => 'http-',
			'device'      => '',
			'webp'        => '',
			'compression' => '',
		);

		// Set the scheme.
		if ( isset( $_SERVER['HTTPS'] ) && ( 'on' === strtolower( $_SERVER['HTTPS'] ) || '1' === (string) $_SERVER['HTTPS'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$cache_keys['scheme'] = 'https-';
		} elseif ( isset( $_SERVER['SERVER_PORT'] ) && '443' === (string) $_SERVER['SERVER_PORT'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$cache_keys['scheme'] = 'https-';
		} elseif ( 'https' === Cache_Engine::$request_headers['X-Forwarded-Proto'] || 'https' === Cache_Engine::$request_headers['X-Forwarded-Scheme'] ) {
			$cache_keys['scheme'] = 'https-';
		}

		if ( Cache_Engine::$settings['mobile'] ) {
			self::debug_message( 'mobile cache enabled' );
			if (
				strpos( Cache_Engine::$request_headers['User-Agent'], 'Mobile' ) !== false ||
				strpos( Cache_Engine::$request_headers['User-Agent'], 'Android' ) !== false ||
				strpos( Cache_Engine::$request_headers['User-Agent'], 'Silk/' ) !== false ||
				strpos( Cache_Engine::$request_headers['User-Agent'], 'Kindle' ) !== false ||
				strpos( Cache_Engine::$request_headers['User-Agent'], 'BlackBerry' ) !== false ||
				strpos( Cache_Engine::$request_headers['User-Agent'], 'Opera Mini' ) !== false ||
				strpos( Cache_Engine::$request_headers['User-Agent'], 'Opera Mobi' ) !== false
			) {
				self::debug_message( 'mobile device detected' );
				$cache_keys['device'] = '-mobile';
			}
		}

		if ( Cache_Engine::$settings['webp'] ) {
			self::debug_message( 'webp cache enabled' );
			if ( false !== strpos( Cache_Engine::$request_headers['Accept'], 'image/webp' ) ) {
				self::debug_message( 'webp support detected' );
				$cache_keys['webp'] = '-webp';
			} else {
				self::debug_message( Cache_Engine::$request_headers['Accept'] );
			}
		}

		return $cache_keys;
	}

	/**
	 * Create cache signature.
	 *
	 * @param string $cache_file_name Filename of cached page.
	 * @return string Cache signature string.
	 */
	private static function get_cache_signature( $cache_file_name ) {
		$cache_signature = sprintf(
			"\n<!-- %s @ %s (%s) -->",
			'SWIS Cache',
			self::get_current_time(),
			$cache_file_name
		);
		return $cache_signature;
	}

	/**
	 * Get the size of the cache.
	 *
	 * @param string $dir Path of the cache folder.
	 * @return int Size in bytes.
	 */
	public static function get_cache_size( $dir = null ) {
		$cache_size = 0;

		// Get a list of the files in a folder.
		if ( is_dir( $dir ) && is_readable( $dir ) ) {
			$dir_objects = self::get_dir_objects( $dir );
		} else {
			$dir_objects = self::get_site_objects( home_url() );
		}

		if ( empty( $dir_objects ) ) {
			return $cache_size;
		}

		foreach ( $dir_objects as $dir_object ) {
			// Create the full path.
			$dir_object = trailingslashit( ( $dir ) ? $dir : ( self::$cache_dir . DIRECTORY_SEPARATOR . parse_url( home_url(), PHP_URL_HOST ) . parse_url( home_url(), PHP_URL_PATH ) ) ) . $dir_object;

			if ( is_dir( $dir_object ) ) {
				$cache_size += self::get_cache_size( $dir_object );
			} elseif ( is_file( $dir_object ) && is_readable( $dir_object ) ) {
				$cache_size += filesize( $dir_object );
			}
		}

		return $cache_size;
	}

	/**
	 * Get settings file path.
	 *
	 * @param bool $fallback Whether or not fallback settings should be returned. Optional, defaults to false.
	 * @return string Path to the settings file.
	 */
	private static function get_settings_file( $fallback = false ) {
		$settings_file = sprintf(
			'%s/%s',
			self::$settings_dir,
			self::get_settings_file_name( $fallback )
		);

		return $settings_file;
	}

	/**
	 * Get settings file name.
	 *
	 * @param bool $fallback Whether or not fallback settings file name should be returned. Optional, defaults to false.
	 * @param bool $skip_blog_path Whether or not blog path should be included in settings file name. Optional, defaults to false.
	 * @return string File name for settings file.
	 */
	private static function get_settings_file_name( $fallback = false, $skip_blog_path = false ) {
		$settings_file_name = '';

		// If creating or deleting settings file.
		if ( function_exists( 'home_url' ) ) {
			$settings_file_name = self::parse_url( home_url(), PHP_URL_HOST );

			// Sub-directory network install.
			if ( is_multisite() && defined( 'SUBDOMAIN_INSTALL' ) && ! SUBDOMAIN_INSTALL ) {
				$blog_path           = swis()->cache->get_blog_path();
				$settings_file_name .= ( ! empty( $blog_path ) ) ? '.' . trim( $blog_path, '/' ) : '';
			}

			$settings_file_name .= '.php';
			// If getting settings from settings file.
		} elseif ( is_dir( self::$settings_dir ) ) {
			if ( $fallback ) {
				$settings_files      = self::get_dir_objects( self::$settings_dir );
				$settings_file_regex = '/\.php$/';

				if ( is_multisite() ) {
					$settings_file_regex = '/^' . strtolower( stripslashes( Cache_Engine::$request_headers['Host'] ) );
					$settings_file_regex = str_replace( '.', '\.', $settings_file_regex );

					// Sub-directory network install.
					if ( defined( 'SUBDOMAIN_INSTALL' ) && ! SUBDOMAIN_INSTALL && ! $skip_blog_path ) {
						$url_path = Cache_Engine::$request_uri;

						if ( ! empty( $url_path ) ) {
							$url_path_regex       = str_replace( '/', '|', $url_path );
							$url_path_regex       = '\.(' . $url_path_regex . ')';
							$settings_file_regex .= $url_path_regex;
						}
					}
					$settings_file_regex .= '\.php$/';
				}
				$filtered_settings_files = preg_grep( $settings_file_regex, $settings_files );

				if ( ! empty( $filtered_settings_files ) ) {
					$settings_file_name = current( $filtered_settings_files );
				} elseif ( is_multisite() && defined( 'SUBDOMAIN_INSTALL' ) && ! SUBDOMAIN_INSTALL && ! $skip_blog_path ) {
					$settings_file_name = self::get_settings_file_name( true, true );
				}
			} else {
				$settings_file_name = strtolower( Cache_Engine::$request_headers['Host'] );

				// Sub-directory network install.
				if ( is_multisite() && defined( 'SUBDOMAIN_INSTALL' ) && ! SUBDOMAIN_INSTALL && ! $skip_blog_path ) {
					$url_path        = Cache_Engine::$request_uri;
					$url_path_pieces = explode( '/', $url_path, 3 );
					$blog_path       = $url_path_pieces[1];

					if ( ! empty( $blog_path ) ) {
						$settings_file_name .= '.' . $blog_path;
					}

					$settings_file_name .= '.php';

					// Check if main site.
					if ( ! is_file( self::$settings_dir . '/' . $settings_file_name ) ) {
						$settings_file_name = self::get_settings_file_name( false, true );
					}
				}
				$settings_file_name .= ( strpos( $settings_file_name, '.php' ) === false ) ? '.php' : '';
			}
		}
		self::debug_message( "settings file at $settings_file_name" );
		return $settings_file_name;
	}

	/**
	 * Get settings from settings file.
	 *
	 * @return array Settings from the file.
	 */
	public static function get_settings() {
		$settings = array();

		$settings_file = self::get_settings_file();
		// Check if the settings file exists.
		if ( self::is_file( $settings_file ) && is_readable( $settings_file ) ) {
			$settings = include_once( $settings_file );
		} else {
			// Try to get fallback settings file otherwise.
			$fallback_settings_file = self::get_settings_file( true );

			if ( self::is_file( $fallback_settings_file ) && is_readable( $fallback_settings_file ) ) {
				$settings = include_once( $fallback_settings_file );
			}
		}

		// Create settings file if cache exists but settings file does not.
		if ( ( empty( $settings ) || ! is_array( $settings ) ) && class_exists( '\SWIS\Cache' ) ) {
			$new_settings_file = self::create_settings_file( swis()->cache->get_settings() );
			if ( self::is_file( $new_settings_file ) && is_readable( $new_settings_file ) ) {
				$settings = include_once( $new_settings_file );
			}
		}

		if ( is_array( $settings ) ) {
			foreach ( $settings as $name => $value ) {
				if ( defined( 'SWIS_CACHE_' . strtoupper( $name ) ) ) {
					$settings[ $name ] = constant( 'SWIS_CACHE_' . strtoupper( $name ) );
				}
			}
			return $settings;
		}
		return array();
	}

	/**
	 * Get all the files in a directory.
	 *
	 * @param string $dir The directory's path.
	 * @return array A list of files found.
	 */
	public static function get_dir_objects( $dir ) {
		self::debug_message( __METHOD__ );
		self::debug_message( microtime( true ) );
		if ( ! is_readable( $dir ) ) {
			return array();
		}
		// Scan the directory.
		$dir_objects = @scandir( $dir );

		if ( is_array( $dir_objects ) ) {
			$dir_objects = array_diff( $dir_objects, array( '..', '.' ) );
		} else {
			$dir_objects = array();
		}
		return $dir_objects;
	}

	/**
	 * Get site file system objects.
	 *
	 * @param string $site_url The site URL.
	 * @return array An array of site objects (files & dirs).
	 */
	public static function get_site_objects( $site_url ) {
		self::debug_message( __METHOD__ );
		$site_objects = array();

		// Get the directory for the given URL.
		$dir = self::get_cache_file_dir( $site_url );

		// Check if the directory exists.
		if ( ! is_dir( $dir ) ) {
			return $site_objects;
		}

		// Get site objects from $dir.
		$site_objects = self::get_dir_objects( $dir );

		// Cleanup sub-directory network site objects.
		if ( is_multisite() && ! is_subdomain_install() ) {
			$blog_path  = swis()->cache->get_blog_path();
			$blog_paths = swis()->cache->get_blog_paths();

			// Filter objects if this is the main site in a sub-directory network.
			if ( ! in_array( $blog_path, $blog_paths, true ) ) {
				foreach ( $site_objects as $key => $site_object ) {
					// Delete a site object if it does not belong to the main site.
					if ( in_array( '/' . $site_object . '/', $blog_paths, true ) ) {
						unset( $site_objects[ $key ] );
					}
				}
			}
		}

		return $site_objects;
	}

	/**
	 * Get the current time (formatted).
	 *
	 * @return string The current time in HTTP-date format.
	 */
	private static function get_current_time() {
		return current_time( 'D, d M Y H:i:s', true ) . ' GMT';
	}

	/**
	 * Set or unset WP_CACHE constant.
	 *
	 * @param bool $set True to set WP_CACHE constant in wp-config.php, false to unset.
	 */
	private static function set_wp_cache_constant( $set = true ) {
		self::debug_message( __METHOD__ );
		$wp_config_file = false;
		// Get wp-config.php file.
		if ( self::is_file( ABSPATH . 'wp-config.php' ) ) {
			// Config file resides in ABSPATH.
			$wp_config_file = ABSPATH . 'wp-config.php';
		} elseif ( self::is_file( dirname( ABSPATH ) . '/wp-config.php' ) && ! self::is_file( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			// Config file resides one level above ABSPATH but is not part of another installation.
			$wp_config_file = dirname( ABSPATH ) . '/wp-config.php';
		}

		// Check if config file is writable.
		if ( ! $wp_config_file || ! is_writable( $wp_config_file ) ) {
			return;
		}

		// Get config file contents.
		$wp_config_file_contents = file_get_contents( $wp_config_file );

		// Validate config file contents.
		if ( ! is_string( $wp_config_file_contents ) ) {
			return;
		}
		$wp_cache_line = "define( 'WP_CACHE', true ); // Added by SWIS Performance\n";

		$found_wp_cache_constant = preg_match( '/define\s*\(\s*[\'\"]WP_CACHE[\'\"]\s*,.+\);/', $wp_config_file_contents );
		if ( $set && ! $found_wp_cache_constant ) {
			$wp_config_file_contents = preg_replace( '/(<\?php\r?\n?)/i', "<?php\n$wp_cache_line", $wp_config_file_contents );
		} elseif ( $set && $found_wp_cache_constant && defined( 'WP_CACHE' ) && ! WP_CACHE ) {
			$wp_config_file_contents = preg_replace( '/define\s*\(\s*[\'\"]WP_CACHE[\'\"]\s*,.+\);.*\r?\n?/', "$wp_cache_line\n", $wp_config_file_contents );
		}

		if ( ! $set ) {
			$wp_config_file_contents = preg_replace( '/define.+Added by SWIS Performance\r?\n?/', '', $wp_config_file_contents );
		}

		file_put_contents( $wp_config_file, $wp_config_file_contents, LOCK_EX );
	}

	/**
	 * Delete an empty parent directory.
	 *
	 * @param string $dir Path of a directory.
	 */
	private static function delete_parent_dir( $dir ) {
		self::debug_message( __METHOD__ );
		self::debug_message( microtime( true ) );
		self::debug_message( "maybe deleting parent of $dir" );
		$parent_dir         = dirname( $dir );
		$parent_dir_objects = self::get_dir_objects( $parent_dir );

		if ( empty( $parent_dir_objects ) && is_writable( $parent_dir ) ) {
			rmdir( $parent_dir );
			self::debug_message( "removed parent $parent_dir" );

			// Add this folder to the list of directories cleared.
			self::$dir_cleared[ $parent_dir ] = $parent_dir_objects;

			self::delete_parent_dir( $parent_dir );
		}
	}

	/**
	 * Delete settings file for advanced-cache.php.
	 */
	private static function delete_settings_file() {
		self::debug_message( __METHOD__ );
		// Get location of settings file.
		$settings_file = self::get_settings_file();

		if ( ! self::is_file( $settings_file ) || ! is_writable( $settings_file ) ) {
			return;
		}

		unlink( $settings_file );

		$dir_objects = self::get_dir_objects( self::$settings_dir );

		if ( empty( $dir_objects ) ) {
			rmdir( self::$settings_dir );
		}
		clearstatcache();
	}
}
