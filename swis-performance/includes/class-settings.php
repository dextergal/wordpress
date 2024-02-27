<?php
/**
 * Class and methods to work with plugin settings.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enables plugin to filter HTML through a variety of functions.
 */
final class Settings extends Base {

	/**
	 * Register hook function to startup buffer.
	 */
	function __construct() {
		parent::__construct();
		add_action( 'init', array( $this, 'process_asset_cache_request' ) );
		add_action( 'init', array( $this, 'plugin_updater' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ), 11 );
		add_action( 'admin_notices', array( $this, 'cache_cleared_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'cache_cleared_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SWIS_PLUGIN_FILE ), array( $this, 'settings_link' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu_item' ), 90 );
		add_action( 'admin_bar_menu', array( $this, 'add_asset_admin_bar_menu_item' ), 95 );
		add_action( 'admin_bar_menu', array( $this, 'add_debug_admin_bar_menu_item' ), 96 );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'network_admin_menu', array( $this, 'network_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'settings_script' ) );
		add_action( 'admin_action_swis_remove_license', array( $this, 'remove_license' ) );
		add_action( 'admin_action_swis_retest_background_mode', array( $this, 'retest_background_optimization' ) );
		add_action( 'admin_action_swis_view_debug_log', array( $this, 'view_debug_log' ) );
		add_action( 'admin_action_swis_delete_debug_log', array( $this, 'delete_debug_log' ) );
		add_action( 'wp_ajax_swis_cache_preload_status', array( $this, 'cache_preload_status_ajax' ) );
		add_action( 'wp_ajax_swis_generate_css_status', array( $this, 'generate_css_status_ajax' ) );
		add_action( 'shutdown', array( $this, 'debug_log' ), PHP_INT_MAX );
	}

	/**
	 * Plugin updater initialization.
	 */
	function plugin_updater() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		// To support auto-updates, this needs to run during the wp_version_check cron job for privileged users.
		$doing_cron = defined( 'DOING_CRON' ) && DOING_CRON;
		if ( ! current_user_can( 'manage_options' ) && ! $doing_cron ) {
			return;
		}

		// Sets up the update checker.
		if ( get_option( 'swis_license' ) && 'valid' === get_option( 'swis_license_status' ) ) {
			$edd_updater = new EDD_SL_Plugin_Updater(
				SWIS_SL_STORE_URL,
				SWIS_PLUGIN_FILE,
				array(
					'version' => '1.7.0',
					'license' => get_option( 'swis_license' ),
					'item_id' => SWIS_SL_ITEM_ID,
					'author'  => 'Shane Bishop',
					'url'     => home_url(),
				)
			);
		}
	}

	/**
	 * Settings initialization for WP-admin.
	 */
	function admin_init() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$this->upgrade();
		register_setting( 'swis_performance_options', 'swis_performance', array( $this, 'validate_settings' ) );
		$this->activate_license();
	}

	/**
	 * Admin notices for settings class, and remove the activation option after other SWIS notices have fired.
	 */
	function admin_notices() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if (
			isset( $_GET['swis_activation_nonce'] ) &&
			check_admin_referer( 'swis_activation_nonce', 'swis_activation_nonce' ) &&
			isset( $_GET['swis_activation'] )
		) {
			if ( empty( $_GET['swis_activation'] ) && ! empty( $_GET['message'] ) ) {
				$message = urldecode( sanitize_text_field( wp_unslash( $_GET['message'] ) ) );
				?>
				<div id="swis-activation-failed" class="error">
					<p>
						<?php echo esc_html( $message ); ?>
					</p>
				</div>
				<?php
			} elseif ( ! empty( $_GET['swis_activation'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' .
					esc_html__( 'License activation successful.', 'swis-performance' ) .
					'</p></div>';
			}
		}
		delete_option( 'swis_activation' );
	}

	/**
	 * Display notice after clearing the cache.
	 */
	function cache_cleared_notice() {
		// Check user has permissions to clear cache.
		if ( ! $this->user_can_clear_cache() ) {
			return;
		}
		if ( get_transient( $this->get_cache_cleared_transient_name() ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'The cache has been cleared.', 'swis-performance' )
			);
			delete_transient( $this->get_cache_cleared_transient_name() );
		}
	}

	/**
	 * Settings validation and sanitation.
	 *
	 * @param array $settings The plugin options in an associative array.
	 */
	function validate_settings( $settings ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return array();
		}
		return array(
			'background_processing' => ! empty( $settings['background_processing'] ),
			'debug'                 => ! empty( $settings['debug'] ),
			'defer_js'              => ! empty( $settings['defer_js'] ),
			'defer_jquery_safe'     => true,
			'defer_js_exclude'      => ! empty( $settings['defer_js_exclude'] ) ? $this->sanitize_textarea_exclusions( $settings['defer_js_exclude'] ) : '',
			'defer_css'             => ! empty( $settings['defer_css'] ),
			'defer_css_exclude'     => ! empty( $settings['defer_css_exclude'] ) ? $this->sanitize_textarea_exclusions( $settings['defer_css_exclude'] ) : '',
			'minify_js'             => ! empty( $settings['minify_js'] ),
			'minify_js_exclude'     => ! empty( $settings['minify_js_exclude'] ) ? $this->sanitize_textarea_exclusions( $settings['minify_js_exclude'] ) : '',
			'minify_css'            => ! empty( $settings['minify_css'] ),
			'minify_css_exclude'    => ! empty( $settings['minify_css_exclude'] ) ? $this->sanitize_textarea_exclusions( $settings['minify_css_exclude'] ) : '',
			'critical_css'          => ! empty( $settings['critical_css'] ) ? sanitize_textarea_field( $settings['critical_css'] ) : '',
			'critical_css_key'      => ! empty( $settings['critical_css_key'] ) ? sanitize_textarea_field( $settings['critical_css_key'] ) : '',
			'pre_hint_domains'      => ! empty( $settings['pre_hint_domains'] ) ? $this->sanitize_textarea_exclusions( $settings['pre_hint_domains'] ) : '',
			'cdn_domain'            => ! empty( $settings['cdn_domain'] ) ? $this->sanitize_cdn_domain( $settings['cdn_domain'] ) : '',
			'cdn_all_the_things'    => true,
			'cdn_exclude'           => ! empty( $settings['cdn_exclude'] ) ? $this->sanitize_textarea_exclusions( $settings['cdn_exclude'] ) : '',
			'slim_js_css'           => isset( $settings['slim_js_css'] ) ? $this->sanitize_textarea_exclusions( $settings['slim_js_css'], false ) : $this->get_option( 'slim_js_css' ),
			'cache'                 => ! empty( $settings['cache'] ),
			'cache_settings'        => ! empty( $settings['cache_settings'] ) ? swis()->cache->validate_settings( $settings['cache_settings'] ) : swis()->cache->validate_settings( swis()->cache->get_settings() ),
			'cache_preload'         => ! empty( $settings['cache_preload'] ),
			'optimize_fonts'        => ! empty( $settings['optimize_fonts'] ),
			'optimize_fonts_css'    => ! empty( $settings['optimize_fonts_css'] ) ? sanitize_textarea_field( $settings['optimize_fonts_css'] ) : '',
			'optimize_fonts_list'   => ! empty( $settings['optimize_fonts_list'] ) ? $this->sanitize_textarea_exclusions( $settings['optimize_fonts_list'] ) : '',
		);
	}

	/**
	 * Set default options for plugin.
	 */
	function set_defaults() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$defaults = array(
			'debug'               => false,
			'defer_js'            => false,
			'defer_jquery_safe'   => true,
			'defer_js_exclude'    => '',
			'defer_css'           => false,
			'defer_css_exclude'   => '',
			'minify_js'           => false,
			'minify_js_exclude'   => '',
			'minify_css'          => false,
			'minify_css_exclude'  => '',
			'critical_css'        => '',
			'critical_css_key'    => '',
			'pre_hint_domains'    => '',
			'cdn_domain'          => '',
			'cdn_all_the_things'  => true,
			'cdn_exclude'         => '',
			'slim_js_css'         => '',
			'cache'               => ! swis()->cache->should_disable_caching() && ! swis()->cache->server_cache_detected(),
			'cache_settings'      => array(),
			'cache_preload'       => false,
			'optimize_fonts'      => false,
			'optimize_fonts_css'  => '',
			'optimize_fonts_list' => '',
		);
		add_option( 'swis_performance', $defaults );
	}

	/**
	 * Check for upgrade actions to process.
	 */
	function upgrade() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		global $swis_upgrading;
		$swis_upgrading = false;
		if ( ! get_option( 'swis_version' ) || get_option( 'swis_version' ) < SWIS_PLUGIN_VERSION ) {
			if ( wp_doing_ajax() ) {
				return;
			}
			$swis_upgrading = true;
			$this->install_table();
			$this->set_defaults();
			$this->enable_background_option();
			if ( ! get_option( 'swis_version' ) ) {
				swis()->gzip->insert_htaccess_rules();
			}
			if ( $this->get_option( 'cdn_domain' ) ) {
				if ( 'external' === get_option( 'elementor_css_print_method' ) ) {
					update_option( 'elementor_css_print_method', 'internal' );
				}
				if ( function_exists( 'et_get_option' ) && function_exists( 'et_update_option' ) && 'on' === et_get_option( 'et_pb_static_css_file', 'on' ) ) {
					et_update_option( 'et_pb_static_css_file', 'off' );
					et_update_option( 'et_pb_css_in_footer', 'off' );
				}
			}
			if ( $this->is_file( WP_CONTENT_DIR . '/advanced-cache.php' ) && filesize( dirname( SWIS_PLUGIN_FILE ) . '/assets/advanced-cache.php' ) !== filesize( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
				swis()->cache->on_swis_update();
			}
			update_option( 'swis_version', SWIS_PLUGIN_VERSION );
		}
	}

	/**
	 * Adds/upgrades table in db for storing async/background queues.
	 *
	 * @global object $wpdb
	 */
	function install_table() {
		$this->debug_message( '<b>' . __FUNCTION__ . '()</b>' );
		global $wpdb;
		$wpdb->swis_queue = $wpdb->prefix . 'swis_queue';

		/*
		 * Create a table with 4 columns:
		 * id: unique for each record/page,
		 * page_url: the URL of the page to process,
		 * queue_name: a unique identifier for the queue items.
		 * params: any auxilliary parameters needed to process the queue item.
		 * attempts: 0 when the page is queued, incremented until max_attempts is reached.
		 */
		$sql = "CREATE TABLE $wpdb->swis_queue (
			id int unsigned NOT NULL AUTO_INCREMENT,
			page_url varchar(4096),
			queue_name varchar(20),
			params text,
			attempts tinyint NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY page_url (page_url(191))
		) COLLATE utf8_general_ci;";

		// Include the upgrade library to install/upgrade a table.
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		$updates = dbDelta( $sql );
		$this->debug_message( 'queue db upgrade results: ' . implode( '<br>', $updates ) );

		$wpdb->swis_critical_css = $wpdb->prefix . 'swis_critical_css';

		/*
		 * Create a table with 4 columns:
		 * id: unique for each record/page,
		 * page_url: the URL of the page to process,
		 * type: the post type/template connected to the page.
		 * result_id: a unique identifier for the API result.
		 * validation_status: the quality of the generated CSS.
		 */
		$sql = "CREATE TABLE $wpdb->swis_critical_css (
			id int unsigned NOT NULL AUTO_INCREMENT,
			page_url varchar(4096),
			type varchar (64),
			result_id varchar(32),
			validation_status varchar(32),
			PRIMARY KEY  (id),
			KEY page_url (page_url(191))
		) COLLATE utf8_general_ci;";

		$updates = dbDelta( $sql );
		$this->debug_message( 'ccss db upgrade results: ' . implode( '<br>', $updates ) );

	}

	/**
	 * Tests background/async request functionality.
	 *
	 * Send a known packet to admin-ajax.php via the Test_Async_Request class.
	 */
	function enable_background_option() {
		$this->debug_message( '<b>' . __FUNCTION__ . '()</b>' );
		if ( defined( 'SWIS_DISABLE_ASYNC' ) && SWIS_DISABLE_ASYNC ) {
			return;
		}
		$test_async = new Test_Async_Request();
		$this->set_option( 'background_processing', false );
		$this->debug_message( 'running test async handler' );
		$test_async->data( array( 'swis_test_verify' => '949c34123cf2a4e4ce2f985135830df4a1b2adc24905f53d2fd3f5df5b162932' ) )->dispatch();
	}

	/**
	 * Re-tests background mode at a user's request.
	 */
	function retest_background_optimization() {
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! current_user_can( $permissions ) || ! check_admin_referer( 'swis_retest_background_nonce', 'swis_retest_background_nonce' ) ) {
			wp_die( esc_html__( 'Access denied', 'swis-performance' ) );
		}
		$this->enable_background_option();
		if ( $this->function_exists( 'sleep' ) ) {
			sleep( 10 );
		}
		$base_url = admin_url( 'options-general.php?page=swis-performance-options' );
		wp_safe_redirect( $base_url );
		exit;
	}

	/**
	 * Adds a link on the plugins page for the settings page.
	 *
	 * @param array $links A list of links to display next to the plugin listing.
	 * @return array The modified list of links to be displayed.
	 */
	public function settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		$settings_link = '<a href="' . admin_url( 'options-general.php?page=swis-performance-options' ) . '">' . esc_html__( 'Settings', 'swis-performance' ) . '</a>';
		$docs_link     = '<a href="https://docs.ewww.io/category/85-swis-performance" target="_blank">' . esc_html__( 'Docs', 'swis-performance' ) . '</a>';
		$support_link  = '<a href="https://ewww.io/contact-us/" target="_blank">' . esc_html__( 'Support', 'swis-performance' ) . '</a>';
		array_unshift( $links, $support_link );
		array_unshift( $links, $docs_link );
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Adds the SWIS menu item to the wp admin bar.
	 *
	 * @param object $wp_admin_bar The WP Admin Bar object, passed by reference.
	 */
	function add_admin_bar_menu_item( $wp_admin_bar ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if (
			! current_user_can( $permissions ) ||
			! is_admin_bar_showing()
		) {
			return;
		}
		$menu_args = array(
			'id'     => 'swis',
			'parent' => null,
			'title'  => '<span id="swis-slim-show-top"><span class="ab-icon"></span><span class="ab-label">' . __( 'SWIS', 'swis-performance' ) . '</span></span>',
			'meta'   => array(
				'title' => esc_html__( 'SWIS Performance', 'swis-performance' ),
			),
		);
		if ( is_admin() ) {
			$menu_args['href'] = admin_url( 'options-general.php?page=swis-performance-options' );
		}
		$wp_admin_bar->add_node( $menu_args );
	}

	/**
	 * Adds the JS/CSS purge menu item to the wp admin bar.
	 *
	 * @param object $wp_admin_bar The WP Admin Bar object, passed by reference.
	 */
	function add_asset_admin_bar_menu_item( $wp_admin_bar ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if (
			! current_user_can( $permissions ) ||
			! is_admin_bar_showing()
		) {
			return;
		}
		if ( ! $this->get_option( 'critical_css_key' ) && ! $this->get_option( 'minify_js' ) && ! $this->get_option( 'minify_css' ) ) {
			return;
		}
		$wp_admin_bar->add_menu(
			array(
				'id'     => 'swis-clear-asset-cache',
				'href'   => wp_nonce_url(
					add_query_arg(
						array(
							'_cache'  => 'swis-cache',
							'_action' => 'swis_asset_cache_clear',
						)
					),
					'swis_cache_clear_nonce'
				),
				'parent' => 'swis',
				'title'  => '<span class="ab-item">' . esc_html__( 'Clear JS/CSS Cache', 'swis-performance' ) . '</span>',
				'meta'   => array(
					'title' => esc_html__( 'Clear JS/CSS Cache', 'swis-performance' ),
				),
			)
		);
	}

	/**
	 * Adds a menu item to the wp admin bar which temporarily disables SWIS via query string.
	 *
	 * @param object $wp_admin_bar The WP Admin Bar object, passed by reference.
	 */
	function add_debug_admin_bar_menu_item( $wp_admin_bar ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if (
			! current_user_can( $permissions ) ||
			! is_admin_bar_showing() ||
			! $this->is_frontend()
		) {
			return;
		}
		$wp_admin_bar->add_menu(
			array(
				'id'     => 'swis-disable',
				'href'   => add_query_arg(
					array(
						'swis_disable' => '1',
					)
				),
				'parent' => 'swis',
				'title'  => '<span class="ab-item">' . esc_html__( 'View page without SWIS', 'swis-performance' ) . '</span>',
				'meta'   => array(
					'title' => esc_html__( 'View page without SWIS', 'swis-performance' ),
				),
			)
		);
	}

	/**
	 * Adds a settings page to the network admin menu.
	 */
	function network_admin_menu() {
	}

	/**
	 * Adds a settings page to the admin Settings menu.
	 */
	function admin_menu() {
		add_options_page(
			'SWIS Performance',                                                      // Page title.
			'SWIS Performance',                                                      // Menu title.
			apply_filters( 'swis_performance_admin_permissions', 'manage_options' ), // Capability.
			'swis-performance-options',                                              // Slug.
			array( $this, 'display_settings' )                                       // Function to call.
		);
	}

	/**
	 * Adds version information to the in-memory debug log.
	 *
	 * @global int $wp_version
	 */
	function debug_version_info() {
		self::$debug .= 'SWIS version: ' . SWIS_PLUGIN_VERSION . '<br>';

		// Check the WP version.
		global $wp_version;
		self::$debug .= "WP version: $wp_version<br>";

		if ( defined( 'PHP_VERSION_ID' ) ) {
			self::$debug .= 'PHP version: ' . PHP_VERSION_ID . '<br>';
		}
	}

	/**
	 * Send debug information to the buffer for the options page (and beacon).
	 */
	function debug_info() {
		global $content_width;
		global $swis_temp_debug;
		$swis_temp_debug = false;
		if ( ! $this->get_option( 'debug' ) ) {
			$swis_temp_debug = true;
		}
		$this->debug_version_info();
		$this->debug_message( 'ABSPATH: ' . ABSPATH );
		$this->debug_message( 'WP_CONTENT_DIR: ' . WP_CONTENT_DIR );
		$this->debug_message( 'get_home_url (Site URL): ' . get_home_url() );
		$this->debug_message( 'get_site_url (WordPress URL): ' . get_site_url() );
		$upload_info = wp_get_upload_dir();
		$this->debug_message( 'wp_upload_dir (baseurl): ' . $upload_info['baseurl'] );
		$this->debug_message( 'wp_upload_dir (basedir): ' . $upload_info['basedir'] );
		if ( isset( $content_width ) ) {
			$this->debug_message( "content_width: $content_width" );
		} else {
			$this->debug_message( 'content_width unset' );
		}
		if ( is_multisite() ) {
			$this->debug_message( 'multisite install' );
			if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
				// Need to include the plugin library for the is_plugin_active function.
				require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
			}
			if ( is_plugin_active_for_network( plugin_basename( SWIS_PLUGIN_FILE ) ) ) {
				$this->debug_message( 'network-active' );
			} else {
				$this->debug_message( 'single-site-active' );
			}
		}
		if ( wp_using_ext_object_cache() ) {
			$this->debug_message( 'using external object cache' );
		} else {
			$this->debug_message( 'not external cache' );
		}
		if ( false === strpos( self::$debug, 'gd_support()' ) ) {
			$this->gd_support();
		}
		$this->debug_message( 'cache: ' . ( $this->get_option( 'cache' ) ? 'on' : 'off' ) );
		if ( $this->get_option( 'cache' ) ) {
			$cache_settings = swis()->cache->get_settings();
			$cache_exclude  = ! empty( $cache_settings['exclusions'] ) ? implode( "\n", $cache_settings['exclusions'] ) : '';
			$this->debug_message( 'cache expiration: ' . (int) $cache_settings['expires'] );
			$this->debug_message( 'cache exclusions:' );
			if ( ! empty( $cache_settings['exclusions'] ) ) {
				$this->debug_message( esc_html( implode( "\n", $cache_settings['exclusions'] ) ) );
			}
			$this->debug_message( 'cache clear on upgrade: ' . ( $cache_settings['clear_complete_cache_on_changed_plugin'] ? 'on' : 'off' ) );
			$this->debug_message( 'cache clear on new post: ' . ( $cache_settings['clear_complete_cache_on_saved_post'] ? 'on' : 'off' ) );
			$this->debug_message( 'cache clear on new comment: ' . ( $cache_settings['clear_complete_cache_on_saved_comment'] ? 'on' : 'off' ) );
			$this->debug_message( 'cache create WebP: ' . ( $cache_settings['webp'] ? 'on' : 'off' ) );
			$this->debug_message( 'cache create mobile: ' . ( $cache_settings['mobile'] ? 'on' : 'off' ) );
			$this->debug_message( 'cache exclude cookies: ' . $cache_settings['excluded_cookies'] );
			$this->debug_message( 'cache exclude query params: ' . $cache_settings['excluded_query_strings'] );
		}
		$this->debug_message( 'cache preload: ' . ( $this->get_option( 'cache_preload' ) ? 'on' : 'off' ) );
		$this->debug_message( 'defer css: ' . ( $this->get_option( 'defer_css' ) ? 'on' : 'off' ) );
		$this->debug_message( 'defer css exclusions:' );
		if ( $this->get_option( 'defer_css_exclude' ) ) {
			$this->debug_message( implode( "\n", $this->get_option( 'defer_css_exclude' ) ) );
		}
		$this->debug_message( 'critical css in use: ' . ( $this->get_option( 'critical_css' ) ? 'yes' : 'nope' ) );
		$this->debug_message( 'critical css key active: ' . ( $this->get_option( 'critical_css_key' ) ? 'yes' : 'nope' ) );
		$this->debug_message( 'minify css: ' . ( $this->get_option( 'minify_css' ) ? 'on' : 'off' ) );
		$this->debug_message( 'minify css exclusions:' );
		if ( $this->get_option( 'minify_css_exclude' ) ) {
			$this->debug_message( implode( "\n", $this->get_option( 'minify_css_exclude' ) ) );
		}
		$this->debug_message( 'defer js: ' . ( $this->get_option( 'defer_js' ) ? 'on' : 'off' ) );
		$this->debug_message( 'defer jquery (safe): ' . ( $this->get_option( 'defer_jquery_safe' ) ? 'on' : 'off' ) );
		$this->debug_message( 'defer js exclusions:' );
		if ( $this->get_option( 'defer_js_exclude' ) ) {
			$this->debug_message( implode( "\n", $this->get_option( 'defer_js_exclude' ) ) );
		}
		$this->debug_message( 'minify js: ' . ( $this->get_option( 'minify_js' ) ? 'on' : 'off' ) );
		$this->debug_message( 'minify js exclusions:' );
		if ( $this->get_option( 'minify_js_exclude' ) ) {
			$this->debug_message( implode( "\n", $this->get_option( 'minify_js_exclude' ) ) );
		}
		$this->debug_message( 'optimize Google fonts: ' . ( $this->get_option( 'optimize_fonts' ) ? 'on' : 'off' ) );
		$this->debug_message( 'Google fonts detected: ' . ( $this->get_option( 'optimize_fonts_css' ) ? 'yes' : 'not yet' ) );
		$this->debug_message( 'font handles disabled:' );
		if ( $this->get_option( 'optimize_fonts_list' ) ) {
			$this->debug_message( implode( "\n", $this->get_option( 'optimize_fonts_list' ) ) );
		}
		$this->debug_message( 'pre* hint domains:' );
		if ( $this->get_option( 'pre_hint_domains' ) ) {
			$this->debug_message( implode( "\n", $this->get_option( 'pre_hint_domains' ) ) );
		}
		$this->debug_message( 'CDN domain: ' . $this->get_option( 'cdn_domain' ) );
		$this->debug_message( 'CDN exclusions:' );
		if ( $this->get_option( 'cdn_exclude' ) ) {
			$this->debug_message( implode( "\n", $this->get_option( 'cdn_exclude' ) ) );
		}
		$this->debug_message( 'eliminate unused css/js:' );
		if ( $this->get_option( 'slim_js_css' ) ) {
			$this->debug_message( implode( "\n", $this->get_option( 'slim_js_css' ) ) );
		}
		$swis_temp_debug = false;
	}

	/**
	 * View the debug.log file from the wp-admin.
	 */
	function view_debug_log() {
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! current_user_can( $permissions ) ) {
			wp_die( esc_html__( 'Access denied.', 'swis-performance' ) );
		}
		if ( $this->is_file( WP_CONTENT_DIR . '/swis/debug.log' ) && is_readable( WP_CONTENT_DIR . '/swis/debug.log' ) ) {
			$this->ob_clean();
			header( 'Content-Type: text/plain;charset=UTF-8' );
			readfile( WP_CONTENT_DIR . '/swis/debug.log' );
			exit;
		}
		wp_die( esc_html__( 'The Debug Log is empty.', 'swis-performance' ) );
	}

	/**
	 * Removes the debug.log file from the wp-content/swis/ folder.
	 */
	function delete_debug_log() {
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! current_user_can( $permissions ) ) {
			wp_die( esc_html__( 'Access denied.', 'swis_performance' ) );
		}
		if ( $this->is_file( WP_CONTENT_DIR . '/swis/debug.log' ) && is_writable( WP_CONTENT_DIR . '/swis/debug.log' ) ) {
			unlink( WP_CONTENT_DIR . '/swis/debug.log' );
		}
		$sendback = wp_get_referer();
		wp_safe_redirect( $sendback );
		exit;
	}

	/**
	 * Displays the settings page (single-site for now).
	 */
	function display_settings() {
		swis()->gzip->insert_htaccess_rules();
		$this->debug_info();
		global $swis_upgrading;
		// HS Beacon data.
		$current_user = wp_get_current_user();
		$help_email   = $current_user->user_email;
		$hs_debug     = '';
		if ( ! empty( self::$debug ) ) {
			$hs_debug = str_replace( array( "'", '<br>', '<b>', '</b>', '=>' ), array( "\'", '\n', '**', '**', '=' ), self::$debug );
		}
		if ( get_option( 'swis_license' ) && get_option( 'swis_license_expires' ) && time() > get_option( 'swis_license_expires' ) ) {
			$license_error = $this->get_license_status();
		} elseif ( get_option( 'swis_license' ) && ! get_option( 'swis_license_expires' ) ) {
			$license_error = $this->get_license_status();
		} else {
			$license_error = '';
		}

		$cache_settings     = swis()->cache->get_settings();
		$cache_exclude      = ! empty( $cache_settings['exclusions'] ) ? implode( "\n", $cache_settings['exclusions'] ) : '';
		$defer_css_exclude  = $this->get_option( 'defer_css_exclude' ) ? implode( "\n", $this->get_option( 'defer_css_exclude' ) ) : '';
		$minify_css_exclude = $this->get_option( 'minify_css_exclude' ) ? implode( "\n", $this->get_option( 'minify_css_exclude' ) ) : '';
		$defer_js_exclude   = $this->get_option( 'defer_js_exclude' ) ? implode( "\n", $this->get_option( 'defer_js_exclude' ) ) : '';
		$minify_js_exclude  = $this->get_option( 'minify_js_exclude' ) ? implode( "\n", $this->get_option( 'minify_js_exclude' ) ) : '';
		$ll_exclude         = $this->get_option( 'll_exclude' ) ? implode( "\n", $this->get_option( 'll_exclude' ) ) : '';
		$pre_hint_domains   = $this->get_option( 'pre_hint_domains' ) ? implode( "\n", $this->get_option( 'pre_hint_domains' ) ) : '';
		$cdn_exclude        = $this->get_option( 'cdn_exclude' ) ? implode( "\n", $this->get_option( 'cdn_exclude' ) ) : '';
		$fonts_list         = $this->get_option( 'optimize_fonts_list' ) ? implode( "\n", $this->get_option( 'optimize_fonts_list' ) ) : '';
		?>
<div id='swis-settings-wrap' class='wrap'>
	<h1 style='display:none'>SWIS Performance</h1>
	<div id='swis-header'>
		<div id='swis-logo'>&nbsp;</div>
		<div id='swis-header-info'>
			<h2><?php esc_html_e( 'Getting Started', 'swis-performance' ); ?></h2>
			<p>
				<?php esc_html_e( 'We recommend you enable each of the options below one by one. Then test your site to make sure it is working normally, and move on to the next option.', 'swis-performance' ); ?><br>
				<?php
				printf(
					/* translators: %s: let us know */
					esc_html__( "Of course, if you run into any snags along the way, %s and we'll be happy to help!", 'swis-performance' ),
					'<a class="swis-contact-link" href="https://ewww.io/contact-us/" target="_blank">' . esc_html__( 'let us know', 'swis-performance' ) . '</a>'
				);
				?>
			</p>
			<p>
				<?php
				if ( ! defined( 'WPE_PLUGIN_VERSION' ) ) {
					printf(
						/* translators: %s: GZIP compression */
						esc_html__( '%s and enhanced Browser Caching (cache-control headers) are automatically enabled on supported servers.', 'swis-performance' ) . '<br>',
						'<a href="https://www.giftofspeed.com/gzip-test/" target="_blank">' . esc_html__( 'GZIP Compression', 'swis-performance' ) . '</a>'
					);
				}
				?>
			</p>
		</div>
	</div>
	<div id='swis-flex-wrap'>
		<form id='swis-settings-form' method='post' action='options.php'>
			<?php settings_fields( 'swis_performance_options' ); ?>
			<input type='hidden' name='swis_performance[background_processing]' value='<?php echo ( $this->get_option( 'background_processing' ) ? 1 : 0 ); ?>'>
			<table class='form-table'>
		<?php if ( ! swis()->cache->should_disable_caching() ) : ?>
				<tr>
					<th scope='row'>
						<label for='swis_cache'><?php esc_html_e( 'Page Caching', 'swis-performance' ); ?></label>
						<?php $this->help_link( 'https://docs.ewww.io/article/103-page-caching', '60ca687061c60c534bd66abd' ); ?>
					</th>
					<td>
						<input type='checkbox' id='swis_cache' name='swis_performance[cache]' value='true' <?php checked( $this->get_option( 'cache' ) ); ?> />
						<?php esc_html_e( 'Stores static copies of your pages to improve site response times.', 'swis-performance' ); ?>
			<?php if ( swis()->cache->server_cache_detected() ) : ?>
						<p class='description'>
							<?php esc_html_e( '*Your site may already have a server-based page cache. A server-based cache typically offers better performance than a plugin-based cache, but you may enable page caching in SWIS to extend the cache lifetime.', 'swis-performance' ); ?>
						</p>
			<?php endif; ?>
					</td>
				</tr>
				<tr id="swis_cache_expires_container"<?php echo $this->get_option( 'cache' ) ? '' : ' style="display:none"'; ?>>
					<th>&nbsp;</th>
					<td>
						<input type='text' id='swis_cache_expires' name='swis_performance[cache_settings][expires]' class='small-text' value='<?php echo (int) $cache_settings['expires']; ?>' />
						<label for='swis_cache_expires'><strong><?php esc_html_e( 'Expiration', 'swis-performance' ); ?></strong></label>
						<p class='description'>
							<?php esc_html_e( 'How long should pages be cached (in hours, set to 0 for no expiration).', 'swis-performance' ); ?>
						</p>
					</td>
				</tr>
				<tr id="swis_cache_webp_container"<?php echo $this->get_option( 'cache' ) ? '' : ' style="display:none"'; ?>>
					<th>&nbsp;</th>
					<td>
			<?php if ( class_exists( 'ExactDN' ) ) : ?>
						<input type='checkbox' id='swis_cache_webp' name='swis_performance[cache_settings][webp]' value='true' disabled />
						<label for='swis_cache_webp'><strong><?php esc_html_e( 'WebP Variant', 'swis-performance' ); ?></strong></label>
						<p class='description'>
							<?php esc_html_e( 'Easy IO automatically converts images to WebP.', 'swis-performance' ); ?>
						</p>
			<?php else : ?>
						<input type='checkbox' id='swis_cache_webp' name='swis_performance[cache_settings][webp]' value='true' <?php checked( $cache_settings['webp'] ); ?> />
						<label for='swis_cache_webp'><strong><?php esc_html_e( 'WebP Variant', 'swis-performance' ); ?></strong></label>
						<?php $this->help_link( 'https://docs.ewww.io/article/103-page-caching', '60ca687061c60c534bd66abd' ); ?>
						<p class='description'>
							<?php
							printf(
								/* translators: %s: EWWW Image Optimizer (linked to plugin search page) */
								esc_html__( 'Create an additional cached variant for browsers that support WebP. Convert your images to WebP with %s.', 'swis-performance' ),
								'<a href="' . esc_url( admin_url( 'plugin-install.php?s=ewww+image+optimizer&tab=search&type=term' ) ) . '">EWWW Image Optimizer</a>'
							);
							?>
						</p>
			<?php endif; ?>
					</td>
				</tr>
				<tr id="swis_cache_exclusions_container"<?php echo $this->get_option( 'cache' ) ? '' : ' style="display:none"'; ?>>
					<td>&nbsp;</td>
					<td>
						<label for='swis_cache_exclusions'><strong><?php esc_html_e( 'Exclusions', 'swis-performance' ); ?></strong></label><br>
						<textarea id='swis_cache_exclusions' name='swis_performance[cache_settings][exclusions]' rows='3'><?php echo esc_html( $cache_exclude ); ?></textarea>
						<p class='description'>
							<?php esc_html_e( 'One exclusion per line, no wildcards (*) needed. Use any string that matches the page(s) you wish to exclude.', 'swis-performance' ); ?>
						</p>
					</td>
				</tr>
		<?php else : ?>
				<tr>
					<td>&nbsp;</td>
					<td>
						<p class='description'>
							<?php esc_html_e( 'SWIS Page Caching is disabled because your site appears to have a page caching plugin already.', 'swis-performance' ); ?>
						</p>
					</td>
				</tr>
		<?php endif; ?>
				<tr>
					<th scope='row'>
						<label for='swis_cache_preload'><?php esc_html_e( 'Cache Preload', 'swis-performance' ); ?></label>
						<?php $this->help_link( 'https://docs.ewww.io/article/103-page-caching', '60ca687061c60c534bd66abd' ); ?>
					</th>
					<td>
						<input type='checkbox' id='swis_cache' name='swis_performance[cache_preload]' value='true' <?php checked( $this->get_option( 'cache_preload' ) ); ?> />
						<?php esc_html_e( 'Automatically generate cache files. SWIS will preload pages linked from your homepage and those listed in sitemaps.', 'swis-performance' ); ?>
		<?php if ( $this->get_option( 'cache_preload' ) && ! $this->background_mode_enabled() && ! $swis_upgrading ) : ?>
						<p>
							<strong><?php esc_html_e( 'Async/Background operations are not working, but you may manually preload the cache.', 'swis-performance' ); ?></strong>
						</p>
		<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope='row'>
						<label for='swis_defer_css'><?php esc_html_e( 'Optimize CSS Loading', 'swis-performance' ); ?></label>
						<?php $this->help_link( 'https://docs.ewww.io/article/104-optimize-css-js-loading', '60ca8a7800fd0d7c253f6f7b' ); ?>
					</th>
					<td>
						<input type='checkbox' id='swis_defer_css' name='swis_performance[defer_css]' value='true' <?php checked( $this->get_option( 'defer_css' ) ); ?> />
						<?php esc_html_e( 'Prevent CSS from slowing down your pages. Pre-loads theme CSS files.', 'swis-performance' ); ?>
					</td>
				</tr>
				<tr id="swis_defer_css_exclude_container"<?php echo $this->get_option( 'defer_css' ) ? '' : ' style="display:none"'; ?>>
					<td>&nbsp;</td>
					<td>
						<label for='swis_defer_css_exclude'><strong><?php esc_html_e( 'Exclusions', 'swis-performance' ); ?></strong></label><br>
						<textarea id='swis_defer_css_exclude' name='swis_performance[defer_css_exclude]' rows='3'><?php echo esc_html( $defer_css_exclude ); ?></textarea>
						<p class='description'>
							<?php esc_html_e( 'One exclusion per line, no wildcards (*) needed. Use any string that matches the CSS files you wish to exclude.', 'swis-performance' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope='row'>
						<?php esc_html_e( 'Critical CSS', 'swis-performance' ); ?>
						<?php $this->help_link( 'https://docs.ewww.io/article/98-finding-critical-css', '5fa2e2f64cedfd0016109a64' ); ?>
					</th>
					<td>
						<p>
							<?php esc_html_e( 'Use Critical CSS to prevent a FOUC (Flash of Unstyled Content) due to deferred/optimized CSS loading.', 'swis-performance' ); ?>
		<?php if ( ! $this->get_option( 'critical_css_key' ) ) : ?>
							<br>
							<?php
							printf(
								/* translators: %s: tools */
								esc_html__( 'We recommend the CriticalCSS.com API for optimal results, but there are several %s available to manually generate Critical CSS.', 'swis-performance' ),
								"<a href='https://docs.ewww.io/article/98-finding-critical-css' target='_blank'>" . esc_html__( 'free tools', 'swis-performance' ) . '</a>'
							);
							?>
		<?php endif; ?>
						</p>
						<p class='description'>
							<?php esc_html_e( 'The CriticalCSS.com integration is in beta, please report any bugs/feedback to support@ewww.io.', 'swis-performance' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope='row'>
						&nbsp;
					</th>
					<td>
						<strong><label for='swis_critical_css_key'><?php esc_html_e( 'CriticalCSS.com API Key', 'swis-performance' ); ?></label></strong>
						<br>
						<input type='<?php echo ! empty( $this->get_option( 'critical_css_key' ) ) ? 'password' : 'text'; ?>' id='swis_critical_css_key' name='swis_performance[critical_css_key]' size='40' value='<?php echo esc_attr( $this->get_option( 'critical_css_key' ) ); ?>' />
						<p class='description'>
							<?php
							printf(
								/* translators: %s: CriticalCSS.com (link) */
								esc_html__( 'Enter your %s API key to automatically generate and deliver page-specific critical CSS.', 'swis-performance' ),
								"<a href='https://criticalcss.com/account/api-keys?aff=11590&c=5TUd6lbe' target='_blank'>" . esc_html__( 'CriticalCSS.com', 'swis-performance' ) . '</a>'
							);
							?>
		<?php if ( ! $this->get_option( 'critical_css_key' ) ) : ?>
							<br>
							<a href='https://criticalcss.com?aff=11590&c=5TUd6lbe' target='_blank'>
								<?php esc_html_e( 'SWIS Performance users get an exclusive 10% lifetime discount at CriticalCSS.com.', 'swis-performance' ); ?>
							</a>
		<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope='row'>
						&nbsp;
					</th>
					<td>
						<strong><label for='swis_critical_css'><?php esc_html_e( 'Fallback CSS', 'swis-performance' ); ?></label></strong>
						<br>
						<textarea id='swis_critical_css' name='swis_performance[critical_css]' rows='6'><?php echo wp_kses( $this->get_option( 'critical_css' ), 'strip' ); ?></textarea>
						<p class='description'>
							<?php esc_html_e( 'The fallback CSS will be used for any page where the CriticalCSS API cannot generate CSS, or in case an API key is not provided.', 'swis-performance' ); ?><br>
						</p>
					</td>
				</tr>
				<tr>
					<th scope='row'>
						<label for='swis_minify_css'><?php esc_html_e( 'Minify CSS', 'swis-performance' ); ?></label>
						<?php $this->help_link( 'https://docs.ewww.io/article/105-minify-css-js', '60ca8dbb8556b07a28846d7e' ); ?>
					</th>
					<td>
		<?php if ( ( get_option( 'ewww_image_optimizer_exactdn' ) || get_option( 'easyio_exactdn' ) ) && get_option( 'exactdn_all_the_things' ) ) : ?>
						<p class='description'><?php esc_html_e( 'CSS resources are minified automatically by Easy IO.', 'swis-performance' ); ?></p>
		<?php else : ?>
						<input type='checkbox' id='swis_minify_css' name='swis_performance[minify_css]' value='true' <?php checked( $this->get_option( 'minify_css' ) ); ?> />
						<?php esc_html_e( 'Make your stylesheets as small as possible by removing whitespace, comments, etc.', 'swis-performance' ); ?>
					</td>
				</tr>
				<tr id="swis_minify_css_exclude_container"<?php echo $this->get_option( 'minify_css' ) ? '' : ' style="display:none"'; ?>>
					<td>&nbsp;</td>
					<td>
						<label for='swis_minify_css_exclude'><strong><?php esc_html_e( 'Exclusions', 'swis-performance' ); ?></strong></label><br>
						<textarea id='swis_minify_css_exclude' name='swis_performance[minify_css_exclude]' rows='3'><?php echo esc_html( $minify_css_exclude ); ?></textarea>
						<p class='description'>
							<?php esc_html_e( 'One exclusion per line, no wildcards (*) needed. Use any string that matches the CSS files you wish to exclude.', 'swis-performance' ); ?>
						</p>
		<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope='row'>
						<label for='swis_defer_js'><?php esc_html_e( 'Optimize JS Loading', 'swis-performance' ); ?></label>
						<?php $this->help_link( 'https://docs.ewww.io/article/104-optimize-css-js-loading', '60ca8a7800fd0d7c253f6f7b' ); ?>
					</th>
					<td>
						<input type='checkbox' id='swis_defer_js' name='swis_performance[defer_js]' value='true' <?php checked( $this->get_option( 'defer_js' ) ); ?> />
						<?php esc_html_e( 'Prevent scripts from render-blocking and slowing down your pages.', 'swis-performance' ); ?>
					</td>
				</tr>
				<tr id="swis_defer_js_exclude_container"<?php echo $this->get_option( 'defer_js' ) ? '' : ' style="display:none"'; ?>>
					<td>&nbsp;</td>
					<td>
						<label for='swis_defer_js_exclude'><strong><?php esc_html_e( 'Exclusions', 'swis-performance' ); ?></strong></label><br>
						<textarea id='swis_defer_js_exclude' name='swis_performance[defer_js_exclude]' rows='3'><?php echo esc_html( $defer_js_exclude ); ?></textarea>
						<p class='description'>
							<?php esc_html_e( 'One exclusion per line, no wildcards (*) needed. Use any string that matches the JS files you wish to exclude.', 'swis-performance' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope='row'>
						<label for='swis_minify_js'><?php esc_html_e( 'Minify JS', 'swis-performance' ); ?></label>
						<?php $this->help_link( 'https://docs.ewww.io/article/105-minify-css-js', '60ca8dbb8556b07a28846d7e' ); ?>
					</th>
					<td>
		<?php if ( ( get_option( 'ewww_image_optimizer_exactdn' ) || get_option( 'easyio_exactdn' ) ) && get_option( 'exactdn_all_the_things' ) ) : ?>
						<p class='description'><?php esc_html_e( 'JS resources are minified automatically by Easy IO.', 'swis-performance' ); ?></p>
		<?php else : ?>
						<input type='checkbox' id='swis_minify_js' name='swis_performance[minify_js]' value='true' <?php checked( $this->get_option( 'minify_js' ) ); ?> />
						<?php esc_html_e( 'Make your scripts as small as possible by removing whitespace, comments, etc.', 'swis-performance' ); ?>
					</td>
				</tr>
				<tr id="swis_minify_js_exclude_container"<?php echo $this->get_option( 'minify_js' ) ? '' : ' style="display:none"'; ?>>
					<td>&nbsp;</td>
					<td>
						<label for='swis_minify_js_exclude'><strong><?php esc_html_e( 'Exclusions', 'swis-performance' ); ?></strong></label><br>
						<textarea id='swis_minify_js_exclude' name='swis_performance[minify_js_exclude]' rows='3'><?php echo esc_html( $minify_js_exclude ); ?></textarea>
						<p class='description'>
							<?php esc_html_e( 'One exclusion per line, no wildcards (*) needed. Use any string that matches the JS files you wish to exclude.', 'swis-performance' ); ?>
						</p>
		<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope='row'>
						<label for='swis_optimize_fonts'><?php esc_html_e( 'Optimize Google Fonts', 'swis-performance' ); ?></label>
						<?php $this->help_link( 'https://docs.ewww.io/article/106-optimize-google-fonts', '60ca973b05ff892e6bc281e3' ); ?>
					</th>
					<td>
						<input type='checkbox' id='swis_optimize_fonts' name='swis_performance[optimize_fonts]' value='true' <?php checked( $this->get_option( 'optimize_fonts' ) ); ?> />
						<?php esc_html_e( 'Speed up the loading of Google Fonts. Inlines the font CSS and adds a preconnect directive for supported browsers.', 'swis-performance' ); ?>
						<p class='description'>
							<?php esc_html_e( 'After enabling this option, loading any of your pages while logged in with admin permissions will allow SWIS to auto-detect your Google Fonts.', 'swis-performance' ); ?>
						</p>
					</td>
				</tr>
				<tr id="swis_optimize_fonts_css_container"<?php echo $this->get_option( 'optimize_fonts' ) ? '' : ' style="display:none"'; ?>>
					<td>&nbsp;</td>
					<td>
						<label for='swis_optimize_fonts_css'><strong><?php esc_html_e( 'Google Fonts CSS', 'swis-performance' ); ?></strong></label><br>
						<textarea id='swis_optimize_fonts_css' name='swis_performance[optimize_fonts_css]' rows='6'><?php echo wp_kses( $this->get_option( 'optimize_fonts_css' ), 'strip' ); ?></textarea>
						<p class='description'>
							<?php esc_html_e( 'This field should be auto-populated when you load any page while logged in with admin permissions. You may edit the auto-detected CSS, or specify the font CSS manually.', 'swis-performance' ); ?>
						</p>
					</td>
				</tr>
				<tr id="swis_optimize_fonts_list_container"<?php echo $this->get_option( 'optimize_fonts' ) ? '' : ' style="display:none"'; ?>>
					<td>&nbsp;</td>
					<td>
						<label for='swis_optimize_fonts_list'><strong><?php esc_html_e( 'Remove Font CSS', 'swis-performance' ); ?></strong></label><br>
						<textarea id='swis_optimize_fonts_list' name='swis_performance[optimize_fonts_list]' rows='3'><?php echo esc_html( $fonts_list ); ?></textarea>
						<p class='description'>
							<?php esc_html_e( 'Once the Google Fonts CSS is inlined, SWIS will suppress external CSS files for Google Fonts.', 'swis-performance' ); ?><br>
							<?php esc_html_e( 'This field should also be auto-populated, but you may edit the stylesheet handles manually if necessary, one per line.', 'swis-performance' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope='row'>
						<label for='swis_pre_hint_domains'><?php esc_html_e( 'Pre* Hint Domains', 'swis-performance' ); ?></label>
						<?php $this->help_link( 'https://docs.ewww.io/article/108-pre-hint-domains', '60cabdd405ff892e6bc28224' ); ?>
					</th>
					<td>
						<textarea id='swis_pre_hint_domains' name='swis_performance[pre_hint_domains]' rows='3'><?php echo esc_html( $pre_hint_domains ); ?></textarea>
						<p class='description'>
							<?php esc_html_e( 'SWIS will automatically add DNS Prefetch and Preconnect hints to speed up third-party resources, but you may provide additional domains if any are missing.', 'swis-performance' ); ?><br>
						</p>
					</td>
				</tr>
		<?php if ( ( ! get_option( 'ewww_image_optimizer_exactdn' ) && ! get_option( 'easyio_exactdn' ) ) || ! get_option( 'exactdn_all_the_things' ) ) : ?>
				<tr>
					<th scope='row'>
						<label for='swis_cdn_domain'><?php esc_html_e( 'CDN Domain', 'swis-performance' ); ?></label>
						<?php $this->help_link( 'https://docs.ewww.io/article/107-cdn-domain-rewriting', '60caabcd9e87cb3d01244800' ); ?>
					</th>
					<td>
						<input type='text' id='swis_cdn_domain' name='swis_performance[cdn_domain]' size='40' value='<?php echo esc_attr( $this->get_option( 'cdn_domain' ) ); ?>' />
						<?php esc_html_e( 'Enter a CDN domain to deliver all static resources from speedy servers: JS, CSS, images, etc.', 'swis-performance' ); ?>
					</td>
				</tr>
				<tr id="swis_cdn_exclude_container"<?php echo $this->get_option( 'cdn_domain' ) ? '' : ' style="display:none"'; ?>>
					<td>&nbsp;</td>
					<td>
						<label for='swis_cdn_exclude'><strong><?php esc_html_e( 'Exclusions', 'swis-performance' ); ?></strong></label><br>
						<textarea id='swis_cdn_exclude' name='swis_performance[cdn_exclude]' rows='3'><?php echo esc_html( $cdn_exclude ); ?></textarea>
						<p class='description'>
							<?php esc_html_e( 'One exclusion per line, no wildcards (*) needed. Use any string that matches the files you wish to exclude.', 'swis-performance' ); ?>
						</p>
					</td>
				</tr>
		<?php endif; ?>
				<tr>
					<th scope='row'>
						<label for='swis_slim_js_css'><?php esc_html_e( 'Eliminate Unused CSS/JS (Slim)', 'swis-performance' ); ?></label>
						<?php $this->help_link( 'https://docs.ewww.io/article/97-disabling-unused-css-and-js', '5fa2c6604cedfd00165ad105' ); ?>
					</th>
					<td>
						<p><?php esc_html_e( 'Enter rules to unload any JS/CSS files you choose, on any page(s) you choose.', 'swis-performance' ); ?></p>
						<div id="swis-slim-rules">
							<?php swis()->slim->display_backend_rules(); ?>
						</div>
						<div id="swis-slim-add-rule" class="swis-slim-rule">
							<div class="swis-slim-header"><?php esc_html_e( 'Add New Rule', 'swis-performance' ); ?></div>
							<div class="swis-slim-row">
								<div class="swis-slim-control-group">
									<input type="text" id="swis_slim_new_handle" name="swis_slim_new_handle" />
									<strong><label for='swis_slim_new_handle'><?php esc_html_e( 'JS/CSS Handle', 'swis-performance' ); ?></label></strong>
								</div>
								<div class="swis-slim-row">
									<input type="radio" id="swis_slim_new_mode_include" class="swis-slim-new-radio" name="swis_slim_new_mode" value="include" />
									<strong><label for='swis_slim_new_mode_include'><?php esc_html_e( 'disable everywhere except:', 'swis-performance' ); ?></label></strong>
								</div>
								<div class="swis-slim-row">
									<input type="radio" id="swis_slim_new_mode_exclude" class="swis-slim-new-radio" name="swis_slim_new_mode" value="exclude" />
									<strong><label for='swis_slim_new_mode_exclude'><?php esc_html_e( 'disable on:', 'swis-performance' ); ?></label></strong>
								</div>
								<div class="swis-slim-row" style="display:none">
									<input type="radio" id="swis_slim_new_mode_all" class="swis-slim-new-radio" name="swis_slim_new_mode" value="all" checked />
									<strong><label for='swis_slim_new_mode_all'><?php esc_html_e( 'disable everywhere', 'swis-performance' ); ?></label></strong>
								</div>
							</div>
							<div class="swis-slim-error-message"></div>
							<div class="swis-slim-row">
								<input type="text" id="swis_slim_new_exclusions" name="swis-slim-new-exclusions" placeholder="<?php esc_html_e( 'Leave blank to disable everywhere', 'swis-performance' ); ?>"/>
								<button type="button" class="button-primary swis-slim-rule-add"><?php esc_html_e( 'Add Rule', 'swis-performance' ); ?></button>
							</div>
							<p class="description">
								<label for='swis_slim_new_exclusions'>
									<?php esc_html_e( 'Comma-separated list of pages, URL patterns (use * as wildcard), or content types in the form T>post or T>page.', 'swis-performance' ); ?>
								</label>
							</p>
						</div>
						<p>
							*<?php esc_html_e( 'Visit any page while logged in and use the SWIS menu in the WP Admin Bar to manage resources.', 'swis-performance' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope='row'>
						<label for='swis_debug'><?php esc_html_e( 'Debugging', 'swis-performance' ); ?></label>
					</th>
					<td>
						<input type='checkbox' id='swis_debug' name='swis_performance[debug]' value='true' <?php checked( $this->get_option( 'debug' ) ); ?> />
						<?php esc_html_e( 'Use this to log information for troubleshooting problems you might encounter.', 'swis-performance' ); ?>
						<p class='description'><a href='#TB_inline?&width=820&height=450&inlineId=swis-debug' class='thickbox'><?php esc_html_e( 'View Debug Info', 'swis-performance' ); ?></a></p>
					</td>
				</tr>
			</table>
		<?php if ( ! empty( self::$debug ) ) : ?>
			<div id='swis-debug'>
				<p class="debug-actions">
					<strong><?php esc_html_e( 'Debugging Information', 'swis-performance' ); ?>:</strong>&emsp;
					<button id="swis-copy-debug" class="button button-secondary" type="button"><?php esc_html_e( 'Copy', 'swis-performance' ); ?></button>
					<span id='swis-copy-debug-success'><?php esc_html_e( 'Copied!', 'swis-performance' ); ?></span>
					<span id='swis-copy-debug-fail'><?php esc_html_e( 'Copy failed, please copy the debug info manually.', 'swis-performance' ); ?></span>
				</p>
				<div id="swis-debug-info" contenteditable="true">
					<?php echo wp_kses_post( self::$debug ); ?>
				</div>
				<?php if ( $this->is_file( WP_CONTENT_DIR . '/swis/debug.log' ) ) : ?>
				<p>
					<?php /* translators: %s: file path to debug.log */ ?>
					<?php printf( esc_html__( 'The full debug log is located at %s', 'swis-performance' ), esc_html( WP_CONTENT_DIR . '/swis/debug.log' ) ); ?><br>
					<a href='<?php echo esc_url( admin_url( 'admin.php?action=swis_view_debug_log' ) ); ?>' target='_blank'><?php esc_html_e( 'View Debug Log', 'swis-performance' ); ?></a> -
					<a href='<?php echo esc_url( admin_url( 'admin.php?action=swis_delete_debug_log' ) ); ?>'><?php esc_html_e( 'Clear Debug Log', 'swis-performance' ); ?></a>
				</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
			<p class='submit'><input type='submit' class='button-primary' value='<?php esc_attr_e( 'Save', 'swis-performance' ); ?>' /></p>
		</form>
		<div id='swis-right'>
			<h2 id='swis-support'><?php esc_html_e( 'Get Help', 'swis-performance' ); ?></h2>
			<p>
				<a class='swis-docs-root' href='https://docs.ewww.io/category/85-swis-performance' target='_blank'><?php esc_html_e( 'Read The Funny Manual', 'swis-performance' ); ?></a> |
				<a class='swis-contact-link' href='https://ewww.io/contact-us/' target='_blank'><?php esc_html_e( 'Email Us', 'swis-performance' ); ?></a> |
				<a href='https://ewww.io/account/' target='_blank'><?php esc_html_e( 'My Account', 'swis-performance' ); ?></a>
			</p>
			<p>
				<?php esc_html_e( 'If you find yourself using the exclusions to fix a problem, let us know so we can improve the plugin.', 'swis-performance' ); ?>
			</p>
			<hr>
		<?php if ( get_option( 'swis_license' ) && $license_error ) : ?>
			<p><strong><?php esc_html_e( 'License:', 'swis-performance' ); ?></strong> <?php echo esc_html( $license_error ); ?></p>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?action=swis_remove_license' ) ); ?>"><?php esc_html_e( 'Deactivate License', 'swis-performance' ); ?></a></p>
		<?php elseif ( get_option( 'swis_license' ) && 'valid' === get_option( 'swis_license_status' ) ) : ?>
			<h2><?php esc_html_e( 'License:', 'swis-performance' ); ?> <span style="color:#00d4d4;"><?php esc_html_e( 'Active' ); ?></span></h2>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?action=swis_remove_license' ) ); ?>"><?php esc_html_e( 'Deactivate License', 'swis-performance' ); ?></a></p>
		<?php elseif ( get_option( 'swis_license' ) && 'valid' === get_option( 'swis_license_status' ) && ! empty( get_option( 'swis_license_expires' ) ) ) : ?>
			<h2><?php esc_html_e( 'License:', 'swis-performance' ); ?> <span style="color:#00d4d4;"><?php esc_html_e( 'Active' ); ?></span></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: expiration date */
						__( 'License expires on %s.', 'swis-performance' ),
						wp_date( get_option( 'date_format' ), get_option( 'swis_license_expires' ) )
					)
				);
				?>
				<br><a href="<?php echo esc_url( admin_url( 'admin.php?action=swis_remove_license' ) ); ?>"><?php esc_html_e( 'Deactivate License', 'swis-performance' ); ?></a>
			</p>
		<?php else : ?>
			<form method="post" action="options.php">
				<label class="description" for="swis_license_key">
					<?php
					printf(
						/* translators: %s: license key */
						esc_html__( 'Activate your %s in order to enable plugin updates:', 'swis-performance' ),
						'<a href="https://ewww.io/file-downloads/" target="_blank">' . esc_html__( 'license key', 'swis-performance' ) . '</a>'
					);
					?>
				</label><br>
				<input id="swis_license_key" name="swis_license_key" type="text" class="regular-text" /><br>
				<?php wp_nonce_field( 'swis_activation_nonce', 'swis_activation_nonce' ); ?>
				<input type="submit" class="button-secondary" name="swis_license_activate" value="<?php esc_attr_e( 'Activate License', 'swis-performance' ); ?>"/>
			</form>
		<?php endif; ?>
		<?php $this->background_mode_status(); ?>
		<?php if ( $this->get_option( 'cache' ) ) : ?>
			<hr>
			<h2><?php esc_html_e( 'Page Cache', 'swis-performance' ); ?></h2>
			<?php $this->cache_status(); ?>
			<?php $this->cache_preload_status(); ?>
		<?php elseif ( $this->get_option( 'cache_preload' ) ) : ?>
			<hr>
			<h2><?php esc_html_e( 'Page Cache', 'swis-performance' ); ?></h2>
			<?php $this->cache_preload_status(); ?>
		<?php endif; ?>
		<?php if ( $this->get_option( 'critical_css_key' ) ) : ?>
			<hr>
			<h2><?php esc_html_e( 'Critical CSS', 'swis-performance' ); ?></h2>
			<?php $this->generate_css_status(); ?>
			<hr>
			<?php $this->display_asset_purge(); ?>
		<?php elseif ( $this->get_option( 'minify_js' ) || $this->get_option( 'minify_css' ) ) : ?>
			<hr>
			<?php $this->display_asset_purge(); ?>
		<?php endif; ?>
		</div><!-- end #swis-right -->
	</div><!-- end #swis-flex-wrap -->
</div>
<script type="text/javascript">!function(e,t,n){function a(){var e=t.getElementsByTagName("script")[0],n=t.createElement("script");n.type="text/javascript",n.async=!0,n.src="https://beacon-v2.helpscout.net",e.parentNode.insertBefore(n,e)}if(e.Beacon=n=function(t,n,a){e.Beacon.readyQueue.push({method:t,options:n,data:a})},n.readyQueue=[],"complete"===t.readyState)return a();e.attachEvent?e.attachEvent("onload",a):e.addEventListener("load",a,!1)}(window,document,window.Beacon||function(){});</script>
<script type="text/javascript">
	window.Beacon('init', '9bb65ee6-3453-4204-811b-79ac0c3bd951');
	window.Beacon('config', {
		color: '#00d4d4',
	});
	Beacon( 'prefill', {
		email: '<?php echo esc_js( utf8_encode( $help_email ) ); ?>',
		text: '\n\n----------------------------------------\n<?php echo wp_kses_post( $hs_debug ); ?>',
	});
</script>
		<?php
	}

	/**
	 * Enqueue JS needed for the settings page.
	 *
	 * @param string $hook The hook name of the page being loaded.
	 */
	function settings_script( $hook ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		wp_enqueue_style( 'swis-performance-settings', plugins_url( '/assets/swis.css', SWIS_PLUGIN_FILE ), array(), SWIS_PLUGIN_VERSION );
		// Make sure we are being called from the settings page.
		if ( strpos( $hook, 'settings_page_swis-performance-options' ) !== 0 ) {
			return;
		}
		add_thickbox();
		wp_enqueue_script( 'swis-performance-settings', plugins_url( '/assets/swis.js', SWIS_PLUGIN_FILE ), array( 'jquery' ), SWIS_PLUGIN_VERSION );
		wp_enqueue_script( 'swis-performance-slim', plugins_url( '/assets/slim.js', SWIS_PLUGIN_FILE ), array( 'jquery' ), SWIS_PLUGIN_VERSION );
		wp_localize_script(
			'swis-performance-settings',
			'swisperformance_vars',
			array(
				'ajaxurl'          => admin_url( 'admin-ajax.php' ),
				'_wpnonce'         => wp_create_nonce( 'swis-performance-settings' ),
				'preload_nonce'    => wp_create_nonce( 'swis_cache_preload_nonce' ),
				'ccss_nonce'       => wp_create_nonce( 'swis_generate_css_nonce' ),
				'invalid_response' => esc_html__( 'Received an invalid response from your website, please check for errors in the Developer Tools console of your browser.', 'swis-performance' ),
				'no_preload_pages' => esc_html__( 'No pages found for preload. This is likely an error, please enable Debugging and try again. Then send us a copy of the debug log for assistance.', 'swis-performance' ),
				'preload_running'  => ! defined( 'SWIS_DISABLE_ASYNC' ) && ! empty( get_transient( 'swis_cache_preload_total' ) ),
				'preload_complete' => esc_html__( 'Cache preloading complete.', 'swis-performance' ),
				'no_ccss_pages'    => esc_html__( 'No pages found for critical CSS generation. This is likely an error, please enable Debugging and try again. Then send us a copy of the debug log for assistance.', 'swis-performance' ),
				'ccss_running'     => ! defined( 'SWIS_DISABLE_ASYNC' ) && ! empty( get_transient( 'swis_generate_css_total' ) ),
				'ccss_complete'    => esc_html__( 'CSS generation complete.', 'swis-performance' ),
				'remove_rule'      => esc_html__( 'Are you sure you want to remove this rule?', 'swis-performance' ),
				'removing_message' => esc_html__( 'Deleting...', 'swis-performance' ),
				'saving_message'   => esc_html__( 'Saving...', 'swis-performance' ),
			)
		);
	}

	/**
	 * Get status of a license key.
	 *
	 * @return string An error message, if the key could not be activated.
	 */
	function get_license_status() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$license = get_option( 'swis_license' );
		if ( ! $license ) {
			return __( 'No license key found.', 'swis-performance' );
		}

		$api_params = array(
			'edd_action' => 'check_license',
			'license'    => $license,
			'item_id'    => SWIS_SL_ITEM_ID,
			'url'        => home_url(),
		);

		$response = wp_remote_post(
			SWIS_SL_STORE_URL,
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'body'      => $api_params,
			)
		);

		$message = '';
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$message = ( is_wp_error( $response ) && ! empty( $response->get_error_message() ) ) ? $response->get_error_message() : __( 'An error occurred, please try again.', 'swis-performance' );
			$this->debug_message( "license check http error: $message" );
			return $message;
		} else {
			$license_data = json_decode( wp_remote_retrieve_body( $response ) );
			if ( 'valid' !== $license_data->license ) {
				$this->debug_message( 'license check error status: ' . $license_data->license );
				switch ( $license_data->license ) {
					case 'expired':
						$message = sprintf(
							/* translators: %s: expiration date */
							__( 'Your license key expired on %s.', 'swis-performance' ),
							wp_date( get_option( 'date_format' ), strtotime( $license_data->expires ) )
						);
						break;

					case 'disabled':
					case 'revoked':
						$message = __( 'Your license key has been disabled.', 'swis-performance' );
						break;
					case 'item_name_mismatch':
					case 'key_mismatch':
						$message = __( 'This appears to be an invalid license key for SWIS Performance.', 'swis-performance' );
						break;

					default:
						$message = __( 'An error occurred, please try again.', 'swis-performance' );
						break;
				}
			}
		}

		update_option( 'swis_license_status', $license_data->license, false );
		if ( ! empty( $license_data->expires ) ) {
			update_option( 'swis_license_expires', strtotime( $license_data->expires ), false );
		} else {
			update_option( 'swis_license_expires', 0, false );
		}
		$this->debug_message( "license check error: $message" );
		return $message;
	}

	/**
	 * Display background mode status/details.
	 */
	function background_mode_status() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! $this->get_option( 'cache_preload' ) ) {
			return;
		}
		global $swis_upgrading;
		$retest_nonce = wp_create_nonce( 'swis_retest_background_nonce' );
		?>
		<hr>
		<h2><?php esc_html_e( 'Background/Async Mode', 'swis-performance' ); ?></h2>
		<p>
			<?php if ( defined( 'SWIS_DISABLE_ASYNC' ) && SWIS_DISABLE_ASYNC ) : ?>
				<span><?php esc_html_e( 'Disabled by administrator', 'swis-performance' ); ?></span>
			<?php elseif ( $swis_upgrading ) : ?>
				<span><?php esc_html_e( 'Upgrade in progress, re-testing', 'swis-performance' ); ?></span>
			<?php elseif ( $this->detect_wpsf_location_lock() ) : ?>
				<span style="color: orange; font-weight: bolder"><?php esc_html_e( "Disabled by Shield's Lock to Location feature", 'swis-performance' ); ?></span>
			<?php elseif ( ! $this->get_option( 'background_processing' ) ) : ?>
				<span style="color: orange; font-weight: bolder">
					<?php esc_html_e( 'Disabled automatically, async requests blocked', 'swis-performance' ); ?><br>
					<a href="<?php echo esc_url( admin_url( 'admin.php?action=swis_retest_background_mode&swis_retest_background_nonce=' . $retest_nonce ) ); ?>">
						<?php esc_html_e( 'Re-test', 'swis-performance' ); ?>
					</a>
				</span>
			<?php else : ?>
				<span>
					<?php esc_html_e( 'Enabled', 'swis-performance' ); ?><br>
					<a href="<?php echo esc_url( admin_url( 'admin.php?action=swis_retest_background_mode&swis_retest_background_nonce=' . $retest_nonce ) ); ?>">
						<?php esc_html_e( 'Re-test', 'swis-performance' ); ?>
					</a>
				</span>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Display cache stats.
	 */
	function cache_status() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! $this->get_option( 'cache' ) ) {
			return;
		}
		$cache_size = swis()->cache->get_cache_size();
		// Show the clear cache button.
		$clear_cache_url = wp_nonce_url(
			add_query_arg(
				array(
					'_cache'  => 'swis-cache',
					'_action' => 'swis_cache_clear',
				)
			),
			'swis_cache_clear_nonce'
		);
		?>
		<p id="swis-cache-status-container">
			<span id="swis-cache-size">
				<?php
				/* translators: %s: human readable filesize */
				printf( esc_html__( 'Total cache size: %s', 'swis-performance' ), esc_html( size_format( $cache_size, 1 ) ) );
				?>
			</span>
		</p>
		<p id="swis-clear-cache-container">
			<a class="button button-secondary" href="<?php echo esc_url( $clear_cache_url ); ?>">
				<?php esc_html_e( 'Clear Cache', 'swis-performance' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Display cache preload status.
	 */
	function cache_preload_status() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! $this->get_option( 'cache_preload' ) ) {
			return;
		}
		$preload_nonce = wp_create_nonce( 'swis_cache_preload_nonce' );
		if ( ! $this->background_mode_enabled() ) {
			$loading_image = plugins_url( '/assets/images/spinner.gif', SWIS_PLUGIN_FILE );
			?>
			<p id="swis-cache-preload-start-container">
				<button id="swis-cache-preload-start" class="button button-secondary" type="button">
					<?php esc_html_e( 'Preload Cache', 'swis-performance' ); ?>
				</button>
			</p>
			<p id="swis-cache-preload-status-container" style="display:none;">
				<span id="swis-cache-preload-message">
					<?php esc_html_e( 'Searching for URLs to preload...', 'swis-performance' ); ?>
				</span>
				<img id="swis-cache-preload-spinner" src="<?php echo esc_attr( $loading_image ); ?>" /><br>
			</p>
			<p id="swis-cache-preload-warning" class="description" style="display:none;">
				<?php esc_html_e( 'This window must remain open while the preloader is running. To cancel, simpy reload the page. If interrupted, the preloader will continue where it left off the next time.', 'swis-performance' ); ?>
			</p>
			<?php
			return;
		}
		$total_urls = get_transient( 'swis_cache_preload_total' );
		if ( empty( $total_urls ) ) {
			?>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?action=swis_cache_preload_manual&swis_cache_preload_nonce=' . $preload_nonce ) ); ?>">
					<?php esc_html_e( 'Preload Cache', 'swis-performance' ); ?>
				</a>
			</p>
			<?php
			return;
		} elseif ( $total_urls < 0 ) {
			?>
			<div id='swis-preload-queue-info'>
				<p>
					<?php
					esc_html_e( 'Cache preload is searching for URLs', 'swis-performance' );
					?>
				</p>
			</div>
			<?php
			return;
		}
		$queue_status = __( 'idle', 'swis-performance' );
		if ( swis()->cache_preload_background->is_process_running() ) {
			$queue_status = __( 'running', 'swis-performance' );
		}
		$remaining_urls = swis()->cache_preload_background->count_queue();
		if ( empty( $remaining_urls ) ) {
			delete_transient( 'swis_cache_preload_total' );
			?>
			<div id='swis-preload-queue-info'>
				<p>
					<strong><?php esc_html_e( 'Cache preloading complete.', 'swis-performance' ); ?></strong>
				</p>
			</div>
			<?php
		} else {
			$completed = $total_urls - $remaining_urls;
			?>
			<div id='swis-preload-queue-info'>
				<p>
					<?php
					/* translators: %s: idle/running */
					printf( esc_html__( 'Cache preload process is %s:', 'swis-performance' ) . '<br>', esc_html( $queue_status ) );
					/* translators: 1: number of completed pages 2: total number of pages */
					printf( esc_html__( '%1$d / %2$d pages have been completed.', 'swis-performance' ), (int) $completed, (int) $total_urls );
					?>
				</p>
				<p>
			<?php if ( swis()->cache_preload_background->is_process_running() ) : ?>
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?action=swis_cache_preload_manual&swis_stop_preload=1&swis_cache_preload_nonce=' . $preload_nonce ) ); ?>">
						<?php esc_html_e( 'Cancel Preload', 'swis-performance' ); ?>
					</a>
			<?php else : ?>
				<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?action=swis_cache_preload_resume_manual&swis_cache_preload_nonce=' . $preload_nonce ) ); ?>">
					<?php esc_html_e( 'Resume Preload', 'swis-performance' ); ?>
				</a>
			<?php endif; ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Return the cache preload status output via AJAX.
	 */
	function cache_preload_status_ajax() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! current_user_can( $permissions ) || ! check_ajax_referer( 'swis_cache_preload_nonce', 'swis_cache_preload_nonce', false ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Access token has expired, please reload the page.', 'swis-performance' ) ) ) );
		}
		if ( ! get_transient( 'swis_cache_preload_total' ) ) {
			die( wp_json_encode( array( 'html' => false ) ) );
		}
		ob_start();
		$this->cache_preload_status();
		$preload_html = trim( ob_get_clean() );
		die( wp_json_encode( array( 'html' => $preload_html ) ) );
	}

	/**
	 * Display critical CSS generation status.
	 */
	function generate_css_status() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! $this->get_option( 'critical_css_key' ) ) {
			return;
		}
		$generate_nonce = wp_create_nonce( 'swis_generate_css_nonce' );
		if ( ! $this->background_mode_enabled() ) {
			$loading_image  = plugins_url( '/assets/images/spinner.gif', SWIS_PLUGIN_FILE );
			$total_css      = (int) swis()->critical_css->get_critical_css_count();
			$remaining_urls = (int) swis()->critical_css_background->count_queue();
			if ( empty( $remaining_urls ) ) {
				delete_transient( 'swis_generate_css_total' );
			}
			$total_urls = (int) get_transient( 'swis_generate_css_total' );
			?>
			<?php if ( ! $total_urls && $total_css ) : ?>
			<p>
				<?php
				/* translators: %d: number of Critical CSS files */
				printf( esc_html__( '%d critical CSS files generated', 'swis-performance' ), (int) $total_css );
				?>
			</p>
			<?php endif; ?>
			<p id="swis-generate-css-start-container">
				<button id="swis-generate-css-start" class="button button-secondary" type="button">
					<?php esc_html_e( 'Generate Critical CSS', 'swis-performance' ); ?>
				</button>
			</p>
			<p id="swis-generate-css-status-container" style="display:none;">
				<span id="swis-generate-css-message">
					<?php esc_html_e( 'Searching for pages that need critical CSS...', 'swis-performance' ); ?>
				</span>
				<img id="swis-generate-css-spinner" src="<?php echo esc_attr( $loading_image ); ?>" /><br>
			</p>
			<p id="swis-generate-css-last-info" style="display:none;"></p>
			<div id="swis-generate-css-error-log" style="display:none;"><hr></div>
			<p id="swis-generate-css-warning" class="description" style="display:none;">
				<?php esc_html_e( 'This window must remain open while CSS generation is running. To cancel, simpy reload the page. If interrupted, CSS generation will continue where it left off the next time.', 'swis-performance' ); ?>
			</p>
			<?php
			return;
		}
		$total_urls = get_transient( 'swis_generate_css_total' );
		if ( empty( $total_urls ) ) {
			$total_css = (int) swis()->critical_css->get_critical_css_count();
			?>
			<p>
				<?php
				/* translators: %d: number of Critical CSS files */
				printf( esc_html__( '%d critical CSS files generated', 'swis-performance' ), (int) $total_css );
				?>
			</p>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?action=swis_generate_css_manual&swis_generate_css_nonce=' . $generate_nonce ) ); ?>">
					<?php esc_html_e( 'Generate Critical CSS', 'swis-performance' ); ?>
				</a>
			</p>
			<?php
			return;
		} elseif ( $total_urls < 0 ) {
			?>
			<div id='swis-generate-css-queue-info'>
				<p>
					<?php
					esc_html_e( 'CSS generation is searching for pages', 'swis-performance' );
					?>
				</p>
			</div>
			<?php
			return;
		}
		$queue_status = __( 'idle', 'swis-performance' );
		if ( swis()->critical_css_background->is_process_running() ) {
			$queue_status = __( 'running', 'swis-performance' );
		}
		$remaining_urls = swis()->critical_css_background->count_queue();
		if ( empty( $remaining_urls ) ) {
			delete_transient( 'swis_generate_css_total' );
			?>
			<div id='swis-generate-css-queue-info'>
				<p>
					<strong><?php esc_html_e( 'Critical CSS generation complete.', 'swis-performance' ); ?></strong>
				</p>
			</div>
			<?php
		} else {
			$completed = $total_urls - $remaining_urls;
			?>
			<div id='swis-generate-css-queue-info'>
				<p>
					<?php
					/* translators: %s: idle/running */
					printf( esc_html__( 'CSS generation process is %s:', 'swis-performance' ) . '<br>', esc_html( $queue_status ) );
					/* translators: 1: number of completed pages 2: total number of pages */
					printf( esc_html__( '%1$d / %2$d pages have been completed.', 'swis-performance' ), (int) $completed, (int) $total_urls );
					?>
				</p>
				<p>
			<?php if ( swis()->critical_css_background->is_process_running() ) : ?>
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?action=swis_generate_css_manual&swis_stop_generate_css=1&swis_generate_css_nonce=' . $generate_nonce ) ); ?>">
						<?php esc_html_e( 'Cancel CSS generation', 'swis-performance' ); ?>
					</a>
			<?php else : ?>
				<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?action=swis_generate_css_resume_manual&swis_generate_css_nonce=' . $generate_nonce ) ); ?>">
					<?php esc_html_e( 'Resume CSS generation', 'swis-performance' ); ?>
				</a>
			<?php endif; ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Return the critical CSS generation status output via AJAX.
	 */
	function generate_css_status_ajax() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! current_user_can( $permissions ) || ! check_ajax_referer( 'swis_generate_css_nonce', 'swis_generate_css_nonce', false ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Access token has expired, please reload the page.', 'swis-performance' ) ) ) );
		}
		if ( ! get_transient( 'swis_generate_css_total' ) ) {
			die( wp_json_encode( array( 'html' => false ) ) );
		}
		ob_start();
		$this->generate_css_status();
		$generate_css_html = trim( ob_get_clean() );
		die( wp_json_encode( array( 'html' => $generate_css_html ) ) );
	}

	/**
	 * Display asset purge button.
	 */
	function display_asset_purge() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// Show the clear cache button.
		$clear_cache_url = wp_nonce_url(
			add_query_arg(
				array(
					'_cache'  => 'swis-cache',
					'_action' => 'swis_asset_cache_clear',
				)
			),
			'swis_cache_clear_nonce'
		);
		?>
		<p id="swis-clear-asset-cache-container">
			<a class="button button-secondary" href="<?php echo esc_url( $clear_cache_url ); ?>">
				<?php esc_html_e( 'Clear JS/CSS Cache', 'swis-performance' ); ?>
			</a>
		</p>
		<p class="description">
			*<?php esc_html_e( 'Removes minified JS/CSS and all critical CSS files.', 'swis-performance' ); ?>
		</p>
		<?php
	}

	/**
	 * Process a request to clear the asset (JS/CSS) cache.
	 */
	public function process_asset_cache_request() {
		// Check if this is a cache clear request.
		if (
			empty( $_GET['_cache'] ) || // The _cache arg is empty.
			empty( $_GET['_action'] ) || // The _action arg is empty.
			'swis-cache' !== $_GET['_cache'] || // The _cache arg isn't what it ought to be.
			'swis_asset_cache_clear' !== $_GET['_action'] // The _action param isn't one we recognize.
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

		swis()->critical_css->purge_cache();
		swis()->minify_css->purge_cache();
		swis()->minify_js->purge_cache();

		if ( is_admin() ) {
			set_transient( $this->get_cache_cleared_transient_name(), 1 );
		}

		wp_safe_redirect( remove_query_arg( array( '_cache', '_action', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Activate a license key.
	 */
	function activate_license() {
		if ( isset( $_POST['swis_license_activate'] ) && isset( $_POST['swis_activation_nonce'] ) ) {
			$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
			if ( ! check_admin_referer( 'swis_activation_nonce', 'swis_activation_nonce' ) ) {
				return;
			}
			$nonce_value = sanitize_key( wp_unslash( $_POST['swis_activation_nonce'] ) );

			if ( empty( $_POST['swis_license_key'] ) ) {
				return;
			}
			$license = $this->sanitize_license( $_POST['swis_license_key'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( ! $license ) {
				return;
			}

			$api_params = array(
				'edd_action' => 'activate_license',
				'license'    => $license,
				'item_id'    => SWIS_SL_ITEM_ID,
				'url'        => home_url(),
			);

			$response = wp_remote_post(
				SWIS_SL_STORE_URL,
				array(
					'timeout'   => 15,
					'sslverify' => true,
					'body'      => $api_params,
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				$message = ( is_wp_error( $response ) && ! empty( $response->get_error_message() ) ) ? $response->get_error_message() : __( 'An error occurred, please try again.', 'swis-performance' );
				$this->debug_message( "activation error: $message" );
				$this->debug_message( 'response code: ' . wp_remote_retrieve_response_code( $response ) );
			} else {
				$license_data = json_decode( wp_remote_retrieve_body( $response ) );
				if ( false === $license_data->success ) {
					switch ( $license_data->error ) {
						case 'expired':
							$message = sprintf(
								/* translators: %s: expiration date */
								__( 'Your license key expired on %s.', 'swis-performance' ),
								wp_date( get_option( 'date_format' ), strtotime( $license_data->expires ) )
							);
							break;

						case 'disabled':
						case 'revoked':
							$message = __( 'Your license key has been disabled.', 'swis-performance' );
							break;

						case 'missing':
							$message = __( 'Invalid license.', 'swis-performance' );
							break;

						case 'invalid':
						case 'site_inactive':
							$message = __( 'Your license is not active for this URL.', 'swis-performance' );
							break;

						case 'license_not_activable':
						case 'item_name_mismatch':
						case 'key_mismatch':
							$message = __( 'This appears to be an invalid license key for SWIS Performance.', 'swis-performance' );
							break;

						case 'no_activations_left':
							$message = __( 'Your license key has reached its activation limit.', 'swis-performance' );
							break;

						default:
							$message = __( 'An error occurred, please try again.', 'swis-performance' );
							break;
					}
					$this->debug_message( 'license error code: ' . $license_data->error );
				}
			}

			// Check if anything passed on a message constituting a failure.
			if ( ! empty( $message ) ) {
				$base_url = admin_url( 'options-general.php?page=swis-performance-options' );
				$redirect = add_query_arg(
					array(
						'swis_activation'       => 0,
						'message'               => urlencode( $message ),
						'swis_activation_nonce' => $nonce_value,
					),
					$base_url
				);

				wp_safe_redirect( $redirect );
				exit();
			}

			update_option( 'swis_license', $license, false );
			update_option( 'swis_license_status', $license_data->license, false );
			update_option( 'swis_license_expires', strtotime( $license_data->expires ), false );
			wp_safe_redirect( admin_url( 'options-general.php?page=swis-performance-options&swis_activation=1&swis_activation_nonce=' . $nonce_value ) );
			exit();
		}
	}

	/**
	 * Removes license key from the database and resets activation status.
	 */
	function remove_license() {
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! current_user_can( $permissions ) ) {
			wp_die( esc_html__( 'Access denied', 'swis-performance' ) );
		}
		$api_params = array(
			'edd_action' => 'deactivate_license',
			'license'    => get_option( 'swis_license' ),
			'item_id'    => SWIS_SL_ITEM_ID,
			'item_name'  => 'SWIS Performance',
			'url'        => home_url(),
		);

		$response = wp_remote_post(
			SWIS_SL_STORE_URL,
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'body'      => $api_params,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->debug_message( is_wp_error( $response ) && ! empty( $response->get_error_message() ) ? $response->get_error_message() : __( 'An error occurred, please try again.', 'swis-performance' ) );
		} else {
			$license_data = json_decode( wp_remote_retrieve_body( $response ) );
			if ( false === $license_data->success ) {
				$this->debug_message( 'license deactivation failed' );
			} else {
				$this->debug_message( 'license deactivation success' );
			}
		}
		delete_option( 'swis_license' );
		delete_option( 'swis_license_status' );
		$base_url = admin_url( 'options-general.php?page=swis-performance-options' );
		wp_safe_redirect( $base_url );
		exit;
	}

	/**
	 * Run sanity checks on license key provided by user.
	 *
	 * @param string $input The license key as entered.
	 * @return string The license key after validation.
	 */
	function sanitize_license( $input ) {
		if ( empty( $input ) ) {
			return false;
		}
		$input = trim( $input );
		if ( preg_match( '/^[a-zA-Z0-9]+$/', $input ) ) {
			return $input;
		}
		return false;
	}

	/**
	 * Sanitize the CDN domain provided.
	 *
	 * @param string $domain A CDN domain name.
	 * @return string The sanitized domain name.
	 */
	function sanitize_cdn_domain( $domain ) {
		$domain = sanitize_text_field( $domain );
		$domain = preg_replace( '#https?://#', '', $domain );
		$domain = trim( $domain, '/' );
		return $domain;
	}

	/**
	 * Sanitize the folders/patterns to exclude from any given option.
	 *
	 * @param string $input A list of paths/URL-matching strings, from a textarea.
	 * @param bool   $strip_wildcards True by default, so that wildcards will be implied.
	 * @return array The sanitized list of exclusions.
	 */
	function sanitize_textarea_exclusions( $input, $strip_wildcards = true ) {
		$this->debug_message( '<b>' . __FUNCTION__ . '()</b>' );
		if ( empty( $input ) ) {
			return '';
		}
		$path_array = array();
		if ( is_array( $input ) ) {
			$paths = $input;
		} elseif ( is_string( $input ) ) {
			$paths = explode( "\n", $input );
		}
		if ( $this->is_iterable( $paths ) ) {
			$i = 0;
			foreach ( $paths as $path ) {
				$i++;
				$this->debug_message( "validating textarea exclusion: $path" );
				if ( $strip_wildcards ) {
					$path = trim( sanitize_text_field( $path ), "* \t\n\r\0\x0B" );
				} else {
					$path = trim( sanitize_text_field( $path ), " \t\n\r\0\x0B" );
				}
				if ( ! empty( $path ) ) {
					$path_array[] = $path;
				}
			}
		}
		return $path_array;
	}
}
