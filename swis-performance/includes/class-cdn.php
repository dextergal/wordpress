<?php
/**
 * Class and methods to rewrite resources for CDN.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enables plugin to filter the page content and replace local URLs with CDN URLs.
 */
final class CDN extends Page_Parser {

	/**
	 * A list of user-defined exclusions, populated by validate_user_exclusions().
	 *
	 * @access protected
	 * @var array $user_exclusions
	 */
	protected $user_exclusions = array();

	/**
	 * Indicates if we are in full-page filtering mode.
	 *
	 * @access public
	 * @var bool $filtering_the_page
	 */
	public $filtering_the_page = false;

	/**
	 * Indicates if we are in content filtering mode.
	 *
	 * @access public
	 * @var bool $filtering_the_content
	 */
	public $filtering_the_content = false;

	/**
	 * List of permitted domains for CDN rewriting.
	 *
	 * @access public
	 * @var array $allowed_domains
	 */
	public $allowed_domains = array();

	/**
	 * Path portion to remove at beginning of URL, usually for path-style S3 domains.
	 *
	 * @access public
	 * @var string $remove_path
	 */
	public $remove_path = '';

	/**
	 * The CDN domain/zone.
	 *
	 * @access private
	 * @var string $cdn_domain
	 */
	private $cdn_domain = false;

	/**
	 * If this is running at the same time as ExactDN.
	 *
	 * @access private
	 * @var bool $parsing_exactdn
	 */
	private $parsing_exactdn = false;

	/**
	 * The detected site scheme (http/https).
	 *
	 * @access private
	 * @var string $scheme
	 */
	private $scheme = false;

	/**
	 * Register actions and filters for CDN rewriting.
	 */
	function __construct() {
		if ( ! $this->get_option( 'cdn_domain' ) ) {
			return;
		}
		parent::__construct();
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		$this->cdn_domain = $this->sanitize_domain( $this->get_option( 'cdn_domain' ) );
		// Make sure we have a CDN domain to use.
		if ( ! $this->cdn_domain ) {
			return;
		}
		if ( \get_option( 'ewww_image_optimizer_exactdn' ) || \get_option( 'easyio_exactdn' ) ) {
			$this->parsing_exactdn = true;
			add_filter( 'swis_cdn_skip_image', '__return_true' );
			$this->debug_message( 'bypassing images for ExactDN' );
		}

		// Images in post content and galleries.

		$uri = add_query_arg( null, null );
		$this->debug_message( "request uri is $uri" );

		if ( ! $this->scheme ) {
			$site_url = get_home_url();
			$scheme   = 'http';
			if ( false !== strpos( $site_url, 'https://' ) ) {
				$this->debug_message( 'site URL contains https' );
				$scheme = 'https';
			} elseif ( isset( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) {
				$this->debug_message( 'page requested over https' );
				$scheme = 'https';
			} elseif ( false !== strpos( $uri, 'https://' ) ) {
				$this->debug_message( 'request uri contains https' );
				$scheme = 'https';
			} else {
				$this->debug_message( 'using plain http' );
			}
			$this->scheme = $scheme;
		}

		/**
		 * Allow pre-empting the parsers by page.
		 *
		 * @param bool Whether to skip parsing the page.
		 * @param string $uri The URL of the page.
		 */
		if ( apply_filters( 'swis_skip_cdn_by_page', false, $uri ) ) {
			return;
		}

		if ( ! defined( 'SWIS_CDN_ALL_THE_THINGS' ) ) {
			define( 'SWIS_CDN_ALL_THE_THINGS', true );
		}

		// Get all the script/css urls and rewrite them (if enabled).
		add_filter( 'style_loader_src', array( $this, 'parse_enqueue' ), 10000 );
		add_filter( 'script_loader_src', array( $this, 'parse_enqueue' ), 10000 );

		if ( $this->parsing_exactdn ) {
			add_filter( 'exactdn_the_page', array( $this, 'filter_page_output' ), 5 );
		} else {
			add_filter( 'the_content', array( $this, 'filter_the_content' ), 1000000 );
			// Hook onto the output buffer filter.
			add_filter( $this->prefix . 'filter_page_output', array( $this, 'filter_page_output' ), 5 );
		}

		add_filter( 'swis_cdn_rewrite_url', array( $this, 'plugin_get_image_url' ) );
		add_filter( 'eio_lazy_placeholder', array( $this, 'plugin_get_image_url' ) );
		add_filter( 'wp_get_attachment_thumb_url', array( $this, 'plugin_get_image_url' ) ); // $param1 is the image URL.
		add_filter( 'wp_get_attachment_url', array( $this, 'plugin_get_image_url' ) ); // $param1 is the image URL.
		add_filter( 'wp_get_attachment_image_src', array( $this, 'get_attachment_image_src' ) ); // $param1[0] is the image URL.
		add_filter( 'get_image_tag', array( $this, 'filter_img_tag' ) ); // tag/html is $param1.

		// Allow parsing of certain "admin" requests.
		add_filter( 'swis_cdn_admin_allow_image_srcset', array( $this, 'allow_admin_image_rewriting' ), 10, 2 );
		add_filter( 'swis_cdn_admin_allow_plugin_image_url', array( $this, 'allow_admin_image_rewriting' ), 10, 2 );
		add_filter( 'swis_cdn_admin_allow_get_attachment_image_src', array( $this, 'allow_admin_image_rewriting' ), 10, 2 );
		add_filter( 'swis_cdn_admin_allow_img_tag', array( $this, 'allow_admin_image_rewriting' ), 10, 2 );

		// Check REST API requests to see if CDN rewriter should be running.
		add_filter( 'rest_request_before_callbacks', array( $this, 'parse_restapi_maybe' ), 11, 3 );

		// Overrides for user exclusions.
		add_filter( 'swis_cdn_skip_image', array( $this, 'cdn_skip_user_exclusions' ), 9, 2 );
		add_filter( 'swis_cdn_skip_url', array( $this, 'cdn_skip_user_exclusions' ), 9, 2 );

		// Responsive image srcset substitution.
		add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset_array' ), 11, 1 );

		// Filter for NextGEN image URLs within JS.
		add_filter( 'ngg_pro_lightbox_images_queue', array( $this, 'ngg_pro_lightbox_images_queue' ) );
		add_filter( 'ngg_get_image_url', array( $this, 'plugin_get_image_url' ) );

		// Filter for Envira image URLs.
		add_filter( 'envira_gallery_output_item_data', array( $this, 'envira_gallery_output_item_data' ) );
		add_filter( 'envira_gallery_image_src', array( $this, 'plugin_get_image_url' ) );

		// Filter for legacy WooCommerce API endpoints.
		add_filter( 'woocommerce_api_product_response', array( $this, 'woocommerce_api_product_response' ) );

		// DNS prefetching.
		add_filter( 'wp_resource_hints', array( $this, 'dns_prefetch' ), 10, 2 );

		$upload_url_parts = $this->parse_url( $this->content_url() );
		if ( empty( $upload_url_parts ) ) {
			$this->debug_message( "could not break down URL: $this->site_url" );
			return;
		}
		$this->upload_domain = $upload_url_parts['host'];
		$this->debug_message( "allowing images from here: $this->upload_domain" );
		$this->allowed_domains[] = $this->upload_domain;
		if ( false === strpos( $this->upload_domain, 'www' ) ) {
			$this->allowed_domains[] = 'www.' . $this->upload_domain;
		} elseif ( 0 === strpos( $this->upload_domain, 'www' ) ) {
			$nonwww = ltrim( ltrim( $this->upload_domain, 'w' ), '.' );
			if ( $nonwww && $nonwww !== $this->upload_domain ) {
				$this->allowed_domains[] = $nonwww;
			}
		}
		$wpml_domains = apply_filters( 'wpml_setting', array(), 'language_domains' );
		if ( $this->is_iterable( $wpml_domains ) ) {
			$this->debug_message( 'wpml domains: ' . implode( ',', $wpml_domains ) );
			$this->allowed_domains[] = $this->parse_url( get_option( 'home' ), PHP_URL_HOST );
			foreach ( $wpml_domains as $wpml_domain ) {
				$this->allowed_domains[] = $wpml_domain;
			}
		}
		$this->allowed_domains = apply_filters( 'swis_cdn_allowed_domains', $this->allowed_domains );
		$this->debug_message( 'allowed domains: ' . implode( ',', $this->allowed_domains ) );
		$this->get_allowed_paths();
		$this->validate_user_exclusions();
	}

	/**
	 * Validate the CDN domain.
	 *
	 * @param string $domain The user-supplied CDN domain.
	 * @return string The validated CDN domain.
	 */
	function sanitize_domain( $domain ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! $domain ) {
			return;
		}
		if ( strlen( $domain ) > 100 ) {
			$this->debug_message( "$domain too long" );
			return false;
		}
		if ( ! preg_match( '#^[A-Za-z0-9\.\-]+$#', $domain ) ) {
			$this->debug_message( "$domain has bad characters" );
			return false;
		}
		return $domain;
	}

	/**
	 * Get the paths for wp-content, wp-includes, and the uploads directory.
	 * These are used to help determine which URLs are allowed to be rewritten for the CDN.
	 */
	function get_allowed_paths() {
		$wp_content_path = trim( $this->parse_url( content_url(), PHP_URL_PATH ), '/' );
		$wp_include_path = trim( $this->parse_url( includes_url(), PHP_URL_PATH ), '/' );
		$this->debug_message( "wp-content path: $wp_content_path" );
		$this->debug_message( "wp-includes path: $wp_include_path" );

		$this->content_path = basename( $wp_content_path );
		$this->include_path = basename( $wp_include_path );
		$this->uploads_path = basename( $wp_content_path );

		// NOTE: This bit is not currently in use, so we'll see if anyone needs it.
		$uploads_info = wp_upload_dir();
		if ( ! empty( $uploads_info['baseurl'] ) && ! empty( $wp_content_path ) && false === strpos( $uploads_info['baseurl'], $wp_content_path ) ) {
			$uploads_path = trim( $this->parse_url( $uploads_info['baseurl'], PHP_URL_PATH ), '/' );
			$this->debug_message( "wp uploads path: $uploads_path" );
			$this->uploads_path = basename( $uploads_path );
		}
	}

	/**
	 * Validate the user-defined exclusions for CDN rewriting.
	 */
	function validate_user_exclusions() {
		$user_exclusions = $this->get_option( 'cdn_exclude' );
		if ( ! empty( $user_exclusions ) ) {
			if ( is_string( $user_exclusions ) ) {
				$user_exclusions = array( $user_exclusions );
			}
			if ( is_array( $user_exclusions ) ) {
				foreach ( $user_exclusions as $exclusion ) {
					if ( ! is_string( $exclusion ) ) {
						continue;
					}
					if ( $this->content_path && false !== strpos( $exclusion, $this->content_path ) ) {
						$exclusion = preg_replace( '#([^"\'?>]+?)?' . $this->content_path . '/#i', '', $exclusion );
					}
					$this->user_exclusions[] = ltrim( $exclusion, '/' );
				}
			}
		}
		$this->user_exclusions[] = 'plugins/anti-captcha/';
		$this->user_exclusions[] = 'fusion-app';
		$this->user_exclusions[] = 'themes/Avada/';
		$this->user_exclusions[] = 'plugins/fusion-builder/';
	}

	/**
	 * Identify images in page content, and if images are local to the site, send through CDN.
	 *
	 * @param string $content The page/post content.
	 * @return string The content with CDN image urls.
	 */
	function filter_page_output( $content ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$this->filtering_the_page = true;

		$content = $this->filter_the_content( $content );

		/**
		 * Allow parsing the full page content after the CDN rewriter is finished with it.
		 *
		 * @param string $content The fully-parsed HTML code of the page.
		 */
		$content = apply_filters( 'swis_cdn_the_page', $content );

		$this->filtering_the_page = false;
		return $content;
	}

	/**
	 * Identify images in the content, and if images are local to the site, send through CDN.
	 *
	 * @param string $content The page/post content.
	 * @return string The content with CDN image urls.
	 */
	function filter_the_content( $content ) {
		if ( $this->is_json( $content ) ) {
			return $content;
		}
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$images = $this->get_images_from_html( $content, true );

		if ( ! empty( $images ) ) {
			$this->debug_message( 'we have images to parse' );

			foreach ( $images[0] as $index => $tag ) {
				// Identify image source.
				$src     = $images['img_url'][ $index ];
				$new_tag = $this->filter_img_tag( $tag, $src );
				if ( $new_tag !== $tag ) {
					// Replace original tag with modified version.
					$content = str_replace( $tag, $new_tag, $content );
				}
			} // End foreach().
		} // End if();
		$element_types = apply_filters( 'swis_allowed_background_image_elements', array( 'div', 'li', 'span', 'section', 'a' ) );
		foreach ( $element_types as $element_type ) {
			// Process background images on HTML elements.
			$content = $this->filter_bg_images( $content, $element_type );
		}
		if ( $this->filtering_the_page ) {
			$content = $this->filter_style_blocks( $content );
		}
		if ( $this->filtering_the_page && $this->get_option( 'cdn_all_the_things' ) ) {
			$this->debug_message( 'rewriting all other wp-content/wp-includes urls' );
			$content = $this->filter_all_the_things( $content );
		}
		$this->debug_message( 'done parsing page' );
		$this->filtering_the_content = false;

		return $content;
	}

	/**
	 * Filter an individual img tag and rewrite URLs to the CDN domain.
	 *
	 * @param string $tag The img tag HTML.
	 * @param string $src The img src attribute. Optional, default to empty string.
	 * @return string The img tag, potentially with CDN URLs.
	 */
	function filter_img_tag( $tag, $src = '' ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// This makes sure we don't pollute the wp-admin via get_image_tag() filter.
		if ( is_admin() && false === apply_filters( 'swis_cdn_admin_allow_img_tag', false, $tag ) ) {
			return $tag;
		}
		if ( ! is_string( $tag ) ) {
			$this->debug_message( '$tag is not a string?' );
		}
		if ( $src && is_string( $src ) ) {
			$this->debug_message( $src );
		} else {
			$src = $this->get_attribute( $tag, 'src' );
			$this->debug_message( $src );
		}
		$src_orig = $src;

		/**
		 * Allow specific images to be skipped by the CDN rewriter.
		 *
		 * @param bool false Should the CDN rewriter ignore this image? Default false.
		 * @param string $src Image URL.
		 * @param string $tag Image HTML Tag.
		 */
		if ( apply_filters( 'swis_cdn_skip_image', false, $src, $tag ) ) {
			return $tag;
		}

		$this->debug_message( 'made it passed the filters' );

		$is_relative = false;
		// Check for relative urls that start with a slash. Unlikely that we'll attempt relative urls beyond that.
		if (
			'/' === substr( $src, 0, 1 ) &&
			'/' !== substr( $src, 1, 1 )
		) {
			$src         = $this->scheme . '://' . $this->upload_domain . $src;
			$is_relative = true;
		}

		$new_tag = $tag;
		// Check if image URL is allowed to be rewritten.
		if ( $this->validate_image_url( $src ) ) {
			$this->debug_message( 'url validated' );

			$cdn_url = $this->generate_url( $src );
			$this->debug_message( "new url $cdn_url" );

			// Ensure changes are only applied to the current image by copying and modifying the matched tag, then replacing the entire tag with our modified version.
			if ( $src !== $cdn_url ) {
				// Supplant the original source value with the CDN URL.
				$this->debug_message( "replacing $src_orig with $cdn_url" );
				if ( $is_relative ) {
					$this->set_attribute( $new_tag, 'src', $cdn_url, true );
				} else {
					$new_tag = str_replace( $src_orig, $cdn_url, $new_tag );
				}
			}
		}
		foreach ( $this->allowed_domains as $local_domain ) {
			if ( false !== strpos( $new_tag, $local_domain ) ) {
				$this->debug_message( "doing str_replace( $local_domain, {$this->cdn_domain} )" );
				$new_tag = str_replace( '//' . $local_domain . '/', '//' . $this->cdn_domain . '/', $new_tag );
			}
		}
		return $tag;
	}

	/**
	 * Parse page content looking for elements with CSS background-image properties.
	 *
	 * @param string $content The HTML content to parse.
	 * @param string $tag_type The type of HTML tag to look for.
	 * @return string The filtered HTML content.
	 */
	function filter_bg_images( $content, $tag_type ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// Process background images on elements.
		$elements = $this->get_elements_from_html( $content, $tag_type );
		if ( $this->is_iterable( $elements ) ) {
			foreach ( $elements as $index => $element ) {
				$this->debug_message( "parsing a $tag_type" );
				if ( false === strpos( $element, 'background:' ) && false === strpos( $element, 'background-image:' ) ) {
					continue;
				}
				$style = $this->get_attribute( $element, 'style' );
				if ( empty( $style ) ) {
					continue;
				}
				$this->debug_message( "checking style attr for background-image: $style" );
				$bg_image_url = $this->get_background_image_url( $style );
				if ( $this->validate_image_url( $bg_image_url ) ) {
					/** This filter is already documented in class-cdn.php */
					if ( apply_filters( 'swis_cdn_skip_image', false, $bg_image_url, $element ) ) {
						continue;
					}
					$cdn_bg_image_url = $this->generate_url( $bg_image_url );
					if ( $bg_image_url !== $cdn_bg_image_url ) {
						$new_style = str_replace( $bg_image_url, $cdn_bg_image_url, $style );
						$element   = str_replace( $style, $new_style, $element );
					}
				}
				if ( $element !== $elements[ $index ] ) {
					$content = str_replace( $elements[ $index ], $element, $content );
				}
			}
		}
		return $content;
	}

	/**
	 * Parse page content looking for CSS blocks with background-image properties.
	 *
	 * @param string $content The HTML content to parse.
	 * @return string The filtered HTML content.
	 */
	function filter_style_blocks( $content ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// Process background images on elements.
		$elements = $this->get_style_tags_from_html( $content );
		if ( $this->is_iterable( $elements ) ) {
			foreach ( $elements as $eindex => $element ) {
				$this->debug_message( 'parsing a style block, starts with: ' . str_replace( "\n", '', substr( $element, 0, 50 ) ) );
				if ( false === strpos( $element, 'background:' ) && false === strpos( $element, 'background-image:' ) ) {
					continue;
				}
				$bg_images = $this->get_background_images( $element );
				if ( $this->is_iterable( $bg_images ) ) {
					foreach ( $bg_images as $bindex => $bg_image ) {
						$this->debug_message( "parsing a background CSS rule: $bg_image" );
						$bg_image_url = $this->get_background_image_url( $bg_image );
						$this->debug_message( "found potential background image url: $bg_image_url" );
						if ( $this->validate_image_url( $bg_image_url ) ) {
							if ( apply_filters( 'swis_cdn_skip_image', false, $bg_image_url, $element ) ) {
								continue;
							}
							$cdn_bg_image_url = $this->generate_url( $bg_image_url );
							if ( $bg_image_url !== $cdn_bg_image_url ) {
								$this->debug_message( "replacing $bg_image_url with $cdn_bg_image_url" );
								$bg_image = str_replace( $bg_image_url, $cdn_bg_image_url, $bg_image );
								if ( $bg_image !== $bg_images[ $bindex ] ) {
									$this->debug_message( "replacing bg url with $bg_image" );
									$element = str_replace( $bg_images[ $bindex ], $bg_image, $element );
								}
							}
						}
					}
				}
				if ( $element !== $elements[ $eindex ] ) {
					$this->debug_message( 'replacing style block' );
					$content = str_replace( $elements[ $eindex ], $element, $content );
				}
			}
		}
		return $content;
	}

	/**
	 * Parse page content looking for wp-content/wp-includes URLs to rewrite.
	 *
	 * @param string $content The HTML content to parse.
	 * @return string The filtered HTML content.
	 */
	function filter_all_the_things( $content ) {
		if ( $this->cdn_domain && $this->upload_domain && $this->content_path ) {
			$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
			$upload_domain = $this->upload_domain;
			if ( 0 === strpos( $this->upload_domain, 'www.' ) ) {
				$upload_domain = substr( $this->upload_domain, 4 );
			}
			$escaped_upload_domain = str_replace( '.', '\.', $upload_domain );
			$this->debug_message( $escaped_upload_domain );
			if ( ! empty( $this->user_exclusions ) ) {
				$content = preg_replace( '#(https?:)?//(?:www\.)?' . $escaped_upload_domain . '([^"\'?>]+?)?/' . $this->content_path . '/([^"\'?>]+?)?(' . implode( '|', $this->user_exclusions ) . ')#i', '$1//' . $this->upload_domain . '$2/?wpcontent-bypass?/$3$4', $content );
			}
			if ( strpos( $content, '<use ' ) ) {
				// Pre-empt rewriting of files within <use> tags, particularly to prevent security errors for SVGs.
				$content = preg_replace( '#(<use\s+?(?>xlink:)?href=["\'])(https?:)?//(?>www\.)?' . $escaped_upload_domain . '([^"\'?>]+?)?/' . $this->content_path . '/#is', '$1$2//' . $this->upload_domain . '$3/?wpcontent-bypass?/', $content );
			}
			// Pre-empt rewriting of wp-includes and wp-content if the extension is not allowed by using a temporary placeholder.
			$content = preg_replace( '#(https?:)?//(?:www\.)?' . $escaped_upload_domain . '([^"\'?>]+?)?/' . $this->content_path . '/([^"\'?>]+?)\.(htm|html|php|ashx|m4v|mov|wvm|qt|webm|ogv|mp4|m4p|mpg|mpeg|mpv)#i', '$1//' . $this->upload_domain . '$2/?wpcontent-bypass?/$3.$4', $content );
			// Pre-empt partial paths that are used by JS to build other URLs.
			$content = str_replace( $this->content_path . '/themes/jupiter"', '?wpcontent-bypass?/themes/jupiter"', $content );
			$content = str_replace( $this->content_path . '/plugins/onesignal-free-web-push-notifications/sdk_files/"', '?wpcontent-bypass?/plugins/onesignal-free-web-push-notifications/sdk_files/"', $content );
			$content = str_replace( $this->content_path . '/plugins/u-shortcodes/shortcodes/monthview/"', '?wpcontent-bypass?/plugins/u-shortcodes/shortcodes/monthview/"', $content );
			$this->debug_message( 'searching for #(https?:)?//(?:www\.)?' . $escaped_upload_domain . '/([^"\'?&>]+?)?(nextgen-image|' . $this->include_path . '|' . $this->content_path . ')/#i and replacing with $1//' . $this->cdn_domain . '/$2$3/' );
			$content = preg_replace( '#(https?:)?//(?:www\.)?' . $escaped_upload_domain . '/([^"\'?>]+?)?(nextgen-image|' . $this->include_path . '|' . $this->content_path . ')/#i', '$1//' . $this->cdn_domain . '/$2$3/', $content );
			$content = str_replace( '?wpcontent-bypass?', $this->content_path, $content );
		}
		return $content;
	}

	/**
	 * Allow rewriting of srcset images for some admin-ajax requests.
	 *
	 * @param bool  $allow Will normally be false, unless already modified by another function.
	 * @param array $image Bunch of information about the image, but we don't care about that here.
	 * @return bool True if it's an allowable admin-ajax request, false for all other admin requests.
	 */
	function allow_admin_image_rewriting( $allow, $image ) {
		if ( ! wp_doing_ajax() ) {
			return $allow;
		}
		if ( ! empty( $_REQUEST['action'] ) && 'alm_get_posts' === $_REQUEST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}
		if ( ! empty( $_POST['action'] ) && 'eddvbugm_viewport_downloads' === $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}
		if ( ! empty( $_POST['action'] ) && 'Essential_Grid_Front_request_ajax' === $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}
		if ( ! empty( $_POST['action'] ) && 'filter_listing' === $_POST['action'] && ! empty( $_POST['layout'] ) && ! empty( $_POST['paged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}
		if ( ! empty( $_POST['action'] ) && 'mabel-rpn-getnew-purchased-products' === $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}
		if ( ! empty( $_POST['action'] ) && 'um_activity_load_wall' === $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}
		if ( ! empty( $_POST['action'] ) && 'vc_get_vc_grid_data' === $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}
		return $allow;
	}

	/**
	 * Filters an array of image `srcset` values, replacing each URL with its ExactDN equivalent.
	 *
	 * @param array $sources An array of image URLs and widths.
	 * @return array An array of CDN image URLs.
	 */
	public function filter_srcset_array( $sources = array() ) {
		$started = microtime( true );
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( is_admin() && false === apply_filters( 'swis_cdn_admin_allow_image_srcset', false, $sources ) ) {
			return $sources;
		}

		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $i => $source ) {
			if ( ! $this->validate_image_url( $source['url'] ) ) {
				continue;
			}

			/** This filter is already documented in class-cdn.php */
			if ( apply_filters( 'swis_cdn_skip_image', false, $source['url'], $source ) ) {
				continue;
			}

			$sources[ $i ]['url'] = $this->generate_url( $source['url'] );
		}
		return $sources;
	}

	/**
	 * Check if this is a REST API request that we should handle (or not).
	 *
	 * @param WP_HTTP_Response $response Result to send to the client. Usually a WP_REST_Response.
	 * @param WP_REST_Server   $handler  ResponseHandler instance (usually WP_REST_Server).
	 * @param WP_REST_Request  $request  Request used to generate the response.
	 * @return WP_HTTP_Response The result, unaltered.
	 */
	function parse_restapi_maybe( $response, $handler, $request ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! is_a( $request, 'WP_REST_Request' ) ) {
			$this->debug_message( 'oddball REST request or handler' );
			return $response; // Something isn't right, bail.
		}
		$route = $request->get_route();
		if ( is_string( $route ) ) {
			$this->debug_message( "current REST route is $route" );
		}
		if ( is_string( $route ) && false !== strpos( $route, 'wp/v2/media/' ) && ! empty( $request['context'] ) && 'edit' === $request['context'] ) {
			$this->debug_message( 'REST API media endpoint from post editor' );
			// We don't want CDN urls anywhere near the editor, so disable everything we can.
			add_filter( 'swis_cdn_skip_image', '__return_true', PHP_INT_MAX ); // This skips existing srcset indices.
		} elseif ( is_string( $route ) && false !== strpos( $route, 'wp/v2/media' ) && ! empty( $request['post'] ) && ! empty( $request->get_file_params() ) ) {
			$this->debug_message( 'REST API media endpoint (new upload)' );
			// We don't want CDN urls anywhere near the editor, so disable everything we can.
			add_filter( 'swis_cdn_skip_image', '__return_true', PHP_INT_MAX ); // This skips existing srcset indices.
		}
		return $response;
	}

	/**
	 * Make sure the image domain is on the list of approved domains.
	 *
	 * @param string $domain The hostname to validate.
	 * @return bool True if the hostname is allowed, false otherwise.
	 */
	public function allow_image_domain( $domain ) {
		$domain = trim( $domain );
		foreach ( $this->allowed_domains as $allowed ) {
			$allowed = trim( $allowed );
			if ( $domain === $allowed ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ensure image URL is valid for CDN rewriting.
	 *
	 * @param string $url The image url to be validated.
	 * @uses wp_parse_args
	 * @return bool True if the url is considerd valid, false otherwise.
	 */
	protected function validate_image_url( $url ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! is_string( $url ) ) {
			$this->debug_message( 'cannot validate uri when variable is not a string' );
			return false;
		}
		if ( false !== strpos( $url, 'data:image/' ) ) {
			$this->debug_message( "could not parse data uri: $url" );
			return false;
		}
		$parsed_url = $this->parse_url( $url );
		if ( ! $parsed_url ) {
			$this->debug_message( "could not parse: $url" );
			return false;
		}

		// Parse URL and ensure needed keys exist, since the array returned by `parse_url` only includes the URL components it finds.
		$url_info = wp_parse_args(
			$parsed_url,
			array(
				'scheme' => null,
				'host'   => null,
				'port'   => null,
				'path'   => null,
			)
		);

		if ( is_null( $url_info['host'] ) ) {
			$this->debug_message( 'null host' );
			return false;
		}

		// Bail if the image already went through ExactDN.
		if ( $this->cdn_domain === $url_info['host'] ) {
			$this->debug_message( 'already CDN image' );
			return false;
		}

		// Bail if no path is found.
		if ( is_null( $url_info['path'] ) ) {
			$this->debug_message( 'null path' );
			return false;
		}

		// Ensure image extension is acceptable, unless it's a dynamic NextGEN image.
		if ( ! in_array( strtolower( pathinfo( $url_info['path'], PATHINFO_EXTENSION ) ), $this->extensions, true ) && false === strpos( $url_info['path'], 'nextgen-image/' ) ) {
			$this->debug_message( 'invalid extension' );
			return false;
		}

		// Make sure this is an allowed image domain/hostname for ExactDN on this site.
		if ( ! $this->allow_image_domain( $url_info['host'] ) ) {
			$this->debug_message( 'invalid host for CDN' );
			return false;
		}

		return true;
	}

	/**
	 * Handle image urls within the NextGEN pro lightbox displays.
	 *
	 * @param array $images An array of NextGEN images and associated attributes.
	 * @return array The CDNified array of images.
	 */
	function ngg_pro_lightbox_images_queue( $images ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( $this->is_iterable( $images ) ) {
			foreach ( $images as $index => $image ) {
				if ( ! empty( $image['image'] ) && $this->validate_image_url( $image['image'] ) ) {
					$images[ $index ]['image'] = $this->generate_url( $image['image'] );
				}
				if ( ! empty( $image['thumb'] ) && $this->validate_image_url( $image['thumb'] ) ) {
					$images[ $index ]['thumb'] = $this->generate_url( $image['thumb'] );
				}
				if ( ! empty( $image['full_image'] ) && $this->validate_image_url( $image['full_image'] ) ) {
					$images[ $index ]['full_image'] = $this->generate_url( $image['full_image'] );
				}
				if ( $this->is_iterable( $image['srcsets'] ) ) {
					foreach ( $image['srcsets'] as $size => $srcset ) {
						if ( $this->validate_image_url( $srcset ) ) {
							$images[ $index ]['srcsets'][ $size ] = $this->generate_url( $srcset );
						}
					}
				}
				if ( $this->is_iterable( $image['full_srcsets'] ) ) {
					foreach ( $image['full_srcsets'] as $size => $srcset ) {
						if ( $this->validate_image_url( $srcset ) ) {
							$images[ $index ]['full_srcsets'][ $size ] = $this->generate_url( $srcset );
						}
					}
				}
			}
		}
		return $images;
	}

	/**
	 * Handle image urls within the Envira pro displays.
	 *
	 * @param array $image An Envira gallery image with associated attributes.
	 * @return array The CDNified array of data.
	 */
	function envira_gallery_output_item_data( $image ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( $this->is_iterable( $image ) ) {
			foreach ( $image as $index => $attr ) {
				if ( is_string( $attr ) && 0 === strpos( $attr, 'http' ) && $this->validate_image_url( $attr ) ) {
					$image[ $index ] = $this->generate_url( $attr );
				}
			}
			if ( ! empty( $image['opts']['thumb'] ) && $this->validate_image_url( $image['opts']['thumb'] ) ) {
				$image['opts']['thumb'] = $this->generate_url( $image['opts']['thumb'] );
			}
		}
		return $image;
	}

	/**
	 * Handle an array from wp_get_attachment_image_src().
	 *
	 * @param array $image An array of $src, $width, and $height.
	 * @return array The CDNified image data.
	 */
	function get_attachment_image_src( $image ) {
		// Don't foul up the admin side of things, unless a plugin wants to.
		if ( is_admin() && false === apply_filters( 'swis_cdn_admin_allow_get_attachment_image_src', false, $image ) ) {
			return $image;
		}
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( is_array( $image ) && ! empty( $image[0] ) && is_string( $image[0] ) ) {
			$image[0] = $this->plugin_get_image_url( $image[0] );
		}
		return $image;
	}

	/**
	 * Handle direct image urls within Plugins.
	 *
	 * @param string $image A url for an image.
	 * @return string The CDNified image url.
	 */
	function plugin_get_image_url( $image ) {
		// Don't foul up the admin side of things, unless a plugin wants to.
		if ( is_admin() && false === apply_filters( 'swis_cdn_admin_allow_plugin_image_url', false, $image ) ) {
			return $image;
		}
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( $this->validate_image_url( $image ) ) {
			return $this->generate_url( $image );
		}
		return $image;
	}

	/**
	 * Handle images in legacy WooCommerce API endpoints.
	 *
	 * @param array $product_data The product information that will be returned via the API.
	 * @return array The product information with CDNified image urls.
	 */
	function woocommerce_api_product_response( $product_data ) {
		if ( is_array( $product_data ) && ! empty( $product_data['featured_src'] ) ) {
			if ( $this->validate_image_url( $product_data['featured_src'] ) ) {
				$product_data['featured_src'] = $this->generate_url( $product_data['featured_src'] );
			}
		}
		return $product_data;
	}

	/**
	 * Exclude images and other resources from being processed based on user specified list.
	 *
	 * @param boolean $skip Whether ExactDN should skip processing.
	 * @param string  $url Resource URL.
	 * @return boolean True to skip the resource, unchanged otherwise.
	 */
	function cdn_skip_user_exclusions( $skip, $url ) {
		if ( $this->user_exclusions ) {
			foreach ( $this->user_exclusions as $exclusion ) {
				if ( false !== strpos( $url, $exclusion ) ) {
					$this->debug_message( "user excluded $url via $exclusion" );
					return true;
				}
			}
		}
		return $skip;
	}

	/**
	 * Converts a local script/css url to use CDN.
	 *
	 * @param string $url URL to the resource being parsed.
	 * @return string The CDN version of the resource, if it was local.
	 */
	function parse_enqueue( $url ) {
		if ( is_admin() ) {
			return $url;
		}
		if ( did_action( 'cornerstone_boot_app' ) || did_action( 'cs_before_preview_frame' ) ) {
			return $url;
		}
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$parsed_url = $this->parse_url( $url );

		if ( false !== strpos( $url, 'wp-admin/' ) ) {
			return $url;
		}
		if ( false !== strpos( $url, 'xmlrpc.php' ) ) {
			return $url;
		}

		/**
		 * Allow specific URLs to avoid going through CDN.
		 *
		 * @param bool false Should the URL be returned as is, without going through CDN. Default to false.
		 * @param string $url Resource URL.
		 */
		if ( true === apply_filters( 'swis_cdn_skip_url', false, $url ) ) {
			return $url;
		}

		// Unable to parse.
		if ( ! $parsed_url || ! is_array( $parsed_url ) || empty( $parsed_url['host'] ) || empty( $parsed_url['path'] ) ) {
			$this->debug_message( 'src url no good' );
			return $url;
		}

		// No PHP files shall pass.
		if ( preg_match( '/\.php$/', $parsed_url['path'] ) ) {
			return $url;
		}

		// Make sure this is an allowed image domain/hostname for CDN on this site.
		if ( ! $this->allow_image_domain( $parsed_url['host'] ) ) {
			$this->debug_message( "invalid host for CDN: {$parsed_url['host']}" );
			return $url;
		}

		// Figure out which CDN (sub)domain to use.
		if ( empty( $this->cdn_domain ) ) {
			$this->debug_message( 'no CDN domain configured' );
			return $url;
		}

		// No need to run a CDN URL through again.
		if ( $this->cdn_domain === $parsed_url['host'] ) {
			$this->debug_message( 'url already has CDN domain' );
			return $url;
		}

		$scheme = $this->scheme;
		if ( isset( $parsed_url['scheme'] ) && 'https' === $parsed_url['scheme'] ) {
			$scheme = 'https';
		}

		global $wp_version;
		// If a resource doesn't have a version string, we add one to help with cache-busting.
		if (
			false !== strpos( $url, $this->content_path . '/themes/' ) &&
			( empty( $parsed_url['query'] ) || 'ver=' . $wp_version === $parsed_url['query'] )
		) {
			$modified = $this->function_exists( 'filemtime' ) ? filemtime( get_template_directory() ) : '';
			if ( empty( $modified ) ) {
				$modified = $this->version;
			}
			/**
			 * Allows a custom version string for resources that are missing one.
			 *
			 * @param string Defaults to the modified time of the theme folder, and falls back to the plugin version.
			 */
			$parsed_url['query'] = apply_filters( 'swis_cdn_version_string', "m=$modified" );
		} elseif (
			false !== strpos( $url, $this->content_path . '/plugins/' ) &&
			( empty( $parsed_url['query'] ) || 'ver=' . $wp_version === $parsed_url['query'] )
		) {
			$parsed_url['query'] = '';
			$path                = $this->url_to_path_exists( $url );
			if ( $path ) {
				$modified = $this->function_exists( 'filemtime' ) ? filemtime( dirname( $path ) ) : '';
				if ( empty( $modified ) ) {
					$modified = $this->version;
				}
				/**
				 * Allows a custom version string for resources that are missing one.
				 *
				 * @param string Defaults to the modified time of the folder, and falls back to the plugin version.
				 */
				$parsed_url['query'] = apply_filters( 'swis_cdn_version_string', "m=$modified" );
			}
		} elseif ( empty( $parsed_url['query'] ) ) {
			$parsed_url['query'] = apply_filters( 'swis_cdn_version_string', 'm=' . $this->version );
		}

		$cdn_url = $scheme . '://' . $this->cdn_domain . '/' . ltrim( $parsed_url['path'], '/' ) . '?' . $parsed_url['query'];
		$this->debug_message( "cdn css/script url: $cdn_url" );
		return $this->url_scheme( $cdn_url, $scheme );
	}

	/**
	 * Generates a CDN URL.
	 *
	 * @param string $image_url URL to the publicly accessible image you want to manipulate.
	 * @return string The raw final URL. You should run this through esc_url() before displaying it.
	 */
	function generate_url( $image_url ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$image_url = trim( $image_url );

		$scheme = $this->scheme;

		/**
		 * Disables CDN URL processing for local development.
		 *
		 * @param bool false default
		 */
		if ( true === apply_filters( 'swis_cdn_development_mode', false ) ) {
			return $image_url;
		}

		/**
		 * Allow specific URLs to avoid going through CDN.
		 *
		 * @param bool false Should the URL be returned as is, without going through CDN. Default to false.
		 * @param string $image_url Resource URL.
		 */
		if ( true === apply_filters( 'swis_cdn_skip_url', false, $image_url ) ) {
			return $image_url;
		}

		if ( empty( $image_url ) ) {
			return $image_url;
		}

		$image_url_parts = $this->parse_url( $image_url );

		// Unable to parse.
		if ( ! is_array( $image_url_parts ) || empty( $image_url_parts['host'] ) || empty( $image_url_parts['path'] ) ) {
			$this->debug_message( 'src url no good' );
			return $image_url;
		}

		if ( isset( $image_url_parts['scheme'] ) && 'https' === $image_url_parts['scheme'] ) {
			$scheme = 'https';
		}

		$this->debug_message( $image_url_parts['host'] );

		// Check if we have a CDN domain to use.
		if ( empty( $this->cdn_domain ) ) {
			$this->debug_message( 'no cdn domain configured' );
			return $image_url;
		}

		// No need to run a CDN URL through again.
		if ( $this->cdn_domain === $image_url_parts['host'] ) {
			$this->debug_message( 'url already has CDN domain' );
			return $image_url;
		}

		$extension = pathinfo( $image_url_parts['path'], PATHINFO_EXTENSION );
		if ( ( empty( $extension ) && false === strpos( $image_url_parts['path'], 'nextgen-image/' ) ) || in_array( $extension, array( 'php', 'ashx' ), true ) ) {
			$this->debug_message( 'bad extension' );
			return $image_url;
		}

		$domain  = 'http://' . $this->cdn_domain . '/';
		$cdn_url = $domain . ltrim( $image_url_parts['path'], '/' );
		$this->debug_message( "bare CDN URL: $cdn_url" );

		return $this->url_scheme( $cdn_url, $scheme );
	}

	/**
	 * Prepends schemeless urls or replaces non-http scheme with a valid scheme, defaults to 'http'.
	 *
	 * @param string      $url The URL to parse.
	 * @param string|null $scheme Retrieve specific URL component.
	 * @return string Result of parse_url.
	 */
	function url_scheme( $url, $scheme ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			$this->debug_message( 'not a valid scheme' );
			if ( preg_match( '#^(https?:)?//#', $url ) ) {
				$this->debug_message( 'url has a valid scheme already' );
				return $url;
			}
			$this->debug_message( 'invalid scheme provided, and url sucks, defaulting to http' );
			$scheme = 'http';
		}
		$this->debug_message( "valid $scheme - $url" );
		return preg_replace( '#^([a-z:]+)?//#i', "$scheme://", $url );
	}

	/**
	 * Adds link to header which enables DNS prefetching and preconnect for faster speed.
	 *
	 * @param array  $hints A list of hints for a particular relationship type.
	 * @param string $relationship_type The type of hint being filtered: dns-prefetch, preconnect, etc.
	 * @return array The list of hints, potentially with the CDN domain added in.
	 */
	function dns_prefetch( $hints, $relationship_type ) {
		global $exactdn;
		if ( is_object( $exactdn ) && $exactdn->get_exactdn_domain() === $this->cdn_domain ) {
			return $hints;
		}
		if ( $this->cdn_domain && ( 'dns-prefetch' === $relationship_type || 'preconnect' === $relationship_type ) ) {
			$hints[] = '//' . $this->cdn_domain;
		}
		return $hints;
	}
}
