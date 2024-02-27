<?php
/**
 * Implements WebP rewriting using page parsing for cache engine.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enables WebP URL replacement to create a separate cached page for WebP-supporting browsers.
 */
class Cache_WebP extends Page_Parser {

	/**
	 * Allowed paths for WebP.
	 *
	 * @access protected
	 * @var array $webp_paths
	 */
	protected $webp_paths = array();

	/**
	 * Allowed domains for WebP.
	 *
	 * @access protected
	 * @var array $webp_domains
	 */
	protected $webp_domains = array();

	/**
	 * Setup the paths/domains for WebP Caching.
	 */
	function __construct() {
		parent::__construct();

		$upload_dir        = wp_get_upload_dir();
		$this->content_url = trailingslashit( ! empty( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] : content_url( 'uploads' ) );
		$this->debug_message( "content_url: $this->content_url" );
		$this->home_domain = $this->parse_url( $this->home_url, PHP_URL_HOST );
		$this->debug_message( "home domain: $this->home_domain" );

		// Find the WP Offload Media domain/path.
		if ( class_exists( 'Amazon_S3_And_CloudFront' ) ) {
			global $as3cf;
			$s3_scheme = $as3cf->get_url_scheme();
			$s3_bucket = $as3cf->get_setting( 'bucket' );
			$s3_region = $as3cf->get_setting( 'region' );
			if ( is_wp_error( $s3_region ) ) {
				$s3_region = '';
			}
			if ( ! empty( $s3_bucket ) && ! is_wp_error( $s3_bucket ) && method_exists( $as3cf, 'get_provider' ) ) {
				$s3_domain = $as3cf->get_provider()->get_url_domain( $s3_bucket, $s3_region, null, array(), true );
			} elseif ( ! empty( $s3_bucket ) && ! is_wp_error( $s3_bucket ) && method_exists( $as3cf, 'get_storage_provider' ) ) {
				$s3_domain = $as3cf->get_storage_provider()->get_url_domain( $s3_bucket, $s3_region );
			}
			if ( ! empty( $s3_domain ) && $as3cf->get_setting( 'serve-from-s3' ) ) {
				$this->debug_message( "found S3 domain of $s3_domain with bucket $s3_bucket and region $s3_region" );
				$this->webp_paths[] = $s3_scheme . '://' . $s3_domain . '/';
				if ( $as3cf->get_setting( 'enable-delivery-domain' ) && $as3cf->get_setting( 'delivery-domain' ) ) {
					$delivery_domain    = $as3cf->get_setting( 'delivery-domain' );
					$this->webp_paths[] = $s3_scheme . '://' . $delivery_domain . '/';
					$this->debug_message( "found WOM delivery domain of $delivery_domain" );
				}
				$this->s3_active = $s3_domain;
				if ( $as3cf->get_setting( 'enable-object-prefix' ) ) {
					$this->s3_object_prefix = $as3cf->get_setting( 'object-prefix' );
					$this->debug_message( $as3cf->get_setting( 'object-prefix' ) );
				} else {
					$this->debug_message( 'no WOM prefix' );
				}
				if ( $as3cf->get_setting( 'object-versioning' ) ) {
					$this->s3_object_version = true;
					$this->debug_message( 'object versioning enabled' );
				}
			}
		}

		if ( $this->get_option( 'cdn_domain' ) ) {
			$this->webp_paths[] = $this->get_option( 'cdn_domain' );
		}
		foreach ( $this->webp_paths as $webp_path ) {
			$webp_domain = $this->parse_url( $webp_path, PHP_URL_HOST );
			if ( $webp_domain ) {
				$this->webp_domains[] = $webp_domain;
			}
		}
		$this->debug_message( 'checking any images matching these patterns for webp: ' . implode( ',', $this->webp_paths ) );
		$this->debug_message( 'rewriting any images matching these domains to webp: ' . implode( ',', $this->webp_domains ) );
	}

	/**
	 * Replaces images within a srcset attribute with their .webp derivatives.
	 *
	 * @param string $srcset A valid srcset attribute from an img element.
	 * @return string The new srcset, possibly with WebP URLs.
	 */
	function srcset_replace( $srcset ) {
		$sizes = explode( ', ', $srcset );
		if ( $this->is_iterable( $sizes ) ) {
			$this->debug_message( 'parsing srcset urls' );
			foreach ( $sizes as $i => $size ) {
				$size_parts = explode( ' ', $size );
				$srcurl     = $size_parts[0];
				$this->debug_message( "looking for $srcurl from srcset" );
				if ( $this->validate_image_url( $srcurl ) ) {
					$sizes[ $i ] = str_replace( $srcurl, $this->generate_url( $srcurl ), $size );
					$this->debug_message( "replaced $srcurl in srcset" );
				}
			}
		}
		$srcset = implode( ', ', $sizes );
		return $srcset;
	}

	/**
	 * Replaces image URLs in the given HTML attribute with their .webp derivatives.
	 *
	 * @param string $image The original img element.
	 * @param string $attr The attribute to check for image URLs.
	 * @return string The modified img element.
	 */
	function attr_replace( $image, $attr ) {
		$orig_url = $this->get_attribute( $image, $attr );
		if ( $orig_url ) {
			$this->debug_message( "looking for $attr: $orig_url" );
			if ( $this->validate_image_url( $orig_url ) ) {
				$this->set_attribute( $image, $attr, $this->generate_url( $orig_url ), true );
				$this->debug_message( "replacing $orig_url in $attr" );
			}
		}
		return $image;
	}

	/**
	 * Search for image URLs and rewrite them with their WebP replacements.
	 *
	 * @param string $buffer The full HTML page.
	 * @return string The altered HTML containing WebP image URLs.
	 */
	function filter_webp_html( $buffer ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( $this->is_json( $buffer ) ) {
			return $buffer;
		}

		$images = $this->get_images_from_html( $buffer, false, false );
		if ( ! empty( $images[0] ) && $this->is_iterable( $images[0] ) ) {
			foreach ( $images[0] as $index => $image ) {
				// Ignore 0-size Pinterest schema images.
				if ( strpos( $image, 'data-pin-description=' ) && strpos( $image, 'width="0" height="0"' ) ) {
					continue;
				}
				if ( ! $this->validate_tag( $image ) ) {
					continue;
				}
				$new_image = $image;
				$file      = $this->get_attribute( $image, 'src' );
				$this->debug_message( "checking an image src: $file" );
				if ( $this->validate_image_url( $file ) ) {
					$this->set_attribute( $new_image, 'src', $this->generate_url( $file ), true );
				}
				$srcset = $this->get_attribute( $new_image, 'srcset' );
				if ( $srcset ) {
					$srcset_webp = $this->srcset_replace( $srcset );
					if ( $srcset_webp !== $srcset ) {
						$this->set_attribute( $new_image, 'srcset', $srcset_webp, true );
					}
				}
				$srcset = $this->get_attribute( $new_image, 'data-srcset' );
				if ( $srcset ) {
					$srcset_webp = $this->srcset_replace( $srcset );
					if ( $srcset_webp !== $srcset ) {
						$this->set_attribute( $new_image, 'data-srcset', $srcset_webp, true );
					}
				}
				$srcset = $this->get_attribute( $new_image, 'data-lazy-srcset' );
				if ( $srcset ) {
					$srcset_webp = $this->srcset_replace( $srcset );
					if ( $srcset_webp !== $srcset ) {
						$this->set_attribute( $new_image, 'data-lazy-srcset', $srcset_webp, true );
					}
				}
				$new_image = $this->attr_replace( $new_image, 'data-orig-file' );
				$new_image = $this->attr_replace( $new_image, 'data-medium-file' );
				$new_image = $this->attr_replace( $new_image, 'data-large-file' );
				$new_image = $this->attr_replace( $new_image, 'data-large_image' );
				$new_image = $this->attr_replace( $new_image, 'data-src' );
				$new_image = $this->attr_replace( $new_image, 'data-lazy-src' );
				$new_image = $this->attr_replace( $new_image, 'data-lazysrc' );
				$new_image = $this->attr_replace( $new_image, 'data-lazyload' );
				// Done with the image element, everything must be replaced by now!
				if ( $new_image !== $image ) {
					$buffer = str_replace( $image, $new_image, $buffer );
				}
			} // End foreach().
		} // End if().
		// Images listed as picture/source elements.
		$pictures = $this->get_picture_tags_from_html( $buffer );
		if ( $this->is_iterable( $pictures ) ) {
			foreach ( $pictures as $index => $picture ) {
				if ( strpos( $picture, 'image/webp' ) ) {
					continue;
				}
				if ( ! $this->validate_tag( $picture ) ) {
					continue;
				}
				$sources = $this->get_elements_from_html( $picture, 'source' );
				if ( $this->is_iterable( $sources ) ) {
					foreach ( $sources as $source ) {
						$this->debug_message( "parsing a picture source: $source" );
						$srcset_attr_name = 'srcset';
						if ( false !== strpos( $source, 'base64,R0lGOD' ) && false !== strpos( $source, 'data-srcset=' ) ) {
							$srcset_attr_name = 'data-srcset';
						}
						$srcset = $this->get_attribute( $source, $srcset_attr_name );
						if ( $srcset ) {
							$srcset_webp = $this->srcset_replace( $srcset );
							if ( $srcset_webp !== $srcset ) {
								$source_webp = str_replace( $srcset, $srcset_webp, $source );
								$this->set_attribute( $source_webp, 'type', 'image/webp', true );
								$picture = str_replace( $source, $source_webp . $source, $picture );
							}
						}
					}
					if ( $picture !== $pictures[ $index ] ) {
						$this->debug_message( 'found webp for picture element' );
						$buffer = str_replace( $pictures[ $index ], $picture, $buffer );
					}
				}
			}
		}
		// NextGEN slides listed as 'a' elements and LL 'a' background images.
		$links = $this->get_elements_from_html( $buffer, 'a' );
		if ( $this->is_iterable( $links ) ) {
			foreach ( $links as $index => $link ) {
				$this->debug_message( "parsing a link $link" );
				if ( ! $this->validate_tag( $link ) ) {
					continue;
				}
				$file  = $this->get_attribute( $link, 'data-src' );
				$thumb = $this->get_attribute( $link, 'data-thumbnail' );
				if ( $file && $thumb ) {
					$this->debug_message( "checking webp for ngg data-src/data-thumbnail: $file" );
					$link = $this->attr_replace( $link, 'data-src' );
					$link = $this->attr_replace( $link, 'data-thumbnail' );
				}
				$link = $this->background_image_replace( $link );
				if ( $link !== $links[ $index ] ) {
					$buffer = str_replace( $links[ $index ], $link, $buffer );
				}
			}
		}
		// Revolution Slider 'li' elements and LL li backgrounds.
		$listitems = $this->get_elements_from_html( $buffer, 'li' );
		if ( $this->is_iterable( $listitems ) ) {
			foreach ( $listitems as $index => $listitem ) {
				$this->debug_message( 'parsing a listitem' );
				if ( ! $this->validate_tag( $listitem ) ) {
					continue;
				}
				if ( $this->get_attribute( $listitem, 'data-title' ) === 'Slide' && ( $this->get_attribute( $listitem, 'data-lazyload' ) || $this->get_attribute( $listitem, 'data-thumb' ) ) ) {
					$this->debug_message( 'checking webp for revslider data-thumb' );
					$listitem  = $this->attr_replace( $listitem, 'data-thumb' );
					$param_num = 1;
					while ( $param_num < 11 ) {
						$this->debug_message( "checking webp for revslider data-param$param_num" );
						$listitem = $this->attr_replace( $listitem, 'data-param' . $param_num );
						$param_num++;
					}
				}
				$listitem = $this->background_image_replace( $listitem );
				if ( $listitem !== $listitems[ $index ] ) {
					$buffer = str_replace( $listitems[ $index ], $listitem, $buffer );
				}
			} // End foreach().
		} // End if().
		// WooCommerce thumbs listed as 'div' elements and LL div backgrounds.
		$divs = $this->get_elements_from_html( $buffer, 'div' );
		if ( $this->is_iterable( $divs ) ) {
			foreach ( $divs as $index => $div ) {
				$this->debug_message( 'parsing a div' );
				if ( ! $this->validate_tag( $div ) ) {
					continue;
				}
				$thumb     = $this->get_attribute( $div, 'data-thumb' );
				$div_class = $this->get_attribute( $div, 'class' );
				if ( $div_class && $thumb && strpos( $div_class, 'woocommerce-product-gallery__image' ) !== false ) {
					$this->debug_message( "checking webp for WC data-thumb: $thumb" );
					$div = $this->attr_replace( $div, 'data-thumb' );
				}
				$div = $this->background_image_replace( $div );
				if ( $div !== $divs[ $index ] ) {
					$buffer = str_replace( $divs[ $index ], $div, $buffer );
				}
			}
		}
		// Look for LL 'section' elements.
		$sections = $this->get_elements_from_html( $buffer, 'section' );
		if ( $this->is_iterable( $sections ) ) {
			foreach ( $sections as $index => $section ) {
				$this->debug_message( 'parsing a section' );
				if ( ! $this->validate_tag( $section ) ) {
					continue;
				}
				$section = $this->background_image_replace( $section );
				if ( $section !== $sections[ $index ] ) {
					$buffer = str_replace( $sections[ $index ], $section, $buffer );
				}
			}
		}
		// Look for LL 'span' elements.
		$spans = $this->get_elements_from_html( $buffer, 'span' );
		if ( $this->is_iterable( $spans ) ) {
			foreach ( $spans as $index => $span ) {
				$this->debug_message( 'parsing a span' );
				if ( ! $this->validate_tag( $span ) ) {
					continue;
				}
				$span = $this->background_image_replace( $span );
				if ( $span !== $spans[ $index ] ) {
					$buffer = str_replace( $spans[ $index ], $span, $buffer );
				}
			}
		}
		// Video elements, looking for poster attributes that are images.
		$videos = $this->get_elements_from_html( $buffer, 'video' );
		if ( $this->is_iterable( $videos ) ) {
			foreach ( $videos as $index => $video ) {
				$this->debug_message( 'parsing a video element' );
				if ( ! $this->validate_tag( $video ) ) {
					continue;
				}
				$file = $this->get_attribute( $video, 'poster' );
				if ( $file ) {
					$this->debug_message( "checking webp for video poster: $file" );
					if ( $this->validate_image_url( $file ) ) {
						$this->set_attribute( $video, 'poster', $this->generate_url( $file ), true );
						$this->debug_message( "found webp for video poster: $file" );
						$buffer = str_replace( $videos[ $index ], $video, $buffer );
					}
				}
			}
		}
		$buffer = $this->filter_style_blocks( $buffer );
		$this->debug_message( 'all done parsing page for webp' );
		return apply_filters( 'swis_disk_cache_webp_converted_data', $buffer );
	}

	/**
	 * Parse an HTML element for inline CSS background images.
	 *
	 * @param string $element The HTML element to parse.
	 * @return string The modified element with WebP URLs.
	 */
	function background_image_replace( $element ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$bg_image = $this->get_attribute( $element, 'data-bg' );
		$class    = $this->get_attribute( $element, 'class' );
		if ( $class && $bg_image && false !== strpos( $class, 'lazyload' ) ) {
			$this->debug_message( "checking for LL data-bg: $bg_image" );
			$element = $this->attr_replace( $element, 'data-bg' );
		}
		if ( false === strpos( $element, 'background:' ) && false === strpos( $element, 'background-image:' ) ) {
			return $element;
		}
		$this->debug_message( 'element contains background/background-image:' );
		$style = $this->get_attribute( $element, 'style' );
		if ( empty( $style ) ) {
			return $element;
		}
		$this->debug_message( "checking style attr for background-image: $style" );
		$bg_image_url = $this->get_background_image_url( $style );
		if ( $bg_image_url ) {
			$this->debug_message( 'bg-image url found' );
			if ( $this->validate_image_url( $bg_image_url ) ) {
				$this->debug_message( "found webp for $bg_image_url" );
				$new_style = str_replace( $bg_image_url, $this->generate_url( $bg_image_url ), $style );
			}
			if ( ! empty( $new_style ) && $style !== $new_style ) {
				$this->debug_message( 'style modified, continuing' );
				$element = str_replace( $style, $new_style, $element );
			}
		}
		return $element;
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
							$webp_bg_image_url = $this->generate_url( $bg_image_url );
							if ( $bg_image_url !== $webp_bg_image_url ) {
								$this->debug_message( "replacing $bg_image_url with $webp_bg_image_url" );
								$bg_image = str_replace( $bg_image_url, $webp_bg_image_url, $bg_image );
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
	 * Attempts to reverse a CDN URL to a local path to test for file existence.
	 *
	 * Used for supporting pull-mode CDNs without forcing everything to WebP.
	 *
	 * @param string $url The image URL to mangle.
	 * @return bool True if a local file exists correlating to the CDN URL, false otherwise.
	 */
	function cdn_to_local( $url ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! is_array( $this->webp_domains ) || ! count( $this->webp_domains ) ) {
			return false;
		}
		foreach ( $this->webp_domains as $webp_domain ) {
			if ( $webp_domain === $this->home_domain ) {
				continue;
			}
			$this->debug_message( "looking for domain $webp_domain in $url" );
			if (
				! empty( $this->s3_active ) &&
				false !== strpos( $url, $this->s3_active ) &&
				(
					( false !== strpos( $this->s3_active, '/' ) ) ||
					( ! empty( $this->s3_object_prefix ) && false !== strpos( $url, $this->s3_object_prefix ) )
				)
			) {
				// We will wait until the paths loop to fix this one.
				continue;
			}
			if ( false !== strpos( $url, $webp_domain ) ) {
				$local_url = str_replace( $webp_domain, $this->home_domain, $url );
				$this->debug_message( "found $webp_domain, replaced with $this->home_domain to get $local_url" );
				if ( $this->url_to_path_exists( $local_url ) ) {
					return true;
				}
			}
		}
		foreach ( $this->webp_paths as $webp_path ) {
			if ( false === strpos( $webp_path, 'http' ) ) {
				continue;
			}
			$this->debug_message( "looking for path $webp_path in $url" );
			if (
				! empty( $this->s3_active ) &&
				false !== strpos( $url, $this->s3_active ) &&
				! empty( $this->s3_object_prefix ) &&
				0 === strpos( $url, $webp_path . $this->s3_object_prefix )
			) {
				$local_url = str_replace( $webp_path . $this->s3_object_prefix, $this->content_url, $url );
				$this->debug_message( "found $webp_path (and $this->s3_object_prefix), replaced with $this->content_url to get $local_url" );
				if ( $this->url_to_path_exists( $local_url ) ) {
					return true;
				}
			}
			if ( false !== strpos( $url, $webp_path ) ) {
				$local_url = str_replace( $webp_path, $this->content_url, $url );
				$this->debug_message( "found $webp_path, replaced with $this->content_url to get $local_url" );
				if ( $this->url_to_path_exists( $local_url ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Remove S3 object versioning from URL.
	 *
	 * @param string $url The image URL with a potential version string embedded.
	 * @return string The URL without a version string.
	 */
	function maybe_strip_object_version( $url ) {
		if ( ! empty( $this->s3_object_version ) ) {
			$possible_version = basename( dirname( $url ) );
			if (
				! empty( $possible_version ) &&
				8 === strlen( $possible_version ) &&
				ctype_digit( $possible_version )
			) {
				$url = str_replace( '/' . $possible_version . '/', '/', $url );
				$this->debug_message( "removed version $possible_version from $url" );
			} elseif (
				! empty( $possible_version ) &&
				14 === strlen( $possible_version ) &&
				ctype_digit( $possible_version )
			) {
				$year  = substr( $possible_version, 0, 4 );
				$month = substr( $possible_version, 4, 2 );
				$url   = str_replace( '/' . $possible_version . '/', "/$year/$month/", $url );
				$this->debug_message( "removed version $possible_version from $url" );
			}
		}
		return $url;
	}

	/**
	 * Converts a URL to a file-system path and checks if the resulting path exists.
	 *
	 * @param string $url The URL to mangle.
	 * @param string $extension An optional extension to append during is_file().
	 * @return bool True if a local file exists correlating to the URL, false otherwise.
	 */
	function url_to_path_exists( $url, $extension = '' ) {
		$url = $this->maybe_strip_object_version( $url );
		return parent::url_to_path_exists( $url, '.webp' );
	}

	/**
	 * Checks if the tag is allowed to be rewritten.
	 *
	 * @param string $image The HTML tag: img, span, etc.
	 * @return bool False if it flags a filter or exclusion, true otherwise.
	 */
	function validate_tag( $image ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// Ignore 0-size Pinterest schema images.
		if ( strpos( $image, 'data-pin-description=' ) && strpos( $image, 'width="0" height="0"' ) ) {
			$this->debug_message( 'data-pin-description img skipped' );
			return false;
		}

		$exclusions = apply_filters(
			'swis_cache_webp_exclusions',
			array(
				'timthumb.php?',
				'wpcf7_captcha/',
			),
			$image
		);
		foreach ( $exclusions as $exclusion ) {
			if ( false !== strpos( $image, $exclusion ) ) {
				$this->debug_message( "tag matched $exclusion" );
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks if the path is a valid WebP image, on-disk or forced.
	 *
	 * @param string $image The image URL.
	 * @return bool True if the file exists or matches a forced path, false otherwise.
	 */
	function validate_image_url( $image ) {
		$this->debug_message( "webp validation for $image" );
		if (
			strpos( $image, 'base64,R0lGOD' ) ||
			strpos( $image, 'lazy-load/images/1x1' ) ||
			strpos( $image, '/assets/images/' )
		) {
			$this->debug_message( 'lazy load placeholder' );
			return false;
		}
		// If we got a relative image URL...
		if ( '/' === substr( $image, 0, 1 ) && '/' !== substr( $image, 1, 1 ) ) {
			$image = '//' . $this->home_domain . $image;
		}
		$extension  = '';
		$image_path = $this->parse_url( $image, PHP_URL_PATH );
		if ( ! is_null( $image_path ) && $image_path ) {
			$extension = strtolower( pathinfo( $image_path, PATHINFO_EXTENSION ) );
		}
		if ( $extension && 'svg' === $extension ) {
			return false;
		}
		if ( $extension && 'webp' === $extension ) {
			return false;
		}
		if ( apply_filters( 'swis_cache_skip_webp_rewrite', false, $image ) ) {
			return false;
		}
		if ( $this->webp_paths && $this->webp_domains ) {
			if ( $this->cdn_to_local( $image ) ) {
				return true;
			}
		}
		return $this->url_to_path_exists( $image );
	}

	/**
	 * Generate a WebP url.
	 *
	 * Adds .webp to the end, or adds a webp parameter for ExactDN urls.
	 *
	 * @param string $url The image url.
	 * @return string The WebP version of the image url.
	 */
	function generate_url( $url ) {
		$path_parts = explode( '?', $url );
		return $path_parts[0] . '.webp' . ( ! empty( $path_parts[1] ) && 'is-pending-load=1' !== $path_parts[1] ? '?' . $path_parts[1] : '' );
	}
}
