<?php
/**
 * Plugin Name: OIS Cambridge Courses
 * Description: Student-ID + OTP verified checkout flow for Oxford International School's Cambridge courses. Drop the [ois_cambridge_courses] shortcode into any page (works inside Elementor's Shortcode widget).
 * Version: 1.0.0
 * Author: Sakib Islam
 * Author URI: tel:01950025990
 * Text Domain: ois-cc
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OIS_CC_VERSION', '1.0.0' );
define( 'OIS_CC_FILE', __FILE__ );
define( 'OIS_CC_DIR', plugin_dir_path( __FILE__ ) );
define( 'OIS_CC_URL', plugin_dir_url( __FILE__ ) );

require_once OIS_CC_DIR . 'includes/class-courses.php';
require_once OIS_CC_DIR . 'includes/class-orders.php';
require_once OIS_CC_DIR . 'includes/class-fems-api.php';
require_once OIS_CC_DIR . 'includes/class-otp.php';
require_once OIS_CC_DIR . 'includes/class-payment-gateway.php';
require_once OIS_CC_DIR . 'includes/class-rest-api.php';
require_once OIS_CC_DIR . 'includes/class-shortcode.php';
require_once OIS_CC_DIR . 'includes/class-transactions-table.php';
require_once OIS_CC_DIR . 'includes/class-admin.php';

/**
 * Boot every module. Each class wires its own hooks in the constructor,
 * so this just needs to instantiate them once.
 */
final class OIS_CC_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		new OIS_CC_Courses();
		new OIS_CC_Orders();
		new OIS_CC_REST_API();
		new OIS_CC_Shortcode();
		new OIS_CC_Admin();
	}
}

function ois_cc() {
	return OIS_CC_Plugin::instance();
}
add_action( 'plugins_loaded', 'ois_cc' );

/**
 * Register CPTs immediately on activation so rewrite rules flush correctly,
 * and seed the 7 Cambridge courses so the shortcode has something to show
 * on first test.
 */
function ois_cc_activate() {
	OIS_CC_Courses::register_post_type();
	OIS_CC_Orders::register_post_type();
	OIS_CC_Courses::seed_default_courses();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'ois_cc_activate' );

function ois_cc_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'ois_cc_deactivate' );
