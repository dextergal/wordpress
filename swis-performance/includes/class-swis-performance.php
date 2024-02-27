<?php
/**
 * Class and methods to defer JS/CSS and include critical CSS.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main SWIS_Performance Class.
 */
final class SWIS_Performance {
	/* Singleton */

	/**
	 * The one and only true SWIS_Performance
	 *
	 * @var SWIS_Performance The one and only true SWIS_Performance
	 */
	private static $instance;

	/**
	 * SWIS Settings object for option pages and related functions.
	 *
	 * @var object|\SWIS\Settings
	 */
	public $settings;

	/**
	 * SWIS Buffer object for HTML parsing.
	 *
	 * @var object|\SWIS\Buffer
	 */
	public $buffer;

	/**
	 * SWIS Element_Filter object for tag/element filtering.
	 *
	 * @var object|\SWIS\Element_Filter
	 */
	public $element_filter;

	/**
	 * SWIS Cache object.
	 *
	 * @var object|\SWIS\Cache
	 */
	public $cache;

	/**
	 * SWIS Cache Preload object.
	 *
	 * @var object|\SWIS\Cache_Preload
	 */
	public $cache_preload;

	/**
	 * SWIS CDN parser object.
	 *
	 * @var object|\SWIS\CDN
	 */
	public $cdn;

	/**
	 * SWIS Defer CSS object.
	 *
	 * @var object|\SWIS\Defer_CSS
	 */
	public $defer_css;

	/**
	 * SWIS Defer JS object.
	 *
	 * @var object|\SWIS\Defer_JS
	 */
	public $defer_js;

	/**
	 * SWIS Minify CSS object.
	 *
	 * @var object|\SWIS\Minify_CSS
	 */
	public $minify_css;

	/**
	 * SWIS Minify JS object.
	 *
	 * @var object|\SWIS\Minify_JS
	 */
	public $minify_js;

	/**
	 * SWIS GZIP object.
	 *
	 * @var object|\SWIS\GZIP
	 */
	public $gzip;

	/**
	 * SWIS Optimize_Fonts object.
	 *
	 * @var object|\SWIS\Optimize_Fonts
	 */
	public $fonts;

	/**
	 * SWIS Prefetch object.
	 *
	 * @var object|\SWIS\Prefetch
	 */
	public $prefetch;

	/**
	 * SWIS Slim object.
	 *
	 * @var object|\SWIS\Slim
	 */
	public $slim;

	/**
	 * Main SWIS_Performance instance.
	 *
	 * Ensures that only one instance of SWIS_Performance exists in memory at any given time.
	 *
	 * @static
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof SWIS_Performance ) ) {
			global $wpdb;
			if ( ! isset( $wpdb->swis_queue ) ) {
				$wpdb->swis_queue = $wpdb->prefix . 'swis_queue';
			}
			if ( ! isset( $wpdb->swis_critical_css ) ) {
				$wpdb->swis_critical_css = $wpdb->prefix . 'swis_critical_css';
			}
			self::$instance = new SWIS_Performance;
			self::$instance->setup_constants();

			add_action( 'init', array( self::$instance, 'load_textdomain' ), 9 );
			add_filter( 'sq_lateloading', '__return_true' );

			if ( self::$instance->php_supported() && self::$instance->wp_supported() ) {
				self::$instance->includes();
				self::$instance->settings = new \SWIS\Settings();
				add_action( 'plugins_loaded', array( self::$instance, 'init' ) );
				add_filter( 'update_option_swis_performance', array( self::$instance, 'options_updated' ), 10, 2 );
			}
		}

		return self::$instance;
	}

	/**
	 * Throw error on object clone.
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object. Therefore, we don't want the object to be cloned.
	 */
	public function __clone() {
		// Cloning instances of the class is forbidden.
		_doing_it_wrong( __METHOD__, esc_html__( 'Cannot clone core object.', 'swis-performance' ), esc_html( SWIS_PLUGIN_VERSION ) );
	}

	/**
	 * Disable unserializing of the class.
	 */
	public function __wakeup() {
		// Unserializing instances of the class is forbidden.
		_doing_it_wrong( __METHOD__, esc_html__( 'Cannot unserialize (wakeup) the core object.', 'swis-performance' ), esc_html( SWIS_PLUGIN_VERSION ) );
	}

	/**
	 * Class initialization for plugin.
	 */
	function init() {
		self::$instance->buffer         = new \SWIS\Buffer();
		self::$instance->element_filter = new \SWIS\Element_Filter();
		self::$instance->cache          = new \SWIS\Cache();
		self::$instance->cache_preload  = new \SWIS\Cache_Preload();
		self::$instance->cdn            = new \SWIS\CDN();
		self::$instance->critical_css   = new \SWIS\Critical_CSS();
		self::$instance->defer_js       = new \SWIS\Defer_JS();
		self::$instance->defer_css      = new \SWIS\Defer_CSS();
		self::$instance->minify_js      = new \SWIS\Minify_JS();
		self::$instance->minify_css     = new \SWIS\Minify_CSS();
		self::$instance->gzip           = new \SWIS\GZIP();
		self::$instance->fonts          = new \SWIS\Optimize_Fonts();
		self::$instance->prefetch       = new \SWIS\Prefetch();
		self::$instance->slim           = new \SWIS\Slim();

		// Background/Async classes.
		self::$instance->cache_preload_async      = new \SWIS\Cache_Preload_Async();
		self::$instance->cache_preload_background = new \SWIS\Cache_Preload_Background();
		self::$instance->critical_css_async       = new \SWIS\Critical_CSS_Async();
		self::$instance->critical_css_background  = new \SWIS\Critical_CSS_Background();
		self::$instance->test_async               = new \SWIS\Test_Async_Request();
		register_deactivation_hook( SWIS_PLUGIN_FILE, array( self::$instance->gzip, 'remove_htaccess_rules' ) );
	}

	/**
	 * Make sure we are on a supported version of PHP.
	 *
	 * @access private
	 */
	private function php_supported() {
		if ( ! defined( 'PHP_VERSION_ID' ) || PHP_VERSION_ID < 70200 ) {
			add_action( 'network_admin_notices', array( self::$instance, 'unsupported_php_notice' ) );
			add_action( 'admin_notices', array( self::$instance, 'unsupported_php_notice' ) );
			return false;
		}
		return true;
	}

	/**
	 * Make sure we are on a supported version of WP.
	 *
	 * @access private
	 */
	private function wp_supported() {
		global $wp_version;
		if ( version_compare( $wp_version, '5.4' ) >= 0 ) {
			return true;
		}
		add_action( 'network_admin_notices', array( self::$instance, 'unsupported_wp_notice' ) );
		add_action( 'admin_notices', array( self::$instance, 'unsupported_wp_notice' ) );
		return false;
	}

	/**
	 * Display a notice that the PHP version is too old.
	 */
	public function unsupported_php_notice() {
		echo '<div id="swis-warning-php" class="notice notice-error"><p><a href="https://docs.ewww.io/article/55-upgrading-php" target="_blank">' . esc_html__( 'For performance and security reasons, SWIS Performance requires PHP 7.2 or greater. If you are unsure how to upgrade to a supported version, ask your webhost for instructions.', 'swis-performance' ) . '</a></p></div>';
	}

	/**
	 * Display a notice that the WP version is too old.
	 */
	public function unsupported_wp_notice() {
		echo '<div id="swis-warning-wp" class="notice notice-error"><p>' . esc_html__( 'SWIS Performance requires WordPress 5.0 or greater, please update your website.', 'swis-performance' ) . '</p></div>';
	}

	/**
	 * Setup plugin constants.
	 *
	 * @access private
	 */
	private function setup_constants() {
		// This is the full system path to the plugin folder.
		define( 'SWIS_PLUGIN_PATH', plugin_dir_path( SWIS_PLUGIN_FILE ) );
		if ( ! defined( 'SWIS_CONTENT_DIR' ) ) {
			define( 'SWIS_CONTENT_DIR', WP_CONTENT_DIR . '/swis/' );
		}
		// The directory where cached HTML pages are stored (if page caching is enabled ).
		define( 'SWIS_CACHE_DIR', SWIS_CONTENT_DIR . 'cache/html' );
		// The site for auto-update checking.
		define( 'SWIS_SL_STORE_URL', 'https://ewww.io' );
		// Product ID for update checking.
		define( 'SWIS_SL_ITEM_ID', 1188482 );
	}

	/**
	 * Include required files.
	 *
	 * @access private
	 */
	private function includes() {
		// All the base functions for our plugins.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-base.php' );
		// All the parsing functions for our plugins.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-page-parser.php' );
		// Sets up the settings page and options.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-settings.php' );
		// Starts the HTML buffer for all other functions to parse.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-buffer.php' );
		// Parses the HTML buffer for elements that should be filtered by multiple classes.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-element-filter.php' );
		// The test async class and methods.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-test-async-request.php' );
		// The Caching class & methods.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-cache.php' );
		// The Cache Engine class & methods.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-cache-engine.php' );
		// The WebP Caching extension class & methods.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-cache-webp.php' );
		// The Disk Cache class & methods.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-disk-cache.php' );
		// The Cache Preloader class & methods.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-cache-preload.php' );
		// Async Request methods for Cache Preloader.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-cache-preload-async.php' );
		// Background Processing methods for Cache Preloader.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-cache-preload-background.php' );
		// The CDN-parsing class & functions.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-cdn.php' );
		// The JS deferring class & functions.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-defer-js.php' );
		// The CSS deferring class & functions.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-defer-css.php' );
		// Class & methods for generating Critical CSS.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-critical-css.php' );
		// Async Request methods for Critical CSS generation.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-critical-css-async.php' );
		// Background Processing methods for Critical CSS generation.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-critical-css-background.php' );
		// The JS minify class & functions.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-minify-js.php' );
		// The CSS minify class & functions.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-minify-css.php' );
		// The class to auto-insert GZIP and other rules.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-gzip.php' );
		// The class to auto-detect Google Fonts, inline and pre-connect them.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-optimize-fonts.php' );
		// The class to auto-insert DNS prefetch hints.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-prefetch.php' );
		// The class to eliminate unused JS/CSS and related tools.
		require_once( SWIS_PLUGIN_PATH . 'includes/class-slim.php' );
		// The class to run plugin updates.
		require_once( SWIS_PLUGIN_PATH . 'vendor/EDD_SL_Plugin_Updater.php' );
	}

	/**
	 * Makes sure to load the language files.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'swis-performance', false, dirname( plugin_basename( SWIS_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Run any actions triggered by an update to the swis_performance options.
	 *
	 * @param mixed $old_settings The old value(s).
	 * @param mixed $new_settings The new value(s).
	 */
	public function options_updated( $old_settings, $new_settings ) {
		$network_wide = false;
		if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		if ( is_multisite() && is_plugin_active_for_network( plugin_basename( SWIS_PLUGIN_FILE ) ) ) {
			$network_wide = true;
		}
		if ( empty( $old_settings['cache'] ) && ! empty( $new_settings['cache'] ) ) {
			$this->cache->on_activation( $network_wide );
		}
		if ( ! empty( $old_settings['cache'] ) && empty( $new_settings['cache'] ) ) {
			remove_all_actions( 'swis_site_cache_cleared' );
			remove_all_actions( 'swis_cache_by_url_cleared' );
			$this->cache->on_deactivation( $network_wide );
		}
		if ( ! empty( $old_settings['cache'] ) && ! empty( $new_settings['cache'] ) ) {
			$cache_clear_settings = array( 'defer_css', 'defer_js', 'minify_css', 'minify_js', 'critical_css', 'optimize_fonts', 'cdn_domain' );
			foreach ( $cache_clear_settings as $key ) {
				if ( isset( $old_settings[ $key ] ) && isset( $new_settings[ $key ] ) && $old_settings[ $key ] !== $new_settings[ $key ] ) {
					$this->cache->clear_complete_cache();
				}
			}
		}
		if ( (bool) $old_settings['cache_settings']['webp'] !== (bool) $new_settings['cache_settings']['webp'] ) {
			$this->cache->clear_complete_cache();
		}
		if ( $this->settings->background_mode_enabled() ) {
			if ( empty( $old_settings['cache_preload'] ) && ! empty( $new_settings['cache_preload'] ) ) {
				// If cache preload is enabled, start the preloader.
				$this->cache_preload->start_preload();
			} elseif ( ! empty( $old_settings['cache_preload'] ) && empty( $new_settings['cache_preload'] ) ) {
				// If cache preload is disabled, cancel anything that is running.
				$this->cache_preload->stop_preload();
			} elseif ( ! empty( $new_settings['cache_preload'] ) && ! empty( $old_settings['cache'] ) && empty( $new_settings['cache'] ) ) {
				// If caching is disabled and preload is still enabled, also cancel.
				$this->cache_preload->stop_preload();
			}
		}
	}
}
