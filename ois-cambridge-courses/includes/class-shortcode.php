<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the [ois_cambridge_courses] shortcode: a single empty container
 * that assets/js/app.js boots into a multi-step wizard (Student ID -> OTP
 * -> course grid -> course detail -> checkout -> thank you). Works inside
 * Elementor via its Shortcode widget since it's a plain shortcode.
 */
class OIS_CC_Shortcode {

	public function __construct() {
		add_shortcode( 'ois_cambridge_courses', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets() {
		wp_register_style( 'ois-cc-app', OIS_CC_URL . 'assets/css/app.css', array(), OIS_CC_VERSION );
		wp_register_script( 'ois-cc-app', OIS_CC_URL . 'assets/js/app.js', array(), OIS_CC_VERSION, true );
	}

	public function render( $atts ) {
		wp_enqueue_style( 'ois-cc-app' );
		wp_enqueue_script( 'ois-cc-app' );

		wp_localize_script(
			'ois-cc-app',
			'OIS_CC',
			array(
				'restUrl' => esc_url_raw( rest_url( 'ois-cc/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'schoolName' => 'Oxford International School',
			)
		);

		return '<div id="ois-cc-app" class="ois-cc-app" data-loading="1"><div class="ois-cc-boot">Loading courses…</div></div>';
	}
}
