<?php
/**
 * Class and methods for HTML page caching.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enables caching of HTML pages.
 */
final class Cache extends Base {

	/**
	 * Determines if the page cache cleared hook should fire.
	 *
	 * @var bool
	 */
	public $fire_page_cache_cleared_hook = true;

	/**
	 * Setup all the hooks and settings.
	 */
	public function __construct() {
		if ( ! $this->get_option( 'cache' ) ) {
			return;
		}
		parent::__construct();
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		// Init hooks.
		add_action( 'init', array( '\SWIS\Cache_Engine', 'start' ) );
		add_action( 'init', array( $this, 'process_clear_cache_request' ) );

		// Clear cache hooks for public use.
		add_action( 'swis_clear_complete_cache', array( $this, 'clear_complete_cache' ) );
		add_action( 'swis_clear_site_cache', array( $this, 'clear_site_cache' ) );
		add_action( 'swis_clear_site_cache_by_blog_id', array( $this, 'clear_site_cache_by_blog_id' ) );
		add_action( 'swis_clear_page_cache_by_post_id', array( $this, 'clear_page_cache_by_post_id' ) );
		add_action( 'swis_clear_page_cache_by_url', array( $this, 'clear_page_cache_by_url' ) );

		// Core, theme, and plugin changes/updates.
		add_action( '_core_updated_successfully', array( $this, 'clear_complete_cache' ) );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 10, 2 );
		add_action( 'switch_theme', array( $this, 'clear_site_cache' ) );
		add_action( 'permalink_structure_changed', array( $this, 'clear_site_cache' ) );
		add_action( 'activated_plugin', array( $this, 'on_plugin_activation_deactivation' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_activation_deactivation' ), 10, 2 );
		// Post changes.
		add_action( 'save_post', array( $this, 'on_save_trash_post' ) );
		add_action( 'wp_trash_post', array( $this, 'on_save_trash_post' ) );
		add_action( 'pre_post_update', array( $this, 'on_pre_post_update' ), 10, 2 );
		// Comment changes.
		add_action( 'comment_post', array( $this, 'on_comment_post' ), 99, 2 );
		add_action( 'edit_comment', array( $this, 'on_edit_comment' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_transition_comment_status' ), 10, 3 );
		// Third-party hooks.
		add_action( 'autoptimize_action_cachepurged', array( $this, 'clear_complete_cache' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_woocommerce_stock_update' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_woocommerce_stock_update' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_woocommerce_stock_update' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_woocommerce_stock_update' ) );
		add_action( 'fl_builder_before_save_layout', array( $this, 'clear_complete_cache' ) );
		add_action( 'fl_builder_cache_cleared', array( $this, 'clear_complete_cache' ) );

		// Multisite hooks.
		add_action( 'wp_initialize_site', array( $this, 'install_later' ) );
		add_action( 'wp_uninitialize_site', array( $this, 'uninstall_later' ) );
		// Advanced cache hooks.
		add_action( 'permalink_structure_changed', array( $this, 'install_backend' ) );
		add_action( 'add_option_swis_performance', array( $this, 'install_backend' ) );
		add_action( 'update_option_swis_performance', array( $this, 'install_backend' ) );

		// Admin bar hook.
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_items' ), 91 );

		// Admin interface hooks.
		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'admin_init' ), 9 );
			// Alert hooks.
			add_action( 'admin_notices', array( $this, 'requirements_check' ) );
		}
	}

	/**
	 * See if we should pre-empt the caching engine (possibly other caching plugin or server-based caching).
	 *
	 * @return bool True to prevent use of caching engine, false otherwise.
	 */
	function should_disable_caching() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( $this->plugin_cache_detected() ) {
			return true;
		}
		return apply_filters( 'swis_disable_cache', false );
	}

	/**
	 * Check if the server is already doing page caching.
	 *
	 * @return bool True if server-based caching detected, false otherwise.
	 */
	function server_cache_detected() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if (
			isset( $_SERVER['cw_allowed_ip'] ) || // Cloudways.
			defined( 'IS_PRESSABLE' ) ||
			getenv( 'SPINUPWP_CACHE_PATH' ) ||
			defined( 'WPE_PLUGIN_VERSION' ) ||
			defined( 'FLYWHEEL_CONFIG_DIR' ) ||
			defined( 'KINSTAMU_VERSION' ) ||
			defined( 'O2SWITCH_VARNISH_PURGE_KEY' ) ||
			! empty( $_ENV['PANTHEON_ENVIRONMENT'] ) ||
			defined( 'WPCOMSH_VERSION' ) ||
			( defined( '\Savvii\CacheFlusherPlugin::NAME_FLUSH_NOW' ) && defined( '\Savvii\CacheFlusherPlugin::NAME_DOMAINFLUSH_NOW' ) ) ||
			class_exists( 'VarnishPurger' )
		) {
			return true;
		}
		return false;
	}

	/**
	 * Check if a plugin is already doing page caching.
	 *
	 * @return bool True if plugin-based caching detected, false otherwise.
	 */
	function plugin_cache_detected() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( defined( 'WP_CACHE' ) && WP_CACHE && is_file( WP_CONTENT_DIR . '/advanced-cache.php' ) && is_readable( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			$contents = file_get_contents( WP_CONTENT_DIR . '/advanced-cache.php' );
			if ( false === strpos( $contents, 'SWIS_Performance' ) && ! empty( $contents ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Make sure plugin is setup.
	 */
	public function admin_init() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( get_option( 'swis_activation' ) ) {
			$this->on_activation( $this->is_plugin_active_for_network() );
		} elseif ( ! $this->should_disable_caching() && ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) ) {
			Disk_Cache::setup();
		}
	}

	/**
	 * Check if plugin is activated network-wide.
	 */
	public function is_plugin_active_for_network() {
		if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		if ( is_multisite() && is_plugin_active_for_network( plugin_basename( SWIS_PLUGIN_FILE ) ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide If the plugin is network activated.
	 * @return bool True if advanced-cache.php exists and WP_CACHE defined. False otherwise.
	 */
	public function on_activation( $network_wide ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( $this->should_disable_caching() ) {
			$this->set_option( 'cache', false );
			return true;
		}
		// Activation & setup routine.
		$this->each_site( $network_wide, array( $this, 'install_backend' ) );

		// Copy advanced cache file and define WP_CACHE.
		Disk_Cache::setup();
	}

	/**
	 * Upgrade hook (or core, theme, plugin, or translation updates).
	 *
	 * @param object $obj WP_Upgrader instance.
	 * @param array  $data Extra data related to the upgrade.
	 */
	public function on_upgrade( $obj, $data ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( empty( $data['action'] || 'update' !== $data['action'] ) ) {
			return;
		}
		// For theme updates.
		if ( ! empty( $data['type'] ) && 'theme' === $data['type'] && isset( $data['themes'] ) ) {
			$updated_themes = (array) $data['themes'];
			$sites_themes   = $this->each_site( is_multisite(), 'wp_get_theme' );

			// Check the themes for each site.
			foreach ( $sites_themes as $blog_id => $site_theme ) {
				// If the active or parent theme has been updated, clear the site cache.
				if ( in_array( $site_theme->stylesheet, $updated_themes, true ) || in_array( $site_theme->template, $updated_themes, true ) ) {
					$this->clear_site_cache_by_blog_id( $blog_id );
				}
			}
		}

		// If option enabled clear complete cache on any plugin update.
		if ( Cache_Engine::$settings['clear_complete_cache_on_changed_plugin'] ) {
			$this->clear_site_cache();
		}

		// Check for updated plugins.
		if ( ! empty( $data['type'] ) && 'plugin' === $data['type'] && isset( $data['plugins'] ) ) {
			$updated_plugins = (array) $data['plugins'];

			// If SWIS has been updated.
			if ( in_array( plugin_basename( SWIS_PLUGIN_FILE ), $updated_plugins, true ) ) {
				// Do update routine.
				$this->on_swis_update();
			} else {
				$network_plugins = ( is_multisite() ) ? array_flip( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();

				// If a network-activated plugin has been updated, go nuclear!
				if ( ! empty( array_intersect( $updated_plugins, $network_plugins ) ) ) {
					$this->clear_complete_cache();
				} else {
					// Check each site otherwise.
					$sites_plugins = $this->each_site( is_multisite(), 'get_option', array( 'active_plugins', array() ) );
					foreach ( $sites_plugins as $blog_id => $site_plugins ) {
						if ( ! empty( array_intersect( $updated_plugins, (array) $site_plugins ) ) ) {
							$this->clear_site_cache_by_blog_id( $blog_id );
						}
					}
				}
			}
		}
	}

	/**
	 * SWIS Performance update actions for caching engine.
	 */
	public function on_swis_update() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$network_wide = $this->is_plugin_active_for_network();

		// Clean cache settings files.
		$this->each_site( $network_wide, '\SWIS\Disk_Cache::clean' );

		if ( $this->should_disable_caching() ) {
			$this->set_option( 'cache', false );
			return;
		}
		if ( ! $this->get_option( 'cache' ) ) {
			return;
		}

		// Create cache settings files.
		$this->each_site( $network_wide, array( $this, 'install_backend' ) );

		Disk_Cache::setup();

		$this->clear_complete_cache();
	}

	/**
	 * Deactivation hook.
	 *
	 * @param bool $network_wide If the plugin is network activated.
	 */
	public function on_deactivation( $network_wide ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// Deactivation & tear-down/cleanup routine.
		$this->each_site( $network_wide, '\SWIS\Disk_Cache::clean' );
		$this->each_site( $network_wide, array( $this, 'clear_site_cache' ) );
	}

	/**
	 * Install SWIS Cache on new site in multisite network.
	 *
	 * @param object $new_site WP_Site instance for new site.
	 */
	public function install_later( $new_site ) {
		if ( ! is_plugin_active_for_network( plugin_basename( SWIS_PLUGIN_FILE ) ) ) {
			return;
		}
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		// Switch to the new blog.
		switch_to_blog( (int) $new_site->blog_id );

		// Initialize settings, and create advanced cache settings file.
		$this->install_backend();

		restore_current_blog();
	}

	/**
	 * Create or update advanced cache settings.
	 */
	public function install_backend() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// Runs settings through validation and creates the advanced cache settings file.
		Disk_Cache::create_settings_file( $this->get_settings() );
	}

	/**
	 * Uninstall SWIS Cache from deleted site in multisite network.
	 *
	 * @param object $old_site WP_Site instance for deleted site.
	 */
	public function uninstall_later( $old_site ) {
		if ( ! $this->is_plugin_active_for_network() ) {
			return;
		}
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		// Delete advanced cache settings file.
		Disk_Cache::clean();

		// Clear complete cache of deleted site.
		$this->clear_site_cache_by_blog_id( (int) $old_site->blog_id, false );
	}

	/**
	 * Run a function on each site.
	 *
	 * @param bool   $network Whether or not to perform the action on each site in network.
	 * @param string $callback The callback function to run.
	 * @param array  $callback_params The callback function parameters.
	 * @return array The returned value(s) from callback function.
	 */
	private function each_site( $network, $callback, $callback_params = array() ) {
		$callback_return = array();

		if ( $network ) {
			$blog_ids = $this->get_blog_ids();
			// Switch to each site in network.
			foreach ( $blog_ids as $blog_id ) {
				switch_to_blog( $blog_id );
				$callback_return[ $blog_id ] = call_user_func_array( $callback, $callback_params );
				restore_current_blog();
			}
		} else {
			$blog_id                     = 1;
			$callback_return[ $blog_id ] = (int) call_user_func_array( $callback, $callback_params );
		}

		return $callback_return;
	}

	/**
	 * Plugin activation and deactivation hooks.
	 */
	public function on_plugin_activation_deactivation() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// If option enabled, clear complete cache on any plugin activation or deactivation.
		if ( Cache_Engine::$settings['clear_complete_cache_on_changed_plugin'] ) {
			$this->clear_site_cache();
		}
	}

	/**
	 * Get the SWIS Cache settings. If they don't exist, return defaults.
	 *
	 * @return array SWIS Cache options and defaults.
	 */
	public function get_settings() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$cache_settings   = $this->get_option( 'cache_settings', array() );
		$default_settings = array(
			'permalink_structure'                    => (string) $this->get_permalink_structure(),
			'expires'                                => 0,
			'clear_complete_cache_on_saved_post'     => 0,
			'clear_complete_cache_on_saved_comment'  => 0,
			'clear_complete_cache_on_changed_plugin' => 1,
			'webp'                                   => 0,
			'mobile'                                 => 0,
			'exclusions'                             => '',
			'excluded_cookies'                       => '',
			'excluded_query_strings'                 => '',
		);
		foreach ( $default_settings as $name => $value ) {
			if ( defined( 'SWIS_CACHE_' . strtoupper( $name ) ) ) {
				$cache_settings[ $name ] = constant( 'SWIS_CACHE_' . strtoupper( $name ) );
			}
		}
		return wp_parse_args( $cache_settings, $default_settings );
	}

	/**
	 * Validate the user-defined exclusions.
	 *
	 * @param array $user_exclusions A list of user-specified exclusions.
	 * @return array The validations exclusions list.
	 */
	function validate_user_exclusions( $user_exclusions ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$valid_exclusions = array( '/checkout/' );
		if ( ! empty( $user_exclusions ) ) {
			if ( is_string( $user_exclusions ) ) {
				$user_exclusions = explode( "\n", $user_exclusions );
			}
			if ( is_array( $user_exclusions ) ) {
				foreach ( $user_exclusions as $exclusion ) {
					if ( ! is_string( $exclusion ) ) {
						continue;
					}
					if ( '/checkout/' === trim( $exclusion ) ) {
						continue;
					}
					$valid_exclusions[] = trim( $exclusion );
				}
			}
		}
		return $valid_exclusions;
	}

	/**
	 * Get blog IDs.
	 *
	 * @return array List of blog IDs.
	 */
	private function get_blog_ids() {
		$blog_ids = array( 1 );
		if ( is_multisite() ) {
			global $wpdb;
			$blog_ids = array_map( 'absint', $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" ) );
		}
		return $blog_ids;
	}

	/**
	 * Get blog path.
	 *
	 * @return string Blog path from site address URL, empty otherwise.
	 */
	public static function get_blog_path() {
		$site_url_path        = parse_url( home_url(), PHP_URL_PATH );
		$site_url_path        = rtrim( $site_url_path, '/' );
		$site_url_path_pieces = explode( '/', $site_url_path );

		// Get last piece in case installation is in a subdirectory.
		$blog_path = ( ! empty( end( $site_url_path_pieces ) ) ) ? '/' . end( $site_url_path_pieces ) . '/' : '';
		return $blog_path;
	}

	/**
	 * Get blog paths.
	 *
	 * @return array List of blog paths.
	 */
	public function get_blog_paths() {
		$blog_paths = array( '/' );
		if ( is_multisite() ) {
			global $wpdb;
			$blog_paths = $wpdb->get_col( "SELECT path FROM $wpdb->blogs" );
		}
		return $blog_paths;
	}

	/**
	 * Get the current permalink structure.
	 *
	 * @return string The permalink structure, as a string.
	 */
	private function get_permalink_structure() {
		// Get permalink structure.
		$permalink_structure = get_option( 'permalink_structure' );

		// Permalink structure is custom and has a trailing slash.
		if ( $permalink_structure && preg_match( '/\/$/', $permalink_structure ) ) {
			return 'has_trailing_slash';
		}

		// Permalink structure is custom and does not have a trailing slash.
		if ( $permalink_structure && ! preg_match( '/\/$/', $permalink_structure ) ) {
			return 'no_trailing_slash';
		}

		// Permalink structure is not custom, and sucks eggs.
		if ( empty( $permalink_structure ) ) {
			return 'plain';
		}
	}

	/**
	 * Get cache directory size.
	 *
	 * @return int The cache size in bytes
	 */
	public function get_cache_size() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$size = get_transient( $this->get_cache_size_transient_name() );
		if ( ! $size ) {
			$size = Disk_Cache::get_cache_size();
			set_transient( $this->get_cache_size_transient_name(), $size, MINUTE_IN_SECONDS * 15 );
		}
		return $size;
	}

	/**
	 * Get the cache size transient name.
	 *
	 * @return string The transient name for the given/current blog.
	 */
	private function get_cache_size_transient_name() {
		return 'swis_cache_size';
	}

	/**
	 * Add admin-bar links to purge the cache.
	 *
	 * @param object $wp_admin_bar The WP Admin Bar object, passed by reference.
	 */
	public function add_admin_bar_items( $wp_admin_bar ) {
		if ( ! $this->user_can_clear_cache() || ! is_admin_bar_showing() ) {
			return;
		}

		// Get clear complete cache button title.
		$title = ( is_multisite() && is_network_admin() ) ? esc_html__( 'Clear Network Cache', 'swis-performance' ) : esc_html__( 'Clear Site Cache', 'swis-performance' );

		// Add the clear cache button in admin bar.
		$wp_admin_bar->add_node(
			array(
				'id'     => 'swis-clear-cache',
				'href'   => wp_nonce_url(
					add_query_arg(
						array(
							'_cache'  => 'swis-cache',
							'_action' => 'swis_cache_clear',
						)
					),
					'swis_cache_clear_nonce'
				),
				'parent' => 'swis',
				'title'  => '<span class="ab-item">' . $title . '</span>',
				'meta'   => array(
					'title' => $title,
				),
			)
		);

		// Add Clear URL Cache button in admin bar.
		if ( ! is_admin() ) {
			$wp_admin_bar->add_node(
				array(
					'id'     => 'swis-clear-url-cache',
					'href'   => wp_nonce_url(
						add_query_arg(
							array(
								'_cache'  => 'swis-cache',
								'_action' => 'swis_cache_clearurl',
							)
						),
						'swis_cache_clear_nonce'
					),
					'parent' => 'swis',
					'title'  => '<span class="ab-item">' . esc_html__( 'Clear URL Cache', 'swis-performance' ) . '</span>',
					'meta'   => array(
						'title' => esc_html__( 'Clear URL Cache', 'swis-performance' ),
					),
				)
			);
		}
	}

	/**
	 * Process a request to clear the cache.
	 */
	public function process_clear_cache_request() {
		// Check if this is a cache clear request.
		if (
			empty( $_GET['_cache'] ) || // The _cache arg is empty.
			empty( $_GET['_action'] ) || // The _action arg is empty.
			'swis-cache' !== $_GET['_cache'] || // The _cache arg isn't what it ought to be.
			( 'swis_cache_clear' !== $_GET['_action'] && 'swis_cache_clearurl' !== $_GET['_action'] ) // The _action param isn't one we recognize.
		) {
			return;
		}
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		// Verify nonce.
		if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'swis_cache_clear_nonce' ) ) {
			return;
		}

		// Check user has permissions to clear cache.
		if ( ! $this->user_can_clear_cache() ) {
			return;
		}

		if ( 'swis_cache_clearurl' === $_GET['_action'] ) {
			// Get clear URL without query string.
			$clear_url = $this->parse_url( home_url(), PHP_URL_SCHEME ) . '://' . Cache_Engine::$request_headers['Host'] . add_query_arg( null, null );
			$this->clear_page_cache_by_url( $clear_url );
		} elseif ( 'swis_cache_clear' === $_GET['_action'] ) {
			$this->each_site( ( is_multisite() && is_network_admin() ), array( $this, 'clear_site_cache' ) );
			$this->clear_server_caches();
		}

		if ( is_admin() ) {
			set_transient( $this->get_cache_cleared_transient_name(), 1 );
		}

		wp_safe_redirect( remove_query_arg( array( '_cache', '_action', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * When any published post type is updated or sent to the trash.
	 *
	 * @param int $post_id The post ID number.
	 */
	public function on_save_trash_post( $post_id ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$post_status = get_post_status( $post_id );
		if ( 'publish' === $post_status ) {
			$this->clear_cache_on_post_save( $post_id );
		}
	}

	/**
	 * Clear the cache based on the pre_post_update hook.
	 *
	 * @param int   $post_id The post ID number.
	 * @param array $post_data The unslashed post data.
	 */
	public function on_pre_post_update( $post_id, $post_data ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		$old_post_status = get_post_status( $post_id );
		$new_post_status = $post_data['post_status'];

		// If any published post's status has changed.
		if ( 'publish' === $old_post_status && 'trash' !== $new_post_status ) {
			$this->clear_cache_on_post_save( $post_id );
		}
	}

	/**
	 * Clear the cache if a comment is posted.
	 *
	 * @param int        $comment_id The comment ID number.
	 * @param int|string $comment_approved 1 if the comment is approved, 0 if not, 'spam' if spam.
	 */
	public function on_comment_post( $comment_id, $comment_approved ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// Check if comment is approved.
		if ( 1 === $comment_approved ) {
			$this->clear_cache_on_comment_save( $comment_id );
		}
	}


	/**
	 * Clear cache if a comment is edited.
	 *
	 * @param int   $comment_id The comment ID number.
	 * @param array $comment_data Data connected to the comment.
	 */
	public function on_edit_comment( $comment_id, $comment_data ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$comment_approved = (int) $comment_data['comment_approved'];
		if ( 1 === $comment_approved ) {
			$this->clear_cache_on_comment_save( $comment_id );
		}
	}

	/**
	 * Clear cache if comment status changes.
	 *
	 * @param string $new_status The new comment status.
	 * @param string $old_status The old comment status.
	 * @param object $comment WP_Comment object.
	 */
	public function on_transition_comment_status( $new_status, $old_status, $comment ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// Check if status has been changed to or from 'approved'.
		if ( 'approved' === $old_status || 'approved' === $new_status ) {
			$this->clear_cache_on_comment_save( $comment );
		}
	}

	/**
	 * WooCommerce stock hooks
	 *
	 * @param int|object $product The product ID or a WC_Product instance.
	 */
	public function on_woocommerce_stock_update( $product ) {
		// Get the product ID.
		if ( is_int( $product ) ) {
			$product_id = $product;
		} elseif ( is_object( $product ) ) {
			$product_id = $product->get_id();
		} else {
			return;
		}
		$this->clear_cache_on_post_save( $product_id );
	}

	/**
	 * Clear the whole cache.
	 */
	public function clear_complete_cache() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$network_wide = $this->is_plugin_active_for_network();

		$this->each_site( $network_wide, array( $this, 'clear_site_cache' ) );

		// Delete cache size transient(s).
		$this->each_site( $network_wide, 'delete_transient', array( $this->get_cache_size_transient_name() ) );
	}

	/**
	 * Clear the cache for a site.
	 */
	public function clear_site_cache() {
		$this->clear_site_cache_by_blog_id( get_current_blog_id() );
	}

	/**
	 * Clear cached pages that might have changed from any new or updated post.
	 *
	 * @param WP_Post $post The post instance.
	 */
	public function clear_associated_cache( $post ) {
		// Clear post type archives.
		$this->clear_post_type_archives_cache( $post->post_type );

		// Clear taxonomy archives.
		$this->clear_taxonomies_archives_cache_by_post_id( $post->ID );

		if ( 'post' === $post->post_type ) {
			$this->clear_author_archives_cache_by_user_id( $post->post_author );
			$this->clear_date_archives_cache_by_post_id( $post->ID );
		}
	}

	/**
	 * Clear post type archives page cache.
	 *
	 * @param string $post_type The post type to clear.
	 */
	public function clear_post_type_archives_cache( $post_type ) {
		$post_type_archives_url = get_post_type_archive_link( $post_type );

		// If an archive page exists for this post type, clear the archive page and its pagination page(s) cache.
		if ( ! empty( $post_type_archives_url ) ) {
			$this->clear_page_cache_by_url( $post_type_archives_url, 'pagination' );
		}
	}

	/**
	 * Clear taxonomy archives pages cache by post ID.
	 *
	 * @param int $post_id The post ID number.
	 */
	public function clear_taxonomies_archives_cache_by_post_id( $post_id ) {
		$taxonomies = get_taxonomies();

		foreach ( $taxonomies as $taxonomy ) {
			if ( wp_count_terms( $taxonomy ) > 0 ) {
				// Get terms attached to post.
				$term_ids = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
				foreach ( $term_ids as $term_id ) {
					$term_archives_url = get_term_link( (int) $term_id, $taxonomy );
					// If the term archive URL exists and does not have a query string.
					if ( ! is_wp_error( $term_archives_url ) && false === strpos( $term_archives_url, '?' ) ) {
						// Clear taxonomy archives page and its pagination page(s) cache.
						$this->clear_page_cache_by_url( $term_archives_url, 'pagination' );
					}
				}
			}
		}
	}

	/**
	 * Clear author archives page cache by user ID.
	 *
	 * @param int $user_id The user ID of the author.
	 */
	public function clear_author_archives_cache_by_user_id( $user_id ) {
		// Get author archives URL.
		$author_username     = get_the_author_meta( 'user_login', $user_id );
		$author_base         = $GLOBALS['wp_rewrite']->author_base;
		$author_archives_url = home_url( '/' ) . $author_base . '/' . $author_username;

		// Clear author archives page and its pagination page(s) cache.
		$this->clear_page_cache_by_url( $author_archives_url, 'pagination' );
	}

	/**
	 * Clear date archives page cache.
	 *
	 * @param int $post_id The post ID number.
	 */
	public function clear_date_archives_cache_by_post_id( $post_id ) {
		// Get post dates.
		$post_date_day   = get_the_date( 'd', $post_id );
		$post_date_month = get_the_date( 'm', $post_id );
		$post_date_year  = get_the_date( 'Y', $post_id );

		// Get post date archive URLs.
		$date_archives_day_url   = get_day_link( $post_date_year, $post_date_month, $post_date_day );
		$date_archives_month_url = get_month_link( $post_date_year, $post_date_month );
		$date_archives_year_url  = get_year_link( $post_date_year );

		// Clear date archive pages and their pagination pages cache.
		$this->clear_page_cache_by_url( $date_archives_day_url, 'pagination' );
		$this->clear_page_cache_by_url( $date_archives_month_url, 'pagination' );
		$this->clear_page_cache_by_url( $date_archives_year_url, 'pagination' );
	}

	/**
	 * Clear page cache by post ID.
	 *
	 * @param int    $post_id The post ID number.
	 * @param string $clear_type Clear the `pagination` cache or all `subpages` cache instead of only the cached `page`.
	 */
	public function clear_page_cache_by_post_id( $post_id, $clear_type = 'page' ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( empty( $post_id ) ) {
			return;
		}
		if ( ! is_int( $post_id ) && is_numeric( $post_id ) ) {
			$post_id = (int) $post_id;
		}
		if ( ! is_int( $post_id ) ) {
			return;
		}
		$page_url = get_permalink( $post_id );

		// Clear page cache for post.
		if ( ! empty( $page_url ) && false === strpos( $page_url, '?' ) ) {
			$this->clear_page_cache_by_url( $page_url, $clear_type );
		}
	}

	/**
	 * Clear page cache by URL.
	 *
	 * @param string $clear_url URL of a page.
	 * @param string $clear_type Clear the `pagination` cache or all `subpages` cache instead of only the cached `page`.
	 */
	public function clear_page_cache_by_url( $clear_url, $clear_type = 'page' ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! is_string( $clear_url ) ) {
			return;
		}
		// Validate the given URL.
		if ( ! filter_var( $clear_url, FILTER_VALIDATE_URL ) ) {
			return;
		}

		Disk_Cache::clear_cache( $clear_url, $clear_type );
	}

	/**
	 * Clear cache by blog ID.
	 *
	 * @param int  $blog_id The blog ID number.
	 * @param bool $delete_cache_size_transient Whether or not the cache size transient should be deleted. Optional, defaults to true.
	 */
	public function clear_site_cache_by_blog_id( $blog_id, $delete_cache_size_transient = true ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		if ( ! is_int( $blog_id ) && is_numeric( $blog_id ) ) {
			$blog_id = (int) $blog_id;
		}
		if ( ! is_int( $blog_id ) ) {
			return;
		}

		// Check if the blog ID exists.
		if ( ! in_array( $blog_id, $this->get_blog_ids(), true ) ) {
			return;
		}

		// Make sure when we clear a site cache that we've made it the "current" site/blog, so that all subsequent actions are site-specific.
		if ( is_multisite() ) {
			switch_to_blog( $blog_id );
		}

		// Disable the hook that fires when a page/URL is cleared.
		$this->fire_page_cache_cleared_hook = false;

		// Get clear URL.
		$site_url = home_url();

		// Get all cache objects for the site.
		$site_objects = Disk_Cache::get_site_objects( $site_url );

		// Then clear the cache for each page.
		foreach ( $site_objects as $site_object ) {
			$this->clear_page_cache_by_url( trailingslashit( $site_url ) . $site_object, 'subpages' );
		}

		// Finally clear the home page cache.
		$this->clear_page_cache_by_url( $site_url );

		// Delete cache size transient.
		if ( $delete_cache_size_transient ) {
			delete_transient( $this->get_cache_size_transient_name() );
		}

		if ( is_multisite() ) {
			restore_current_blog();
		}
	}

	/**
	 * Clear cache when any post type is created or updated.
	 *
	 * @param int|object $post The post ID number or a WP_POST instance.
	 */
	public function clear_cache_on_post_save( $post ) {
		if ( ! is_object( $post ) ) {
			if ( is_int( $post ) ) {
				$post = get_post( $post );
			} else {
				return;
			}
		}

		// If setting enabled clear complete cache.
		if ( Cache_Engine::$settings['clear_complete_cache_on_saved_post'] ) {
			$this->clear_site_cache();
			// Otherwise, just clear the associated caches.
		} else {
			$this->clear_page_cache_by_post_id( $post->ID );
			// Clear associated cache.
			$this->clear_associated_cache( $post );
		}
	}

	/**
	 * Clear the cache when a comment has been posted or modified.
	 *
	 * @param int|object $comment The comment ID# or a WP_Comment instance.
	 */
	public function clear_cache_on_comment_save( $comment ) {
		if ( ! is_object( $comment ) ) {
			if ( is_int( $comment ) ) {
				$comment = get_comment( $comment );
			} else {
				return;
			}
		}

		if ( Cache_Engine::$settings['clear_complete_cache_on_saved_comment'] ) {
			$this->clear_site_cache();
		} else {
			$this->clear_page_cache_by_post_id( $comment->comment_post_ID );
		}
	}

	/**
	 * Clear any server caches we can find.
	 */
	private function clear_server_caches() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// Check user has permissions to clear cache.
		if ( ! $this->user_can_clear_cache() ) {
			return;
		}
		if ( ! apply_filters( 'swis_clear_server_caches', true ) ) {
			return;
		}
		$this->clear_object_cache();
		// WP Engine.
		if ( class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_memcached' ) ) {
			WpeCommon::purge_memcached();
			WpeCommon::purge_varnish_cache();
		}
		// SG Optimizer by Siteground.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		// LiteSpeed.
		if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_all' ) ) {
			LiteSpeed_Cache_API::purge_all();
		}
		// Pagely.
		if ( class_exists( 'PagelyCachePurge' ) && method_exists( 'PagelyCachePurge', 'purgeAll' ) ) {
			PagelyCachePurge::purgeAll();
		}
		// Cloudways.
		do_action( 'breeze_clear_varnish' );
		// SpinupWP.
		do_action( 'spinupwp_purge_object_cache' );
		do_action( 'spinupwp_purge_page_cache' );
	}

	/**
	 * Clear the WP object cache.
	 */
	private function clear_object_cache() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// Check user has permissions to clear cache.
		if ( ! $this->user_can_clear_cache() ) {
			return;
		}
		global $wp_object_cache;
		// Per WPE, sometimes this crashes, so catching the exception should help.
		if ( $wp_object_cache && is_object( $wp_object_cache ) ) {
			try {
				\wp_cache_flush();
			} catch ( Exception $ex ) {
				$this->debug_message( 'error flushing WP object cache: ' . $ex->getMessage() . "\n" );
			}
		}
	}

	/**
	 * Check plugin requirements.
	 */
	public function requirements_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		// Check advanced-cache.php drop-in.
		if ( ! get_option( 'swis_activation' ) && ! is_file( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			echo '<div class="notice notice-warning"><p>' .
				sprintf(
					/* translators: 1: SWIS Performance 2: advanced-cache.php 3: wp-content/plugins/swis-performance/assets/ 4: wp-content/ */
					esc_html__( '%1$s requires the %2$s drop-in. Please disable and then re-enable Page Caching to automatically copy this file or manually copy it from the %3$s directory to the %4$s directory.', 'swis-performance' ),
					'<strong>SWIS Performance</strong>',
					'<code>advanced-cache.php</code>',
					'<code>wp-content/plugins/swis-performance/assets/</code>',
					'<code>wp-content/</code>'
				) .
				'</p></div>';
		}

		// Warning if no custom permlink structure.
		if ( ! empty( Cache_Engine::$settings['permalink_structure'] ) && 'plain' === Cache_Engine::$settings['permalink_structure'] && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning"><p>' .
				sprintf(
					/* translators: 1: SWIS Performance 2: Permalink Settings */
					esc_html__( '%1$s requires custom permalinks (something besides the "Plain" permalink structure). Please change the permalink structure in the %2$s.', 'swis-performance' ),
					'<strong>SWIS Performance</strong>',
					'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Permalink Settings', 'swis-performance' ) . '</a>'
				) .
				'</p></div>';
		}

		// Permission check, can't do much without a writable cache directory.
		if ( file_exists( Disk_Cache::$cache_dir ) && ! is_writable( Disk_Cache::$cache_dir ) ) {
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

		// Check WP_CACHE constant.
		if ( ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) && ! get_option( 'swis_activation' ) ) {
			// Check also to see if permissions allow modifying wp-config.php.
			if ( file_exists( trailingslashit( ABSPATH ) . 'wp-config.php' ) && ! is_writable( trailingslashit( ABSPATH ) . 'wp-config.php' ) ) {
				echo '<div class="notice notice-warning"><p>' .
					sprintf(
						/* translators: 1: SWIS Performance 2: define( 'WP_CACHE', true ); 3: wp-config.php 4: file permissions */
						esc_html__( '%1$s could not set %2$s in the %3$s file. Please change the %4$s or add it manually to enable Page Caching.', 'swis-performance' ),
						'<strong>SWIS Performance</strong>',
						"<code>define( 'WP_CACHE', true );</code>",
						'<code>wp-config.php</code>',
						'<a href="https://wordpress.org/support/article/changing-file-permissions/" target="_blank">' . esc_html__( 'file permissions', 'swis-performance' ) . '</a>'
					) .
					'</p></div>';
			} else {
				echo '<div class="notice notice-warning"><p>' .
					sprintf(
						/* translators: 1: define( 'WP_CACHE', true ); 2: wp-config.php */
						esc_html__( '%1$s requires %2$s to be set for Page Caching. Please set this in the %3$s file.', 'swis-performance' ),
						'<strong>SWIS Performance</strong>',
						"<code>define( 'WP_CACHE', true );</code>",
						'<code>wp-config.php</code>'
					) .
					'</p></div>';
			}
		}
	}

	/**
	 * Validate a regex pattern.
	 *
	 * @param string $regex A (potential) regex pattern.
	 * @return string The regex pattern or an empty string if input is invalid.
	 */
	public function validate_regex( $regex ) {
		if ( ! empty( $regex ) ) {
			if ( ! preg_match( '/^\/.*\/$/', $regex ) ) {
				$regex = '/' . $regex . '/';
			}

			// If it returns false, that's an error condition for a bogus pattern.
			if ( @preg_match( $regex, null ) === false ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return '';
			}

			return sanitize_text_field( $regex );
		}
		return '';
	}

	/**
	 * Validate settings.
	 *
	 * @param array $settings The cache settings.
	 * @return array The validated settings.
	 */
	public function validate_settings( $settings ) {
		// Check if empty.
		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return;
		}

		$system_settings = array(
			'permalink_structure' => (string) $this->get_permalink_structure(),
		);

		$validated_settings = array(
			'expires'                                => ! empty( $settings['expires'] ) ? (int) $settings['expires'] : 0,
			'clear_complete_cache_on_changed_plugin' => 1, /* Change this if we add it to the UI: (int) ( ! empty( $data['clear_complete_cache_on_changed_plugin'] ) ), */
			'clear_complete_cache_on_saved_post'     => (int) ( ! empty( $settings['clear_complete_cache_on_saved_post'] ) ),
			'clear_complete_cache_on_saved_comment'  => (int) ( ! empty( $settings['clear_complete_cache_on_saved_comment'] ) ),
			'webp'                                   => (int) ( ! empty( $settings['webp'] ) ),
			'mobile'                                 => (int) ( ! empty( $settings['mobile'] ) ),
			'exclusions'                             => ! empty( $settings['exclusions'] ) ? $this->validate_user_exclusions( $settings['exclusions'] ) : '',
			'excluded_cookies'                       => ! empty( $settings['excluded_cookies'] ) ? (string) $this->validate_regex( $settings['excluded_cookies'] ) : '',
			'excluded_query_strings'                 => ! empty( $settings['excluded_query_strings'] ) ? (string) $this->validate_regex( $settings['excluded_query_strings'] ) : '',
		);
		$validated_settings = wp_parse_args( $validated_settings, $system_settings );
		return $validated_settings;
	}
}
