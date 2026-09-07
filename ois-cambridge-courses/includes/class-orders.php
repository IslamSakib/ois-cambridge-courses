<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "ois_order" custom post type — one post per purchase attempt. Reusing a
 * CPT (instead of a bespoke DB table) gets us the admin list table, search,
 * and revision-free storage for free.
 */
class OIS_CC_Orders {

	const POST_TYPE = 'ois_order';

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
	}

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => 'Orders',
					'singular_name' => 'Order',
					'menu_name'     => 'Course Orders',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => false, // shown via the custom Transactions table instead
				'supports'     => array( 'title' ),
				'capabilities' => array(
					'create_posts' => 'do_not_allow',
				),
				'map_meta_cap' => true,
			)
		);
	}

	public function columns( $columns ) {
		return array(
			'cb'          => $columns['cb'],
			'title'       => 'Order',
			'student'     => 'Student',
			'course'      => 'Course',
			'amount'      => 'Amount',
			'status'      => 'Status',
			'date'        => $columns['date'],
		);
	}

	public function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'student':
				printf(
					'%s (ID: %s)',
					esc_html( get_post_meta( $post_id, '_student_name', true ) ),
					esc_html( get_post_meta( $post_id, '_student_id', true ) )
				);
				break;
			case 'course':
				$course_id = get_post_meta( $post_id, '_course_id', true );
				echo esc_html( $course_id ? get_the_title( $course_id ) : '' );
				break;
			case 'amount':
				echo esc_html( get_post_meta( $post_id, '_amount', true ) ) . ' BDT';
				break;
			case 'status':
				$status = get_post_meta( $post_id, '_status', true );
				$color  = 'paid' === $status ? '#0a7d31' : ( 'failed' === $status ? '#b32d2e' : '#996800' );
				printf( '<strong style="color:%s">%s</strong>', esc_attr( $color ), esc_html( ucfirst( $status ) ) );
				break;
		}
	}

	/**
	 * Create a pending order and return its ID + order number.
	 */
	public static function create_order( $student, $course ) {
		$order_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => 'Order (pending)',
				'post_status' => 'publish',
			)
		);
		if ( is_wp_error( $order_id ) || ! $order_id ) {
			return false;
		}

		$order_number = 'OIS-' . gmdate( 'Ymd' ) . '-' . $order_id;

		update_post_meta( $order_id, '_order_number', $order_number );
		update_post_meta( $order_id, '_student_id', $student['id'] );
		update_post_meta( $order_id, '_student_name', $student['name'] );
		update_post_meta( $order_id, '_student_mobile', $student['mobile'] );
		update_post_meta( $order_id, '_student_class', isset( $student['class'] ) ? $student['class'] : '' );
		update_post_meta( $order_id, '_course_id', $course['id'] );
		update_post_meta( $order_id, '_course_title', $course['title'] );
		update_post_meta( $order_id, '_amount', $course['price'] );
		update_post_meta( $order_id, '_status', 'pending' );

		wp_update_post(
			array(
				'ID'         => $order_id,
				'post_title' => $order_number,
			)
		);

		return array(
			'order_id'     => $order_id,
			'order_number' => $order_number,
		);
	}

	public static function mark_paid( $order_id, $txn_id, $payment_method ) {
		update_post_meta( $order_id, '_status', 'paid' );
		update_post_meta( $order_id, '_txn_id', $txn_id );
		update_post_meta( $order_id, '_payment_method', $payment_method );
		update_post_meta( $order_id, '_paid_at', current_time( 'mysql' ) );
	}

	/**
	 * Shared query used by both the admin Transactions table and CSV export,
	 * so filters/search always match between what's shown and what exports.
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'range'    => 'all',
				'status'   => '',
				'orderby'  => 'date',
				'order'    => 'DESC',
				'paged'    => 1,
				'per_page' => 20, // 0 = no limit (used for CSV export)
			)
		);

		$join = "
			LEFT JOIN {$wpdb->postmeta} pm_sid ON pm_sid.post_id = p.ID AND pm_sid.meta_key = '_student_id'
			LEFT JOIN {$wpdb->postmeta} pm_sname ON pm_sname.post_id = p.ID AND pm_sname.meta_key = '_student_name'
			LEFT JOIN {$wpdb->postmeta} pm_course ON pm_course.post_id = p.ID AND pm_course.meta_key = '_course_title'
			LEFT JOIN {$wpdb->postmeta} pm_amount ON pm_amount.post_id = p.ID AND pm_amount.meta_key = '_amount'
			LEFT JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_status'
			LEFT JOIN {$wpdb->postmeta} pm_method ON pm_method.post_id = p.ID AND pm_method.meta_key = '_payment_method'
			LEFT JOIN {$wpdb->postmeta} pm_txn ON pm_txn.post_id = p.ID AND pm_txn.meta_key = '_txn_id'
		";

		$where = array( "p.post_type = '" . self::POST_TYPE . "'", "p.post_status = 'publish'" );

		if ( $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare(
				'(p.post_title LIKE %s OR pm_sid.meta_value LIKE %s OR pm_sname.meta_value LIKE %s OR pm_course.meta_value LIKE %s)',
				$like,
				$like,
				$like,
				$like
			);
		}

		if ( $args['status'] ) {
			$where[] = $wpdb->prepare( 'pm_status.meta_value = %s', $args['status'] );
		}

		$range_clause = self::date_range_clause( $args['range'] );
		if ( $range_clause ) {
			$where[] = $range_clause;
		}

		$where_sql = implode( ' AND ', $where );

		$orderby_map = array(
			'date'   => 'p.post_date',
			'amount' => 'CAST(pm_amount.meta_value AS DECIMAL(10,2))',
			'status' => 'pm_status.meta_value',
			'course' => 'pm_course.meta_value',
			'student'=> 'pm_sname.meta_value',
		);
		$orderby_sql = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'p.post_date';
		$order_sql   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p $join WHERE $where_sql" ); // phpcs:ignore

		$limit_sql = '';
		if ( $args['per_page'] > 0 ) {
			$offset    = max( 0, ( (int) $args['paged'] - 1 ) * (int) $args['per_page'] );
			$limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', (int) $args['per_page'], $offset );
		}

		$sql = "SELECT p.ID, p.post_title AS order_number, p.post_date,
					pm_sid.meta_value AS student_id,
					pm_sname.meta_value AS student_name,
					pm_course.meta_value AS course_title,
					pm_amount.meta_value AS amount,
					pm_status.meta_value AS status,
					pm_method.meta_value AS payment_method,
					pm_txn.meta_value AS txn_id
				FROM {$wpdb->posts} p $join
				WHERE $where_sql
				ORDER BY $orderby_sql $order_sql
				$limit_sql";

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	public static function date_range_clause( $range ) {
		global $wpdb;
		$now = current_time( 'timestamp' ); // phpcs:ignore

		switch ( $range ) {
			case 'week':
				$start = strtotime( 'monday this week', $now );
				break;
			case 'month':
				$start = strtotime( gmdate( 'Y-m-01', $now ) );
				break;
			case 'year':
				$start = strtotime( gmdate( 'Y-01-01', $now ) );
				break;
			default:
				return '';
		}

		return $wpdb->prepare( 'p.post_date >= %s', gmdate( 'Y-m-d H:i:s', $start ) );
	}

	/**
	 * KPI numbers for the stat cards at the top of the Transactions page.
	 */
	public static function get_stats( $range = 'all' ) {
		global $wpdb;

		$join       = "
			LEFT JOIN {$wpdb->postmeta} pm_amount ON pm_amount.post_id = p.ID AND pm_amount.meta_key = '_amount'
			LEFT JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_status'
		";
		$where      = array( "p.post_type = '" . self::POST_TYPE . "'", "p.post_status = 'publish'" );
		$range_clause = self::date_range_clause( $range );
		if ( $range_clause ) {
			$where[] = $range_clause;
		}
		$where_sql = implode( ' AND ', $where );

		$total_orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p $join WHERE $where_sql" ); // phpcs:ignore
		$paid_orders  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p $join WHERE $where_sql AND pm_status.meta_value = 'paid'" ); // phpcs:ignore
		$pending      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p $join WHERE $where_sql AND pm_status.meta_value = 'pending'" ); // phpcs:ignore
		$revenue      = (float) $wpdb->get_var( "SELECT SUM(CAST(pm_amount.meta_value AS DECIMAL(10,2))) FROM {$wpdb->posts} p $join WHERE $where_sql AND pm_status.meta_value = 'paid'" ); // phpcs:ignore

		return array(
			'total_orders' => $total_orders,
			'paid_orders'  => $paid_orders,
			'pending'      => $pending,
			'revenue'      => $revenue,
		);
	}

	public static function get_order_data( $order_id ) {
		$order = get_post( $order_id );
		if ( ! $order || self::POST_TYPE !== $order->post_type ) {
			return null;
		}
		return array(
			'order_id'       => $order_id,
			'order_number'   => get_post_meta( $order_id, '_order_number', true ),
			'student_id'     => get_post_meta( $order_id, '_student_id', true ),
			'student_name'   => get_post_meta( $order_id, '_student_name', true ),
			'student_class'  => get_post_meta( $order_id, '_student_class', true ),
			'course_title'   => get_post_meta( $order_id, '_course_title', true ),
			'amount'         => (float) get_post_meta( $order_id, '_amount', true ),
			'status'         => get_post_meta( $order_id, '_status', true ),
			'txn_id'         => get_post_meta( $order_id, '_txn_id', true ),
			'payment_method' => get_post_meta( $order_id, '_payment_method', true ),
			'paid_at'        => get_post_meta( $order_id, '_paid_at', true ),
		);
	}
}
