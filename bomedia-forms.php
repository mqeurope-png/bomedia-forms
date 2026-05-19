<?php
/**
 * Plugin Name:       Bomedia Forms
 * Plugin URI:        https://github.com/mqeurope-png/bomedia-forms
 * Description:       Generic forms plugin with native AgileCRM integration, used across Bomedia websites.
 * Version:           0.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Bomedia
 * Author URI:        https://bomedia.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bomedia-forms
 * Domain Path:       /languages
 *
 * @package BomediaForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BF_VERSION', '0.3.0' );
define( 'BF_DB_VERSION', '1' );
define( 'BF_PLUGIN_FILE', __FILE__ );
define( 'BF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BF_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once BF_PLUGIN_DIR . 'includes/class-logger.php';
require_once BF_PLUGIN_DIR . 'includes/class-encryption.php';
require_once BF_PLUGIN_DIR . 'includes/class-i18n.php';
require_once BF_PLUGIN_DIR . 'includes/class-cpt.php';
require_once BF_PLUGIN_DIR . 'includes/class-settings.php';
require_once BF_PLUGIN_DIR . 'includes/class-agilecrm-client.php';
require_once BF_PLUGIN_DIR . 'includes/class-form-renderer.php';
require_once BF_PLUGIN_DIR . 'includes/class-submission-handler.php';

if ( is_admin() ) {
	require_once BF_PLUGIN_DIR . 'admin/class-admin.php';
}

/**
 * Main plugin bootstrap.
 *
 * Wires together the component classes and registers the core WordPress
 * hooks. Kept intentionally thin — each concern lives in its own class.
 */
final class Bomedia_Forms {

	/**
	 * Singleton instance.
	 *
	 * @var Bomedia_Forms|null
	 */
	private static $instance = null;

	/**
	 * @var BF_CPT
	 */
	public $cpt;

	/**
	 * @var BF_I18n
	 */
	public $i18n;

	/**
	 * @var BF_Form_Renderer
	 */
	public $renderer;

	/**
	 * @var BF_Submission_Handler
	 */
	public $submissions;

	/**
	 * @var BF_Admin|null
	 */
	public $admin = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return Bomedia_Forms
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Private — use instance().
	 */
	private function __construct() {
		$this->i18n        = new BF_I18n();
		$this->cpt         = new BF_CPT();
		$this->renderer    = new BF_Form_Renderer();
		$this->submissions = new BF_Submission_Handler();

		if ( is_admin() ) {
			$this->admin = new BF_Admin();
		}

		$this->register_hooks();
	}

	/**
	 * Register core hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this->cpt, 'register' ) );
		add_action( 'init', array( $this->i18n, 'init' ) );

		add_shortcode( 'bomedia_form', array( $this->renderer, 'shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this->renderer, 'enqueue_assets' ) );

		// AJAX endpoints (logged-in + anonymous).
		add_action( 'wp_ajax_bf_submit', array( $this->submissions, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_bf_submit', array( $this->submissions, 'handle_submit' ) );
		add_action( 'wp_ajax_bf_get_form', array( $this->submissions, 'handle_get_form' ) );

		if ( $this->admin ) {
			add_action( 'admin_menu', array( $this->admin, 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_assets' ) );
			add_action( 'add_meta_boxes', array( $this->admin, 'register_meta_boxes' ) );
			add_action( 'save_post_bf_form', array( $this->admin, 'save_meta' ), 10, 2 );
			add_action( 'admin_notices', array( $this->admin, 'admin_notice_auth_key' ) );
			add_action( 'wp_ajax_bf_agilecrm_test', array( $this->admin, 'ajax_test_agilecrm' ) );
		}

		// Daily cleanup cron.
		add_action( 'bf_daily_cleanup', array( $this->submissions, 'cleanup_old_submissions' ) );
	}

	/**
	 * Load the plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'bomedia-forms', false, dirname( BF_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Activation routine: create tables, register CPT, schedule cron.
	 *
	 * @return void
	 */
	public static function activate() {
		require_once BF_PLUGIN_DIR . 'includes/class-cpt.php';
		require_once BF_PLUGIN_DIR . 'includes/class-submission-handler.php';

		$cpt = new BF_CPT();
		$cpt->register();

		BF_Submission_Handler::install_table();

		if ( ! wp_next_scheduled( 'bf_daily_cleanup' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'bf_daily_cleanup' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Deactivation routine: clear scheduled cron.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'bf_daily_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'bf_daily_cleanup' );
		}
		flush_rewrite_rules();
	}
}

register_activation_hook( __FILE__, array( 'Bomedia_Forms', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Bomedia_Forms', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Bomedia_Forms::instance();
	}
);
