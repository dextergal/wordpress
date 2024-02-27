<?php
/**
 * Class and methods to preload Cache.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enables plugin to get a list of pages to preload, and prime the cache.
 */
final class Cache_Preload extends Page_Parser {

	/**
	 * A list of user-defined exclusions, populated by validate_user_exclusions().
	 *
	 * @access protected
	 * @var array $user_exclusions
	 */
	protected $user_exclusions = array();

	/**
	 * Register actions and filters for Cache Preload.
	 */
	function __construct() {
		if ( ! $this->get_option( 'cache_preload' ) ) {
			return;
		}
		parent::__construct();
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		if ( $this->background_mode_enabled() ) {
			// Any time the cache is cleared, kick off the preloader.
			add_action( 'swis_site_cache_cleared', array( $this, 'start_preload' ) );
			add_action( 'swis_cache_by_url_cleared', array( $this, 'start_preload_url' ) );

			// If the SWIS cache is not in use...
			if ( ! $this->get_option( 'cache' ) ) {
				// use a front page check to see if preloading should be started.
				add_action( 'posts_selection', array( $this, 'check_front_page_cache' ) );
			}

			// Add handler to manually start the (async) preloader.
			add_action( 'admin_action_swis_cache_preload_manual', array( $this, 'manual_preload_action' ) );
			add_action( 'admin_action_swis_cache_preload_resume_manual', array( $this, 'manual_preload_resume_action' ) );
		} else {
			// Any time the cache is cleared, clear the preload queue.
			add_action( 'swis_complete_cache_cleared', array( $this, 'stop_preload' ) );
			add_action( 'swis_site_cache_cleared', array( $this, 'stop_preload' ) );
			add_action( 'swis_cache_by_url_cleared', array( $this, 'stop_preload' ) );
		}

		// Actions to process preload via AJAX.
		add_action( 'wp_ajax_swis_cache_preload_init', array( $this, 'start_preload_ajax' ) );
		add_action( 'wp_ajax_swis_cache_preload_url', array( $this, 'preload_url_ajax' ) );

		// Allow the user to override the preload delay with a constant.
		add_filter( 'swis_cache_preload_delay', array( $this, 'preload_delay_override' ) );

		// Overrides for user exclusions.
		add_filter( 'swis_skip_cache_preload', array( $this, 'skip_cache_preload' ), 10, 2 );

		$this->validate_user_exclusions();
	}

	/**
	 * Checks to see if the user defined an override for the preload delay.
	 *
	 * @param int $delay The currently configured preload delay (defaults to 5 seconds).
	 * @return int The default, or a user-configured override.
	 */
	function preload_delay_override( $delay ) {
		if ( defined( 'SWIS_CACHE_PRELOAD_DELAY' ) ) {
			$delay_override = SWIS_CACHE_PRELOAD_DELAY;
			return absint( $delay_override );
		}
		return $delay;
	}

	/**
	 * Validate the user-defined exclusions.
	 */
	function validate_user_exclusions() {
		$user_exclusions = $this->get_option( 'cache_preload_exclude' );
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
	 * Exclude page from being preloaded based on user specified list.
	 *
	 * @param boolean $skip Whether SWIS should skip preloading.
	 * @param string  $url The page URL.
	 * @return boolean True to skip the page, unchanged otherwise.
	 */
	function skip_cache_preload( $skip, $url ) {
		if ( $this->user_exclusions ) {
			foreach ( $this->user_exclusions as $exclusion ) {
				if ( false !== strpos( $url, $exclusion ) ) {
					$this->debug_message( __METHOD__ . "(); user excluded $url via $exclusion" );
					return true;
				}
			}
		}
		return $skip;
	}

	/**
	 * Handle the manual preload admin action.
	 */
	function manual_preload_action() {
		$permissions = \apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! \current_user_can( $permissions ) || ! \check_admin_referer( 'swis_cache_preload_nonce', 'swis_cache_preload_nonce' ) ) {
			\wp_die( \esc_html__( 'Access denied', 'swis-performance' ) );
		}
		if ( ! empty( $_GET['swis_stop_preload'] ) ) {
			$this->stop_preload();
		} else {
			$this->start_preload();
		}
		$base_url = admin_url( 'options-general.php?page=swis-performance-options' );
		\wp_safe_redirect( $base_url );
		exit;
	}

	/**
	 * Handle the manual preload resume admin action.
	 */
	function manual_preload_resume_action() {
		$permissions = \apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! \current_user_can( $permissions ) || ! \check_admin_referer( 'swis_cache_preload_nonce', 'swis_cache_preload_nonce' ) ) {
			\wp_die( \esc_html__( 'Access denied', 'swis-performance' ) );
		}
		swis()->cache_preload_background->dispatch();
		$base_url = admin_url( 'options-general.php?page=swis-performance-options' );
		\wp_safe_redirect( $base_url );
		exit;
	}

	/**
	 * Begin preload process.
	 */
	function start_preload() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$this->stop_preload();
		\set_transient( 'swis_cache_preload_total', -1, DAY_IN_SECONDS );
		swis()->cache_preload_async->dispatch();
	}

	/**
	 * Stop preload process.
	 */
	function stop_preload() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		swis()->cache_preload_background->cancel_process();
		\delete_transient( 'swis_cache_preload_total' );
	}

	/**
	 * Check if the home/front page is uncached and therefore the preloader should be launched.
	 */
	function check_front_page_cache() {
		return;
		if ( $this->get_option( 'cache_preload_front_page_auto' ) && ! \is_user_logged_in() && \is_front_page() && ! \get_transient( 'swis_cache_preload_frontpage_triggered' ) ) {
			$this->debug_message( 'front page not cached, starting preload' );
			\set_transient( 'swis_cache_preload_frontpage_triggered', true, 10 * MINUTE_IN_SECONDS );
			$this->start_preload();
		}
	}

	/**
	 * Begin preload process for a given URL.
	 *
	 * @param string $url The page to preload.
	 */
	function start_preload_url( $url ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$this->debug_message( "preloading $url" );
		swis()->cache_preload_async->data(
			array(
				'swis_preload_url' => esc_url( $url ),
			)
		)->dispatch();
	}

	/**
	 * Begin preload process via AJAX request.
	 */
	function start_preload_ajax() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! current_user_can( $permissions ) || ! check_ajax_referer( 'swis_cache_preload_nonce', 'swis_cache_preload_nonce', false ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Access token has expired, please reload the page.', 'swis-performance' ) ) ) );
		}
		$remaining_urls = (int) swis()->cache_preload_background->count_queue();
		$completed      = 0;

		if ( empty( $remaining_urls ) ) {
			$this->debug_message( 'looking for URLs to preload' );
			$this->get_urls();
			$total_urls = (int) swis()->cache_preload_background->count_queue();
		} else {
			$total_urls = (int) get_transient( 'swis_cache_preload_total' );
			if ( ! $total_urls ) {
				$total_urls = $remaining_urls;
				set_transient( 'swis_cache_preload_total', (int) $total_urls, DAY_IN_SECONDS );
			}
			$completed = $total_urls - $remaining_urls;
		}

		/* translators: %d: number of images */
		$message = sprintf( esc_html__( '%1$d / %2$d pages have been completed.', 'swis-performance' ), $completed, (int) $total_urls );
		die(
			wp_json_encode(
				array(
					'success' => $total_urls,
					'message' => $message,
				)
			)
		);
	}

	/**
	 * Preload the next URL in the queue via AJAX request.
	 */
	function preload_url_ajax() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! current_user_can( $permissions ) || ! check_ajax_referer( 'swis_cache_preload_nonce', 'swis_cache_preload_nonce', false ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Access token has expired, please reload the page.', 'swis-performance' ) ) ) );
		}

		global $wpdb;
		$url = $wpdb->get_row( "SELECT id,page_url FROM $wpdb->swis_queue WHERE queue_name = 'swis_cache_preload' LIMIT 1", ARRAY_A );
		if ( ! $this->is_iterable( $url ) || empty( $url['page_url'] ) ) {
			die( wp_json_encode( array( 'success' => 0 ) ) );
		}

		$this->preload( $url['page_url'] );

		swis()->cache_preload_background->delete( $url['id'] );

		$remaining_urls = (int) swis()->cache_preload_background->count_queue();
		$total_urls     = (int) get_transient( 'swis_cache_preload_total' );
		$completed      = $total_urls - $remaining_urls;
		/* translators: %d: number of images */
		$message = sprintf( esc_html__( '%1$d / %2$d pages have been completed.', 'swis-performance' ), (int) $completed, (int) $total_urls );
		die(
			wp_json_encode(
				array(
					'success' => $remaining_urls,
					'message' => $message,
				)
			)
		);
	}

	/**
	 * Gets all the URLs to preload, called via AJAX or async operation.
	 */
	function get_urls() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$urls = array_merge( $this->get_homepage_urls(), $this->get_sitemap_urls() );
		if ( $this->is_iterable( $urls ) ) {
			foreach ( $urls as $url ) {
				if ( empty( $url ) || ! is_string( $url ) ) {
					continue;
				}
				$this->debug_message( "queueing $url for preload" );
				swis()->cache_preload_background->push_to_queue( $url );
			}
			set_transient( 'swis_cache_preload_total', (int) swis()->cache_preload_background->count_queue(), DAY_IN_SECONDS );
		}
	}

	/**
	 * Fetch the home page and get all links for preloading.
	 *
	 * @return array A list of URLs that should be preloaded.
	 */
	function get_homepage_urls() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$urls = array();

		$home_url    = get_home_url();
		$home_domain = $this->parse_url( $home_url, PHP_URL_HOST );

		$args = array(
			'user-agent' => 'SWIS Performance/Preload',
			'sslverify'  => apply_filters( 'https_local_ssl_verify', false, $home_url ),
		);

		$args = apply_filters( 'swis_cache_preload_homepage_request_args', $args );

		$result = wp_remote_get( $home_url, $args );

		if ( is_wp_error( $result ) ) {
			$this->debug_message( 'cache preload error: ' . $result->get_error_message() );
			return $urls;
		}
		$http_code = wp_remote_retrieve_response_code( $result );
		if ( 200 !== (int) $http_code ) {
			$this->debug_message( "cache preload error, http code $http_code" );
			return $urls;
		}
		$content = wp_remote_retrieve_body( $result );
		$links   = $this->get_elements_from_html( $content, 'a' );
		foreach ( $links as $link ) {
			$url = $this->get_attribute( $link, 'href' );
			$url = $this->should_preload( $url, $home_url, $home_domain );
			if ( ! empty( $url ) ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	/**
	 * Fetch the sitemap to get URLs for preloading.
	 *
	 * @param string $sitemap_url The sitemap URL to search through.
	 * @return array A list of URLs that should be preloaded.
	 */
	function get_sitemap_urls( $sitemap_url = '' ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$urls = array();

		$sitemap_urls = false;

		if ( ! $sitemap_url ) {
			if ( defined( 'SWIS_CACHE_PRELOAD_SITEMAP' ) && SWIS_CACHE_PRELOAD_SITEMAP ) {
				$sitemap_override = SWIS_CACHE_PRELOAD_SITEMAP;
				if ( is_string( $sitemap_override ) ) {
					$sitemap_urls = array( $sitemap_override );
				}
			}
			if ( ! $this->is_iterable( $sitemap_urls ) ) {
				$sitemap_urls = array(
					home_url( 'sitemap_index.xml' ),
					home_url( 'sitemap.xml' ),
					home_url( 'wp-sitemap.xml' ),
				);
			}
			$sitemap_urls = apply_filters( 'swis_cache_preload_default_sitemaps', $sitemap_urls );
			foreach ( $sitemap_urls as $sitemap_url ) {
				$sitemap_xml = $this->get_sitemap_xml( $sitemap_url );
				if ( $sitemap_xml ) {
					break;
				}
			}
		} else {
			$sitemap_xml = $this->get_sitemap_xml( $sitemap_url );
		}

		if ( $sitemap_xml && function_exists( 'simplexml_load_string' ) ) {
			libxml_use_internal_errors( true );
			$xml = simplexml_load_string( $sitemap_xml );
			if ( false !== $xml ) {
				$url_count = count( $xml->url );
				$map_count = count( $xml->sitemap );
				if ( $url_count ) {
					foreach ( $xml->url as $xml_url ) {
						if ( ! empty( $xml_url->loc ) ) {
							$this->debug_message( 'found a url in sitemap: ' . $xml_url->loc );
							$urls[] = (string) $xml_url->loc;
						}
					}
				}
				if ( $map_count ) {
					foreach ( $xml->sitemap as $sitemap ) {
						$this->debug_message( 'found a child map at ' . $sitemap->loc );
						$urls = array_merge( $urls, $this->get_sitemap_urls( (string) $sitemap->loc ) );
					}
				}
			}
		}
		if ( empty( $urls ) ) {
			$urls = $this->get_post_urls();
		}
		return $urls;
	}

	/**
	 * Retrieve a sitemap for parsing.
	 *
	 * @param string $sitemap_url The sitemap URL.
	 * @return string The contents of the sitemap.
	 */
	function get_sitemap_xml( $sitemap_url ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$this->debug_message( "fetching $sitemap_url" );
		$args = array(
			'user-agent' => 'SWIS Performance/Preload',
			'sslverify'  => apply_filters( 'https_local_ssl_verify', false, $sitemap_url ),
		);

		$args = apply_filters( 'swis_cache_preload_sitemap_request_args', $args );

		$result = wp_remote_get( esc_url_raw( $sitemap_url ), $args );

		if ( is_wp_error( $result ) ) {
			$this->debug_message( 'cache preload error: ' . $result->get_error_message() );
			return '';
		}

		$http_code = wp_remote_retrieve_response_code( $result );
		if ( 200 !== $http_code ) {
			$this->debug_message( "cache preload error, code $http_code" );
			return '';
		}

		$xml_content = wp_remote_retrieve_body( $result );
		// Check to be sure this is a valid sitemap.
		if ( false === strpos( $xml_content, '<loc>' ) ) {
			$this->debug_message( 'cache preload error, no <loc> sections found!' );
			return '';
		}
		return $xml_content;
	}

	/**
	 * Fetch posts for preloading, fallback if no sitemaps were found.
	 *
	 * @return array A list of URLs that should be preloaded.
	 */
	function get_post_urls() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$urls = array();

		$post_types = get_post_types( array( 'public' => true ) );
		$post_types = array_filter( $post_types, 'is_post_type_viewable' );

		$args = apply_filters(
			'swis_preload_posts_args',
			array(
				'fields'         => 'ids',
				'numberposts'    => 1000,
				'orderby'        => 'post_date',
				'order'          => 'DESC',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'post_type'      => $post_types,
			)
		);

		$blog_posts = get_posts( $args );

		if ( ! $this->is_iterable( $blog_posts ) ) {
			return $urls;
		}

		foreach ( $blog_posts as $blog_post ) {
			$permalink = get_permalink( $blog_post );
			$this->debug_message( "found $permalink for post $blog_post" );
			if ( $permalink ) {
				$urls[] = $permalink;
			}
		}

		return $urls;
	}

	/**
	 * Check if the given URL should be preloaded.
	 *
	 * @param string $url URL to check.
	 * @param string $home_url Homepage URL.
	 * @param string $home_domain Homepage domain name.
	 * @return bool True to preload, false otherwise.
	 */
	function should_preload( $url, $home_url, $home_domain ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$this->debug_message( "checking $url against $home_url with $home_domain" );
		$url_parts = $this->parse_url( $url );
		if ( empty( $url_parts ) ) {
			$this->debug_message( 'parsing failed' );
			return false;
		}
		if ( ! empty( $url_parts['fragment'] ) ) {
			$this->debug_message( 'bookmark not necessary' );
			return false;
		}
		if ( empty( $url_parts['host'] ) ) {
			$url = home_url( $url );
			$this->debug_message( "fixed $url" );
			$url_parts = $this->parse_url( $url );
		}
		if ( 0 === strpos( $url, '//' ) && ! empty( $url_parts['scheme'] ) ) {
			$url = $url_parts['scheme'] . ':' . $url;
			$this->debug_message( "added scheme to $url" );
		}
		if ( untrailingslashit( $url ) === untrailingslashit( $home_url ) ) {
			$this->debug_message( 'URL is home URL' );
			return false;
		}
		if ( $url_parts['host'] !== $home_domain ) {
			$this->debug_message( 'URL is not at home' );
			return false;
		}
		if ( apply_filters( 'swis_skip_cache_preload', false, $url ) ) {
			$this->debug_message( 'URL skipped by user/filter' );
			return false;
		}
		if ( $this->is_file_url( $url ) ) {
			$this->debug_message( 'URL is a file' );
			return false;
		}

		$cache_settings = swis()->cache->get_settings();
		if ( ! empty( $cache_settings['excluded_query_strings'] ) ) {
			$query_string_regex = self::$settings['excluded_query_strings'];
		} else {
			$query_string_regex = '/^(?!(fbclid|ref|mc_(cid|eid)|utm_(source|medium|campaign|term|content|expid)|gclid|fb_(action_ids|action_types|source)|age-verified|usqp|cn-reloaded|_ga|_ke)).+$/';
		}
		if ( ! empty( $url_parts['query'] ) && preg_match( $query_string_regex, $url_parts['query'] ) ) {
			$this->debug_message( 'URL has disallowed query params' );
			return false;
		}
		return $url;
	}

	/**
	 * Check if the URL is already cached.
	 *
	 * @param string $url The URL path to check.
	 * @return bool True for cached, false if it ain't.
	 */
	function is_cached( $url ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! class_exists( '\SWIS\Disk_Cache' ) ) {
			return false;
		}
		$cache_file_dir = Disk_Cache::get_cache_file_dir( $url );
		$this->debug_message( "checking if $cache_file_dir/ exists" );
		if ( \is_dir( $cache_file_dir ) ) {
			$dir_objects = Disk_Cache::get_dir_objects( $cache_file_dir );
			if ( $this->is_iterable( $dir_objects ) ) {
				foreach ( $dir_objects as $dir_object ) {
					if ( is_file( $dir_object ) ) {
						$this->debug_message( 'it sure does!' );
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Check if the URL path is to a known file type.
	 *
	 * @param string $path The URL path to check.
	 * @return bool True for known files, false for everything else.
	 */
	function is_file_url( $path ) {
		$known_types = array( 'jpe', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'tiff', 'pdf', 'doc', 'docx', 'odt', 'txt', 'mp3', 'ogg', 'avi', 'm4v', 'mov', 'wvm', 'qt', 'webm', 'ogv', 'mp4', 'm4p', 'mpg', 'mpeg', 'mpv', 'zip', 'tar', 'bz2', 'tgz', 'rar', 'gz' );
		$known_types = implode( '|', $known_types );
		if ( preg_match( '#\.(?:' . $known_types . ')$#i', $path ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Preloads the given URL.
	 *
	 * @param string $url The page to preload.
	 */
	function preload( $url ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		if ( $this->is_cached( $url ) ) {
			return;
		}

		// Sleep first, instead of later, which gives the cache clearing time to finish.
		// It also means that when we're done, that's it, and we exit right away.
		if ( $this->function_exists( 'sleep' ) ) {
			sleep( absint( apply_filters( 'swis_cache_preload_delay', 5 ) ) );
		}

		$args = array(
			'timeout'    => 10,
			'user-agent' => 'SWIS Performance/Preload',
			'sslverify'  => apply_filters( 'https_local_ssl_verify', false, $url ),
		);

		if ( $this->get_option( 'cache_webp' ) ) {
			$args['headers'] = 'Accept: image/webp';
		}

		$args = apply_filters( 'swis_cache_preload_url_request_args', $args );

		$result = wp_remote_get( esc_url_raw( $url ), $args );

		if ( is_wp_error( $result ) ) {
			$this->debug_message( 'cache preload error: ' . $result->get_error_message() );
		} else {
			$this->debug_message( wp_remote_retrieve_response_code( $result ) );
		}
	}
}
