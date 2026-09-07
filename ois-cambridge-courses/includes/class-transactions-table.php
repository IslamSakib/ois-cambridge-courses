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

	protected function get_current_filters() {
		return array(
			'search' => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'range'  => isset( $_REQUEST['range'] ) ? sanitize_key( $_REQUEST['range'] ) : 'all',
			'status' => isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : '',
		);
	}

	public function prepare_items() {
		$per_page = 20;
		$paged    = $this->get_pagenum();
		$filters  = $this->get_current_filters();

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
		return $item['payment_method'] ? esc_html( ucfirst( $item['payment_method'] ) ) : '—';
	}

	public function column_status( $item ) {
		$status = $item['status'] ? $item['status'] : 'pending';
		$map    = array(
			'paid'    => array( '#0a7d31', '#e6f6ea' ),
			'pending' => array( '#996800', '#fff6e0' ),
			'failed'  => array( '#b32d2e', '#fde8e8' ),
		);
		list( $fg, $bg ) = isset( $map[ $status ] ) ? $map[ $status ] : $map['pending'];
		return sprintf(
			'<span class="ois-cc-badge" style="color:%s;background:%s">%s</span>',
			esc_attr( $fg ),
			esc_attr( $bg ),
			esc_html( ucfirst( $status ) )
		);
	}

	public function column_post_date( $item ) {
		return esc_html( mysql2date( 'M j, Y g:i a', $item['post_date'] ) );
	}

	public function no_items() {
		echo 'No transactions found for the current filters.';
	}

	/**
	 * Adds the date-range and status dropdown filters above the table,
	 * next to the native search box.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$filters = $this->get_current_filters();
		?>
		<div class="alignleft actions">
			<select name="range">
				<?php foreach ( array( 'all' => 'All Time', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year' ) as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['range'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="status">
				<option value="">All Statuses</option>
				<?php foreach ( array( 'paid' => 'Paid', 'pending' => 'Pending', 'failed' => 'Failed' ) as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( 'Filter', '', 'filter_action', false ); ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( $filters, array( 'action' => 'ois_cc_export_csv' ) ), admin_url( 'admin-post.php' ) ), 'ois_cc_export_csv' ) ); ?>">Export CSV</a>
		</div>
		<?php
	}
}
