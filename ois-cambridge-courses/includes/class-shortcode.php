<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the [ois_cambridge_courses] shortcode: a single empty container
 * that assets/js/app.js boots into a multi-step wizard (Student ID -> OTP
 * -> products -> checkout -> thank you). Works inside Elementor via its
 * Shortcode widget since it's a plain shortcode.
 */
class OIS_CC_Shortcode {

	public function __construct() {
		add_shortcode( 'ois_cambridge_courses', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'template_redirect', array( $this, 'no_page_cache' ) );
	}

	/**
	 * The page holding the checkout carries a per-request nonce and must
	 * always load the current app, so it's excluded from page/proxy caches
	 * (SiteGround's dynamic cache honours these headers). Elementor keeps
	 * its content in _elementor_data, so that's checked too.
	 */
	public function no_page_cache() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		$found = has_shortcode( $post->post_content, 'ois_cambridge_courses' )
			|| false !== strpos( (string) get_post_meta( $post->ID, '_elementor_data', true ), 'ois_cambridge_courses' );
		if ( $found ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
			header( 'X-Cache-Enabled: False' ); // SiteGround dynamic-cache bypass
		}
	}

	public function register_assets() {
		// Fraunces (display serif) + Hanken Grotesk (body), one request.
		// Versioned by file mtime, not the plugin version: any edit to the
		// file changes its URL, so browsers/CDNs can never serve a stale copy.
		wp_register_style( 'ois-cc-font', 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap', array(), null );
		wp_register_style( 'ois-cc-app', OIS_CC_URL . 'assets/css/app.css', array( 'ois-cc-font' ), self::asset_version( 'assets/css/app.css' ) );
		wp_register_script( 'ois-cc-app', OIS_CC_URL . 'assets/js/app.js', array(), self::asset_version( 'assets/js/app.js' ), true );
	}

	private static function asset_version( $rel ) {
		$mtime = @filemtime( OIS_CC_DIR . $rel ); // phpcs:ignore
		return OIS_CC_VERSION . ( $mtime ? '.' . $mtime : '' );
	}

	/**
	 * Photo for the right-hand column: [ois_cambridge_courses image="…"],
	 * else the Settings value, else the bundled Cambridge English photo.
	 */
	public function render( $atts ) {
		$atts  = shortcode_atts( array( 'image' => '' ), $atts, 'ois_cambridge_courses' );
		$image = $atts['image'] ? $atts['image'] : get_option( 'ois_cc_side_image' );
		if ( ! $image ) {
			$image = OIS_CC_URL . 'assets/img/cambridge-english.webp';
		}

		wp_enqueue_style( 'ois-cc-font' );
		wp_enqueue_style( 'ois-cc-app' );
		wp_enqueue_script( 'ois-cc-app' );
		$captcha_key = OIS_CC_REST_API::captcha_enabled() ? get_option( 'ois_cc_recaptcha_site_key' ) : '';
		if ( $captcha_key ) {
			wp_enqueue_script( 'ois-cc-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $captcha_key ), array(), null, true );
		}

		wp_localize_script(
			'ois-cc-app',
			'OIS_CC',
			array(
				'restUrl' => esc_url_raw( rest_url( 'ois-cc/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'brand'      => 'Cambridge English Qualifications',
				'schoolName' => 'Oxford International School',
				'logo'       => esc_url_raw( OIS_CC_URL . 'assets/img/Oxford-International-School.webp' ),
				'captchaKey' => $captcha_key,
				'image'      => esc_url_raw( $image ),
				'supportEmail' => sanitize_email( get_option( 'ois_cc_support_email', 'info@ois.edu.bd' ) ),
			)
		);

		return '<div id="ois-cc-app" class="ois-cc-app" data-loading="1"><div class="ois-cc-boot">Loading courses…</div></div>';
	}
}
