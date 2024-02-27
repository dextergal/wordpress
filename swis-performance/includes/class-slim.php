<?php
/**
 * Class and methods to eliminate unused JS/CSS.
 *
 * @link https://ewww.io/swis/
 * @package SWIS_Performance
 */

namespace SWIS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enables plugin to Lazy Load images.
 */
final class Slim extends Base {

	/**
	 * The list of JS/CSS assets and associated information.
	 *
	 * @var array
	 */
	private $assets = array();

	/**
	 * The list of active plugin data.
	 *
	 * @var array
	 */
	private $active_plugins = array();

	/**
	 * The type of the current page.
	 *
	 * @var array
	 */
	private $content_type = '';

	/**
	 * A list of registered content types.
	 *
	 * @var array
	 */
	private $content_types = array();

	/**
	 * The URL path to the home page.
	 *
	 * @var string
	 */
	private $home_path = '';

	/**
	 * Store asset dependencies.
	 *
	 * @var array
	 */
	private $deps = array();

	/**
	 * A list of user-defined exclusions, populated by validate_user_exclusions().
	 *
	 * @access protected
	 * @var array $user_exclusions
	 */
	public $user_exclusions = array();

	/**
	 * CSS/JS that should not ever be excluded.
	 *
	 * @var array
	 */
	private $whitelist = array( 'admin-bar', 'swis-performance-slim' );

	/**
	 * Register actions and filters for JS/CSS Slim.
	 */
	function __construct() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		parent::__construct();
		if ( ! is_admin() ) {
			$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
			if ( current_user_can( $permissions ) && ( ! defined( 'SWIS_SLIM_DISABLE_FRONTEND_MENU' ) || ! SWIS_SLIM_DISABLE_FRONTEND_MENU ) ) {
				add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu_item' ), 100 );
				add_action( 'wp_head', array( $this, 'dash_css' ) );
				add_action( 'wp_head', array( $this, 'find_assets' ), 9999 );
				add_action( 'wp_enqueue_scripts', array( $this, 'frontend_script' ) );
				add_action( 'wp_footer', array( $this, 'find_assets' ), 9999 );
				add_action( 'wp_footer', array( $this, 'dash_script' ), 9999 );
				add_action( 'wp_footer', array( $this, 'display_assets' ), 10000 );
			}
			if ( $this->get_option( 'slim_js_css' ) || $this->get_option( 'optimize_fonts_list' ) ) {
				add_action( 'template_redirect', array( $this, 'get_content_type' ) );
				add_action( 'template_redirect', array( $this, 'maybe_remove_emoji' ), 11 );
				add_filter( 'script_loader_src', array( $this, 'disable_assets' ), 10, 2 );
				add_filter( 'style_loader_src', array( $this, 'disable_assets' ), 10, 2 );
				$this->validate_user_exclusions();
			}
		}
		add_action( 'wp_ajax_swis_slim_rule_edit', array( $this, 'edit_rule' ) );
	}

	/**
	 * Adds the Slim menu item to the wp admin bar.
	 *
	 * @param object $wp_admin_bar The WP Admin Bar object, passed by reference.
	 */
	function add_admin_bar_menu_item( $wp_admin_bar ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if (
			! current_user_can( $permissions ) ||
			! is_admin_bar_showing() ||
			! $this->is_frontend()
		) {
			return;
		}
		if ( defined( 'SWIS_SLIM_DISABLE_FRONTEND_MENU' ) && SWIS_SLIM_DISABLE_FRONTEND_MENU ) {
			return;
		}
		$wp_admin_bar->add_node(
			array(
				'id'     => 'swis-slim',
				'parent' => 'swis',
				'title'  => '<span id="swis-slim-show"><span class="ab-item">' . __( 'Manage JS/CSS', 'swis-performance' ) . '</span></span>',
			)
		);
	}

	/**
	 * Enqueue JS needed for the front-end assets pane.
	 */
	function frontend_script() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		wp_enqueue_script( 'swis-performance-slim', plugins_url( '/assets/slim.js', SWIS_PLUGIN_FILE ), array( 'jquery-core' ), SWIS_PLUGIN_VERSION, true );
		wp_localize_script(
			'swis-performance-slim',
			'swisperformance_vars',
			array(
				'ajaxurl'          => admin_url( 'admin-ajax.php' ),
				'_wpnonce'         => wp_create_nonce( 'swis-performance-settings' ),
				'invalid_response' => esc_html__( 'Received an invalid response from your website, please check for errors in the Developer Tools console of your browser.', 'swis-performance' ),
				'remove_rule'      => esc_html__( 'Are you sure you want to remove this rule?', 'swis-performance' ),
				'removing_message' => esc_html__( 'Deleting...', 'swis-performance' ),
				'saving_message'   => esc_html__( 'Saving...', 'swis-performance' ),
			)
		);
	}

	/**
	 * Adds some dashicon CSS for our admin bar item.
	 */
	function dash_css() {
		if ( ! $this->is_frontend() ) {
			return;
		}
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		$slim_css = file_get_contents( SWIS_PLUGIN_PATH . 'assets/swis.css' );
		?>
		<style>
		<?php echo $slim_css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		#wpadminbar #swis-slim-show-top, #wpadminbar #swis-slim-show {
			cursor: pointer;
		}
		#wpadminbar #wp-admin-bar-swis .ab-icon:before {
			content: '\f198';
			top: 2px;
		}
		#swis-slim-close-pane {
			/*border: 1px solid #aaa;
			color: #6d6d6d;
			cursor: pointer;*/
			float: right;
			font-size: 16px;
			/*padding: 5px;*/
		}
		#swis-slim-close-pane:hover {
			/*border: 1px solid #000;
			color: #000;*/
		}
		#swis-slim-assets-pane {
			background-color: #fff;
			color: #000;
			font-family: sans-serif;
			padding: 15px;
		}
		#swis-slim-assets-pane .slim-main-heading {
			font-family: sans-serif;
			font-size: 36px;
			font-weight: 800;
		}
		#swis-slim-assets-pane .slim-section-heading {
			font-family: sans-serif;
			font-size: 30px;
			font-weight: 700;
		}
		.swis-slim-unscroll {
			overflow: hidden;
		}
		.swis-slim-visible {
			display: block;
			position: fixed;
			top: 32px;
			left: 0;
			bottom: 0;
			overflow: scroll;
			width: 100%;
			height: calc(100% - 32px);
			z-index: 99999;
		}
		.swis-slim-assets {
			border: 1px solid #ddd;
			border-collapse: collapse;
			font-family: sans-serif;
			width: 100%;
		}
		.swis-slim-assets th {
			font-weight: 700;
		}
		.swis-slim-assets th, .swis-slim-assets td {
			border: 1px solid #ddd;
			font-family: sans-serif;
			font-size: 16px;
			padding: 8px;
		}
		.swis-slim-asset-active {
			width: 75px;
		}
		.swis-slim-asset-size {
			width: 100px;
		}
		.swis-slim-active-yes {
			font-weight: bolder;
			color: green;
		}
		.swis-slim-active-no {
			font-weight: bolder;
			color: red;
		}
		@media screen and (max-width: 782px) {
			#wpadminbar li#wp-admin-bar-swis-slim{
				display: block!important;
			}
		}
		</style>
		<?php
	}

	/**
	 * Adds the script for our admin bar action.
	 */
	function dash_script() {
		if ( ! $this->is_frontend() ) {
			return;
		}
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		?>
		<script>
		function toggleSWISPane() {
			var SWISPane = document.getElementById('swis-slim-assets-pane');
			SWISPane.classList.toggle('swis-slim-hidden');
			SWISPane.classList.toggle('swis-slim-visible');
			document.body.classList.toggle('swis-slim-unscroll');
		}
		function addSWISClickers() {
			document.getElementById('swis-slim-show-top').addEventListener('click', toggleSWISPane);
			document.getElementById('swis-slim-show').addEventListener('click', toggleSWISPane);
			document.getElementById('swis-slim-close-pane').addEventListener('click', toggleSWISPane);
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', addSWISClickers);
		} else {
			addSWISClickers();
		}
		</script>
		<?php
	}

	/**
	 * Handle a rule update via AJAX. Possible actions are "create", "update", and "delete".
	 *
	 * On success, returns the updated HTML for the rule to display, an error message otherwise.
	 */
	function edit_rule() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( empty( $_REQUEST['swis_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_REQUEST['swis_wpnonce'] ), 'swis-performance-settings' ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Access token has expired, please reload the page.', 'swis-performance' ) ) ) );
		}
		if ( empty( $_POST['swis_slim_action'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Invalid operation requested.', 'swis-performance' ) ) ) );
		}
		if ( empty( $_POST['swis_slim_handle'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'No handle provided.', 'swis-performance' ) ) ) );
		}
		$output = '';
		$status = '';
		$action = sanitize_text_field( wp_unslash( $_POST['swis_slim_action'] ) );
		$mode   = '';
		if ( ! empty( $_POST['swis_slim_exclusions'] ) && ! empty( $_POST['swis_slim_mode'] ) && 'all' === $_POST['swis_slim_mode'] && empty( $_POST['swis_slim_frontend'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Please select an option.', 'swis-performance' ) ) ) );
		}
		if ( ! empty( $_POST['swis_slim_exclusions'] ) && empty( $_POST['swis_slim_mode'] ) && ! empty( $_POST['swis_slim_frontend'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Please select an option.', 'swis-performance' ) ) ) );
		}
		if ( ! empty( $_POST['swis_slim_current_page'] ) ) {
			$this->current_page = trim( sanitize_text_field( wp_unslash( $_POST['swis_slim_current_page'] ) ) );
		}
		if ( ! empty( $_POST['swis_slim_exclusions'] ) && ! empty( $_POST['swis_slim_mode'] ) ) {
			if ( 'include' === $_POST['swis_slim_mode'] ) {
				$mode = '+';
			}
			if ( 'exclude' === $_POST['swis_slim_mode'] ) {
				$mode = '-';
			}
		}
		$this->validate_user_exclusions();
		$user_exclusions = $this->get_option( 'slim_js_css' );
		switch ( $action ) {
			case 'create':
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$exclusions = ! empty( $_POST['swis_slim_exclusions'] ) && $mode ? swis()->settings->sanitize_textarea_exclusions( wp_unslash( $_POST['swis_slim_exclusions'] ), false ) : false;
				$handle     = sanitize_text_field( wp_unslash( $_POST['swis_slim_handle'] ) );
				$new_rule   = ( $exclusions && ! empty( $exclusions[0] ) ? $mode . $exclusions[0] . ':' : '' ) . $handle;
				if ( $this->is_iterable( $this->user_exclusions ) && isset( $this->user_exclusions[ $handle ] ) ) {
					die( wp_json_encode( array( 'error' => esc_html__( 'A rule already exists for that handle, edit the existing rule or remove it before adding a new rule.', 'swis-performance' ) ) ) );
				}
				$this->debug_message( "adding $new_rule to:" );
				if ( $this->function_exists( 'print_r' ) ) {
					$this->debug_message( print_r( $user_exclusions, true ) );
				}
				if ( empty( $user_exclusions ) ) {
					$this->debug_message( 'adding as only rule' );
					$user_exclusions = array( $new_rule );
				} elseif ( is_array( $user_exclusions ) && 1 === count( $user_exclusions ) && empty( $user_exclusions[0] ) ) {
					$this->debug_message( 'adding as only rule (because the existing one is empty)' );
					$user_exclusions = array( $new_rule );
				} else {
					$this->debug_message( 'adding to existing rules' );
					$user_exclusions[] = $new_rule;
				}
				$this->debug_message( 'now slim exclusions are:' );
				if ( $this->function_exists( 'print_r' ) ) {
					$this->debug_message( print_r( $user_exclusions, true ) );
				}
				$result = $this->set_option( 'slim_js_css', $user_exclusions );
				if ( ! $result ) {
					die( wp_json_encode( array( 'error' => esc_html__( 'Unable to save rule.', 'swis-performance' ) ) ) );
				}
				$output = $this->get_rule_html( $handle, $this->parse_slim_rule( $new_rule ) );
				$status = $this->asset_disabled( '', $handle ) ? "<span class='swis-slim-active-no'>" . esc_html__( 'No', 'swis-performance' ) . '</span>' : "<span class='swis-slim-active-yes'>" . esc_html__( 'Yes', 'swis-performance' ) . '</span>';
				break;
			case 'update':
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$exclusions = ! empty( $_POST['swis_slim_exclusions'] ) ? swis()->settings->sanitize_textarea_exclusions( wp_unslash( $_POST['swis_slim_exclusions'] ), false ) : false;
				$handle     = sanitize_text_field( wp_unslash( $_POST['swis_slim_handle'] ) );
				$new_rule   = ( $exclusions && ! empty( $exclusions[0] ) ? $mode . $exclusions[0] . ':' : '' ) . $handle;
				if ( ! $this->is_iterable( $this->user_exclusions ) || ! isset( $this->user_exclusions[ $handle ] ) ) {
					die(
						wp_json_encode(
							array(
								'error' => sprintf(
									/* translators: %s: registered handle for a JS/CSS resource */
									esc_html__( 'Could not find a match for %s to update.', 'swis-performance' ),
									esc_html( $handle )
								),
							)
						)
					);
				}
				foreach ( $user_exclusions as $index => $user_exclusion ) {
					$parsed_rule = $this->parse_slim_rule( $user_exclusion );
					if ( ! empty( $parsed_rule['handle'] ) && $handle === $parsed_rule['handle'] ) {
						$user_exclusions[ $index ] = $new_rule;
					}
				}
				if ( $this->function_exists( 'print_r' ) ) {
					$this->debug_message( print_r( $user_exclusions, true ) );
				}
				$this->set_option( 'slim_js_css', $user_exclusions );
				$output = $this->get_rule_html( $handle, $this->parse_slim_rule( $new_rule ) );
				$status = $this->asset_disabled( '', $handle ) ? "<span class='swis-slim-active-no'>" . esc_html__( 'No', 'swis-performance' ) . '</span>' : "<span class='swis-slim-active-yes'>" . esc_html__( 'Yes', 'swis-performance' ) . '</span>';
				break;
			case 'delete':
				$handle = sanitize_text_field( wp_unslash( $_POST['swis_slim_handle'] ) );
				if ( ! $this->is_iterable( $this->user_exclusions ) || ! isset( $this->user_exclusions[ $handle ] ) ) {
					die(
						wp_json_encode(
							array(
								'error' => sprintf(
									/* translators: %s: registered handle for a JS/CSS resource */
									esc_html__( 'Could not find a match for %s to remove.', 'swis-performance' ),
									esc_html( $handle )
								),
							)
						)
					);
				}
				foreach ( $user_exclusions as $index => $user_exclusion ) {
					$parsed_rule = $this->parse_slim_rule( $user_exclusion );
					if ( ! empty( $parsed_rule['handle'] ) && $handle === $parsed_rule['handle'] ) {
						unset( $user_exclusions[ $index ] );
					}
				}
				$this->set_option( 'slim_js_css', $user_exclusions );
				$output = $this->get_rule_html( $handle, array() );
				$status = "<span class='swis-slim-active-yes'>" . esc_html__( 'Yes', 'swis-performance' ) . '</span>';
				break;
			default:
				die( wp_json_encode( array( 'error' => esc_html__( 'Unknown operation requested.', 'swis-performance' ) ) ) );
		}
		die(
			wp_json_encode(
				array(
					'success' => 1,
					'message' => $output,
					'status'  => $status,
				)
			)
		);
	}

	/**
	 * Retrieve the HTML for a given rule.
	 *
	 * @param string $handle The CSS/JS handle.
	 * @param array  $rule Exclusions and inclusions for the given $handle.
	 * @return string The HTML produced for Slim rule.
	 */
	private function get_rule_html( $handle, $rule ) {
		ob_start();
		// Nonce verification has already happened before we get here.
		if ( empty( $_POST['swis_slim_frontend'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( empty( $rule ) ) {
				return '';
			}
			$this->display_backend_rule( $handle, $rule );
		} else {
			$this->display_frontend_rule_form( $handle, $rule );
		}
		return trim( ob_get_clean() );
	}

	/**
	 * Display the HTML for a given rule on the settings.
	 *
	 * @param string $handle The CSS/JS handle.
	 * @param array  $rule Exclusions and inclusions for the given $handle.
	 */
	private function display_backend_rule( $handle, $rule ) {
		$rule_id = preg_replace( '/[\W_]/', '', uniqid( '', true ) );
		?>
		<div id="swis-slim-rule-<?php echo esc_attr( $rule_id ); ?>" class="swis-slim-rule" data-slim-handle="<?php echo esc_attr( $handle ); ?>" data-slim-rule-id="<?php echo esc_attr( $rule_id ); ?>">
		<?php if ( $rule['include'] ) : ?>
			<?php
			$includes = array();
			foreach ( $rule['include'] as $include ) {
				if ( 0 === strpos( $include, 'T>' ) ) {
					$includes[] = '<i>' . substr( $include, 2 ) . '</i> ' . esc_html__( 'content type', 'swis-performance' );
				} elseif ( '<home_page>' === $include ) {
					$includes[] = esc_html__( 'home page', 'swis-performance' );
				} else {
					$includes[] = $include;
				}
			}
			$raw_rule_parts = explode( ':', $rule['raw'] );
			$raw_rule_html  = ltrim( $raw_rule_parts[0], '+-' );
			?>
			<div class="swis-slim-rule-description">
				<?php /* translators: %s: registered handle for a JS/CSS resource */ ?>
				<?php printf( esc_html__( '%s disabled everywhere except:', 'swis-performance' ), '<strong>' . esc_html( $handle ) . '</strong>' ); ?>
				<input style="display:none;" type="radio" name="swis_slim_mode_<?php echo esc_attr( $rule_id ); ?>" value="include" checked />
				<div class="swis-slim-pretty-rule">
					<?php echo wp_kses_post( implode( ', ', $includes ) ); ?>
				</div>
				<div class="swis-slim-error-message"></div>
				<div class="swis-slim-hidden">
					<div class="swis-slim-raw-rule">
						<input type="text" name="swis-slim-rule-exclusions-<?php echo esc_attr( $rule_id ); ?>" value="<?php echo esc_attr( $raw_rule_html ); ?>" />
						<button type="button" class="button-primary swis-slim-rule-save"><?php esc_html_e( 'Save', 'swis-performance' ); ?></button>
					</div>
					<p class="swis-slim-edit-rule-description description">
						<?php esc_html_e( 'Enter a comma-separated list of pages, URL patterns (use * as wildcard), or content types in the form T>post or T>page.', 'swis-performance' ); ?>
					</p>
				</div>
			</div>
			<div class="swis-slim-rule-actions">
				<button type="button" class="button-link button-link-edit"><?php esc_html_e( 'Edit', 'swis-performance' ); ?></button>
				|
				<button type="button" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'swis-performance' ); ?></button>
			</div>
		<?php elseif ( $rule['exclude'] ) : ?>
			<?php
			$excludes = array();
			foreach ( $rule['exclude'] as $exclude ) {
				if ( 0 === strpos( $exclude, 'T>' ) ) {
					$excludes[] = '<i>' . substr( $exclude, 2 ) . '</i> ' . esc_html__( 'content type', 'swis-performance' );
				} elseif ( '<home_page>' === $exclude ) {
					$excludes[] = esc_html__( 'home page', 'swis-performance' );
				} else {
					$excludes[] = $exclude;
				}
			}
			$raw_rule_parts = explode( ':', $rule['raw'] );
			$raw_rule_html  = ltrim( $raw_rule_parts[0], '+-' );
			?>
			<div class="swis-slim-rule-description">
				<?php /* translators: %s: A JS/CSS handle, like 'jquery-form' */ ?>
				<?php printf( esc_html__( '%s disabled on:', 'swis-performance' ), '<strong>' . esc_html( $handle ) . '</strong>' ); ?><br>
				<input style="display:none;" type="radio" name="swis_slim_mode_<?php echo esc_attr( $rule_id ); ?>" value="exclude" checked />
				<div class="swis-slim-pretty-rule">
					<?php echo wp_kses_post( implode( ', ', $excludes ) ); ?>
				</div>
				<div class="swis-slim-error-message"></div>
				<div class="swis-slim-hidden">
					<div class="swis-slim-raw-rule">
						<input type="text" name="swis-slim-rule-exclusions-<?php echo esc_attr( $rule_id ); ?>" value="<?php echo esc_attr( $raw_rule_html ); ?>" />
						<button type="button" class="button-primary swis-slim-rule-save"><?php esc_html_e( 'Save', 'swis-performance' ); ?></button>
					</div>
					<p class="swis-slim-edit-rule-description description">
						<?php esc_html_e( 'Enter a comma-separated list of pages, URL patterns (use * as wildcard), or content types in the form T>post or T>page.', 'swis-performance' ); ?>
					</p>
				</div>
			</div>
			<div class="swis-slim-rule-actions">
				<button type="button" class="button-link button-link-edit"><?php esc_html_e( 'Edit', 'swis-performance' ); ?></button>
				|
				<button type="button" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'swis-performance' ); ?></button>
			</div>
		<?php else : ?>
			<div class="swis-slim-rule-description">
				<?php /* translators: %s: A JS/CSS handle, like 'jquery-form' */ ?>
				<?php printf( esc_html__( '%s disabled everywhere', 'swis-performance' ), '<strong>' . esc_html( $handle ) . '</strong>' ); ?>
				<input style="display:none;" type="radio" name="swis_slim_mode_<?php echo esc_attr( $rule_id ); ?>" value="all" checked />
				<div class="swis-slim-error-message"></div>
				<div class="swis-slim-hidden">
					<div class="swis-slim-column">
						<div class="swis-slim-row">
							<input type="radio" id="swis_slim_mode_include_<?php echo esc_attr( $rule_id ); ?>" class="swis-slim-radio" name="swis_slim_mode_<?php echo esc_attr( $rule_id ); ?>" value="include" />
							<strong><label for="swis_slim_mode_include_<?php echo esc_attr( $rule_id ); ?>"><?php esc_html_e( 'disable everywhere except:', 'swis-performance' ); ?></label></strong>
						</div>
						<div class="swis-slim-row">
							<input type="radio" id="swis_slim_mode_exclude_<?php echo esc_attr( $rule_id ); ?>" class="swis-slim-radio" name="swis_slim_mode_<?php echo esc_attr( $rule_id ); ?>" value="exclude" />
							<strong><label for="swis_slim_mode_exclude_<?php echo esc_attr( $rule_id ); ?>"><?php esc_html_e( 'disable on:', 'swis-performance' ); ?></label></strong>
						</div>
						<div class="swis-slim-raw-rule">
							<input type="text" name="swis-slim-rule-exclusions-<?php echo esc_attr( $rule_id ); ?>" value="" />
							<button type="button" class="button-primary swis-slim-rule-save"><?php esc_html_e( 'Save', 'swis-performance' ); ?></button>
						</div>
						<p class="swis-slim-edit-rule-description description">
							<?php esc_html_e( 'Enter a comma-separated list of pages, URL patterns (use * as wildcard), or content types in the form T>post or T>page.', 'swis-performance' ); ?>
						</p>
					</div>
				</div>
			</div>
			<div class="swis-slim-rule-actions">
				<button type="button" class="button-link button-link-edit"><?php esc_html_e( 'Add Exclusion', 'swis-performance' ); ?></button>
				|
				<button type="button" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'swis-performance' ); ?></button>
			</div>
		<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Display existing rules on the settings.
	 */
	public function display_backend_rules() {
		$this->validate_user_exclusions();
		if ( empty( $this->user_exclusions ) ) {
			return;
		}
		foreach ( $this->user_exclusions as $handle => $rule ) {
			$this->display_backend_rule( $handle, $rule );
		}
	}

	/**
	 * Add more exclusions from third-party code.
	 *
	 * @param string $rule A handle or rule using our SLIM syntax.
	 */
	public function add_exclusion( $rule ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( is_string( $rule ) ) {
			$this->parse_slim_rule( $rule );
		}
	}

	/**
	 * Validate the user-defined rules.
	 */
	function validate_user_exclusions() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$user_exclusions = $this->get_option( 'slim_js_css' );
		if ( ! empty( $user_exclusions ) ) {
			if ( is_string( $user_exclusions ) ) {
				$user_exclusions = array( $user_exclusions );
			}
			if ( is_array( $user_exclusions ) ) {
				foreach ( $user_exclusions as $exclusion ) {
					if ( ! is_string( $exclusion ) ) {
						continue;
					}
					$this->parse_slim_rule( $exclusion );
				}
			}
		}
	}

	/**
	 * Parse a user-supplied slim rule into an array and append to $this->user_exclusions.
	 *
	 * @param string $rule The user-supplied rule.
	 * @return array The parsed array-style version of the rule.
	 */
	function parse_slim_rule( $rule ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! $this->home_path ) {
			$this->home_path = $this->parse_url( trailingslashit( get_home_url() ), PHP_URL_PATH );
			$this->debug_message( 'the home path to match is ' . $this->home_path );
		}
		$rule = trim( str_replace( '\\', '/', $rule ) );
		$raw  = $rule;
		if ( 0 === strpos( $rule, '+' ) || 0 === strpos( $rule, '-' ) ) {
			$type = substr( $rule, 0, 1 );
			$rule = ltrim( $rule, '+' );
			$rule = ltrim( $rule, '-' );
			if ( strpos( $rule, ':' ) ) {
				$parts = explode( ':', $rule );
				if ( empty( $parts[0] ) || empty( $parts[1] ) ) {
					return;
				}
				$handle = $parts[1];
				$except = explode( ',', trim( $parts[0] ) );
				if ( $this->is_iterable( $except ) ) {
					foreach ( $except as $key => $page ) {
						$page = trim( $page );
						$this->debug_message( "comparing $page to home_path" );
						if ( $page === $this->home_path ) {
							$except[ $key ] = '<home_page>';
						}
					}
				}

				$this->user_exclusions[ $handle ] = array(
					'handle'  => $handle,
					'include' => '-' !== $type ? $except : array(),
					'exclude' => '-' === $type ? $except : array(),
					'raw'     => $raw,
				);
				return $this->user_exclusions[ $handle ];
			}
		} elseif ( false === strpos( $rule, ':' ) ) {
			// Found an "exclude everywhere" rule.
			$this->user_exclusions[ $rule ] = array(
				'handle'  => $rule,
				'include' => array(),
				'exclude' => array(),
				/* translators: %s: A JS/CSS handle, like 'jquery-form' */
				'raw'     => sprintf( __( '%s disabled everywhere', 'swis-performance' ), $rule ),
			);
			return $this->user_exclusions[ $rule ];
		}
	}

	/**
	 * Check if an asset is from an external site.
	 *
	 * @param string $url The asset URL.
	 * @return bool True for external asset, false for local asset.
	 */
	function is_external( $url ) {
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			return false;
		}
		$asset_url_parts = $this->parse_url( $url );
		$local_url_parts = $this->parse_url( get_site_url() );
		if ( ! empty( $asset_url_parts['host'] ) && ! empty( $local_url_parts['host'] ) && 0 === strcasecmp( $asset_url_parts['host'], $local_url_parts['host'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Check size of asset.
	 *
	 * @param string $url The asset URL.
	 * @return string A human-readable size.
	 */
	function get_asset_size( $url ) {
		$size     = '';
		$url_bits = explode( '?', $url );

		$asset_path = ABSPATH . str_replace( get_site_url(), '', $this->prepend_url_scheme( $url_bits[0] ) );

		if ( $url !== $asset_path && is_file( $asset_path ) ) {
			$size = size_format( filesize( $asset_path ), 1 );
		}
		return $size;
	}

	/**
	 * Check to see which JS/CSS files have been registered for the current page.
	 */
	function find_assets() {
		if ( ! $this->is_frontend() ) {
			return;
		}
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$assets = array(
			'js'  => wp_scripts(),
			'css' => wp_styles(),
		);

		$core_url    = ! empty( $assets['js']->default_dirs[1] ) ? dirname( $assets['js']->default_dirs[1] ) : dirname( $assets['js']->default_dirs[0] );
		$plugins_url = plugins_url();
		$theme_url   = get_theme_root_uri();
		$this->debug_message( $core_url );
		$this->debug_message( $plugins_url );
		$this->debug_message( $theme_url );

		foreach ( $assets as $type => $data ) {
			foreach ( $data->done as $handle ) {
				if ( ! in_array( $handle, $this->whitelist, true ) && ! empty( $data->registered[ $handle ] ) ) {
					$url = $this->prepend_url_scheme( $data->registered[ $handle ]->src );
					if ( false !== strpos( $url, $plugins_url ) ) {
						$asset_source_type = 'plugins';

						// Get the plugin folder name.
						$plugin_path = ltrim( str_replace( $plugins_url, '', $url ), '/' );
						$plugin_path = explode( '/', $plugin_path );
						$plugin_dir  = $plugin_path[0];
					} elseif ( false !== strpos( $url, $theme_url ) ) {
						$asset_source_type = 'theme';
					} elseif ( false !== strpos( $url, $core_url ) || 'jquery' === $handle ) {
						$asset_source_type = 'core';
					} else {
						$asset_source_type = 'misc';
					}

					$url_info = pathinfo( $url );
					$asset    = array(
						'url'      => $url,
						'external' => (int) $this->is_external( $url ),
						'filename' => ! empty( $url_info['basename'] ) ? $url_info['basename'] : $url,
						'size'     => $this->get_asset_size( $url ),
						'disabled' => (int) $this->asset_disabled( $type, $handle ),
						'deps'     => isset( $data->registered[ $handle ]->deps ) ? $data->registered[ $handle ]->deps : array(),
					);
					if ( 'plugins' === $asset_source_type ) {
						$this->assets[ $asset_source_type ][ $plugin_dir ][ $type ][ $handle ] = $asset;
					} else {
						$this->assets[ $asset_source_type ][ $type ][ $handle ] = $asset;
					}
					$this->deps[] = array(
						'name' => $handle,
						'deps' => $asset['deps'],
						'type' => $type,
					);
				}
			}
		}
		global $wp_version;
		if ( version_compare( $wp_version, '4.2', '>=' ) ) {
			$url = '/wp-includes/js/wp-emoji-release.min.js';

			$this->assets['core']['js']['wp-emoji'] = array(
				'url'      => $url,
				'external' => false,
				'filename' => 'wp-emoji-release.min.js',
				'size'     => $this->get_asset_size( $url ),
				'disabled' => (int) $this->asset_disabled( 'js', 'wp-emoji' ),
				'deps'     => array(),
			);
		}
	}

	/**
	 * Make sure protocol-relative URLs like //www.example.com/wp-includes/script.js get a scheme added.
	 *
	 * @param string $url The URL to potentially fix.
	 * @return string The properly-schemed URL.
	 */
	function prepend_url_scheme( $url ) {
		if ( 0 === strpos( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http' ) . $url;
		}
		return $url;
	}

	/**
	 * Get registered content types.
	 */
	function get_content_types() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$this->content_types = get_post_types( array( 'public' => true ) );
	}

	/**
	 * Check the content type of the current page.
	 */
	function get_content_type() {
		if ( is_singular() ) {
			$this->content_type = get_post_type();
		}
	}

	/**
	 * See if the current content type matches a content type rule.
	 *
	 * @param string $rule A content-type rule (prefixed with T>).
	 * @return bool True if the current type matches the rule, false otherwise.
	 */
	function check_content_type( $rule ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( 0 === strpos( $rule, 'T>' ) ) {
			$rule_content_type = substr( $rule, 2 );
			$this->debug_message( "found rule content type: $rule_content_type" );
			if ( $rule_content_type === $this->content_type ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if the user has disabled an asset for this particular page.
	 *
	 * @param string $type The type of asset: 'js' or 'css'.
	 * @param string $handle The handle/slug of the asset.
	 * @return bool True to suppress the asset for the current page, false otherwise.
	 */
	function asset_disabled( $type, $handle ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$uri = $this->parse_url( add_query_arg( null, null ), PHP_URL_PATH );
		if ( wp_doing_ajax() && ! empty( $_POST['swis_slim_frontend'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $this->current_page !== $uri ) {
				$uri = $this->current_page;
			}
		}
		$this->debug_message( "request uri is $uri" );
		if ( 'jquery-migrate' === $handle && ! isset( $this->user_exclusions[ $handle ] ) && isset( $this->user_exclusions['jquery-core'] ) ) {
			$this->debug_message( "using jquery-core exclusions for $handle" );
			return $this->asset_disabled( $type, 'jquery-core' );
		}
		if ( 'jquery-core' === $handle && ! isset( $this->user_exclusions[ $handle ] ) && isset( $this->user_exclusions['jquery'] ) ) {
			$this->debug_message( "using jquery exclusions for $handle" );
			return $this->asset_disabled( $type, 'jquery' );
		}
		if ( isset( $this->user_exclusions[ $handle ] ) ) {
			if ( empty( $this->user_exclusions[ $handle ]['include'] ) && empty( $this->user_exclusions[ $handle ]['exclude'] ) ) {
				$this->debug_message( "site-wide rule triggered for $handle" );
				return true;
			}
			if ( ! empty( $this->user_exclusions[ $handle ]['include'] ) ) {
				foreach ( $this->user_exclusions[ $handle ]['include'] as $include ) {
					$include = trim( $include );
					if ( $this->check_content_type( $include ) ) {
						$this->debug_message( "content-include rule triggered for $handle" );
						return false;
					}
					if ( '<home_page>' === $include && $uri === $this->home_path ) {
						$this->debug_message( "home page include rule triggered for $handle" );
						return false;
					}
					if ( false !== strpos( $include, '*' ) && false === strpos( $include, '#' ) && preg_match( "#$include#", $uri ) ) {
						$this->debug_message( "pattern-include ($include) rule triggered for $handle" );
						return false;
					} elseif ( $uri === $include ) {
						$this->debug_message( "page-include ($include) rule triggered for $handle" );
						return false;
					}
				}
				return true;
			}
			if ( ! empty( $this->user_exclusions[ $handle ]['exclude'] ) ) {
				foreach ( $this->user_exclusions[ $handle ]['exclude'] as $exclude ) {
					$exclude = trim( $exclude );
					if ( $this->check_content_type( $exclude ) ) {
						$this->debug_message( "content-exclude rule triggered for $handle" );
						return true;
					}
					if ( '<home_page>' === $exclude && $uri === $this->home_path ) {
						$this->debug_message( "home page exclude rule triggered for $handle" );
						return true;
					}
					if ( false !== strpos( $exclude, '*' ) && false === strpos( $exclude, '#' ) && preg_match( "#$exclude#", $uri ) ) {
						$this->debug_message( "pattern-exclude ($exclude) rule triggered for $handle" );
						return true;
					} elseif ( $uri === $exclude ) {
						$this->debug_message( "page-exclude ($exclude) rule triggered for $handle" );
						return true;
					}
				}
			}
		}
		$this->debug_message( "no rules matched for $handle" );
		return false;
	}

	/**
	 * Remove JS/CSS files if the user has disabled them.
	 *
	 * @param string $url The address of the resource.
	 * @param string $handle The registered handle for the resource.
	 */
	function disable_assets( $url, $handle ) {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		$this->debug_message( "checking $url" );
		$type        = current_filter() === 'script_loader_src' ? 'js' : 'css';
		$permissions = apply_filters( 'swis_performance_admin_permissions', 'manage_options' );
		if (
			'jquery-core' === $handle &&
			$this->is_frontend() &&
			is_user_logged_in() &&
			current_user_can( $permissions ) &&
			! defined( 'SWIS_SLIM_DISABLE_FRONTEND_MENU' )
		) {
			return $url;
		}
		return $this->asset_disabled( $type, $handle ) ? false : $url;
	}

	/**
	 * Disable emoji JS/CSS based on user preference.
	 */
	function maybe_remove_emoji() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( $this->asset_disabled( 'js', 'wp-emoji' ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
		}
	}

	/**
	 * Check to see if Emoji is enqueued.
	 *
	 * @return bool True if it is, false if it ain't.
	 */
	function is_emoji_active() {
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		return (bool) has_action( 'wp_head', 'print_emoji_detection_script' );
	}

	/**
	 * Get a list of dependent assets for any given asset.
	 *
	 * @param string $handle The asset handle/slug.
	 * @param string $type The asset type (js/css).
	 * @return array A list assets that depend on the $handle asset.
	 */
	function get_dependents( $handle, $type ) {
		$dependents = array();
		foreach ( $this->deps as $asset ) {
			if ( in_array( $handle, $asset['deps'], true ) && $type === $asset['type'] && 'jquery' !== $asset['name'] ) {
				$dependents[] = $asset['name'];
			}
		}
		if ( 'jquery-core' === $handle && empty( $dependents ) ) {
			return $this->get_dependents( 'jquery', 'js' );
		}
		if ( 'jquery-migrate' === $handle && empty( $dependents ) ) {
			return $this->get_dependents( 'jquery', 'js' );
		}
		return $dependents;
	}

	/**
	 * Display a list of discovered JS/CSS files for the current page.
	 */
	function display_assets() {
		if ( ! $this->is_frontend() ) {
			return;
		}
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		$this->debug_message( '<b>' . __METHOD__ . '()</b>' );
		echo "<div id='swis-slim-assets-pane' class='swis-slim-hidden'>\n";

		$this->get_content_types();
		$this->sample_type  = $this->get_content_type();
		$this->current_page = $this->parse_url( add_query_arg( null, null ), PHP_URL_PATH );
		$this->sample_page  = '/example-page/';

		$posts = get_posts( 'post_type=page&numberposts=1&fields=ids' );
		if ( ! empty( $posts[0] ) ) {
			$potential_sample = str_replace( get_home_url(), '', get_permalink( $posts[0] ) );
			if ( ! empty( $potential_sample ) && false === strpos( $potential_sample, 'http' ) && $this->current_page !== $potential_sample ) {
				$this->sample_page = $potential_sample;
			}
		}
		$this->active_plugins['root_url'] = trailingslashit( plugins_url() );

		if ( ! empty( $this->assets['core'] ) ) {
			?>
			<button id='swis-slim-close-pane' class="button-secondary"><strong>X</strong></button>
			<div class='slim-main-heading'>
				SWIS Performance
				<?php $this->help_link( 'https://docs.ewww.io/article/97-disabling-unused-css-and-js' ); ?>
			</div>
			<div style="display:none;" id="swis-slim-current-page"><?php echo esc_html( $this->current_page ); ?></div>
			<ul>
				<li><?php esc_html_e( 'Note that the list of CSS/JS file may be different on each page.', 'swis-performance' ); ?></li>
				<li><?php esc_html_e( 'Always test your pages after each change.', 'swis-performance' ); ?></li>
				<li><?php esc_html_e( 'Then, if something breaks, undo it by removing the last rule you added.', 'swis-performance' ); ?></li>
			</ul>
			<?php do_action( 'swis_slim_before_all_sections' ); ?>
			<?php do_action( 'swis_slim_before_core_section' ); ?>
			<div class='slim-section-heading'>
				<?php esc_html_e( 'Core', 'swis-performance' ); ?>
			</div>
			<table class='swis-slim-assets'>
				<tr>
					<th class='swis-slim-asset-active'><?php esc_html_e( 'Active', 'swis-performance' ); ?></th>
					<th class='swis-slim-asset-details'><?php esc_html_e( 'Asset Details', 'swis-performance' ); ?></th>
					<th class='swis-slim-asset-size'><?php esc_html_e( 'Size', 'swis-performance' ); ?></th>
				</tr>
			<?php
			foreach ( $this->assets['core'] as $asset_type => $data ) {
				foreach ( $data as $handle => $asset ) {
					$this->display_asset_info( $handle, $asset, $asset_type );
				}
			}
			?>
			</table>
			<?php
		}
		if ( ! empty( $this->assets['plugins'] ) ) {
			$this->active_plugins['plugin_files'] = get_option( 'active_plugins', array() );
			?>
			<?php do_action( 'swis_slim_before_plugins_section' ); ?>
			<div class='slim-section-heading'>
				<?php esc_html_e( 'Plugins', 'swis-performance' ); ?>
			</div>
			<table class='swis-slim-assets'>
				<tr>
					<th class='swis-slim-asset-active'><?php esc_html_e( 'Active', 'swis-performance' ); ?></th>
					<th class='swis-slim-asset-details'><?php esc_html_e( 'Asset Details', 'swis-performance' ); ?></th>
					<th class='swis-slim-asset-size'><?php esc_html_e( 'Size', 'swis-performance' ); ?></th>
				</tr>
			<?php
			foreach ( $this->assets['plugins'] as $plugin => $asset_types ) {
				foreach ( $asset_types as $asset_type => $data ) {
					foreach ( $data as $handle => $asset ) {
						$this->display_asset_info( $handle, $asset, $asset_type );
					}
				}
			}
			?>
			</table>
			<?php
		}
		if ( ! empty( $this->assets['theme'] ) ) {
			?>
			<?php do_action( 'swis_slim_before_theme_section' ); ?>
			<div class='slim-section-heading'>
				<?php esc_html_e( 'Theme', 'swis-performance' ); ?>
			</div>
			<table class='swis-slim-assets'>
				<tr>
					<th class='swis-slim-asset-active'><?php esc_html_e( 'Active', 'swis-performance' ); ?></th>
					<th class='swis-slim-asset-details'><?php esc_html_e( 'Asset Details', 'swis-performance' ); ?></th>
					<th class='swis-slim-asset-size'><?php esc_html_e( 'Size', 'swis-performance' ); ?></th>
				</tr>
			<?php
			foreach ( $this->assets['theme'] as $asset_type => $data ) {
				foreach ( $data as $handle => $asset ) {
					$this->display_asset_info( $handle, $asset, $asset_type );
				}
			}
			?>
			</table>
			<?php
		}
		if ( ! empty( $this->assets['misc'] ) ) {
			?>
			<div class='slim-section-heading'>
				<?php esc_html_e( 'Miscellaneous', 'swis-performance' ); ?>
			</div>
			<table class='swis-slim-assets'>
				<tr>
					<th class='swis-slim-asset-active'><?php esc_html_e( 'Active', 'swis-performance' ); ?></th>
					<th class='swis-slim-asset-details'><?php esc_html_e( 'Asset Details', 'swis-performance' ); ?></th>
					<th class='swis-slim-asset-size'><?php esc_html_e( 'Size', 'swis-performance' ); ?></th>
				</tr>
			<?php
			foreach ( $this->assets['misc'] as $asset_type => $data ) {
				foreach ( $data as $handle => $asset ) {
					$this->display_asset_info( $handle, $asset, $asset_type );
				}
			}
			?>
			</table>
			<?php
		}
		echo "</div>\n";
	}

	/**
	 * Display the table row for a particular asset.
	 *
	 * @param string $handle The asset handle.
	 * @param array  $asset The asset information.
	 * @param string $type The asset type (js/css).
	 */
	function display_asset_info( $handle, $asset, $type ) {
		$dependents = $this->get_dependents( $handle, $type );
		if ( 'yoast-seo-adminbar' === $handle ) {
			return;
		}
		if ( 'jquery-core' === $handle && empty( $dependents ) ) {
			return;
		}
		if ( 'jquery-migrate' === $handle && empty( $dependents ) ) {
			$jquery_dependents = $this->get_dependents( 'jquery-core', 'js' );
			if ( empty( $dependents ) ) {
				return;
			}
		}
		if ( 'jquery' === $handle ) {
			return;
		}
		if ( 0 === strpos( $asset['url'], '/' ) && 0 !== strpos( $asset['url'], '//' ) ) {
			$asset['url'] = \get_site_url() . $asset['url'];
		}
		$rule = isset( $this->user_exclusions[ $handle ] ) ? $this->user_exclusions[ $handle ] : array();
		if ( false !== strpos( $asset['url'], $this->active_plugins['root_url'] ) ) {
			$plugin_info = false;

			$half_url = str_replace( $this->active_plugins['root_url'], '', $asset['url'] );
			$url_bits = explode( '/', $half_url );
			if ( $this->is_iterable( $url_bits ) && isset( $url_bits[0] ) ) {
				$plugin_slug = $url_bits[0];
				foreach ( $this->active_plugins['plugin_files'] as $plugin_file ) {
					if ( false !== strpos( $plugin_file, $plugin_slug ) ) {
						$abs_plugin_file = \plugin_dir_path( SWIS_PLUGIN_PATH ) . $plugin_file;
						if ( $this->is_file( $abs_plugin_file ) ) {
							$plugin_info = $this->get_plugin_data( $abs_plugin_file, false );
						}
						break;
					}
				}
				if ( $this->is_iterable( $plugin_info ) ) {
					$asset['plugin_title'] = $plugin_info['Title'];
				}
			}
		}
		?>
				<tr>
					<td class="swis-slim-asset-status">
						<?php echo $asset['disabled'] ? "<span class='swis-slim-active-no'>" . esc_html__( 'No', 'swis-performance' ) . '</span>' : "<span class='swis-slim-active-yes'>" . esc_html__( 'Yes', 'swis-performance' ) . '</span>'; ?>
					</td>
					<td>
						<a class='swis-slim-link' href='<?php echo esc_url( $asset['url'] ); ?>' target='_blank'>
							<?php echo $asset['external'] ? esc_html( $asset['url'] ) : esc_html( $asset['filename'] ); ?>
						</a>
						<div class='swis-slim-info'>
					<?php if ( ! empty( $asset['plugin_title'] ) ) : ?>
							<div><strong><?php esc_html_e( 'Plugin:', 'swis-performance' ); ?></strong> <?php echo esc_html( $asset['plugin_title'] ); ?></div>
					<?php endif; ?>
							<div><?php echo '<strong>' . esc_html__( 'Handle:', 'swis-performance' ) . '</strong> ' . esc_html( $handle ); ?></div>
					<?php if ( ! empty( $asset['deps'] ) ) : ?>
							<div><?php echo '<strong>' . esc_html__( 'Requires:', 'swis-performance' ) . '</strong> ' . esc_html( implode( ', ', $asset['deps'] ) ); ?></div>
					<?php endif; ?>
					<?php if ( ! empty( $dependents ) ) : ?>
							<div><?php echo '<strong>' . esc_html__( 'Required by:', 'swis-performance' ) . '</strong> ' . esc_html( implode( ', ', $dependents ) ); ?></div>
					<?php endif; ?>
					<?php $this->display_frontend_rule_form( $handle, $rule, $asset ); ?>
						</div>
					</td>
					<td>
						<?php echo esc_html( $asset['size'] ); ?>
					</td>
				</tr>
		<?php
	}

	/**
	 * Display the HTML for a given rule on the settings.
	 *
	 * @param string $handle The CSS/JS handle.
	 * @param array  $rule Parsed rule for the given $handle.
	 * @param array  $asset Asset info for the given $handle.
	 */
	private function display_frontend_rule_form( $handle, $rule, $asset = array() ) {
		$rule_id = preg_replace( '/[\W_]/', '', uniqid( '', true ) );
		if ( empty( $asset ) ) {
			$asset['disabled'] = $this->asset_disabled( '', $handle );
		}
		?>
		<form class="swis-slim-rule" data-slim-handle="<?php echo esc_attr( $handle ); ?>" data-slim-rule-id="<?php echo esc_attr( $rule_id ); ?>">
		<?php if ( ! empty( $rule['include'] ) ) : ?>
			<?php
			$includes = array();
			foreach ( $rule['include'] as $include ) {
				if ( 0 === strpos( $include, 'T>' ) ) {
					$includes[] = '<i>' . substr( $include, 2 ) . '</i> ' . esc_html__( 'content type', 'swis-performance' );
				} elseif ( '<home_page>' === $include ) {
					$includes[] = esc_html__( 'home page', 'swis-performance' );
				} else {
					$includes[] = $include;
				}
			}
			$raw_rule_parts = explode( ':', $rule['raw'] );
			$raw_rule_html  = ltrim( $raw_rule_parts[0], '+-' );
			?>
			<div class="swis-slim-rule-description">
				<div class="swis-slim-rule-prefix">
					<?php esc_html_e( 'Disabled everywhere except:', 'swis-performance' ); ?>
				</div>
				<input style="display:none;" type="radio" name="swis_slim_mode_<?php echo esc_attr( $rule_id ); ?>" value="include" checked />
				<div class="swis-slim-pretty-rule">
					<?php echo wp_kses_post( implode( ', ', $includes ) ); ?>
				</div>
				<div class="swis-slim-error-message"></div>
				<div class="swis-slim-hidden">
					<div class="swis-slim-raw-rule">
						<input type="text" name="swis-slim-rule-exclusions-<?php echo esc_attr( $rule_id ); ?>" value="<?php echo esc_attr( $raw_rule_html . ( $asset['disabled'] ? ',' . $this->current_page : '' ) ); ?>" />
					</div>
					<p class="swis-slim-edit-rule-description description">
						<?php esc_html_e( 'Enter a comma-separated list of pages, URL patterns (use * as wildcard), or content types in the form T>post or T>page.', 'swis-performance' ); ?>
						<button type="button" class="button-primary swis-slim-rule-save"><?php esc_html_e( 'Save', 'swis-performance' ); ?></button>
					</p>
				</div>
			</div>
			<div class="swis-slim-rule-actions">
				<button type="button" class="button-secondary button-link-edit"><?php ( $asset['disabled'] ? esc_html_e( 'Add', 'swis-performance' ) : esc_html_e( 'Edit', 'swis-performance' ) ); ?></button>
				&nbsp;&nbsp;
				<button type="button" class="button-danger button-link-delete"><?php esc_html_e( 'Delete', 'swis-performance' ); ?></button>
			</div>
		<?php elseif ( ! empty( $rule['exclude'] ) ) : ?>
			<?php
			$excludes = array();
			foreach ( $rule['exclude'] as $exclude ) {
				if ( 0 === strpos( $exclude, 'T>' ) ) {
					$excludes[] = '<i>' . substr( $exclude, 2 ) . '</i> ' . esc_html__( 'content type', 'swis-performance' );
				} elseif ( '<home_page>' === $exclude ) {
					$excludes[] = esc_html__( 'home page', 'swis-performance' );
				} else {
					$excludes[] = $exclude;
				}
			}
			$raw_rule_parts = explode( ':', $rule['raw'] );
			$raw_rule_html  = ltrim( $raw_rule_parts[0], '+-' );
			?>
			<div class="swis-slim-rule-description">
				<div class="swis-slim-rule-prefix">
					<?php esc_html_e( 'Disabled on:', 'swis-performance' ); ?>
				</div>
				<input style="display:none;" type="radio" name="swis_slim_mode_<?php echo esc_attr( $rule_id ); ?>" value="exclude" checked />
				<div class="swis-slim-pretty-rule">
					<?php echo wp_kses_post( implode( ', ', $excludes ) ); ?>
				</div>
				<div class="swis-slim-error-message"></div>
				<div class="swis-slim-hidden">
					<div class="swis-slim-raw-rule">
						<input type="text" name="swis-slim-rule-exclusions-<?php echo esc_attr( $rule_id ); ?>" value="<?php echo esc_attr( $raw_rule_html . ( $asset['disabled'] ? '' : ',' . $this->current_page ) ); ?>" />
					</div>
					<p class="swis-slim-edit-rule-description description">
						<?php esc_html_e( 'Enter a comma-separated list of pages, URL patterns (use * as wildcard), or content types in the form T>post or T>page.', 'swis-performance' ); ?>
						<button type="button" class="button-primary swis-slim-rule-save"><?php esc_html_e( 'Save', 'swis-performance' ); ?></button>
					</p>
				</div>
			</div>
			<div class="swis-slim-rule-actions">
				<button type="button" class="button-secondary button-link-edit"><?php ( $asset['disabled'] ? esc_html_e( 'Edit', 'swis-performance' ) : esc_html_e( 'Add', 'swis-performance' ) ); ?></button>
				&nbsp;&nbsp;
				<button type="button" class="button-danger button-link-delete"><?php esc_html_e( 'Delete', 'swis-performance' ); ?></button>
			</div>
		<?php elseif ( ! empty( $rule['raw'] ) ) : ?>
			<div class="swis-slim-rule-description">
				<div class="swis-slim-rule-prefix">
					<?php esc_html_e( 'Disabled everywhere', 'swis-performance' ); ?>
				</div>
				<input style="display:none;" type="radio" name="swis_slim_mode_<?php echo esc_attr( $rule_id ); ?>" value="all" checked />
				<div class="swis-slim-error-message"></div>
			</div>
			<div class="swis-slim-rule-actions">
				<button type="button" class="button-danger button-link-delete"><?php esc_html_e( 'Delete', 'swis-performance' ); ?></button>
			</div>
		<?php else : ?>
			<div class="swis-slim-rule-description swis-slim-column">
				<?php if ( 'jquery-migrate' === $handle && ! empty( $this->user_exclusions['jquery-core'] ) ) : ?>
					<?php esc_html_e( 'Rules for jquery/jquery-core will automatically apply to jquery-migrate.', 'swis-performance' ); ?>
				<?php endif; ?>
				<div class="swis-slim-error-message"></div>
				<div class="swis-slim-row">
					<div class="swis-slim-reversible">
						<div class="swis-slim-row">
							<input type="radio" id="swis_slim_mode_exclude_<?php echo esc_attr( $rule_id ); ?>" class="swis-slim-radio" name="swis_slim_mode_<?php echo esc_attr( $rule_id ); ?>" value="exclude" />
							<strong><label for="swis_slim_mode_exclude_<?php echo esc_attr( $rule_id ); ?>"><?php esc_html_e( 'disable on this page', 'swis-performance' ); ?></label></strong>
						</div>
						<div class="swis-slim-row">
							<input type="radio" id="swis_slim_mode_include_<?php echo esc_attr( $rule_id ); ?>" class="swis-slim-radio" name="swis_slim_mode_<?php echo esc_attr( $rule_id ); ?>" value="include" />
							<strong><label for="swis_slim_mode_include_<?php echo esc_attr( $rule_id ); ?>"><?php esc_html_e( 'disable everywhere except this page', 'swis-performance' ); ?></label></strong>
						</div>
						<div class="swis-slim-row">
							<input type="radio" id="swis_slim_mode_all_<?php echo esc_attr( $rule_id ); ?>" class="swis-slim-radio" name="swis_slim_mode_<?php echo esc_attr( $rule_id ); ?>" value="all" />
							<strong><label for="swis_slim_mode_all_<?php echo esc_attr( $rule_id ); ?>"><?php esc_html_e( 'disable everywhere', 'swis-performance' ); ?></label></strong>
						</div>
					</div>
					<div class="swis-slim-rule-actions">
						<button type="button" class="button-secondary swis-slim-rule-customize"><?php esc_html_e( 'Customize Rule', 'swis-performance' ); ?></button>
						&nbsp;&nbsp;
						<button type="button" class="button-primary swis-slim-rule-add"><?php esc_html_e( 'Add Rule', 'swis-performance' ); ?></button>
					</div>
				</div>
				<div class="swis-slim-column swis-slim-raw-rule">
					<input style="display:none;" type="text" name="swis-slim-rule-exclusions-<?php echo esc_attr( $rule_id ); ?>" value="<?php echo esc_attr( $this->current_page ); ?>" />
					<label style="display:none;" for="swis-slim-rule-exclusions-<?php echo esc_attr( $rule_id ); ?>">
						<?php esc_html_e( 'Comma-separated list of pages, URL patterns (use * as wildcard), or content types in the form T>post or T>page.', 'swis-performance' ); ?>
					</label>
				</div>
			</div>
		<?php endif; ?>
		</form>
		<?php
	}

}
