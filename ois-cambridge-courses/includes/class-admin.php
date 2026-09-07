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

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_menu', array( $this, 'dedupe_menu' ), 999 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 4 );
		add_action( 'admin_post_ois_cc_export_csv', array( $this, 'handle_export' ) );
		add_action( 'admin_post_ois_cc_add_user', array( $this, 'handle_add_user' ) );
		add_action( 'admin_post_ois_cc_remove_user', array( $this, 'handle_remove_user' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
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

		if ( in_array( $user_id, array_map( 'intval', $allowed_users ), true ) || array_intersect( $allowed_roles, (array) $user->roles ) ) {
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
		global $submenu;
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

		$range = isset( $_REQUEST['range'] ) ? sanitize_key( $_REQUEST['range'] ) : 'all';
		$stats = OIS_CC_Orders::get_stats( $range );

		$table = new OIS_CC_Transactions_Table();
		$table->prepare_items();
		?>
		<div class="wrap ois-cc-wrap">
			<h1 class="ois-cc-heading">Cambridge Courses — Transactions</h1>

			<div class="ois-cc-stats">
				<div class="ois-cc-stat-card ois-cc-stat-blue">
					<span class="ois-cc-stat-label">Total Orders</span>
					<span class="ois-cc-stat-value"><?php echo esc_html( number_format_i18n( $stats['total_orders'] ) ); ?></span>
				</div>
				<div class="ois-cc-stat-card ois-cc-stat-green">
					<span class="ois-cc-stat-label">Paid</span>
					<span class="ois-cc-stat-value"><?php echo esc_html( number_format_i18n( $stats['paid_orders'] ) ); ?></span>
				</div>
				<div class="ois-cc-stat-card ois-cc-stat-amber">
					<span class="ois-cc-stat-label">Pending</span>
					<span class="ois-cc-stat-value"><?php echo esc_html( number_format_i18n( $stats['pending'] ) ); ?></span>
				</div>
				<div class="ois-cc-stat-card ois-cc-stat-purple">
					<span class="ois-cc-stat-label">Revenue</span>
					<span class="ois-cc-stat-value">৳<?php echo esc_html( number_format_i18n( $stats['revenue'], 2 ) ); ?></span>
				</div>
			</div>

			<form method="get" class="ois-cc-table-card">
				<input type="hidden" name="page" value="ois-cc-transactions">
				<?php
				$table->search_box( 'Search student, order # or course', 'ois-cc-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	public function handle_export() {
		check_admin_referer( 'ois_cc_export_csv' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'You do not have access to this export.' );
		}

		$result = OIS_CC_Orders::query(
			array(
				'search'   => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
				'range'    => isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : 'all',
				'status'   => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
				'per_page' => 0,
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ois-cc-transactions-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Order #', 'Student Name', 'Student ID', 'Course', 'Amount (BDT)', 'Status', 'Payment Method', 'Transaction ID', 'Date' ) );

		foreach ( $result['rows'] as $row ) {
			fputcsv(
				$out,
				array(
					$row['order_number'],
					$row['student_name'],
					$row['student_id'],
					$row['course_title'],
					$row['amount'],
					$row['status'],
					$row['payment_method'],
					$row['txn_id'],
					$row['post_date'],
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
					<tr><th><label for="ois_cc_fems_api_url">API URL</label></th><td><input type="url" class="regular-text" id="ois_cc_fems_api_url" name="ois_cc_fems_api_url" value="<?php echo esc_attr( get_option( 'ois_cc_fems_api_url' ) ); ?>" placeholder="https://fems.ois.edu.bd/api/students"></td></tr>
					<tr><th><label for="ois_cc_fems_api_key">API Key</label></th><td><input type="text" class="regular-text" id="ois_cc_fems_api_key" name="ois_cc_fems_api_key" value="<?php echo esc_attr( get_option( 'ois_cc_fems_api_key' ) ); ?>"></td></tr>
				</table>

				<h2>SMS Gateway (OTP)</h2>
				<p class="description">Leave the URL blank to keep OTPs in dummy mode — they'll be logged instead of texted.</p>
				<table class="form-table">
					<tr><th><label for="ois_cc_sms_api_url">Gateway URL</label></th><td><input type="url" class="regular-text" id="ois_cc_sms_api_url" name="ois_cc_sms_api_url" value="<?php echo esc_attr( get_option( 'ois_cc_sms_api_url' ) ); ?>" placeholder="https://sms-gateway.example.com/send"></td></tr>
					<tr><th><label for="ois_cc_sms_api_key">Gateway API Key</label></th><td><input type="text" class="regular-text" id="ois_cc_sms_api_key" name="ois_cc_sms_api_key" value="<?php echo esc_attr( get_option( 'ois_cc_sms_api_key' ) ); ?>"></td></tr>
					<tr>
						<th>Test Mode</th>
						<td>
							<label><input type="checkbox" name="ois_cc_test_mode" value="1" <?php checked( get_option( 'ois_cc_test_mode' ), 1 ); ?>> Show the generated OTP on screen (for testing without a live SMS gateway)</label>
						</td>
					</tr>
				</table>

				<h2>Payment Gateway</h2>
				<p class="description">No gateway credentials yet — payments run through a dummy processor that auto-approves every order. Hook <code>ois_cc_process_payment</code> once the school's real gateway is ready; no settings needed here until then.</p>

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
