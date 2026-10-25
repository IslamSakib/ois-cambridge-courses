<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sidebar menu, Transactions dashboard, Settings page, CSV export, and the
 * custom 'ois_cc_view_transactions' capability that drives access control
 * (which WP users/roles besides Administrators can see the Transactions page).
 */
class OIS_CC_Admin {

	const CAP = 'ois_cc_view_transactions';

	// Assign this role to a user and Cambridge Courses → Transactions is the only
	// admin screen they get (plus their own Profile).
	const ROLE = 'ois_cc_accounts';

	public function __construct() {
		add_action( 'init', array( $this, 'register_role' ) );
		add_action( 'admin_init', array( $this, 'lock_role_to_module' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_menu', array( $this, 'dedupe_menu' ), 999 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 4 );
		add_action( 'admin_post_ois_cc_export_csv', array( $this, 'handle_export' ) );
		add_action( 'admin_post_ois_cc_change_course', array( $this, 'handle_change_course' ) );
		add_action( 'admin_post_ois_cc_recheck', array( $this, 'handle_recheck' ) );
		add_action( 'admin_post_ois_cc_add_user', array( $this, 'handle_add_user' ) );
		add_action( 'admin_post_ois_cc_remove_user', array( $this, 'handle_remove_user' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'test_mode_notice' ) );
	}

	/**
	 * Test Mode shows OTPs on screen and enables the free dummy gateway, so it
	 * must never be left on unnoticed on the live site.
	 */
	public function test_mode_notice() {
		if ( ! get_option( 'ois_cc_test_mode' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>Cambridge Courses Test Mode is ON.</strong> OTP codes are shown on screen (built-in OTP only) and, without a Moneybag key, orders are auto-approved for free. <a href="%s">Turn it off in Settings</a> when you finish testing.</p></div>',
			esc_url( admin_url( 'admin.php?page=ois-cc-settings' ) )
		);
	}

	/**
	 * Spreadsheet apps execute cells starting with = + - @ as formulas; FEMS
	 * names and course titles flow into the export, so neutralise them.
	 */
	private static function csv_cell( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Runs on init rather than activation so the role also appears after a
	 * plain plugin update. get_role() is an in-memory lookup.
	 */
	public function register_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Cambridge Courses Accounts', array( 'read' => true ) );
		}
	}

	private static function is_role_user() {
		return in_array( self::ROLE, (array) wp_get_current_user()->roles, true );
	}

	/**
	 * Allowlist, not blocklist: other plugins (e.g. DearFlip) expose admin screens
	 * to any logged-in user, so every admin page except Transactions and the
	 * user's own Profile is sent to Transactions (also where they land after
	 * login). admin-post.php stays open for the CSV export.
	 */
	public function lock_role_to_module() {
		global $pagenow;
		if ( ! self::is_role_user() ) {
			return;
		}
		$on_transactions = 'admin.php' === $pagenow && isset( $_GET['page'] ) && 'ois-cc-transactions' === $_GET['page'];
		if ( $on_transactions || in_array( $pagenow, array( 'profile.php', 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=ois-cc-transactions' ) );
		exit;
	}

	/**
	 * Grants the view-transactions capability to Administrators always,
	 * plus whichever users/roles are picked on the Settings page.
	 */
	public function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( self::CAP !== $cap ) {
			return $caps;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return array( 'exist' );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array( 'do_not_allow' );
		}

		$allowed_users = (array) get_option( 'ois_cc_access_users', array() );
		$allowed_roles = (array) get_option( 'ois_cc_access_roles', array() );

		if ( in_array( self::ROLE, (array) $user->roles, true ) || in_array( $user_id, array_map( 'intval', $allowed_users ), true ) || array_intersect( $allowed_roles, (array) $user->roles ) ) {
			return array( 'exist' );
		}

		return array( 'do_not_allow' );
	}

	public function register_menu() {
		add_menu_page(
			'Cambridge Courses',
			'Cambridge Courses',
			self::CAP,
			'ois-cc-transactions',
			array( $this, 'render_transactions_page' ),
			'dashicons-welcome-learn-more',
			26
		);

		// Every submenu row is registered explicitly and only here — Courses' CPT
		// registration sets show_in_menu => false specifically so there's no second,
		// WordPress-internal registration path racing with this one. dedupe_menu()
		// below is a belt-and-suspenders cleanup for WordPress's own auto-inserted
		// "first submenu repeats the parent label" row, which can still appear
		// depending on hook-execution order.
		add_submenu_page( 'ois-cc-transactions', 'Transactions', 'Transactions', self::CAP, 'ois-cc-transactions', array( $this, 'render_transactions_page' ) );
		add_submenu_page( 'ois-cc-transactions', 'Courses', 'Courses', 'edit_posts', 'edit.php?post_type=' . OIS_CC_Courses::POST_TYPE );
		add_submenu_page( 'ois-cc-transactions', 'Settings', 'Settings', 'manage_options', 'ois-cc-settings', array( $this, 'render_settings_page' ) );
	}

	/**
	 * Safety net: strips any duplicate row WordPress may have auto-inserted into
	 * this menu's submenu (same target slug appearing twice, one of them labelled
	 * with the top-level menu's own title). Runs late (priority 999) so every
	 * plugin's admin_menu registration — including this one and core's CPT wiring —
	 * has already run.
	 */
	public function dedupe_menu() {
		global $menu, $submenu;

		// Accounts role: hide every top-level menu but ours and Profile.
		if ( self::is_role_user() ) {
			foreach ( (array) $menu as $item ) {
				if ( ! in_array( $item[2], array( 'ois-cc-transactions', 'profile.php' ), true ) ) {
					remove_menu_page( $item[2] );
				}
			}
		}

		if ( empty( $submenu['ois-cc-transactions'] ) ) {
			return;
		}

		$seen = array();
		foreach ( $submenu['ois-cc-transactions'] as $key => $item ) {
			$slug = $item[2];
			if ( isset( $seen[ $slug ] ) ) {
				unset( $submenu['ois-cc-transactions'][ $key ] );
				continue;
			}
			$seen[ $slug ] = true;
		}
		$submenu['ois-cc-transactions'] = array_values( $submenu['ois-cc-transactions'] );
	}

	public function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'ois-cc' ) === false ) {
			return;
		}
		wp_enqueue_style( 'ois-cc-admin', OIS_CC_URL . 'assets/css/admin.css', array(), OIS_CC_VERSION );
	}

	/* ---------------------------------------------------------------- */
	/* Transactions page                                                  */
	/* ---------------------------------------------------------------- */

	public function render_transactions_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'You do not have access to this page.' );
		}

		$stats = OIS_CC_Orders::get_stats( OIS_CC_Transactions_Table::current_filters() );

		$table = new OIS_CC_Transactions_Table();
		$table->prepare_items();
		?>
		<div class="wrap ois-cc-wrap">
			<h1 class="ois-cc-heading">Cambridge Courses — Transactions</h1>
			<?php
			$recheck = array(
				'paid'      => array( 'success', 'Moneybag confirms this payment: the order is now Success.' ),
				'unchanged' => array( 'info', 'Moneybag does not show a completed payment for this order yet. Nothing changed.' ),
				'error'     => array( 'warning', 'Could not reach Moneybag to re-check. Please try again shortly.' ),
				'ineligible' => array( 'info', 'Only Failed orders from the last 3 days can be synced.' ),
			);
			if ( isset( $_GET['ois_cc_recheck'], $recheck[ $_GET['ois_cc_recheck'] ] ) ) :
				$r = $recheck[ $_GET['ois_cc_recheck'] ];
				?>
				<div class="notice notice-<?php echo esc_attr( $r[0] ); ?>"><p><?php echo esc_html( $r[1] ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['ois_cc_sms'] ) ) : ?>
				<?php if ( 'sent' === $_GET['ois_cc_sms'] ) : ?>
					<div class="notice notice-success"><p>Course changed. The student was notified by SMS.</p></div>
				<?php else : ?>
					<div class="notice notice-warning"><p>Course changed, but the SMS could not be sent. Please tell the student yourself (for a balance, copy the payment link from the new Balance row).</p></div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="ois-cc-stats">
				<div class="ois-cc-stat-card ois-cc-stat-blue">
					<span class="ois-cc-stat-label">Total Orders</span>
					<span class="ois-cc-stat-value"><?php echo esc_html( number_format_i18n( $stats['total_orders'] ) ); ?></span>
				</div>
				<div class="ois-cc-stat-card ois-cc-stat-green">
					<span class="ois-cc-stat-label">Success</span>
					<span class="ois-cc-stat-value"><?php echo esc_html( number_format_i18n( $stats['paid_orders'] ) ); ?></span>
				</div>
				<div class="ois-cc-stat-card ois-cc-stat-red">
					<span class="ois-cc-stat-label">Failed</span>
					<span class="ois-cc-stat-value"><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></span>
				</div>
				<div class="ois-cc-stat-card ois-cc-stat-slate">
					<span class="ois-cc-stat-label">Canceled</span>
					<span class="ois-cc-stat-value"><?php echo esc_html( number_format_i18n( $stats['canceled'] ) ); ?></span>
				</div>
				<div class="ois-cc-stat-card ois-cc-stat-purple">
					<span class="ois-cc-stat-label">Revenue</span>
					<span class="ois-cc-stat-value">৳<?php echo esc_html( number_format_i18n( $stats['revenue'], 2 ) ); ?></span>
				</div>
			</div>

			<form method="get" class="ois-cc-table-card" id="ois-cc-filters">
				<input type="hidden" name="page" value="ois-cc-transactions">
				<?php
				$table->search_box( 'Search student, order # or course', 'ois-cc-search' );
				$table->display();
				?>
			</form>
		</div>
		<script>
		// Apply a filter the moment it changes — no Filter button needed.
		// Paging resets so a narrower filter never lands on an empty page.
		( function () {
			var form = document.getElementById( 'ois-cc-filters' );
			if ( ! form ) { return; }
			form.querySelectorAll( '.tablenav.top select[name="course"], .tablenav.top select[name="range"], .tablenav.top select[name="status"], .tablenav.top input[type="date"]' ).forEach( function ( el ) {
				el.addEventListener( 'change', function () {
					var paged = form.querySelector( 'input[name="paged"]' );
					if ( paged ) { paged.value = 1; }
					if ( 'range' === el.name ) { // a preset replaces any custom dates
						form.querySelectorAll( 'input[type="date"]' ).forEach( function ( d ) { d.value = ''; } );
					}
					form.submit();
				} );
			} );
		} )();
		</script>
		<?php
	}

	public function handle_recheck() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'ois_cc_recheck_' . $order_id );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'You do not have access to re-check payments.' );
		}
		$result = OIS_CC_Moneybag_API::recheck_order( $order_id );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ois-cc-transactions', 'ois_cc_recheck' => $result ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_change_course() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		check_admin_referer( 'ois_cc_change_course_' . $order_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Only administrators can change a paid order.' );
		}

		$result = OIS_CC_Orders::change_course( $order_id, isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0 );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) );
		}

		$args = array( 'page' => 'ois-cc-transactions' );
		if ( isset( $result['sms'] ) ) {
			$args['ois_cc_sms'] = $result['sms'] ? 'sent' : 'failed';
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_export() {
		check_admin_referer( 'ois_cc_export_csv' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'You do not have access to this export.' );
		}

		$result = OIS_CC_Orders::query( array_merge( OIS_CC_Transactions_Table::current_filters(), array( 'per_page' => 0 ) ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ois-cc-transactions-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Order #', 'Student Name', 'Student ID', 'Class', 'Course', 'Amount (BDT)', 'Status', 'Payment Method', 'Transaction ID', 'Date' ) );

		foreach ( $result['rows'] as $row ) {
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_cell' ),
					array(
						$row['order_number'],
						$row['student_name'],
						$row['student_id'],
						$row['student_class'],
						$row['course_title'],
						$row['amount'],
						OIS_CC_Orders::status_label( $row['status'] ),
						$row['payment_method'],
						$row['txn_id'],
						$row['post_date'],
					)
				)
			);
		}

		fclose( $out );
		exit;
	}

	/* ---------------------------------------------------------------- */
	/* Settings page                                                      */
	/* ---------------------------------------------------------------- */

	public function register_settings() {
		register_setting( 'ois_cc_settings', 'ois_cc_fems_api_url', array( 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( 'ois_cc_settings', 'ois_cc_fems_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'ois_cc_settings', 'ois_cc_sms_api_url', array( 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( 'ois_cc_settings', 'ois_cc_sms_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'ois_cc_settings', 'ois_cc_test_mode', array( 'sanitize_callback' => 'absint' ) );
		register_setting(
			'ois_cc_settings',
			'ois_cc_otp_channel',
			array(
				'default'           => 'mobile',
				'sanitize_callback' => function ( $v ) {
					return in_array( $v, array( 'email', 'mobile', 'gateway' ), true ) ? $v : 'mobile';
				},
			)
		);
		register_setting( 'ois_cc_settings', 'ois_cc_moneybag_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'ois_cc_settings', 'ois_cc_moneybag_base_url', array( 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( 'ois_cc_settings', 'ois_cc_moneybag_webhook_secret', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'ois_cc_settings', 'ois_cc_recaptcha_site_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'ois_cc_settings', 'ois_cc_recaptcha_secret', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting(
			'ois_cc_settings',
			'ois_cc_recaptcha_threshold',
			array(
				'default'           => 0.5,
				'sanitize_callback' => function ( $v ) {
					return is_numeric( $v ) ? max( 0, min( 1, (float) $v ) ) : 0.5;
				},
			)
		);
		register_setting( 'ois_cc_settings', 'ois_cc_side_image', array( 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( 'ois_cc_settings', 'ois_cc_support_email', array( 'sanitize_callback' => 'sanitize_email', 'default' => 'info@ois.edu.bd' ) );
		register_setting( 'ois_cc_settings', 'ois_cc_access_roles', array( 'sanitize_callback' => array( $this, 'sanitize_role_list' ) ) );
		// ois_cc_access_users is intentionally NOT part of this settings group — it's
		// managed by its own add/remove actions below (one user at a time, with an
		// explicit Remove button) instead of a multi-select box, which is a confusing
		// way to remove just one person from a longer list.
	}

	public function sanitize_role_list( $value ) {
		$editable = array_keys( get_editable_roles() );
		return array_values( array_intersect( (array) $value, $editable ) );
	}

	public function handle_add_user() {
		check_admin_referer( 'ois_cc_add_user' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have access to this page.' );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		if ( $user_id && get_userdata( $user_id ) ) {
			$allowed   = (array) get_option( 'ois_cc_access_users', array() );
			$allowed[] = $user_id;
			update_option( 'ois_cc_access_users', array_values( array_unique( array_map( 'intval', $allowed ) ) ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ois-cc-settings' ) );
		exit;
	}

	public function handle_remove_user() {
		check_admin_referer( 'ois_cc_remove_user' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have access to this page.' );
		}

		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$allowed = (array) get_option( 'ois_cc_access_users', array() );
		update_option( 'ois_cc_access_users', array_values( array_diff( array_map( 'intval', $allowed ), array( $user_id ) ) ) );

		wp_safe_redirect( admin_url( 'admin.php?page=ois-cc-settings' ) );
		exit;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have access to this page.' );
		}

		$allowed_roles = (array) get_option( 'ois_cc_access_roles', array() );
		$allowed_users = (array) get_option( 'ois_cc_access_users', array() );
		?>
		<div class="wrap ois-cc-wrap">
			<h1 class="ois-cc-heading">Cambridge Courses — Settings</h1>
			<form method="post" action="options.php" class="ois-cc-table-card">
				<?php settings_fields( 'ois_cc_settings' ); ?>

				<h2>Student Records API (FEMS)</h2>
				<p class="description">Leave the URL blank to keep using dummy student data for testing — any Student ID will resolve to a test student.</p>
				<table class="form-table">
					<tr><th><label for="ois_cc_fems_api_url">API URL</label></th><td><input type="url" class="regular-text" id="ois_cc_fems_api_url" name="ois_cc_fems_api_url" value="<?php echo esc_attr( get_option( 'ois_cc_fems_api_url' ) ); ?>" placeholder="https://api.fems.education/api/v2/external/student/lookup"><p class="description">Sent as <code>X-Api-Key</code>, with <code>student_id</code> appended as a query arg.</p></td></tr>
					<tr><th><label for="ois_cc_fems_api_key">API Key</label></th><td><input type="text" class="regular-text" id="ois_cc_fems_api_key" name="ois_cc_fems_api_key" value="<?php echo esc_attr( get_option( 'ois_cc_fems_api_key' ) ); ?>"></td></tr>
				</table>

				<h2>OTP Delivery</h2>
				<table class="form-table">
					<tr>
						<th><label for="ois_cc_otp_channel">Send OTP via</label></th>
						<td>
							<select id="ois_cc_otp_channel" name="ois_cc_otp_channel">
								<?php foreach ( array( 'mobile' => 'FEMS — SMS to student mobile', 'email' => 'FEMS — student email', 'gateway' => 'Built-in code + SMS gateway below' ) as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( get_option( 'ois_cc_otp_channel', 'mobile' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">FEMS options use FEMS's own OTP service (<code>/auth/forgot/otp-send</code> + <code>/verify-otp</code>) and only apply once the FEMS API URL above is set; until then the built-in code is used.</p>
						</td>
					</tr>
				</table>

				<h2>SMS Gateway (built-in OTP)</h2>
				<p class="description">Leave the URL blank to keep OTPs in dummy mode — they'll be logged instead of texted.</p>
				<table class="form-table">
					<tr><th><label for="ois_cc_sms_api_url">Gateway URL</label></th><td><input type="url" class="regular-text" id="ois_cc_sms_api_url" name="ois_cc_sms_api_url" value="<?php echo esc_attr( get_option( 'ois_cc_sms_api_url' ) ); ?>" placeholder="https://sms-gateway.example.com/send"></td></tr>
					<tr><th><label for="ois_cc_sms_api_key">Gateway API Key</label></th><td><input type="text" class="regular-text" id="ois_cc_sms_api_key" name="ois_cc_sms_api_key" value="<?php echo esc_attr( get_option( 'ois_cc_sms_api_key' ) ); ?>"></td></tr>
					<tr>
						<th>Test Mode</th>
						<td>
							<label><input type="checkbox" name="ois_cc_test_mode" value="1" <?php checked( get_option( 'ois_cc_test_mode' ), 1 ); ?>> Show the generated OTP on screen (built-in OTP only) and allow the free dummy payment when no Moneybag key is set. <strong>Turn off on the live site.</strong></label>
						</td>
					</tr>
				</table>

				<h2>Payment Gateway (Moneybag)</h2>
				<p class="description">Leave the Merchant API Key blank to keep using the dummy processor (auto-approves every order, no real charge). Once set, <strong>Buy Now</strong> redirects straight to Moneybag's hosted checkout page instead of showing a payment page on this site.</p>
				<table class="form-table">
					<tr><th><label for="ois_cc_moneybag_api_key">Merchant API Key</label></th><td><input type="text" class="regular-text" id="ois_cc_moneybag_api_key" name="ois_cc_moneybag_api_key" value="<?php echo esc_attr( get_option( 'ois_cc_moneybag_api_key' ) ); ?>"></td></tr>
					<tr><th><label for="ois_cc_moneybag_base_url">API Base URL</label></th><td><input type="url" class="regular-text" id="ois_cc_moneybag_base_url" name="ois_cc_moneybag_base_url" value="<?php echo esc_attr( get_option( 'ois_cc_moneybag_base_url' ) ); ?>" placeholder="https://sandbox.api.moneybag.com.bd/api/v2"><p class="description">Sandbox by default. For production, use <code>https://api.moneybag.com.bd/api/v2</code> together with the production API key and webhook secret.</p></td></tr>
					<tr><th><label for="ois_cc_moneybag_webhook_secret">Webhook Signing Secret</label></th><td><input type="text" class="regular-text" id="ois_cc_moneybag_webhook_secret" name="ois_cc_moneybag_webhook_secret" value="<?php echo esc_attr( get_option( 'ois_cc_moneybag_webhook_secret' ) ); ?>"><p class="description">From the Moneybag dashboard's webhook settings. Paste this IPN URL there: <code><?php echo esc_html( rest_url( 'ois-cc/v1/moneybag-ipn' ) ); ?></code></p></td></tr>
				</table>
				<p class="description">Real charges run through Moneybag once a key is set here — test in their sandbox before switching to a production key. Hook <code>ois_cc_process_payment</code> still works as a fallback override on the dummy path.</p>

				<h2>Google reCAPTCHA</h2>
				<p class="description">Invisible spam protection on the Student ID step and OTP resends — no checkbox for students. Create a <strong>reCAPTCHA v3</strong> key at <a href="https://www.google.com/recaptcha/admin/create" target="_blank" rel="noopener">google.com/recaptcha/admin</a> for this domain. Leave either field blank to switch it off.</p>
				<table class="form-table">
					<tr><th><label for="ois_cc_recaptcha_site_key">Site Key</label></th><td><input type="text" class="regular-text" id="ois_cc_recaptcha_site_key" name="ois_cc_recaptcha_site_key" value="<?php echo esc_attr( get_option( 'ois_cc_recaptcha_site_key' ) ); ?>"></td></tr>
					<tr><th><label for="ois_cc_recaptcha_secret">Secret Key</label></th><td><input type="text" class="regular-text" id="ois_cc_recaptcha_secret" name="ois_cc_recaptcha_secret" value="<?php echo esc_attr( get_option( 'ois_cc_recaptcha_secret' ) ); ?>"></td></tr>
					<tr><th><label for="ois_cc_recaptcha_threshold">Score Threshold</label></th><td><input type="number" class="small-text" min="0" max="1" step="0.1" id="ois_cc_recaptcha_threshold" name="ois_cc_recaptcha_threshold" value="<?php echo esc_attr( get_option( 'ois_cc_recaptcha_threshold', 0.5 ) ); ?>"><p class="description">0 (bot) to 1 (human). Requests scoring below this are blocked. Default 0.5; lower it if real students get blocked.</p></td></tr>
				</table>

				<h2>Appearance</h2>
				<table class="form-table">
					<tr><th><label for="ois_cc_support_email">Support Email</label></th><td><input type="email" class="regular-text" id="ois_cc_support_email" name="ois_cc_support_email" value="<?php echo esc_attr( get_option( 'ois_cc_support_email', 'info@ois.edu.bd' ) ); ?>"><p class="description">Shown to students with every error message and on the success page.</p></td></tr>
					<tr><th><label for="ois_cc_side_image">Side Image URL</label></th><td><input type="url" class="regular-text" id="ois_cc_side_image" name="ois_cc_side_image" value="<?php echo esc_attr( get_option( 'ois_cc_side_image' ) ); ?>" placeholder="<?php echo esc_attr( OIS_CC_URL . 'assets/img/cambridge-english.webp' ); ?>"><p class="description">Photo shown beside the form. Leave blank for the bundled Cambridge English photo, or paste a Media Library URL (landscape, at least 1200px wide).</p></td></tr>
				</table>

				<h2>Access Control</h2>
				<p class="description">Administrators always have access. Grant the Transactions dashboard to specific roles and/or individual users below.</p>
				<table class="form-table">
					<tr>
						<th>Allowed Roles</th>
						<td>
							<?php foreach ( get_editable_roles() as $slug => $role ) : ?>
								<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="ois_cc_access_roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $allowed_roles, true ) ); ?>> <?php echo esc_html( translate_user_role( $role['name'] ) ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<div class="ois-cc-table-card" style="margin-top:20px;">
				<h2>Allowed Users</h2>
				<p class="description">Individually granted, in addition to the roles above.</p>
				<?php if ( $allowed_users ) : ?>
					<ul class="ois-cc-user-list">
						<?php foreach ( $allowed_users as $uid ) :
							$u = get_userdata( $uid );
							if ( ! $u ) {
								continue;
							}
							$remove_url = wp_nonce_url( admin_url( 'admin-post.php?action=ois_cc_remove_user&user_id=' . $uid ), 'ois_cc_remove_user' );
							?>
							<li>
								<span><?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?></span>
								<a href="<?php echo esc_url( $remove_url ); ?>" class="button button-small" onclick="return confirm('Remove access for &quot;<?php echo esc_js( $u->display_name ); ?>&quot;?');">Remove</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p><em>No individual users granted access yet.</em></p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
					<input type="hidden" name="action" value="ois_cc_add_user">
					<?php wp_nonce_field( 'ois_cc_add_user' ); ?>
					<select name="user_id">
						<option value="">— Select a user —</option>
						<?php foreach ( get_users( array( 'fields' => array( 'ID', 'display_name', 'user_login' ) ) ) as $u ) :
							if ( in_array( $u->ID, $allowed_users, true ) ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $u->ID ); ?>"><?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( 'Add User', 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}
}
