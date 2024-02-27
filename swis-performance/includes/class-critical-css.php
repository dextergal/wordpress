<?php
/**
 * Class and methods to generate and insert Critical CSS.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Generates and inserts Critical CSS via an external API.
 */
final class Critical_CSS extends Page_Parser {

	/**
	 * The directory to store the critical CSS files.
	 *
	 * @access protected
	 * @var string $cache_dir
	 */
	protected $cache_dir = '';

	/**
	 * A list of user-defined exclusions, populated by validate_user_exclusions().
	 *
	 * @access protected
	 * @var array $user_exclusions
	 */
	protected $user_exclusions = array();

	/**
	 * Register actions and filters for Critical CSS.
	 */
	function __construct() {
		parent::__construct();
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		// We only include critical CSS when the defer/optimize CSS option is enabled.
		// Otherwise, it's a bit pointless, as all CSS will still be render-blocking.
		// This also allows folks to manually upload critical CSS to the cache folder,
		// and not depend on the API for "advanced" usage.
		if ( $this->get_option( 'defer_css' ) ) {
			add_filter( 'wp_head', array( $this, 'inline_critical_css' ), 1 );
		}

		// Add a section to the front-end JS/CSS management panel.
		add_action( 'swis_slim_before_all_sections', array( $this, 'frontend_critical_css_management' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_script' ), 11 );

		// Overrides for user exclusions.
		add_filter( 'swis_skip_generate_css', array( $this, 'skip_generate_css' ), 10, 2 );

		$this->request_headers = $this->get_request_headers();
		$this->get_request_uri();
		$this->validate_user_exclusions();

		$this->cache_dir     = $this->content_dir . 'cache/ccss/';
		$this->cache_dir_url = $this->content_url . 'cache/ccss/';

		// Allow the user to override the css generation delay with a constant.
		add_filter( 'swis_generate_css_delay', array( $this, 'generate_css_delay_override' ) );
		// By default, modify BAD/WARN to be GOOD and allow usage of connected CSS.
		add_filter( 'swis_critical_css_validation_status', array( $this, 'set_valid_status' ), 1 );

		// Suppress all the stylesheets via query param.
		if ( ! empty( $_GET['swis_test_ccss'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_filter( 'swis_elements_link_tag', array( $this, 'disable_all_css' ) );
		}
		// Hide the admin bar when using either of the Critical CSS query params.
		if ( ! empty( $_GET['swis_ccss'] ) || ! empty( $_GET['swis_test_ccss'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_filter( 'show_admin_bar', '__return_false' );
		}

		// Actions to save/edit critical CSS files.
		add_action( 'wp_ajax_swis_save_ccss', array( $this, 'save_ccss_ajax' ) );

		// Everything below here is for generating critical CSS via API.
		if ( ! $this->get_option( 'critical_css_key' ) ) {
			return;
		}
		if ( ! is_dir( $this->cache_dir ) ) {
			if ( ! wp_mkdir_p( $this->cache_dir ) ) {
				add_action( 'admin_notices', array( $this, 'requirements_failed' ) );
				return;
			}
		}
		if ( ! is_writable( $this->cache_dir ) ) {
			add_action( 'admin_notices', array( $this, 'requirements_failed' ) );
			return;
		}
		if ( $this->background_mode_enabled() ) {
			// Add handler to manually start the (async) preloader.
			add_action( 'admin_action_swis_generate_css_manual', array( $this, 'manual_generate_css_action' ) );
			add_action( 'admin_action_swis_generate_css_resume_manual', array( $this, 'manual_generate_css_resume_action' ) );
			// If a page is updated/published, (maybe) clear and regen the CSS.
			add_action( 'save_post', array( $this, 'on_save_post' ) );
		}
		add_action( 'switch_theme', array( $this, 'switch_theme' ) );
		add_action( 'admin_notices', array( $this, 'display_theme_regen_notice' ) );
		add_action( 'wp_ajax_swis_dismiss_theme_regen_notice', array( $this, 'dismiss_theme_regen_notice' ) );

		// TODO: not used, because we have a global JS/CSS purge which can be used from front-end also.
		add_action( 'admin_action_swis_purge_critical_css', array( $this, 'manual_purge_css_action' ) );

		// Adds the ignoreStyleElements argument to the POST fields sent to the API.
		add_filter( 'swis_generate_css_post_fields', array( $this, 'ignore_style_elements' ) );

		// Actions to process CSS generation via AJAX.
		add_action( 'wp_ajax_swis_generate_css_init', array( $this, 'start_generate_css_ajax' ) );
		add_action( 'wp_ajax_swis_url_generate_css', array( $this, 'url_generate_css_ajax' ) );
		add_action( 'wp_ajax_swis_url_generate_page_css', array( $this, 'url_generate_page_css_ajax' ) );
	}

	/**
	 * Display a notice that the plugin requirements check failed.
	 */
	public function requirements_failed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		// Permission check, can't do much without a writable cache directory.
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

	/**
	 * Change a validationStatus of WARN/BAD to GOOD by default.
	 *
	 * @param string $validation_status The validationStatus for the generated CSS.
	 * @return string The potentially modified validationStatus.
	 */
	function set_valid_status( $validation_status ) {
		if ( 'WARN' === $validation_status ) {
			return 'GOOD';
		}
		if ( 'BAD' === $validation_status ) {
			return 'GOOD';
		}
		return $validation_status;
	}

	/**
	 * Enqueue JS needed for the front-end CCSS management.
	 */
	function frontend_script() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		wp_localize_script(
			'swis-performance-slim',
			'swisccss_vars',
			array(
				'_wpnonce'       => wp_create_nonce( 'swis_generate_css_nonce' ),
				'save_message'   => esc_html__( 'Save', 'swis-performance' ),
				'saving_message' => esc_html__( 'Saving...', 'swis-performance' ),
			)
		);
	}

	/**
	 * Check a given directory for a valid critical.css file.
	 *
	 * @param string $dir A file-system directory in which to look for a CSS file and status.
	 * @return string For good files: full path to CSS, for unusable files: 'bad', and 'none' for no file found.
	 */
	function find_css_from_dir( $dir ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( $dir && \is_dir( $dir ) ) {
			$cache_file = $dir . '/critical.css';
			if ( $this->is_file( $cache_file ) ) {
				$this->debug_message( "$cache_file found" );
				$validation_status = 'GOOD';
				if ( $this->is_file( $dir . '/critical.status' ) ) {
					$validation_status = \file_get_contents( $dir . '/critical.status' );
				}
				/**
				 * By default GOOD, WARN, and BAD CSS will be used. Return false or anything besides 'GOOD'
				 * to block the CSS in $cache_file from being used. ERROR and SCREENSHOT_WARN_BLANK are
				 * blocked by default.
				 *
				 * @param string $validation_status The validationStatus returned from the API.
				 * @param string $cache_file The CSS file being considered for use.
				 */
				$validation_status = apply_filters( 'swis_critical_css_validation_status', \trim( $validation_status ), $cache_file );
				$this->debug_message( "validation status is $validation_status" );
				if ( 'GOOD' === \trim( $validation_status ) ) {
					$this->debug_message( "using $cache_file" );
					return $cache_file;
				}
			}
		}
		$this->debug_message( "no valid ccss cache file in $dir" );
		return '';
	}

	/**
	 * Find cricital CSS rules for the current page/URL. Checks by URL and conditional/template tags.
	 *
	 * @return string The critical CSS file for the current URL.
	 */
	function find_css_file_for_current_page() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// First, look for CSS by URL.
		if ( ! \is_search() ) {
			$css_cache_file = $this->find_css_from_dir( $this->get_cache_file_dir() );
			if ( $css_cache_file ) {
				return $css_cache_file;
			}
		}
		$type     = false;
		$fallback = 'page';
		if ( \is_admin() ) {
			$fallback = '';
		} elseif ( \is_search() ) {
			$type = 'is_search';
		} elseif ( \is_front_page() ) {
			$type = 'is_front_page';
		} elseif ( \is_home() ) {
			$type = 'is_home';
		} elseif ( ! empty( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			$type     = 'is_login';
			$fallback = '';
		} elseif ( \is_404() ) {
			$type = 'is_404';
		} elseif ( \is_page() ) {
			$type     = 'page';
			$fallback = 'is_front_page';
		} else {
			if ( \is_single() ) {
				$type     = \get_post_type();
				$fallback = 'post';
				$this->debug_message( "is_single for $type, but $fallback if we must" );
			} else {
				$type     = \get_query_var( 'post_type' );
				$fallback = 'is_home';
				if ( $type ) {
					$type .= '_archive';
				} else {
					$type = 'post_archive';
				}
				$this->debug_message( "not_single $type, will $fallback if needed" );
			}
		}
		if ( $type ) {
			$this->debug_message( "looking for a page type of $type" );
			$template_cache_file = $this->find_css_from_dir( $this->get_template_cache_file_dir( $type ) );
			if ( $template_cache_file ) {
				return $template_cache_file;
			}
		}
		if ( $fallback ) {
			$this->debug_message( "trying fallback of $fallback" );
			$template_cache_file = $this->find_css_from_dir( $this->get_template_cache_file_dir( $fallback ) );
			if ( $template_cache_file ) {
				return $template_cache_file;
			}
		}
		return '';
	}

	/**
	 * Retrieves CSS from the best file for a given page, or use critical CSS fallback option.
	 *
	 * @return string The critical CSS code for the current page.
	 */
	function find_css_for_current_page() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$css_file = $this->find_css_file_for_current_page();
		if ( ! $css_file || ! $this->is_file( $css_file ) ) {
			return $this->get_option( 'critical_css' );
		}
		return \file_get_contents( $css_file );
	}

	/**
	 * Disables all link/CSS elements.
	 *
	 * @param string $element A link element, possibly CSS.
	 * @return string The same element, suppressed if CSS is found.
	 */
	function disable_all_css( $element ) {
		return '<!-- ' . $element . ' -->';
	}

	/**
	 * Insert cricital CSS rules in to the header to prevent FOUC.
	 */
	function inline_critical_css() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! empty( $_GET['swis_ccss'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$raw_critical_css = $this->find_css_for_current_page();
		if ( empty( $raw_critical_css ) ) {
			return;
		}
		echo "<style id='swis-critical-css'>" . $this->sanitize_css( $raw_critical_css ) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( empty( $_GET['swis_test_ccss'] ) && empty( $_GET['swis_ccss'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_filter( 'swis_filter_page_output', array( $this, 'inline_critical_js' ) );
		}
	}

	/**
	 * Insert the JS to remove the Critical CSS section once all the CSS has loaded.
	 *
	 * @param string $buffer The HTML content of the page.
	 * @return string The altered HTML.
	 */
	function inline_critical_js( $buffer ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$script_name   = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? 'critical-css-remove.js' : 'critical-css-remove.min.js';
		$inline_script = file_get_contents( SWIS_PLUGIN_PATH . 'assets/' . $script_name );
		return preg_replace( '#</body>#i', '<script>' . $inline_script . '</script></body>', $buffer, 1 );
	}

	/**
	 * Find cricital CSS 'types' for the current page/URL.
	 *
	 * @return array A list of 'types' found for the current URL.
	 */
	function get_types_for_current_page() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$types = array();
		if ( \is_admin() ) {
			return $types;
		} elseif ( \is_front_page() ) {
			$types[] = 'is_front_page';
		} elseif ( \is_home() ) {
			$types[] = 'is_home';
		} elseif ( \is_search() ) {
			$types[] = 'is_search';
		} elseif ( \is_404() ) {
			$types[] = 'is_404';
		} elseif ( \is_page() ) {
			$types[] = 'page';
		} else {
			if ( \is_single() ) {
				$types[] = \get_post_type();
			} else {
				$type = \get_query_var( 'post_type' );
				if ( $type ) {
					$types[] = $type . '_archive';
				} else {
					$types[] = 'post_archive';
				}
			}
		}
		return $types;
	}

	/**
	 * Adds the critical CSS management section to the front-end panel.
	 */
	function frontend_critical_css_management() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$css_file   = $this->find_css_file_for_current_page();
		$css_url    = '';
		$css_used   = '';
		$css_status = '';
		// Get the location where a page-specific critical.css file would live for comparison to $css_file.
		// We'll also use this if they want to manually upload critical CSS.
		$page_css_file = $this->get_cache_file_dir() . '/critical.css';
		if ( $css_file ) {
			$css_status_file = \trailingslashit( dirname( $css_file ) ) . 'critical.status';
			// We assume a good status until we find otherwise, since manually entered files may not have a status.
			$css_status = 'GOOD';
			if ( $this->is_file( $css_status_file ) ) {
				$css_status_contents = \trim( \file_get_contents( $css_status_file ) );
				if ( 'WARN' === $css_status_contents ) {
					$css_status = 'WARN';
				} elseif ( 'BAD' === $css_status_contents ) {
					$css_status = 'BAD';
				}
			}
			$css_used = 'template';
			$css_type = 'fallback';
			$css_code = \file_get_contents( $css_file );
			if ( $css_file === $page_css_file ) {
				$css_used = 'page';
			} elseif ( false !== \strpos( $css_file, '/template/' ) ) {
				$split_path = \explode( '/template/', $css_file );
				if ( $this->is_iterable( $split_path ) && ! empty( $split_path[1] ) ) {
					$css_type = \str_replace( '/critical.css', '', $split_path[1] );
				}
			}
			$css_url  = \str_replace( $this->cache_dir, $this->cache_dir_url, $css_file );
			$css_file = \str_replace( ABSPATH, '', $css_file );
		}
		$loading_image = \plugins_url( '/assets/images/spinner.gif', SWIS_PLUGIN_FILE );
		$css_types     = $this->get_types_for_current_page();
		$js_type_var   = 'post';
		if ( ! empty( $css_type ) && 'fallback' !== $css_type ) {
			$js_type_var = $css_type;
		} elseif ( $this->is_iterable( $css_types ) && ! empty( $css_types[0] ) ) {
			$js_type_var = $css_types[0];
		}
		?>
		<?php if ( ! \is_admin() ) : ?>
			<script data-cfasync='false' data-no-defer='1'>
				var swis_ccss_types = <?php echo \wp_json_encode( $this->get_types_for_current_page() ); ?>;
				var swis_ccss_type  = <?php echo \wp_json_encode( $js_type_var ); ?>;
				var swis_ccss_url   = <?php echo \wp_json_encode( \home_url( $this->request_uri ) ); ?>;
			</script>
			<div class='slim-section-heading'>
				<?php \esc_html_e( 'Critical CSS', 'swis-performance' ); ?>
			</div>
		<?php endif; ?>
			<form id='swis-active-critical-css'>
		<?php if ( \is_admin() && empty( $css_used ) ) : ?>
				<div class='swis-ccss-row'>
					<div class='swis-ccss-file'>
						<?php \esc_html_e( 'Auto-refresh failed, please reload the page.', 'swis-performance' ); ?>
					</div>
				</div>
		<?php elseif ( empty( $css_used ) ) : ?>
				<div class='swis-ccss-row'>
					<div class='swis-ccss-file'>
			<?php if ( ! empty( $this->get_option( 'critical_css' ) ) ) : ?>
						<?php \esc_html_e( 'Using fallback CSS', 'swis-performance' ); ?>
			<?php else : ?>
						<?php \esc_html_e( 'No critical CSS available', 'swis-performance' ); ?>
			<?php endif; ?>
					</div>
					<div class='swis-ccss-actions'>
						<img class='swis-ccss-spinner' src='<?php echo \esc_attr( $loading_image ); ?>' />
			<?php if ( $this->get_option( 'critical_css' ) ) : ?>
						<a href='<?php echo \esc_url( \add_query_arg( 'swis_test_ccss', '1', \home_url( $this->request_uri ) ) ); ?>' target='_blank'><?php esc_html_e( 'Test CSS', 'swis-performance' ); ?></a>
						&nbsp;&nbsp;
			<?php endif; ?>
			<?php if ( ! empty( $this->get_option( 'critical_css_key' ) ) ) : ?>
						<button type="button" class="button-primary button-link-generate"><?php \esc_html_e( 'Generate Critical CSS', 'swis-performance' ); ?></button>
			<?php else : ?>
						<button type="button" class="button-primary button-link-add"><?php \esc_html_e( 'Add Critical CSS', 'swis-performance' ); ?></button>
			<?php endif; ?>
					</div>
				</div>
				<div class='swis-ccss-add swis-ccss-hidden'>
					<label for='swis-critical-css-code'><strong><?php \esc_html_e( 'Enter CSS', 'swis-performance' ); ?></strong></label><br>
					<textarea id='swis-critical-css-code' name='swis-critical-css-code' rows='6'></textarea>
					<button type="button" class="button-primary button-link-save"><?php \esc_html_e( 'Save', 'swis-performance' ); ?></button>
				</div>
		<?php elseif ( 'template' === $css_used ) : ?>
				<div class='swis-ccss-row'>
					<div class='swis-ccss-file'>
						<?php /* translators: %s: template/type of CSS being used, e.g. 'is_front_page', 'page', or 'post_archive' */ ?>
						<?php \printf( \esc_html__( 'Using %s critical CSS:', 'swis-performance' ), '<i>' . \esc_html( $css_type ) . '</i>' ); ?>
						<a href='<?php echo \esc_url( $css_url ); ?>' target='_blank'><?php echo \esc_html( $css_file ); ?></a>
						<?php $this->display_status_icon( $css_status ); ?>
					</div>
					<div class='swis-ccss-actions'>
						<img class='swis-ccss-spinner' src='<?php echo \esc_attr( $loading_image ); ?>' />
						<a href='<?php echo \esc_url( \add_query_arg( 'swis_test_ccss', '1', \home_url( $this->request_uri ) ) ); ?>' target='_blank'><?php esc_html_e( 'Test CSS', 'swis-performance' ); ?></a>
						&nbsp;&nbsp;
						<?php /* translators: %s: template/type of CSS being used, e.g. 'is_front_page', 'page', or 'post_archive' */ ?>
						<button type='button' class='button-secondary button-link-edit-template'><?php printf( \esc_html__( 'Edit %s CSS', 'swis-performance' ), '<i>' . \esc_html( $css_type ) . '</i>' ); ?></button>
			<?php if ( ! empty( $this->get_option( 'critical_css_key' ) ) && ! \is_404() && ! \is_search() ) : ?>
						&nbsp;&nbsp;
						<button type='button' class='button-primary button-link-generate'><?php \esc_html_e( 'Generate page-specific CSS', 'swis-performance' ); ?></button>
			<?php elseif ( ! is_404() && ! \is_search() ) : ?>
						&nbsp;&nbsp;
						<button type='button' class='button-primary button-link-add'><?php \esc_html_e( 'Add page-specific CSS', 'swis-performance' ); ?></button>
			<?php endif; ?>
					</div>
				</div>
				<div class='swis-ccss-edit-template swis-ccss-hidden'>
					<textarea id='swis-critical-css-template-code' name='swis-critical-css-template-code' rows='6'><?php echo \wp_kses( $css_code, 'strip' ); ?></textarea>
					<button type='button' class='button-primary button-link-save'><?php \esc_html_e( 'Save', 'swis-performance' ); ?></button>
				</div>
				<div class='swis-ccss-add swis-ccss-hidden'>
					<label for='swis-critical-css-code'><strong><?php \esc_html_e( 'Enter CSS', 'swis-performance' ); ?></strong></label>
					<textarea id='swis-critical-css-code' name='swis-critical-css-code' rows='6'></textarea>
					<button type='button' class='button-primary button-link-save'><?php \esc_html_e( 'Save', 'swis-performance' ); ?></button>
				</div>
		<?php else : ?>
				<div class='swis-ccss-row'>
					<div class='swis-ccss-file'>
						<?php \esc_html_e( 'Active CSS:', 'swis-performance' ); ?>
						<a href='<?php echo \esc_url( $css_url ); ?>' target='_blank'><?php echo \esc_html( $css_file ); ?></a>
						<?php $this->display_status_icon( $css_status ); ?>
					</div>
					<div class='swis-ccss-actions'>
						<img class='swis-ccss-spinner' src='<?php echo \esc_attr( $loading_image ); ?>' />
						<a href='<?php echo \esc_url( \add_query_arg( 'swis_test_ccss', '1', \home_url( $this->request_uri ) ) ); ?>' target='_blank'><?php esc_html_e( 'Test CSS', 'swis-performance' ); ?></a>
						&nbsp;&nbsp;
						<button type='button' class='button-secondary button-link-edit'><?php \esc_html_e( 'Edit CSS', 'swis-performance' ); ?></button>
			<?php if ( ! empty( $this->get_option( 'critical_css_key' ) ) ) : ?>
						&nbsp;&nbsp;
						<button type='button' class='button-primary button-link-generate'><?php \esc_html_e( 'Regenerate CSS', 'swis-performance' ); ?></button>
			<?php endif; ?>
					</div>
				</div>
				<div class='swis-ccss-edit swis-ccss-hidden'>
					<textarea id='swis-critical-css-page-code' name='swis-critical-css-page-code' rows='6'><?php echo \wp_kses( $css_code, 'strip' ); ?></textarea>
					<button type='button' class='button-primary button-link-save'><?php \esc_html_e( 'Save', 'swis-performance' ); ?></button>
				</div>
		<?php endif; ?>
				<div class='swis-ccss-error-message'></div>
				<div class='swis-ccss-success-message'></div>
			</form>
		<?php
	}

	/**
	 * Display an icon for a given CSS status/quality along with a link to the docs.
	 *
	 * @param string $status The CSS quality returned via the API.
	 */
	function display_status_icon( $status ) {
		$link = 'https://docs.ewww.io/article/117-critical-css-code-quality-validation';
		?>
		<?php if ( 'GOOD' === $status ) : ?>
			<a class='swis-ccss-status swis-ccss-good' href='<?php echo esc_url( $link ); ?>' target='_blank'>
				<span class='dashicons dashicons-yes-alt'></span>
			</a>
		<?php elseif ( 'WARN' === $status ) : ?>
			<a class='swis-ccss-status swis-ccss-warn' href='<?php echo esc_url( $link ); ?>' target='_blank'>
				<span class='dashicons dashicons-warning'></span>
			</a>
		<?php elseif ( 'BAD' === $status ) : ?>
			<a class='swis-ccss-status swis-ccss-bad' href='<?php echo esc_url( $link ); ?>' target='_blank'>
				<span class='dashicons dashicons-warning'></span>
			</a>
		<?php endif; ?>
		<?php
	}

	/**
	 * Checks to see if the user defined an override for the generate CSS delay.
	 *
	 * @param int $delay The current delay (defaults to 5 seconds).
	 * @return int The default, or a user-configured override.
	 */
	function generate_css_delay_override( $delay ) {
		if ( defined( 'SWIS_GENERATE_CSS_DELAY' ) ) {
			$delay_override = SWIS_GENERATE_CSS_DELAY;
			return absint( $delay_override );
		}
		return $delay;
	}

	/**
	 * Validate the user-defined exclusions.
	 */
	function validate_user_exclusions() {
		// NOTE: This option does not exist yet, will probably be post-meta for starters.
		return;
		$user_exclusions = $this->get_option( 'generate_css_exclude' );
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
	 * Exclude page from having critical CSS generated based on user specified list.
	 *
	 * @param boolean $skip Whether SWIS should skip critical CSS.
	 * @param string  $url The page URL.
	 * @return boolean True to skip the page, unchanged otherwise.
	 */
	function skip_generate_css( $skip, $url ) {
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
	 *
	 * This is used to start/stop the async process. It is *not* for starting the AJAX process.
	 */
	function manual_generate_css_action() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$permissions = \apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! \current_user_can( $permissions ) || ! \check_admin_referer( 'swis_generate_css_nonce', 'swis_generate_css_nonce' ) ) {
			\wp_die( \esc_html__( 'Access denied', 'swis-performance' ) );
		}
		if ( ! empty( $_GET['swis_stop_generate_css'] ) ) {
			$this->stop_generate_css();
		} else {
			$this->start_generate_css();
		}
		$base_url = \admin_url( 'options-general.php?page=swis-performance-options' );
		\wp_safe_redirect( $base_url );
		exit;
	}

	/**
	 * Handle the manual preload resume admin action.
	 */
	function manual_generate_css_resume_action() {
		$permissions = \apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! \current_user_can( $permissions ) || ! \check_admin_referer( 'swis_generate_css_nonce', 'swis_generate_css_nonce' ) ) {
			\wp_die( \esc_html__( 'Access denied', 'swis-performance' ) );
		}
		session_write_close();
		swis()->critical_css_background->dispatch();
		$base_url = \admin_url( 'options-general.php?page=swis-performance-options' );
		\wp_safe_redirect( $base_url );
		exit;
	}

	/**
	 * Handle the manual purge admin action.
	 * TODO: not used, because we have a global JS/CSS purge which can be used from front-end also.
	 *
	 * This is used to purge all critical CSS files from the cache.
	 */
	function manual_purge_css_action() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$permissions = \apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! \current_user_can( $permissions ) || ! \check_admin_referer( 'swis_generate_css_nonce', 'swis_generate_css_nonce' ) ) {
			\wp_die( \esc_html__( 'Access denied', 'swis-performance' ) );
		}
		$this->purge_cache();
		\wp_safe_redirect( remove_query_arg( array( '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Begin critical CSS generation.
	 */
	function start_generate_css() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$this->stop_generate_css();
		\set_transient( 'swis_generate_css_total', -1, DAY_IN_SECONDS );
		swis()->critical_css_async->dispatch();
	}

	/**
	 * Stop critical CSS generation.
	 */
	function stop_generate_css() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		swis()->critical_css_background->cancel_process();
		\delete_transient( 'swis_generate_css_total' );
	}

	/**
	 * Begin critical CSS generation for a given URL.
	 *
	 * @param string $url The page to process.
	 */
	function start_generate_css_url( $url ) {
		// TODO: might use this, might not, will need to add the POST logic back to the async handler.
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$this->debug_message( "generating CSS for $url" );
		swis()->critical_css_async->data(
			array(
				'swis_generate_css_url' => esc_url( $url ),
			)
		)->dispatch();
	}

	/**
	 * When any published post type is updated or published.
	 *
	 * @param int $post_id The post ID number.
	 */
	public function on_save_post( $post_id ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$post_status = \get_post_status( $post_id );
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( 'publish' === $post_status ) {
			$url  = \get_permalink( $post_id );
			$type = (string) \get_post_type( $post_id );
			$page = array(
				'page_url' => $url,
				'params'   => array(
					'type' => $type,
				),
			);
			if ( $url && $this->is_cached( $url ) ) {
				$this->purge_cache_by_url( $url );
				swis()->critical_css_background->push_to_queue( $page );
				swis()->critical_css_background->dispatch();
			} elseif ( 'page' === $type ) {
				swis()->critical_css_background->push_to_queue( $page );
				swis()->critical_css_background->dispatch();
			}
		}
	}

	/**
	 * Set the theme switch transient to alert the user that they should regenerate critical CSS.
	 */
	function switch_theme() {
		\set_transient( 'swis_ccss_switch_theme_notice', true, WEEK_IN_SECONDS );
		$this->stop_generate_css();
	}

	/**
	 * Display a notice that the plugin requirements check failed.
	 */
	function display_theme_regen_notice() {
		if ( ! \current_user_can( 'manage_options' ) || ! \get_transient( 'swis_ccss_switch_theme_notice' ) ) {
			return;
		}
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		?>
		<div id='swis-switch-theme-regen-notice' class='notice notice-info is-dismissible'><p>
			<?php
			printf(
				/* translators: %s: settings page (linked) */
				esc_html__( 'SWIS Performance has detected a theme change. Unless this is temporary, you should visit the %s to purge and regenerate all critical CSS files.', 'swis-performance' ),
				'<a href="' . esc_url( admin_url( 'options-general.php?page=swis-performance-options' ) ) . '">' . esc_html__( 'settings page', 'swis-performance' ) . '</a>'
			);
			?>
		</p></div>
		<script>
			jQuery(document).on('click', '#swis-switch-theme-regen-notice .notice-dismiss', function() {
				var swis_dismiss_theme_regen_data = {
					action: 'swis_dismiss_theme_regen_notice',
				};
				jQuery.post(ajaxurl, swis_dismiss_theme_regen_data, function(response) {
					if (response) {
						console.log(response);
					}
				});
			});
		</script>
		<?php
	}

	/**
	 * Disables the notice about switching themes (and regenerating critical CSS).
	 */
	function dismiss_theme_regen_notice() {
		$this->ob_clean();
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// Verify that the user is properly authorized.
		if ( ! current_user_can( apply_filters( 'swis_performance_admin_permissions', 'manage_options' ) ) ) {
			wp_die( esc_html__( 'Access denied.', 'swis-performance' ) );
		}
		delete_transient( 'swis_ccss_switch_theme_notice' );
		die();
	}

	/**
	 * Purge critical CSS cache.
	 */
	function purge_cache() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$this->stop_generate_css();
		$this->clear_dir( $this->cache_dir );
		// NOTE: let's not, so we can use this to track posts with page-specific CSS.
		global $wpdb;
		// $wpdb->query( "TRUNCATE $wpdb->swis_critical_css" );
	}

	/**
	 * Begin generating critical CSS via AJAX request.
	 */
	function start_generate_css_ajax() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$permissions = \apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! \current_user_can( $permissions ) || ! \check_ajax_referer( 'swis_generate_css_nonce', 'swis_generate_css_nonce', false ) ) {
			die( \wp_json_encode( array( 'error' => \esc_html__( 'Access token has expired, please reload the page.', 'swis-performance' ) ) ) );
		}
		\session_write_close();
		$remaining_urls = (int) swis()->critical_css_background->count_queue();
		$completed      = 0;

		if ( empty( $remaining_urls ) ) {
			$this->debug_message( 'looking for URLs to preload' );
			$this->get_urls();
			$total_urls = (int) swis()->critical_css_background->count_queue();
		} else {
			$total_urls = (int) get_transient( 'swis_generate_css_total' );
			if ( ! $total_urls ) {
				$total_urls = $remaining_urls;
				set_transient( 'swis_generate_css_total', (int) $total_urls, DAY_IN_SECONDS );
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
	 * Process the next URL in the queue via AJAX request.
	 */
	function url_generate_css_ajax() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! \current_user_can( $permissions ) || ! \check_ajax_referer( 'swis_generate_css_nonce', 'swis_generate_css_nonce', false ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Access token has expired, please reload the page.', 'swis-performance' ) ) ) );
		}
		\session_write_close();

		global $wpdb;
		$page = $wpdb->get_row( "SELECT id,page_url,params FROM $wpdb->swis_queue WHERE queue_name = 'swis_generate_css' LIMIT 1", ARRAY_A );
		if ( ! $this->is_iterable( $page ) || empty( $page['page_url'] ) ) {
			die( wp_json_encode( array( 'count' => 0 ) ) );
		}

		$page['params'] = maybe_unserialize( $page['params'] );

		$page = $this->generate( $page );

		$info_message = esc_html__( 'Critical CSS request completed.', 'swis-performance' );
		$pending      = false;
		if (
			! empty( $page['params']['status'] ) &&
			'JOB_DONE' !== $page['params']['status'] &&
			'exists' !== $page['params']['status'] &&
			'failed' !== $page['params']['status'] &&
			'invalid_key' !== $page['params']['status']
		) {
			swis()->critical_css_background->update( $page['id'], $page );
			$info_message = esc_html__( 'Critical CSS request pending...', 'swis-performance' );
			$pending      = true;
		} else {
			swis()->critical_css_background->delete( $page['id'] );
		}

		$remaining_urls = (int) swis()->critical_css_background->count_queue();
		$total_urls     = (int) get_transient( 'swis_generate_css_total' );
		$completed      = $total_urls - $remaining_urls;
		/* translators: %d: number of images */
		$message = sprintf( esc_html__( '%1$d / %2$d pages have been completed.', 'swis-performance' ), (int) $completed, (int) $total_urls );

		$error_status = false;
		if ( ! empty( $page['params']['status'] ) && ( 'failed' === $page['params']['status'] || 'invalid_key' === $page['params']['status'] ) ) {
			$error_status = $page['params']['status'];
			$info_message = $page['params']['error'];
		}
		if ( empty( $remaining_urls ) ) {
			do_action( 'swis_clear_site_cache' );
		}
		die(
			wp_json_encode(
				array(
					'count'   => $remaining_urls,
					'error'   => $error_status,
					'info'    => $info_message,
					'message' => $message,
					'pending' => $pending,
					'url'     => $page['page_url'],
				)
			)
		);
	}

	/**
	 * (re)Generate Critical CSS for a given page via AJAX.
	 */
	function url_generate_page_css_ajax() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! \current_user_can( $permissions ) || ! \check_ajax_referer( 'swis_generate_css_nonce', 'swis_generate_css_nonce', false ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Access token has expired, please reload the page.', 'swis-performance' ) ) ) );
		}
		\session_write_close();

		global $wpdb;
		$attempts   = 0;
		$pending_id = 0;
		if ( ! empty( $_POST['pending_id'] ) ) {
			$pending_id = (int) $_POST['pending_id'];

			$page = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM $wpdb->swis_queue WHERE queue_name = 'swis_single_css' AND id = %d LIMIT 1",
					$pending_id
				),
				ARRAY_A
			);
			if ( $this->is_iterable( $page ) && ! empty( $page['page_url'] ) ) {
				$page['params'] = maybe_unserialize( $page['params'] );
				$attempts       = $page['attempts'];
				$this->debug_message( "$attempts attempts so far" );
			} else {
				die( wp_json_encode( array( 'error' => esc_html__( 'Could not retrieve pending status from database.', 'swis-performance' ) ) ) );
			}
			if ( $attempts > 15 ) {
				die( wp_json_encode( array( 'error' => esc_html__( 'Timed out waiting for response from API. If this continues, please contact support.', 'swis-performance' ) ) ) );
			}
		} else {
			$url = ! empty( $_POST['page_url'] ) ? filter_var( wp_unslash( $_POST['page_url'] ), FILTER_VALIDATE_URL ) : false;
			// Validate the given URL.
			if ( empty( $url ) ) {
				die( wp_json_encode( array( 'error' => esc_html__( 'Could not validate URL.', 'swis-performance' ) ) ) );
			}
			// Not allowing query strings, so bust it apart just in case...
			$url_parts = explode( '?', $url );
			$url       = $url_parts[0];
			// Check for an already queued job that is still pending.
			$page = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM $wpdb->swis_queue WHERE queue_name = 'swis_single_css' AND page_url = %s LIMIT 1",
					$url
				),
				ARRAY_A
			);
			// Found something in the queue, so process as already pending.
			if ( $this->is_iterable( $page ) && ! empty( $page['page_url'] ) && ! $this->is_cached( $page['page_url'] ) ) {
				$this->debug_message( "found pending record by $url" );
				$pending_id     = $page['id'];
				$page['params'] = maybe_unserialize( $page['params'] );
				$url            = '';
				$attempts       = $page['attempts'];
			} else {
				if ( $this->is_iterable( $page ) && ! empty( $page['id'] ) ) {
					$this->debug_message( "pending record for $url is stale, removing" );
					$wpdb->delete(
						$wpdb->swis_queue,
						array(
							'id' => $page['id'],
						),
						array( '%d' )
					);
				}
				$types = ! empty( $_POST['types'] ) && is_array( $_POST['types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['types'] ) ) : array();
				$type  = ! empty( $types[0] ) ? $types[0] : 'page';

				$page = array(
					'page_url' => $url,
					'params'   => array(
						'types' => $types,
						'type'  => $type,
					),
				);
			}
		}

		if ( ! empty( $url ) ) {
			$this->purge_cache_by_url( $url );
		}

		$page = $this->generate( $page );

		$info_message = '';
		// This is a new pending item, so store it in the db and get the record ID.
		if ( ! $pending_id && ! empty( $page['params']['status'] ) && 'JOB_DONE' !== $page['params']['status'] && 'failed' !== $page['params']['status'] ) {
			$this->debug_message( 'new job, storing in db' );
			$params   = serialize( $page['params'] );
			$inserted = $wpdb->insert(
				$wpdb->swis_queue,
				array(
					'page_url'   => $url,
					'params'     => $params,
					'queue_name' => 'swis_single_css',
				)
			);
			if ( $inserted && ! empty( $wpdb->insert_id ) ) {
				$pending_id = (int) $wpdb->insert_id;
				$this->debug_message( "success, stored $pending_id" );
			} else {
				die( wp_json_encode( array( 'error' => esc_html__( 'Could not store pending status in database.', 'swis-performance' ) ) ) );
			}
		} elseif ( ! empty( $page['params']['status'] ) && 'JOB_DONE' === $page['params']['status'] ) {
			$this->debug_message( 'job done, removing queue record' );
			$wpdb->delete(
				$wpdb->swis_queue,
				array(
					'id' => $pending_id,
				),
				array( '%d' )
			);
			$pending_id = 0;
		}

		$error_status = false;
		if ( ! empty( $page['params']['status'] ) && ( 'failed' === $page['params']['status'] || 'invalid_key' === $page['params']['status'] ) ) {
			$this->debug_message( 'error status detected' );
			$error_status = $page['params']['status'];
			$info_message = $page['params']['error'];
			$wpdb->delete(
				$wpdb->swis_queue,
				array(
					'id' => $pending_id,
				),
				array( '%d' )
			);
			$pending_id = 0;
		} elseif ( $pending_id ) {
			$this->debug_message( 'job pending' );
			if ( empty( $url ) ) {
				$this->debug_message( 'updating attempts' );
				$wpdb->update(
					$wpdb->swis_queue,
					array(
						'attempts' => $attempts + 1,
					),
					array(
						'id' => $pending_id,
					)
				);
			}
		} else {
			$this->debug_message( 'all done' );
			\clearstatcache();
			\ob_start();
			$this->request_uri = $this->parse_url( $page['page_url'], PHP_URL_PATH );
			$this->frontend_critical_css_management();
			$info_message = \trim( \ob_get_clean() );
			do_action( 'swis_clear_page_cache_by_url', $page['page_url'] );
		}
		die(
			wp_json_encode(
				array(
					'error'   => $error_status,
					'info'    => $info_message,
					'url'     => $page['page_url'],
					'pending' => (int) $pending_id,
				)
			)
		);
	}

	/**
	 * Purge the critical CSS cache for a given URL.
	 *
	 * @param string $url The URL to be purged.
	 */
	function purge_cache_by_url( $url ) {
		if ( empty( $url ) ) {
			return;
		}
		$cache_dir = $this->get_cache_file_dir( $url );
		if ( $cache_dir ) {
			$cache_file = $cache_dir . '/critical.css';
			if ( $this->is_file( $cache_file ) && is_writable( $cache_file ) ) {
				unlink( $cache_file );
			}
			$status_file = $cache_dir . '/critical.status';
			if ( $this->is_file( $status_file ) && is_writable( $status_file ) ) {
				unlink( $status_file );
			}
		}
	}


	/**
	 * Save critical CSS for a particular page/template via AJAX.
	 */
	function save_ccss_ajax() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$permissions = \apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if ( ! \current_user_can( $permissions ) || ! \check_ajax_referer( 'swis_generate_css_nonce', 'swis_generate_css_nonce', false ) ) {
			die( \wp_json_encode( array( 'error' => \esc_html__( 'Access token has expired, please reload the page.', 'swis-performance' ) ) ) );
		}
		\session_write_close();
		if ( ! isset( $_POST['swis_ccss_code'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Please enter critical CSS code before saving.', 'swis-performance' ) ) ) );
		}

		$css = sanitize_textarea_field( wp_unslash( $_POST['swis_ccss_code'] ) );
		$css = $this->sanitize_css( $css );

		// Validate the given URL.
		$url = ! empty( $_POST['page_url'] ) ? filter_var( wp_unslash( $_POST['page_url'] ), FILTER_VALIDATE_URL ) : false;
		if ( empty( $url ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Could not validate URL.', 'swis-performance' ) ) ) );
		}
		// Not allowing query strings, so bust it apart just in case...
		$url_parts = explode( '?', $url );
		$url       = $url_parts[0];

		$ccss_type = ! empty( $_POST['swis_ccss_type'] ) && 'template' === sanitize_text_field( wp_unslash( $_POST['swis_ccss_type'] ) ) ? 'template' : 'page';
		$template  = '';
		if ( 'template' === $ccss_type && ! empty( $_POST['swis_ccss_template'] ) ) {
			$template       = trim( sanitize_text_field( wp_unslash( $_POST['swis_ccss_template'] ) ) );
			$cache_file_dir = $this->get_template_cache_file_dir( $template, $url );
			$existing_file  = $cache_file_dir . '/critical.css';
			if ( $this->is_file( $existing_file ) && \is_writable( $existing_file ) ) {
				unlink( $existing_file );
			}
		} else {
			$cache_file_dir = $this->get_cache_file_dir( $url );
		}

		$page = array(
			'page_url' => $url,
			'params'   => array(
				'type' => $template,
			),
		);
		if ( empty( $css ) ) {
			$existing_file = $cache_file_dir . '/critical.css';
			if ( $this->is_file( $existing_file ) && \is_writable( $existing_file ) ) {
				\unlink( $existing_file );
			}
			$status_file = $cache_file_dir . '/critical.status';
			if ( $this->is_file( $status_file ) ) {
				\unlink( $status_file );
			}
			$info_message = \esc_html__( 'Critical CSS removed.', 'swis-performance' );
			die(
				\wp_json_encode(
					array(
						'success' => $info_message,
						'css'     => '',
						'replace' => false,
					)
				)
			);
		} elseif ( $template ) {
			$this->store_template_ccss( $page, $css );
		} else {
			$this->store_page_ccss( $page, $css );
		}
		clearstatcache();
		if ( $this->is_file( $cache_file_dir . '/critical.css' ) ) {
			if ( $template ) {
				$info_message = \esc_html__( 'Critical CSS saved.', 'swis-performance' );
			} else {
				\ob_start();
				$this->request_uri = $this->parse_url( $page['page_url'], PHP_URL_PATH );
				$this->frontend_critical_css_management();
				$info_message = \trim( \ob_get_clean() );
			}
			die(
				\wp_json_encode(
					array(
						'success' => $info_message,
						'css'     => $css,
						'replace' => empty( $template ),
					)
				)
			);
		}
		die( \wp_json_encode( array( 'error' => esc_html__( 'Could not save CSS.', 'swis-performance' ) ) ) );
	}

	/**
	 * Gets all the URLs for which to generate CSS, called via AJAX or async operation.
	 */
	function get_urls() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$urls = $this->get_site_urls();
		if ( $this->is_iterable( $urls ) ) {
			foreach ( $urls as $url ) {
				if ( empty( $url ) || ! is_array( $url ) || empty( $url['page_url'] ) ) {
					continue;
				}
				$this->debug_message( "queueing {$url['page_url']} for critical CSS" );
				swis()->critical_css_background->push_to_queue( $url );
			}
			set_transient( 'swis_generate_css_total', (int) swis()->critical_css_background->count_queue(), DAY_IN_SECONDS );
		}
	}

	/**
	 * Fetch posts/pages for generating critical CSS.
	 *
	 * @return array A list of URLs.
	 */
	function get_site_urls() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$urls = array();

		// Get the front page.
		$urls[] = array(
			'page_url' => get_home_url(),
			'params'   => array( 'type' => 'is_front_page' ),
		);
		// Get the login page.
		$urls[] = array(
			'page_url' => wp_login_url(),
			'params'   => array( 'type' => 'is_login' ),
		);

		$is_search_url = get_search_link( 'critical css' );
		if ( apply_filters( 'swis_critical_css_generate_search', $is_search_url ) ) {
			$urls[] = array(
				'page_url' => $is_search_url,
				'params'   => array( 'type' => 'is_search' ),
			);
		}

		$is_404_url = home_url( '/xyz4293a/' );
		if ( apply_filters( 'swis_critical_css_generate_404', $is_404_url ) ) {
			$urls[] = array(
				'page_url' => $is_404_url,
				'params'   => array( 'type' => 'is_404' ),
			);
		}

		$page_args  = array(
			'fields'         => 'ids',
			'numberposts'    => -1,
			'orderby'        => 'post_date',
			'order'          => 'DESC',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'post_type'      => 'page',
		);
		$site_pages = get_posts( $page_args );
		if ( $this->is_iterable( $site_pages ) ) {
			foreach ( $site_pages as $page_id ) {
				$permalink = get_permalink( $page_id );
				$this->debug_message( "found $permalink for page $page_id" );
				if ( $permalink ) {
					$urls[] = array(
						'page_url' => $permalink,
						'params'   => array( 'type' => 'page' ),
					);
				}
			}
		}

		$post_types = get_post_types( array( 'public' => true ) );
		if ( $this->is_iterable( $post_types ) ) {
			$post_types = array_filter( $post_types, 'is_post_type_viewable' );

			$args = array(
				'fields'         => 'ids',
				'numberposts'    => 1,
				'orderby'        => 'post_date',
				'order'          => 'DESC',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
			);

			foreach ( $post_types as $post_type ) {
				if ( 'page' === $post_type ) {
					continue;
				}
				$args['post_type'] = $post_type;
				$posts             = get_posts( $args );
				$this->debug_message( "checking for a single $post_type" );
				if ( $this->is_iterable( $posts ) ) {
					foreach ( $posts as $post_id ) {
						$permalink = get_permalink( $post_id );
						$this->debug_message( "found $permalink for page $post_id" );
						if ( $permalink ) {
							$urls[] = $permalink;
							$urls[] = array(
								'page_url' => $permalink,
								'params'   => array( 'type' => $post_type ),
							);
						}
					}
				}

				$this->debug_message( "looking for $post_type archive link" );
				if ( 'post' === $post_type ) {
					$is_home_url = get_post_type_archive_link( $post_type );
					if ( $is_home_url ) {
						$urls[] = array(
							'page_url' => $is_home_url,
							'params'   => array( 'type' => 'is_home' ),
						);
						$this->debug_message( "added $is_home_url for is_home" );
					}
					// Use the previously-retrieved post_id to get a date-based archive URL.
					$post_archive_url = '';
					if ( ! empty( $post_id ) ) {
						$post_archive_url = get_year_link( get_the_date( 'Y', $post_id ) );
					}
				} else {
					$post_archive_url = get_post_type_archive_link( $post_type );
				}
				if ( $post_archive_url ) {
					$archive_type = $post_type . '_archive';
					$urls[]       = array(
						'page_url' => $post_archive_url,
						'params'   => array( 'type' => $archive_type ),
					);
					$this->debug_message( "added $post_archive_url for $archive_type" );
				} else {
					$this->debug_message( 'got milk?' );
				}
			}
		}
		// Check the *_swis_critical_css table for URLs to add. This catches any that have been manually generated.
		global $wpdb;
		$previous_urls = $wpdb->get_results( "SELECT page_url,type FROM $wpdb->swis_critical_css", ARRAY_A );
		if ( $this->is_iterable( $previous_urls ) ) {
			foreach ( $previous_urls as $prior_url ) {
				if ( ! empty( $prior_url['page_url'] ) && isset( $prior_url['type'] ) ) {
					foreach ( $urls as $url ) {
						if ( ! empty( $url['page_url'] ) && $url['page_url'] === $prior_url['page_url'] ) {
							continue 2;
						}
					}
					$urls[] = array(
						'page_url' => $prior_url['page_url'],
						'params'   => array( 'type' => (string) $prior_url['type'] ),
					);
				}
			}
		}
		return $urls;
	}

	/**
	 * Check if critical CSS for the URL is already cached.
	 *
	 * @param string $url The URL path to check.
	 * @param string $type The type of URL being checked.
	 * @return bool True for cached, false if it ain't.
	 */
	function is_cached( $url, $type = '' ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		// Do some pre-emptive type-checking, since these don't get cached by page.
		if ( $type &&
			(
				'is_404' === $type ||
				'is_search' === $type ||
				'is_login' === $type
			)
		) {
			$cache_file_dir = $this->get_template_cache_file_dir( $type );
		} else {
			$cache_file_dir = $this->get_cache_file_dir( $url );
		}
		$this->debug_message( "checking if $cache_file_dir/critical.css exists" );
		if ( $cache_file_dir && \is_dir( $cache_file_dir ) ) {
			if ( $this->is_file( $cache_file_dir . '/critical.css' ) ) {
				$this->debug_message( 'it sure does!' );
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the directory/path to a cached CSS file for a given URL.
	 *
	 * @param string $url The full URL.
	 * @return string The path to a cached asset.
	 */
	function get_cache_file_dir( $url = null ) {
		$this->debug_message( __METHOD__ );
		$cache_file_dir = '';

		// Validate the given URL.
		if ( $url && ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return $cache_file_dir;
		}

		$cache_file_dir = sprintf(
			'%s%s%s',
			trailingslashit( $this->cache_dir ),
			( $url ) ? $this->parse_url( $url, PHP_URL_HOST ) : strtolower( $this->request_headers['Host'] ),
			$this->parse_url( ( $url ) ? $url : $this->request_uri, PHP_URL_PATH )
		);

		$cache_file_dir = rtrim( $cache_file_dir, '/\\' );
		$this->debug_message( $cache_file_dir );
		return $cache_file_dir;
	}

	/**
	 * Get the directory/path to a cached CSS file for a given template.
	 *
	 * @param string $type The type/template of a given page.
	 * @param string $url The full URL. This is important to make sure we put the CSS in the correct domain-based sub-folder.
	 * @return string The path to a cached asset.
	 */
	function get_template_cache_file_dir( $type, $url = null ) {
		$this->debug_message( __METHOD__ );
		$cache_file_dir = '';

		// Validate the given URL.
		if ( $url && ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return $cache_file_dir;
		}
		$type = preg_replace( '/[^\w-]/', '', $type );
		if ( empty( $type ) ) {
			return $cache_file_dir;
		}

		$cache_file_dir = sprintf(
			'%s%s%s',
			trailingslashit( $this->cache_dir ),
			( $url ) ? $this->parse_url( $url, PHP_URL_HOST ) : strtolower( $this->request_headers['Host'] ),
			'/template/' . $type
		);

		$cache_file_dir = rtrim( $cache_file_dir, '/\\' );
		$this->debug_message( "found $cache_file_dir for $type" );
		return $cache_file_dir;
	}

	/**
	 * Get critical CSS for the given URL.
	 *
	 * @param string $page The URL to generate CSS.
	 */
	function generate( $page ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		if ( empty( $page ) || ! is_array( $page ) || empty( $page['page_url'] ) ) {
			return array(
				'page_url' => false,
				'params'   => array(
					'status' => 'failed',
					'error'  => __( 'No URL provided, or data malformed', 'swis-performance' ),
				),
			);
		}

		// Just in case they get here without being unserialized.
		$page['params'] = maybe_unserialize( $page['params'] );
		if ( ! is_array( $page['params'] ) ) {
			$page['params'] = array();
		}

		$page['params']['type'] = isset( $page['params']['type'] ) ? $page['params']['type'] : '';
		if ( $this->is_cached( $page['page_url'], $page['params']['type'] ) ) {
			$page['params']['status'] = 'exists';
			return $page;
		}

		$invalid_key = (int) \get_transient( 'swis_generate_css_invalid' );
		if ( $invalid_key > 3 ) {
			$this->stop_generate_css();
		}

		// If we don't have a status yet, that means we need to send a 'generate' request.
		if ( empty( $page['params']['status'] ) || empty( $page['params']['id'] ) ) {
			$result = $this->post_generate( $page['page_url'] );

			if ( is_wp_error( $result ) ) {
				$this->debug_message( 'ccss API error: ' . $result->get_error_message() );
				$page['params']['status'] = 'failed';
				/* translators: %s: a WP_Error message */
				$page['params']['error'] = sprintf( __( 'CriticalCSS.com API connection failure: %s', 'swis-performance' ), $result->get_error_message() );
				return $page;
			}

			$status = wp_remote_retrieve_response_code( $result );
			$this->debug_message( "status was $status" );
			if ( 200 === (int) $status ) {
				if ( $invalid_key ) {
					\delete_transient( 'swis_generate_css_invalid' );
					\delete_transient( 'swis_generate_css_paused' );
				}
				$body = \json_decode( wp_remote_retrieve_body( $result ), true );
				$this->debug_message( 'retrieved body' );
				if ( $this->is_iterable( $body ) && ! empty( $body['job'] ) ) {
					if ( ! empty( $body['job']['error'] ) ) {
						$this->debug_message( "ccss generation error: {$body['job']['error']}" );
						$page['params']['status'] = 'failed';
						/* translators: %s: a CriticalCSS.com error message */
						$page['params']['error'] = sprintf( __( 'CriticalCSS.com API request failed: %s', 'swis-performance' ), $body['job']['error'] );
					} elseif ( ! empty( $body['job']['id'] ) && ! empty( $body['job']['status'] ) && ( 'JOB_QUEUED' === $body['job']['status'] || 'JOB_ONGOING' === $body['job']['status'] ) ) {
						$this->debug_message( 'response body: ' . wp_json_encode( $body ) );
						$page['params']['status'] = $body['job']['status'];
						$page['params']['id']     = $body['job']['id'];
						$page['params']['error']  = false;
					} else {
						$this->debug_message( 'response body: ' . wp_json_encode( $body ) );
						$page['params']['status'] = 'failed';
						$page['params']['error']  = __( 'CriticalCSS.com API request failed.', 'swis-performance' );
					}
				} elseif ( ! empty( $body['error'] ) ) {
					$this->debug_message( "ccss generation error: {$body['error']}" );
					$page['params']['status'] = 'failed';
					/* translators: %s: a CriticalCSS.com error message */
					$page['params']['error'] = sprintf( __( 'CriticalCSS.com API request failed: %s', 'swis-performance' ), $body['error'] );
				} else {
					$this->debug_message( 'response body: ' . wp_json_encode( $body ) );
					$page['params']['status'] = 'failed';
					$page['params']['error']  = __( 'CriticalCSS.com API request failed.', 'swis-performance' );
				}
			} elseif ( 401 === (int) $status ) {
				$this->debug_message( 'criticalcss.com API key is invalid or expired' );
				$invalid_key++;
				set_transient( 'swis_generate_css_invalid', $invalid_key, 7 * DAY_IN_SECONDS );
				set_transient( 'swis_generate_css_paused', 1, DAY_IN_SECONDS );
				$page['params']['status'] = 'invalid_key';
				$page['params']['error']  = __( 'CriticalCSS.com API key is invalid, please make sure it is entered correctly and that you have an active subscription.', 'swis-performance' );
			} elseif ( 400 === (int) $status ) {
				$error = 'unknown';
				$body  = wp_remote_retrieve_body( $result );
				if ( ! empty( $body ) ) {
					$body = json_decode( $body, true );
					if ( ! empty( $body['error'] ) ) {
						$this->debug_message( "ccss generation error: {$body['error']}" );
						$error = $body['error'];
					}
				}
				$page['params']['status'] = 'failed';
				/* translators: %s: a CriticalCSS.com error message */
				$page['params']['error'] = sprintf( __( 'CriticalCSS.com API request failed: %s', 'swis-performance' ), $error );
			} else {
				$this->debug_message( wp_remote_retrieve_body( $result ) );
				$page['params']['status'] = 'failed';
				/* translators: %d: a WP_Error message */
				$page['params']['error'] = sprintf( __( 'Could not generate critical CSS, HTTP status code %d', 'swis-performance' ), $status );
			}
		} else {
			$result = $this->get_results( $page['params']['id'] );

			if ( is_wp_error( $result ) ) {
				$this->debug_message( 'ccss API error: ' . $result->get_error_message() );
				$page['params']['status'] = 'failed';
				/* translators: %s: a WP_Error message */
				$page['params']['error'] = sprintf( __( 'CriticalCSS.com API connection failure: %s', 'swis-performance' ), $result->get_error_message() );
				return $page;
			}

			$status = wp_remote_retrieve_response_code( $result );
			$this->debug_message( "status was $status" );
			if ( 200 === (int) $status ) {
				if ( $invalid_key ) {
					\delete_transient( 'swis_generate_css_invalid' );
					\delete_transient( 'swis_generate_css_paused' );
				}
				$body = json_decode( wp_remote_retrieve_body( $result ), true );
				$this->debug_message( 'retrieved body' );
				if ( $this->is_iterable( $body ) && ! empty( $body['id'] ) ) {
					if ( ! empty( $body['error'] ) ) {
						$this->debug_message( "ccss results error: {$body['error']}" );
						$page['params']['status'] = 'failed';
						/* translators: %s: a CriticalCSS.com error message */
						$page['params']['error'] = sprintf( __( 'CriticalCSS.com API request failed: %s', 'swis-performance' ), $body['error'] );
					} elseif ( ! empty( $body['css'] ) && ! empty( $body['status'] ) && 'JOB_DONE' === $body['status'] ) {
						$this->debug_message( "ccss status {$body['status']}" );
						$page['params']['status'] = 'JOB_DONE';
						$page['params']['error']  = false;
						$this->store_ccss( $page, $body['css'], $body['validationStatus'], $body['resultStatus'] );
					} elseif ( ! empty( $body['status'] ) && ( 'JOB_QUEUED' === $body['status'] || 'JOB_ONGOING' === $body['status'] ) ) {
						$this->debug_message( "ccss status {$body['status']}" );
						$page['params']['status'] = $body['status'];
					} elseif ( ! empty( $body['status'] ) && 'JOB_UNKNOWN' === $body['status'] ) {
						$this->debug_message( "ccss status {$body['status']}" );
						$page['params']['status'] = 'failed';
						$page['params']['error']  = __( 'Job unknown, please try again.', 'swis-performance' );
					} elseif ( ! empty( $body['resultStatus'] ) && 'PENTHOUSE_TIMEOUT' === $body['resultStatus'] ) {
						$this->debug_message( "ccss status {$body['resultStatus']}" );
						$page['params']['status'] = 'failed';
						$page['params']['error']  = __( 'Timed out while generating critical CSS for the page.', 'swis-performance' );
					} elseif ( ! empty( $body['resultStatus'] ) && 'CRITICALCSS_GENERATION_TIMEOUT' === $body['resultStatus'] ) {
						$this->debug_message( "ccss status {$body['resultStatus']}" );
						$page['params']['status'] = 'failed';
						$page['params']['error']  = __( 'Timed out while generating critical CSS for the page.', 'swis-performance' );
					} else {
						if ( ! empty( $body['css'] ) ) {
							$this->debug_message( 'css removed' );
							unset( $body['css'] );
						}
						$this->debug_message( 'response body: ' . wp_json_encode( $body ) );
						$page['params']['status'] = 'failed';
						$result_status            = ! empty( $body['resultStatus'] ) ? $body['resultStatus'] : 'UNKNOWN';
						/* translators: %s: a CriticalCSS.com status code */
						$page['params']['error'] = sprintf( __( 'CriticalCSS.com API request failed: %s', 'swis-performance' ), $result_status );
					}
				} elseif ( ! empty( $body['error'] ) ) {
					$this->debug_message( "ccss generation error: {$body['error']}" );
					$page['params']['status'] = 'failed';
					/* translators: %s: a CriticalCSS.com error message */
					$page['params']['error'] = sprintf( __( 'CriticalCSS.com API request failed: %s', 'swis-performance' ), $body['error'] );
				} else {
					if ( ! empty( $body['css'] ) ) {
						$this->debug_message( 'css removed' );
						unset( $body['css'] );
					}
					$this->debug_message( 'response body: ' . wp_json_encode( $body ) );
					$page['params']['status'] = 'failed';
					$page['params']['error']  = __( 'CriticalCSS.com API request failed.', 'swis-performance' );
				}
			} elseif ( 401 === (int) $status ) {
				$this->debug_message( 'criticalcss.com API key is invalid or expired' );
				$invalid_key++;
				set_transient( 'swis_generate_css_invalid', $invalid_key, 7 * DAY_IN_SECONDS );
				$page['params']['status'] = 'invalid_key';
				$page['params']['error']  = __( 'CriticalCSS.com API key is invalid, please make sure it is entered correctly and that you have an active subscription.', 'swis-performance' );
			} elseif ( 400 === (int) $status ) {
				$error = 'unknown';
				$body  = wp_remote_retrieve_body( $result );
				if ( ! empty( $body ) ) {
					$body = json_decode( $body, true );
					if ( ! empty( $body['error'] ) ) {
						$this->debug_message( "ccss generation error: {$body['error']}" );
						$error = $body['error'];
					}
				}
				$page['params']['status'] = 'failed';
				/* translators: %s: a CriticalCSS.com error message */
				$page['params']['error'] = sprintf( __( 'CriticalCSS.com API request failed: %s', 'swis-performance' ), $error );
			} else {
				$this->debug_message( wp_remote_retrieve_body( $result ) );
				$page['params']['status'] = 'failed';
				/* translators: %d: a WP_Error message */
				$page['params']['error'] = sprintf( __( 'Could not generate critical CSS, HTTP status code %d', 'swis-performance' ), $status );
			}
		}
		if ( $this->function_exists( 'sleep' ) ) {
			sleep( absint( apply_filters( 'swis_generate_css_delay', 0 ) ) );
		}
		return $page;
	}

	/**
	 * Adds ignoreStyleElements argument to the API parameters.
	 *
	 * @param array $post_fields Parameters sent to the critical CSS API.
	 * @return array The API parameters, with ignoreStyleElements = true, if the user leaves it enabled.
	 */
	function ignore_style_elements( $post_fields ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( defined( 'SWIS_CCSS_INCLUDE_INLINE' ) && SWIS_CCSS_INCLUDE_INLINE ) {
			return $post_fields;
		}
		if ( ! is_array( $post_fields ) ) {
			return $post_fields;
		}
		$this->debug_message( 'adding ignoreStyleElements' );
		$post_fields['ignoreStyleElements'] = true;
		return $post_fields;
	}

	/**
	 * Send a POST request to the CriticalCSS API to generate the CSS.
	 *
	 * @param string $url The URL of the page.
	 */
	protected function post_generate( $url ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$api_url = 'https://criticalcss.com/api/premium/generate';

		// Use the swis_disable query param to get a clean page load.
		$url = add_query_arg( 'swis_ccss', '1', $url );

		$key  = $this->get_option( 'critical_css_key' );
		$args = array(
			'timeout'   => 20,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false, $api_url ),
			'headers'   => array(
				'Authorization' => "JWT $key",
				'Content-type'  => 'application/json',
				'User-Agent'    => 'SWIS Performance ' . SWIS_PLUGIN_VERSION,
			),
			'body'      => wp_json_encode(
				apply_filters(
					'swis_generate_css_post_fields',
					array(
						'url' => $url,
					)
				)
			),
		);

		// Request the CSS from criticalcss.com.
		return wp_remote_post( $api_url, $args );
	}

	/**
	 * Send a GET request to the CriticalCSS API to retrieve the CSS.
	 *
	 * @param string $job_id The job ID retrieved from the previous /generate request.
	 */
	protected function get_results( $job_id ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$api_url = 'https://criticalcss.com/api/premium/results';

		$key  = $this->get_option( 'critical_css_key' );
		$args = array(
			'timeout'   => 20,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false, $api_url ),
			'headers'   => array(
				'Authorization' => "JWT $key",
				'Content-type'  => 'application/json',
				'User-Agent'    => 'SWIS Performance ' . SWIS_PLUGIN_VERSION,
			),
		);

		// Request the CSS from criticalcss.com.
		return wp_remote_get( $api_url . '?resultId=' . $job_id, $args );
	}

	/**
	 * Get the total number of stored critical CSS files from the database.
	 *
	 * @return int The number of records found.
	 */
	function get_critical_css_count() {
		if ( ! \is_dir( $this->cache_dir ) || ! $this->get_dir_objects( $this->cache_dir ) ) {
			return 0;
		}
		return $this->get_cache_count( $this->cache_dir, 'critical.css' );
	}

	/**
	 * Store critical CSS results to the db for quick reference.
	 *
	 * @param array  $page Details of the page, including the URL and type.
	 * @param string $valid The validation status, indicating the quality of the CSS.
	 */
	protected function update_ccss_record( $page, $valid ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		global $wpdb;

		// First check if the URL is in the db.
		$exists  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->swis_critical_css WHERE page_url = %s", $page['page_url'] ), ARRAY_A );
		$updates = array(
			'type'              => $page['params']['type'],
			'result_id'         => $page['params']['id'],
			'validation_status' => $valid,
		);
		// Store info on the current image for future reference.
		if ( $this->is_iterable( $exists ) && $this->is_iterable( $exists[0] ) && ! empty( $exists[0]['id'] ) ) {
			$exists = $exists[0];
			$this->debug_message( "updating existing record ({$exists['id']}) for {$page['page_url']}: $valid" );
			// Update information for the image.
			$updated = $wpdb->update(
				$wpdb->swis_critical_css,
				$updates,
				array(
					'id' => $exists['id'],
				)
			);
			if ( false === $updated && ! empty( $wpdb->last_error ) ) {
				$this->debug_message( 'db error: ' . $wpdb->last_error );
			} elseif ( false === $updated && empty( $wpdb->last_error ) ) {
				$this->debug_message( 'unknown db error' );
			} else {
				$this->debug_message( "updated $updated records successfully" );
			}
		} else {
			$this->debug_message( "creating new record for {$page['page_url']}: $valid" );
			$updates['page_url'] = $page['page_url'];
			$wpdb->insert( $wpdb->swis_critical_css, $updates );
		} // End if().
	}

	/**
	 * Remove critical CSS result from the db, in case of 404 or stale records.
	 *
	 * @param string $page_url The URL of the page.
	 */
	protected function delete_ccss_record( $page_url ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		global $wpdb;

		$this->debug_message( "removing db record for $page_url" );
		// First check if the URL is in the db.
		$wpdb->delete(
			$wpdb->swis_critical_css,
			array(
				'page_url' => $page_url,
			),
			array( '%s' )
		);
	}

	/**
	 * Store the critical CSS to disk for a page.
	 *
	 * @param array  $page Details of the page, including the URL and type.
	 * @param string $css The CSS code retrieved from the API.
	 * @param string $valid The validation status, indicating the quality of the CSS.
	 * @param string $result The result status, usually GOOD, but could be HTML_404.
	 */
	protected function store_page_ccss( $page, $css, $valid = '', $result = '' ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		if ( 'HTML_404' === $result && ! empty( $page['page_url'] ) ) {
			if ( 'is_404' !== $page['params']['type'] ) {
				$this->delete_ccss_record( $page['page_url'] );
			}
			return;
		}
		if (
			! empty( $page['params']['type'] ) &&
			(
				'is_404' === $page['params']['type'] ||
				'is_search' === $page['params']['type'] ||
				'is_login' === $page['params']['type']
			)
		) {
			return;
		}

		// For pages only, insert/update records in the db.
		if ( $valid ) {
			$this->update_ccss_record( $page, $valid );
		}

		$cache_file_dir = $this->get_cache_file_dir( $page['page_url'] );
		if ( ! $cache_file_dir ) {
			$this->debug_message( 'no dir to store CSS' );
			return;
		}
		$this->debug_message( "checking if $cache_file_dir exists" );
		if ( ! \is_dir( $cache_file_dir ) ) {
			if ( ! \wp_mkdir_p( $cache_file_dir ) ) {
				$this->debug_message( "could not create $cache_file_dir" );
				return;
			}
		}
		$cache_file_path  = $cache_file_dir . '/critical.css';
		$status_file_path = $cache_file_dir . '/critical.status';
		\file_put_contents( $cache_file_path, $css );
		if ( $valid ) {
			\file_put_contents( $status_file_path, $valid );
		} elseif ( $this->is_file( $status_file_path ) && \is_writable( $status_file_path ) ) {
			\unlink( $status_file_path );
		}
		$this->debug_message( "wrote CSS to $cache_file_dir" );
	}

	/**
	 * Store the critical CSS to disk for a template/tag.
	 *
	 * @param array  $page Details of the page, including the URL and type.
	 * @param string $css The CSS code retrieved from the API.
	 * @param string $valid The validation status, indicating the quality of the CSS.
	 * @param string $result The result status, usually GOOD, but could be HTML_404.
	 */
	protected function store_template_ccss( $page, $css, $valid = '', $result = '' ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		if ( empty( $page['params']['type'] ) ) {
			return;
		}

		if ( 'HTML_404' === $result && 'is_404' !== $page['params']['type'] ) {
			return;
		}

		$cache_file_dir = $this->get_template_cache_file_dir( $page['params']['type'], $page['page_url'] );
		if ( ! $cache_file_dir ) {
			$this->debug_message( 'no dir to store template CSS' );
			return;
		}
		$this->debug_message( "checking if $cache_file_dir exists" );
		if ( ! \is_dir( $cache_file_dir ) ) {
			if ( ! \wp_mkdir_p( $cache_file_dir ) ) {
				$this->debug_message( "could not create $cache_file_dir" );
				return;
			}
		}
		$cache_file_path  = $cache_file_dir . '/critical.css';
		$status_file_path = $cache_file_dir . '/critical.status';
		if ( ! $this->is_file( $cache_file_path ) ) {
			\file_put_contents( $cache_file_path, $css );
			if ( $valid ) {
				\file_put_contents( $status_file_path, $valid );
			} elseif ( $this->is_file( $status_file_path ) && \is_writable( $status_file_path ) ) {
				\unlink( $status_file_path );
			}
		}
		if ( 'is_front_page' === $page['params']['type'] && 'posts' === \get_option( 'show_on_front' ) ) {
			$page['params']['types'][] = 'is_home';
			$this->debug_message( 'storing CSS in is_home also' );
		}
		if ( ! empty( $page['params']['types'] ) ) {
			foreach ( $page['params']['types'] as $index => $extra_type ) {
				if ( $page['params']['type'] === $extra_type ) {
					// For to prevent infinite recursion.
					unset( $page['params']['types'][ $index ] );
					continue;
				}
				$page['params']['type'] = $extra_type;
				$this->store_template_ccss( $page, $css, $valid, $result );
			}
		}
	}

	/**
	 * Store the critical CSS to disk.
	 *
	 * @param array  $page Details of the page, including the URL and type.
	 * @param string $css The CSS code retrieved from the API.
	 * @param string $valid The validation status, indicating the quality of the CSS.
	 * @param string $result The result status, usually GOOD, but could be HTML_404.
	 */
	protected function store_ccss( $page, $css, $valid, $result ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );

		// All others will be written to disk and used if not bypassed by filter.
		if ( 'ERROR' === $valid ) {
			return;
		}
		if ( empty( $page['page_url'] ) ) {
			return;
		}

		$css = $this->sanitize_css( $css );
		if ( empty( $css ) ) {
			$this->debug_message( 'critical CSS was invalid/unsafe' );
			return;
		}

		$this->store_page_ccss( $page, $css, $valid, $result );
		$this->store_template_ccss( $page, $css, $valid, $result );
	}
}
