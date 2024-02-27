<?php
/**
 * Class and methods to insert Gzip and other rules to .htaccess file.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automatically insert rules to .htaccess.
 */
final class GZIP extends Base {

	/**
	 * Register actions and filters for Lazy Load.
	 */
	function __construct() {
		if ( defined( 'WPE_PLUGIN_VERSION' ) ) {
			return;
		}
		if ( apply_filters( 'swis_no_htaccess', false ) ) {
			return;
		}
		parent::__construct();
		add_action( 'admin_init', array( $this, 'admin_init' ), 9 );
	}

	/**
	 * Make sure plugin is setup.
	 */
	function admin_init() {
		if ( get_option( 'swis_activation' ) ) {
			$this->insert_htaccess_rules();
		}
	}

	/**
	 * Let folks know we could not auto-insert the rules.
	 */
	function htaccess_failure() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		echo '<div class="notice notice-error is-dismissible"><p>' .
			sprintf(
				/* translators: 1: SWIS Performance 2: Permalink Settings */
				esc_html__( '%1$s was unable to configure your site with gzip and proper cache-control headers. Please check that %2$s is writable and de-activate/re-activate SWIS to try again.', 'swis-performance' ),
				'<strong>SWIS Performance</strong>',
				'<code>' . wp_kses_post( $this->get_htaccess_path() ) . '</code>'
			) .
			'</p></div>';
	}

	/**
	 * Figure out where the .htaccess file should live.
	 *
	 * @return string The path to the .htaccess file.
	 */
	function get_htaccess_path() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$htpath = get_home_path();
		$this->debug_message( "using $htpath.htaccess" );
		return "$htpath.htaccess";
	}

	/**
	 * If rules are present, stay silent. Otherwise, give us some rules to insert!
	 *
	 * @return array Rules to be inserted.
	 */
	function rewrite_verify() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$current_rules = extract_from_markers( $this->get_htaccess_path(), 'SWIS Performance' );
		$swis_rules    = array(
			'AddDefaultCharset UTF-8',
			'# Force UTF-8 for a few others',
			'<IfModule mod_mime.c>',
			'AddCharset UTF-8 .atom .css .js .json .rss .vtt .xml',
			'AddType image/webp .webp',
			'AddType font/woff2 .woff2',
			'</IfModule>',
			'# Because ETags suck eggs.',
			'<IfModule mod_headers.c>',
			'Header unset ETag',
			'</IfModule>',
			'FileETag None',
			'<FilesMatch "\.(html|htm|rtf|rtx|txt|xsd|xsl|xml)$">',
			'<IfModule mod_headers.c>',
			'Header unset Last-Modified',
			'</IfModule>',
			'</FilesMatch>',
			'<FilesMatch "\.(html|htm|rtf|rtx|txt|xsd|xsl|xml|css|htc|js|asf|asx|wax|wmv|wmx|avi|bmp|class|divx|doc|docx|eot|exe|gif|gz|gzip|ico|jpg|jpeg|jpe|json|mdb|mid|midi|mov|qt|mp3|m4a|mp4|m4v|mpeg|mpg|mpe|mpp|otf|odb|odc|odf|odg|odp|ods|odt|ogg|pdf|png|pot|pps|ppt|pptx|ra|ram|svg|svgz|swf|tar|tif|tiff|ttf|ttc|wav|wma|wri|xla|xls|xlsx|xlt|xlw|zip)$">',
			'<IfModule mod_headers.c>',
			'Header unset Pragma',
			'Header append Cache-Control "public"',
			'</IfModule>',
			'</FilesMatch>',
			'# Expires headers to improve cache control.',
			'<IfModule mod_expires.c>',
			'ExpiresActive on',
			'ExpiresDefault                              "access plus 1 month"',
			'# stuff not to cache',
			'ExpiresByType text/cache-manifest           "access plus 0 seconds"',
			'ExpiresByType text/html                     "access plus 0 seconds"',
			'ExpiresByType text/xml                      "access plus 0 seconds"',
			'ExpiresByType application/xml               "access plus 0 seconds"',
			'ExpiresByType application/xhtml+xml         "access plus 0 seconds"',
			'ExpiresByType application/json              "access plus 0 seconds"',
			'# Feeds',
			'ExpiresByType application/rss+xml           "access plus 1 hour"',
			'ExpiresByType application/atom+xml          "access plus 1 hour"',
			'# Media',
			'ExpiresByType image/gif                     "access plus 4 months"',
			'ExpiresByType image/png                     "access plus 4 months"',
			'ExpiresByType image/jpeg                    "access plus 4 months"',
			'ExpiresByType image/webp                    "access plus 4 months"',
			'ExpiresByType image/svg+xml                 "access plus 1 month"',
			'ExpiresByType image/x-icon                  "access plus 1 month"',
			'ExpiresByType image/vnd.microsoft.icon      "access plus 1 month"',
			'ExpiresByType video/ogg                     "access plus 1 month"',
			'ExpiresByType audio/ogg                     "access plus 1 month"',
			'ExpiresByType video/mp4                     "access plus 1 month"',
			'ExpiresByType video/webm                    "access plus 1 month"',
			'# HTC files',
			'ExpiresByType text/x-component              "access plus 1 month"',
			'# Fonts',
			'ExpiresByType font/ttf                      "access plus 4 months"',
			'ExpiresByType font/otf                      "access plus 4 months"',
			'ExpiresByType font/opentype                 "access plus 4 months"',
			'ExpiresByType font/woff                     "access plus 4 months"',
			'ExpiresByType font/woff2                    "access plus 4 months"',
			'ExpiresByType application/vnd.ms-fontobject "access plus 4 months"',
			'ExpiresByType application/x-font            "access plus 4 months"',
			'ExpiresByType application/x-font-opentype   "access plus 4 months"',
			'ExpiresByType application/x-font-otf        "access plus 4 months"',
			'ExpiresByType application/x-font-truetype   "access plus 4 months"',
			'ExpiresByType application/x-font-ttf        "access plus 4 months"',
			'# CSS/JS',
			'ExpiresByType text/css                      "access plus 1 year"',
			'ExpiresByType application/javascript        "access plus 1 year"',
			'ExpiresByType application/x-javascript      "access plus 1 year"',
			'</IfModule>',
			'<IfModule mod_deflate.c>',
			'# Compress HTML, CSS, JavaScript, Text, XML and fonts',
			'AddOutputFilterByType DEFLATE application/javascript',
			'AddOutputFilterByType DEFLATE application/json',
			'AddOutputFilterByType DEFLATE application/rss+xml',
			'AddOutputFilterByType DEFLATE application/vnd.ms-fontobject',
			'AddOutputFilterByType DEFLATE application/x-font',
			'AddOutputFilterByType DEFLATE application/x-font-opentype',
			'AddOutputFilterByType DEFLATE application/x-font-otf',
			'AddOutputFilterByType DEFLATE application/x-font-truetype',
			'AddOutputFilterByType DEFLATE application/x-font-ttf',
			'AddOutputFilterByType DEFLATE application/x-javascript',
			'AddOutputFilterByType DEFLATE application/xhtml+xml',
			'AddOutputFilterByType DEFLATE application/xml',
			'AddOutputFilterByType DEFLATE font/opentype',
			'AddOutputFilterByType DEFLATE font/otf',
			'AddOutputFilterByType DEFLATE font/ttf',
			'AddOutputFilterByType DEFLATE image/svg+xml',
			'AddOutputFilterByType DEFLATE image/x-icon',
			'AddOutputFilterByType DEFLATE text/css',
			'AddOutputFilterByType DEFLATE text/html',
			'AddOutputFilterByType DEFLATE text/javascript',
			'AddOutputFilterByType DEFLATE text/plain',
			'AddOutputFilterByType DEFLATE text/x-component',
			'AddOutputFilterByType DEFLATE text/xml',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'Header append Vary: Accept-Encoding',
			'</IfModule>',
		);
		$test_rules    = array();
		foreach ( $swis_rules as $srule ) {
			if ( 0 === strpos( $srule, '#' ) ) {
				continue;
			}
			$test_rules[] = $srule;
		}
		foreach ( $test_rules as $i => $rule ) {
			if ( empty( $current_rules[ $i ] ) || $rule !== $current_rules[ $i ] ) {
				$this->debug_message( "htaccess rule mismatched ($i): $rule" );
				return $swis_rules;
			}
		}
		$this->debug_message( 'htaccess rules up-to-date' );
		return array();
	}

	/**
	 * Check for SWIS rules in .htaccess, and insert if missing/outdated.
	 */
	function insert_htaccess_rules() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$htaccess_path = $this->get_htaccess_path();
		if ( ! $this->is_file( $htaccess_path ) ) {
			return;
		}
		$swis_rules = $this->rewrite_verify();
		if ( $swis_rules ) {
			$success = insert_with_markers( $this->get_htaccess_path(), 'SWIS Performance', $swis_rules );
			if ( ! $success ) {
				add_action( 'admin_notices', array( $this, 'htaccess_failure' ) );
			}
		}
	}

	/**
	 * Remove htaccess rules.
	 */
	function remove_htaccess_rules() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$current_rules = extract_from_markers( $this->get_htaccess_path(), 'SWIS Performance' );
		if ( $current_rules ) {
			insert_with_markers( $this->get_htaccess_path(), 'SWIS Performance', '' );
		}
	}
}
