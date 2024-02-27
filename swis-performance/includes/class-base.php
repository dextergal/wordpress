<?php
/**
 * Implements basic and common utility functions for all sub-classes.
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
 * HTML element and attribute parsing, replacing, etc.
 */
class Base {

	/**
	 * Content directory (URL) for the plugin to use.
	 *
	 * @access protected
	 * @var string $content_url
	 */
	protected $content_url = WP_CONTENT_URL . 'swis/';

	/**
	 * Content directory (path) for the plugin to use.
	 *
	 * @access protected
	 * @var string $content_dir
	 */
	protected $content_dir = WP_CONTENT_DIR . '/swis/';

	/**
	 * The debug buffer for the plugin.
	 *
	 * @access public
	 * @var string $debug
	 */
	public static $debug = '';

	/**
	 * Site (URL) for the plugin to use.
	 *
	 * @access public
	 * @var string $site_url
	 */
	public $site_url = '';

	/**
	 * Home (URL) for the plugin to use.
	 *
	 * @access public
	 * @var string $home_url
	 */
	public $home_url = '';

	/**
	 * Plugin version for the plugin.
	 *
	 * @access protected
	 * @var float $version
	 */
	protected $version = 0;

	/**
	 * Prefix to be used by plugin in option and hook names.
	 *
	 * @access protected
	 * @var string $prefix
	 */
	protected $prefix = 'swis_';

	/**
	 * Is media offload to S3 (or similar)?
	 *
	 * @access public
	 * @var bool $s3_active
	 */
	public $s3_active = false;

	/**
	 * Set class properties for children.
	 */
	function __construct() {
		$this->home_url          = trailingslashit( get_site_url() );
		$this->relative_home_url = preg_replace( '/https?:/', '', $this->home_url );
		$this->content_url       = content_url( 'swis/' );
		$this->version           = SWIS_PLUGIN_VERSION;
		if ( defined( 'SWIS_CONTENT_DIR' ) ) {
			$this->content_dir = SWIS_CONTENT_DIR;
			$this->content_url = str_replace( WP_CONTENT_DIR, WP_CONTENT_URL, $this->content_dir );
		}
	}

	/**
	 * Saves the in-memory debug log to a logfile in the plugin folder.
	 */
	function debug_log() {
		global $swis_temp_debug;
		$debug_log = $this->content_dir . 'debug.log';
		if ( ! is_dir( $this->content_dir ) && is_writable( WP_CONTENT_DIR ) ) {
			wp_mkdir_p( $this->content_dir );
		}
		$debug_enabled = $this->get_option( $this->prefix . 'debug' );
		if (
			! empty( self::$debug ) &&
			empty( $swis_temp_debug ) &&
			$debug_enabled &&
			is_dir( $this->content_dir ) &&
			is_writable( $this->content_dir )
		) {
			$memory_limit = $this->memory_limit();
			clearstatcache();
			$timestamp = gmdate( 'Y-m-d H:i:s' ) . "\n";
			if ( ! file_exists( $debug_log ) ) {
				touch( $debug_log );
			} else {
				if ( filesize( $debug_log ) + 4000000 + memory_get_usage( true ) > $memory_limit ) {
					unlink( $debug_log );
					touch( $debug_log );
				}
			}
			if ( filesize( $debug_log ) + strlen( self::$debug ) + 4000000 + memory_get_usage( true ) <= $memory_limit && is_writable( $debug_log ) ) {
				self::$debug = str_replace( '<br>', "\n", self::$debug );
				file_put_contents( $debug_log, $timestamp . self::$debug, FILE_APPEND );
			}
		}
		self::$debug = '';
	}

	/**
	 * Adds information to the in-memory debug log.
	 *
	 * @global bool   $swis_temp_debug Indicator that we are temporarily debugging on the wp-admin.
	 *
	 * @param string $message Debug information to add to the log.
	 */
	function debug_message( $message ) {
		if ( ! is_string( $message ) && ! is_int( $message ) && ! is_float( $message ) ) {
			return;
		}
		$message = "$message";
		if ( defined( 'WP_CLI' ) && WP_CLI && is_string( $message ) ) {
			\WP_CLI::debug( $message );
			return;
		}
		global $swis_temp_debug;
		if ( $swis_temp_debug || $this->get_option( $this->prefix . 'debug' ) ) {
			$memory_limit = $this->memory_limit();
			if ( strlen( $message ) + 4000000 + memory_get_usage( true ) <= $memory_limit ) {
				$message      = str_replace( "\n\n\n", '<br>', $message );
				$message      = str_replace( "\n\n", '<br>', $message );
				$message      = str_replace( "\n", '<br>', $message );
				self::$debug .= "$message<br>";
			} else {
				self::$debug = "not logging message, memory limit is $memory_limit";
			}
		}
	}

	/**
	 * Checks if a function is disabled or does not exist.
	 *
	 * @param string $function The name of a function to test.
	 * @param bool   $debug Whether to output debugging.
	 * @return bool True if the function is available, False if not.
	 */
	function function_exists( $function, $debug = false ) {
		if ( function_exists( 'ini_get' ) ) {
			$disabled = @ini_get( 'disable_functions' );
			if ( $debug ) {
				$this->debug_message( "disable_functions: $disabled" );
			}
		}
		if ( extension_loaded( 'suhosin' ) && function_exists( 'ini_get' ) ) {
			$suhosin_disabled = @ini_get( 'suhosin.executor.func.blacklist' );
			if ( $debug ) {
				$this->debug_message( "suhosin_blacklist: $suhosin_disabled" );
			}
			if ( ! empty( $suhosin_disabled ) ) {
				$suhosin_disabled = explode( ',', $suhosin_disabled );
				$suhosin_disabled = array_map( 'trim', $suhosin_disabled );
				$suhosin_disabled = array_map( 'strtolower', $suhosin_disabled );
				if ( function_exists( $function ) && ! in_array( $function, $suhosin_disabled, true ) ) {
					return true;
				}
				return false;
			}
		}
		return function_exists( $function );
	}

	/**
	 * Check for GD support.
	 *
	 * @return bool Debug True if GD support detected.
	 */
	function gd_support() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( function_exists( 'gd_info' ) ) {
			$gd_support = gd_info();
			$this->debug_message( 'GD found, supports:' );
			if ( $this->is_iterable( $gd_support ) ) {
				foreach ( $gd_support as $supports => $supported ) {
					$this->debug_message( "$supports: $supported" );
				}
				if ( ( ! empty( $gd_support['JPEG Support'] ) || ! empty( $gd_support['JPG Support'] ) ) && ! empty( $gd_support['PNG Support'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Retrieve option: use override/constant setting if defined, otherwise use 'blog' setting or $default.
	 *
	 * Overrides are only available for integer and boolean options.
	 *
	 * @param string $option_name The name of the option to retrieve.
	 * @param mixed  $default The default to use if not found/set, defaults to false, but not currently used.
	 * @param bool   $single Use single-site setting regardless of multisite activation. Default is off/false.
	 * @return mixed The value of the option.
	 */
	function get_option( $option_name, $default = false, $single = false ) {
		if ( 0 === strpos( $option_name, 'easyio_' ) && function_exists( 'easyio_get_option' ) ) {
			return easyio_get_option( $option_name );
		}
		if ( 0 === strpos( $option_name, 'ewww_image_optimizer_' ) && function_exists( 'ewww_image_optimizer_get_option' ) ) {
			return ewww_image_optimizer_get_option( $option_name, $default, $single );
		}
		if ( 0 === strpos( $option_name, 'swis_' ) ) {
			$option_name = str_replace( 'swis_', '', $option_name );
		}
		$constant_name = strtoupper( $this->prefix . $option_name );
		if ( defined( $constant_name ) && ( is_int( constant( $constant_name ) ) || is_bool( constant( $constant_name ) ) ) ) {
			return constant( $constant_name );
		}
		$options = get_option( 'swis_performance' );
		if ( empty( $options ) || ! is_array( $options ) ) {
			return $default;
		}
		if ( ! empty( $options[ $option_name ] ) ) {
			return $options[ $option_name ];
		}
		return $default;
	}

	/**
	 * Set an option: use 'site' setting if plugin is network activated, otherwise use 'blog' setting.
	 *
	 * @param string $option_name The name of the option to save.
	 * @param mixed  $option_value The value to save for the option.
	 * @return bool True if the operation was successful.
	 */
	function set_option( $option_name, $option_value ) {
		$constant_name = strtoupper( $this->prefix . $option_name );
		if ( defined( $constant_name ) && ( is_int( constant( $constant_name ) ) || is_bool( constant( $constant_name ) ) ) ) {
			return false;
		}
		$options = get_option( 'swis_performance' );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$options[ $option_name ] = $option_value;
		return update_option( 'swis_performance', $options );
	}

	/**
	 * Retrieves plugin header data.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @return array Plugin data. Values will be empty if not supplied by the plugin.
	 */
	function get_plugin_data( $plugin_file ) {
		$default_headers = array(
			'Name'      => 'Plugin Name',
			'PluginURI' => 'Plugin URI',
			'Version'   => 'Version',
			'AuthorURI' => 'Author URI',
		);

		$plugin_data = \get_file_data( $plugin_file, $default_headers, 'plugin' );

		$plugin_data['Title'] = '';
		if ( ! empty( $plugin_data['Name'] ) ) {
			$plugin_data['Title'] = $plugin_data['Name'];
		}
		return $plugin_data;
	}

	/**
	 * See if background mode is allowed/enabled.
	 *
	 * @return bool True if it is, false if it ain't.
	 */
	function background_mode_enabled() {
		if ( defined( 'SWIS_DISABLE_ASYNC' ) && SWIS_DISABLE_ASYNC ) {
			$this->debug_message( 'background mode disabled by admin' );
			return false;
		}
		if ( $this->detect_wpsf_location_lock() ) {
			$this->debug_message( 'background mode disabled by shield IP location lock' );
			return false;
		}
		return (bool) $this->get_option( 'background_processing' );
	}

	/**
	 * Check to see if Shield's location lock option is enabled.
	 *
	 * @return bool True if the IP location lock is enabled.
	 */
	function detect_wpsf_location_lock() {
		if ( function_exists( 'icwp_wpsf_init' ) ) {
			$this->debug_message( 'Shield Security detected' );
			$shield_user_man = get_option( 'icwp_wpsf_user_management_options' );
			if ( ! isset( $shield_user_man['session_lock_location'] ) ) {
				$this->debug_message( 'Shield security lock location setting does not exist, weird?' );
			}
			if ( ! empty( $shield_user_man['session_lock_location'] ) && 'Y' === $shield_user_man['session_lock_location'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Implode a multi-dimensional array without throwing errors. Arguments can be reverse order, same as implode().
	 *
	 * @param string $delimiter The character to put between the array items (the glue).
	 * @param array  $data The array to output with the glue.
	 * @return string The array values, separated by the delimiter.
	 */
	function implode( $delimiter, $data = '' ) {
		if ( is_array( $delimiter ) ) {
			$temp_data = $delimiter;
			$delimiter = $data;
			$data      = $temp_data;
		}
		if ( is_array( $delimiter ) ) {
			return '';
		}
		$output = '';
		foreach ( $data as $value ) {
			if ( is_string( $value ) || is_numeric( $value ) ) {
				$output .= $value . $delimiter;
			} elseif ( is_bool( $value ) ) {
				$output .= ( $value ? 'true' : 'false' ) . $delimiter;
			} elseif ( is_array( $value ) ) {
				$output .= 'Array,';
			}
		}
		return rtrim( $output, ',' );
	}

	/**
	 * Checks to see if the current page being output is an AMP page.
	 *
	 * @return bool True for an AMP endpoint, false otherwise.
	 */
	function is_amp() {
		global $wp_query;
		if ( ! isset( $wp_query ) ) {
			return false;
		}
		if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
			return true;
		}
		if ( function_exists( 'ampforwp_is_amp_endpoint' ) && ampforwp_is_amp_endpoint() ) {
			return true;
		}
		return false;
	}

	/**
	 * Checks to see if the current buffer/output is a JSON-encoded string.
	 *
	 * Specifically, we are looking for JSON objects/strings, not just ANY JSON value.
	 * Thus, the check is rather "loose", only looking for {} or [] at the start/end.
	 *
	 * @param string $buffer The content to check for JSON.
	 * @return bool True for JSON, false for everything else.
	 */
	function is_json( $buffer ) {
		if ( '{' === substr( $buffer, 0, 1 ) && '}' === substr( $buffer, -1 ) ) {
			return true;
		}
		if ( '[' === substr( $buffer, 0, 1 ) && ']' === substr( $buffer, -1 ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Make sure this is really and truly a "front-end request", excluding page builders and such.
	 *
	 * @return bool True for front-end requests, false for admin/builder requests.
	 */
	function is_frontend() {
		if ( is_admin() ) {
			return false;
		}
		$uri = add_query_arg( null, null );
		if (
			strpos( $uri, 'bricks=run' ) !== false ||
			strpos( $uri, '?brizy-edit' ) !== false ||
			strpos( $uri, '&builder=true' ) !== false ||
			strpos( $uri, 'cornerstone=' ) !== false ||
			strpos( $uri, 'cornerstone-endpoint' ) !== false ||
			strpos( $uri, 'ct_builder=' ) !== false ||
			strpos( $uri, 'ct_render_shortcode=' ) !== false ||
			strpos( $uri, 'action=oxy_render' ) !== false ||
			did_action( 'cornerstone_boot_app' ) || did_action( 'cs_before_preview_frame' ) ||
			is_customize_preview() ||
			'/print/' === substr( $uri, -7 ) ||
			strpos( $uri, 'elementor-preview=' ) !== false ||
			strpos( $uri, 'et_fb=' ) !== false ||
			strpos( $uri, 'fb-edit=' ) !== false ||
			strpos( $uri, '?fl_builder' ) !== false ||
			strpos( $uri, 'vc_editable=' ) !== false ||
			strpos( $uri, 'tatsu=' ) !== false ||
			strpos( $uri, 'tve=true' ) !== false ||
			( ! empty( $_POST['action'] ) && 'tatsu_get_concepts' === sanitize_text_field( wp_unslash( $_POST['action'] ) ) ) || // phpcs:ignore WordPress.Security.NonceVerification
			strpos( $uri, 'wp-login.php' ) !== false ||
			( defined( 'REST_REQUEST' ) && REST_REQUEST )
		) {
			return false;
		}
		global $wp_query;
		if ( isset( $wp_query ) && ( $wp_query instanceof \WP_Query ) ) {
			if (
				is_embed() ||
				is_feed() ||
				is_preview()
			) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check if file exists, and that it is local rather than using a protocol like http:// or phar://
	 *
	 * @param string $file The path of the file to check.
	 * @return bool True if the file exists and is local, false otherwise.
	 */
	function is_file( $file ) {
		if ( false !== strpos( $file, '://' ) ) {
			return false;
		}
		if ( false !== strpos( $file, 'phar://' ) ) {
			return false;
		}
		$file       = realpath( $file );
		$wp_dir     = realpath( ABSPATH );
		$upload_dir = wp_get_upload_dir();
		$upload_dir = realpath( $upload_dir['basedir'] );

		$content_dir = realpath( WP_CONTENT_DIR );
		if ( empty( $content_dir ) ) {
			$content_dir = $wp_dir;
		}
		if ( empty( $upload_dir ) ) {
			$upload_dir = $content_dir;
		}
		$plugin_dir         = realpath( constant( strtoupper( $this->prefix ) . 'PLUGIN_PATH' ) );
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
	 * Make sure an array/object can be parsed by a foreach().
	 *
	 * @since 0.1
	 * @param mixed $var A variable to test for iteration ability.
	 * @return bool True if the variable is iterable.
	 */
	function is_iterable( $var ) {
		return ! empty( $var ) && ( is_array( $var ) || $var instanceof Traversable );
	}

	/**
	 * Perform basic sanitation of CSS.
	 *
	 * @param string $css The raw CSS code.
	 * @return string The sanitized code or an empty string.
	 */
	function sanitize_css( $css ) {
		$css = str_replace( '&gt;', '>', $css );
		$css = \trim( \wp_strip_all_tags( $css ) );
		$css = str_replace( '&gt;', '>', $css );
		if ( empty( $css ) ) {
			return '';
		}

		if ( preg_match( '#</?\w+#', $css ) ) {
			return '';
		}

		$blacklist = array( '#!/', 'function(', '<script', '<?php' );
		foreach ( $blacklist as $blackmark ) {
			if ( false !== strpos( $css, $blackmark ) ) {
				$this->debug_message( 'CSS contained unsafe content' );
				return '';
			}
		}

		$needlist = array( '{', '}', ':' );
		foreach ( $needlist as $needed ) {
			if ( false === strpos( $css, $needed ) ) {
				$this->debug_message( "missing $needed in CSS, invalid" );
				return '';
			}
		}
		$minifier = new Minify\CSS( $css );
		$css      = $minifier->minify();
		return $css;
	}

	/**
	 * Check if a user can clear the cache.
	 *
	 * @return bool True if they can, false if they can't.
	 */
	protected function user_can_clear_cache() {
		// Check user permissions.
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( apply_filters( 'user_can_clear_cache', current_user_can( $permissions ) ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Get the cache cleared transient name used for the clear notice.
	 *
	 * @return string The transient name based on the user ID.
	 */
	protected function get_cache_cleared_transient_name() {
		return 'swis_cache_cleared_' . get_current_user_id();
	}

	/**
	 * Clear the contents of a given directory.
	 *
	 * @param string $dir The directory path.
	 */
	protected function clear_dir( $dir ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$dir = \untrailingslashit( $dir );

		if ( empty( $dir ) ) {
			$this->debug_message( 'give me something man!' );
			return;
		}

		$this->debug_message( "clearing $dir" );

		if ( ! is_dir( $dir ) ) {
			$this->debug_message( 'not a dir' );
			return;
		}
		if ( ! is_readable( $dir ) ) {
			$this->debug_message( 'not readable' );
			return;
		}
		if ( ! is_writable( $dir ) ) {
			$this->debug_message( 'not writable' );
			return;
		}

		$dir_objects = $this->get_dir_objects( $dir );

		foreach ( $dir_objects as $dir_object ) {
			// Create the full path.
			$dir_object = \trailingslashit( $dir ) . $dir_object;

			if ( is_dir( $dir_object ) ) {
				$this->clear_dir( $dir_object );
			} elseif ( $this->is_file( $dir_object ) && is_writable( $dir_object ) ) {
				unlink( $dir_object );
			}
		}

		// Doing this to make sure the directory is empty before we try and delete it.
		clearstatcache();
		$dir_objects = $this->get_dir_objects( $dir );

		// If the directory is empty now. No need to do error suppression here.
		if ( empty( $dir_objects ) ) {
			rmdir( $dir );
		}
		clearstatcache();
	}

	/**
	 * Get the number of files in a cache folder, recursively.
	 *
	 * @param string $dir Path of a cache folder.
	 * @param string $file Only count files matching this pattern.
	 * @return int Number of files found.
	 */
	public function get_cache_count( $dir = null, $file = '' ) {
		$cache_count = 0;

		// Get a list of the files in a folder.
		if ( is_dir( $dir ) && is_readable( $dir ) ) {
			$dir_objects = $this->get_dir_objects( $dir );
		}

		if ( empty( $dir_objects ) ) {
			return $cache_count;
		}

		foreach ( $dir_objects as $dir_object ) {
			// Create the full path.
			$dir_object = \trailingslashit( $dir ) . $dir_object;

			if ( is_dir( $dir_object ) ) {
				$cache_count += $this->get_cache_count( $dir_object, $file );
			} elseif ( is_file( $dir_object ) && is_readable( $dir_object ) ) {
				if ( ! empty( $file ) && false === strpos( $dir_object, $file ) ) {
					continue;
				}
				$cache_count ++;
			}
		}

		return $cache_count;
	}

	/**
	 * Get all the files in a directory.
	 *
	 * @param string $dir The directory's path.
	 * @return array A list of files found.
	 */
	function get_dir_objects( $dir ) {
		$this->debug_message( __METHOD__ );
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
	 * Displays a help icon linked to the docs.
	 *
	 * @since 1.2.1
	 * @param string $link A link to the documentation.
	 * @param string $hsid The HelpScout ID for the docs article. Optional.
	 */
	function help_link( $link, $hsid = '' ) {
		$beacon_attr = '';
		$link_class  = 'swis-help-icon';
		if ( strpos( $hsid, ',' ) ) {
			$beacon_attr = 'data-beacon-articles';
			$link_class  = 'swis-help-beacon-multi';
		} elseif ( $hsid ) {
			$beacon_attr = 'data-beacon-article';
			$link_class  = 'swis-help-beacon-single';
		}
		if ( empty( $hsid ) ) {
			echo '<a class="swis-help-icon swis-help-external" title="' . esc_attr__( 'Help', 'swis-performance' ) . '" href="' . esc_url( $link ) . '" target="_blank">' .
				'<span class="dashicons dashicons-insert"></span>' .
				'</a>';
			return;
		}
		echo '<a class="swis-help-icon ' . esc_attr( $link_class ) . '" title="' . esc_attr__( 'Help', 'swis-performance' ) . '" href="' . esc_url( $link ) . '" target="_blank" ' . esc_attr( $beacon_attr ) . '="' . esc_attr( $hsid ) . '">' .
			'<span class="dashicons dashicons-insert"></span>' .
			'</a>';
	}

	/**
	 * Finds the current PHP memory limit or a reasonable default.
	 *
	 * @return int The memory limit in bytes.
	 */
	function memory_limit() {
		if ( defined( 'EIO_MEMORY_LIMIT' ) && EIO_MEMORY_LIMIT ) {
			$memory_limit = EIO_MEMORY_LIMIT;
		} elseif ( function_exists( 'ini_get' ) ) {
			$memory_limit = ini_get( 'memory_limit' );
		} else {
			if ( ! defined( 'EIO_MEMORY_LIMIT' ) ) {
				// Conservative default, current usage + 16M.
				$current_memory = memory_get_usage( true );
				$memory_limit   = round( $current_memory / ( 1024 * 1024 ) ) + 16;
				define( 'EIO_MEMORY_LIMIT', $memory_limit );
			}
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::debug( "memory limit is set at $memory_limit" );
		}
		if ( ! $memory_limit || -1 === intval( $memory_limit ) ) {
			// Unlimited, set to 32GB.
			$memory_limit = '32000M';
		}
		if ( stripos( $memory_limit, 'g' ) ) {
			$memory_limit = intval( $memory_limit ) * 1024 * 1024 * 1024;
		} else {
			$memory_limit = intval( $memory_limit ) * 1024 * 1024;
		}
		return $memory_limit;
	}

	/**
	 * Clear output buffers without throwing a fit.
	 */
	function ob_clean() {
		if ( ob_get_length() ) {
			ob_end_clean();
		}
	}

	/**
	 * Converts a URL to a file-system path and checks if the resulting path exists.
	 *
	 * @param string $url The URL to mangle.
	 * @param string $extension An optional extension to append during is_file().
	 * @return bool|string The path if a local file exists correlating to the URL, false otherwise.
	 */
	function url_to_path_exists( $url, $extension = '' ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( 0 === strpos( $url, WP_CONTENT_URL ) ) {
			$path = str_replace( WP_CONTENT_URL, WP_CONTENT_DIR, $url );
		} elseif ( 0 === strpos( $url, $this->relative_home_url ) ) {
			$path = str_replace( $this->relative_home_url, ABSPATH, $url );
		} elseif ( 0 === strpos( $url, $this->home_url ) ) {
			$path = str_replace( $this->home_url, ABSPATH, $url );
		} else {
			$this->debug_message( 'not a valid local image' );
			return false;
		}
		$path_parts = explode( '?', $path );
		if ( $this->is_file( $path_parts[0] . $extension ) ) {
			$this->debug_message( 'local file found' );
			return $path_parts[0];
		}
		return false;
	}

	/**
	 * A wrapper for PHP's parse_url, prepending assumed scheme for network path
	 * URLs. PHP versions 5.4.6 and earlier do not correctly parse without scheme.
	 *
	 * @param string  $url The URL to parse.
	 * @param integer $component Retrieve specific URL component.
	 * @return mixed Result of parse_url.
	 */
	function parse_url( $url, $component = -1 ) {
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
	 * Get the shortest version of the content URL.
	 *
	 * @return string The URL where the content lives.
	 */
	function content_url() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( $this->site_url ) {
			return $this->site_url;
		}
		$this->site_url = get_home_url();
		if ( class_exists( 'Amazon_S3_And_CloudFront' ) ) {
			global $as3cf;
			$s3_scheme = $as3cf->get_url_scheme();
			$s3_region = $as3cf->get_setting( 'region' );
			$s3_bucket = $as3cf->get_setting( 'bucket' );
			if ( is_wp_error( $s3_region ) ) {
				$s3_region = '';
			}
			$s3_domain = '';
			if ( ! empty( $s3_bucket ) && ! is_wp_error( $s3_bucket ) && method_exists( $as3cf, 'get_provider' ) ) {
				$s3_domain = $as3cf->get_provider()->get_url_domain( $s3_bucket, $s3_region, null, array(), true );
			} elseif ( ! empty( $s3_bucket ) && ! is_wp_error( $s3_bucket ) && method_exists( $as3cf, 'get_storage_provider' ) ) {
				$s3_domain = $as3cf->get_storage_provider()->get_url_domain( $s3_bucket, $s3_region );
			}
			if ( ! empty( $s3_domain ) && $as3cf->get_setting( 'serve-from-s3' ) ) {
				$this->s3_active = true;
				$this->debug_message( "found S3 domain of $s3_domain with bucket $s3_bucket and region $s3_region" );
			}
		}

		if ( $this->s3_active ) {
			$this->site_url = defined( 'EXACTDN_LOCAL_DOMAIN' ) && EXACTDN_LOCAL_DOMAIN ? EXACTDN_LOCAL_DOMAIN : $s3_scheme . '://' . $s3_domain;
		} else {
			// Normally, we use this one, as it will be shorter for sub-directory installs.
			$home_url    = get_home_url();
			$site_url    = get_site_url();
			$upload_dir  = wp_get_upload_dir();
			$home_domain = $this->parse_url( $home_url, PHP_URL_HOST );
			$site_domain = $this->parse_url( $site_url, PHP_URL_HOST );
			// If the home domain does not match the upload url, and the site domain does match...
			if ( false === strpos( $upload_dir['baseurl'], $home_domain ) && false !== strpos( $upload_dir['baseurl'], $site_domain ) ) {
				$this->debug_message( "using WP URL (via get_site_url) with $site_domain rather than $home_domain" );
				$home_url = $site_url;
			}
			$this->site_url = defined( 'EXACTDN_LOCAL_DOMAIN' ) && EXACTDN_LOCAL_DOMAIN ? EXACTDN_LOCAL_DOMAIN : $home_url;
		}
		return $this->site_url;
	}
}
