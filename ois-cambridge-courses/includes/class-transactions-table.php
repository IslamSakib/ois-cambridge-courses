<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The Transactions table. Built on core WP_List_Table so pagination,
 * sorting, and the search box come for free — only the columns, query,
 * and status badges are custom.
 */
class OIS_CC_Transactions_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'transaction',
				'plural'   => 'transactions',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'order_number' => 'Order #',
			'student'      => 'Student',
			'course_title' => 'Course',
			'amount'       => 'Amount',
			'payment_method' => 'Method',
			'status'       => 'Status',
			'post_date'    => 'Date',
		);
	}

	public function get_sortable_columns() {
		return array(
			'amount'    => array( 'amount', false ),
			'status'    => array( 'status', false ),
			'post_date' => array( 'date', true ),
		);
	}

	/**
	 * Filters from the current request. Static so the stat cards and CSV
	 * export (admin-post.php, no list table) read exactly the same values.
	 */
	public static function current_filters() {
		return array(
			'search' => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'range'  => isset( $_REQUEST['range'] ) ? sanitize_key( $_REQUEST['range'] ) : 'all',
			'status' => isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : '',
			'course' => isset( $_REQUEST['course'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['course'] ) ) : '',
			'from'   => isset( $_REQUEST['from'] ) ? OIS_CC_Orders::valid_date( sanitize_text_field( wp_unslash( $_REQUEST['from'] ) ) ) : '',
			'to'     => isset( $_REQUEST['to'] ) ? OIS_CC_Orders::valid_date( sanitize_text_field( wp_unslash( $_REQUEST['to'] ) ) ) : '',
		);
	}

	public function prepare_items() {
		$per_page = 20;
		$paged    = $this->get_pagenum();
		$filters  = self::current_filters();

		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'date';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'desc';

		$result = OIS_CC_Orders::query(
			array_merge(
				$filters,
				array(
					'orderby'  => 'post_date' === $orderby ? 'date' : $orderby,
					'order'    => $order,
					'paged'    => $paged,
					'per_page' => $per_page,
				)
			)
		);

		$this->items = $result['rows'];

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => ceil( $result['total'] / $per_page ),
			)
		);
	}

	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '—';
	}

	public function column_order_number( $item ) {
		return '<strong>' . esc_html( $item['order_number'] ) . '</strong>' . ( $item['txn_id'] ? '<br><span class="ois-cc-txn">' . esc_html( $item['txn_id'] ) . '</span>' : '' );
	}

	public function column_student( $item ) {
		return esc_html( $item['student_name'] ) . '<br><span class="ois-cc-muted">ID: ' . esc_html( $item['student_id'] ) . '</span>';
	}

	public function column_amount( $item ) {
		return '৳' . number_format_i18n( (float) $item['amount'], 2 );
	}

	public function column_payment_method( $item ) {
		// Moneybag reports methods in mixed case (MASTER, NAGAD, bkash).
		return $item['payment_method'] ? esc_html( ucwords( strtolower( $item['payment_method'] ) ) ) : '—';
	}

	public function column_status( $item ) {
		$key = OIS_CC_Orders::status_key( $item['status'] );
		$map = array(
			'success'  => array( '#0a7d31', '#e6f6ea' ),
			'failed'   => array( '#b32d2e', '#fde8e8' ),
			'canceled' => array( '#475569', '#eef1f5' ),
		);
		list( $fg, $bg ) = $map[ $key ];
		return sprintf(
			'<span class="ois-cc-badge" style="color:%s;background:%s">%s</span>',
			esc_attr( $fg ),
			esc_attr( $bg ),
			esc_html( OIS_CC_Orders::STATUS_LABELS[ $key ] )
		);
	}

	public function column_post_date( $item ) {
		return esc_html( mysql2date( 'M j, Y g:i a', $item['post_date'] ) );
	}

	public function no_items() {
		echo 'No transactions found for the current filters.';
	}

	/**
	 * Adds the course, date-range and status filters above the table,
	 * next to the native search box. A From/To date overrides the preset.
	 * Filters apply as soon as a value changes (see render_transactions_page);
	 * the Filter button only exists as a no-JS fallback.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$filters = self::current_filters();
		?>
		<div class="alignleft actions">
			<select name="course">
				<option value="">All Courses</option>
				<?php foreach ( OIS_CC_Orders::get_course_titles() as $title ) : ?>
					<option value="<?php echo esc_attr( $title ); ?>" <?php selected( $filters['course'], $title ); ?>><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="range">
				<?php foreach ( array( 'all' => 'All Time', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year' ) as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['range'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="ois-cc-date">From <input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" max="<?php echo esc_attr( $filters['to'] ); ?>"></label>
			<label class="ois-cc-date">To <input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" min="<?php echo esc_attr( $filters['from'] ); ?>"></label>
			<select name="status">
				<option value="">All Statuses</option>
				<?php foreach ( OIS_CC_Orders::STATUS_LABELS as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<noscript><?php submit_button( 'Filter', '', 'filter_action', false ); ?></noscript>
			<?php if ( array_filter( array( $filters['course'], $filters['from'], $filters['to'], $filters['status'], $filters['search'] ) ) || 'all' !== $filters['range'] ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ois-cc-transactions' ) ); ?>">Reset</a>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( urlencode_deep( array_merge( $filters, array( 's' => $filters['search'], 'action' => 'ois_cc_export_csv' ) ) ), admin_url( 'admin-post.php' ) ), 'ois_cc_export_csv' ) ); ?>">Export CSV</a>
		</div>
		<?php
	}
}
