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
				$color  = 'paid' === $status ? '#0a7d31' : ( 'failed' === $status ? '#b32d2e' : '#64748b' );
				printf( '<strong style="color:%s">%s</strong>', esc_attr( $color ), esc_html( self::status_label( $status ) ) );
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
		update_post_meta( $order_id, '_student_section', isset( $student['section'] ) ? $student['section'] : '' );
		update_post_meta( $order_id, '_student_roll', isset( $student['roll'] ) ? $student['roll'] : '' );
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
	 * Admin-facing statuses. "pending" (checkout started, never finished)
	 * and "canceled" both show as Canceled — the dashboard only speaks in
	 * Success / Failed / Canceled.
	 */
	const STATUS_LABELS = array(
		'success'  => 'Success',
		'failed'   => 'Failed',
		'canceled' => 'Canceled',
	);

	public static function status_key( $stored ) {
		if ( 'paid' === $stored ) {
			return 'success';
		}
		return 'failed' === $stored ? 'failed' : 'canceled';
	}

	public static function status_label( $stored ) {
		return self::STATUS_LABELS[ self::status_key( $stored ) ];
	}

	/** Not yet final: a verified Moneybag payment may still turn it into Success. */
	public static function is_open( $stored ) {
		return in_array( $stored, array( 'pending', 'canceled', '' ), true );
	}

	/**
	 * Student picked/paid for the wrong course. Paid orders stay immutable:
	 * the order switches to the right course, and any shortfall becomes a
	 * linked "balance" order the student pays through a token link. A
	 * cheaper course just lowers the order and records the cash refund due.
	 * Returns array( 'balance_order_id' => int, 'refund' => float ) or WP_Error.
	 *
	 * ponytail: one change per order — no chained re-adjustments.
	 */
	public static function change_course( $order_id, $course_id ) {
		$order  = self::get_order_data( $order_id );
		$course = OIS_CC_Courses::get_course_data( (int) $course_id );
		if ( ! $order || 'paid' !== $order['status'] || get_post_meta( $order_id, '_parent_order', true ) || get_post_meta( $order_id, '_course_changed_from', true ) ) {
			return new WP_Error( 'not_changeable', 'Only a paid, not-yet-adjusted order can be changed.' );
		}
		if ( ! $course || (int) $course_id === (int) get_post_meta( $order_id, '_course_id', true ) ) {
			return new WP_Error( 'bad_course', 'Pick a different, valid course.' );
		}

		$diff = $course['price'] - $order['amount'];
		$out  = array( 'balance_order_id' => 0, 'refund' => 0.0 );

		if ( $diff > 0.009 ) {
			$balance = self::create_order(
				array(
					'id'      => $order['student_id'],
					'name'    => $order['student_name'],
					'mobile'  => get_post_meta( $order_id, '_student_mobile', true ),
					'class'   => $order['student_class'],
					'section' => $order['student_section'],
					'roll'    => $order['student_roll'],
				),
				array(
					'id'    => $course['id'],
					'title' => 'Balance: ' . $course['title'],
					'price' => $diff,
				)
			);
			if ( ! $balance ) {
				return new WP_Error( 'order_failed', 'Could not create the balance order.' );
			}
			update_post_meta( $balance['order_id'], '_parent_order', $order['order_number'] );
			$out['balance_order_id'] = $balance['order_id'];
		} elseif ( $diff < -0.009 ) {
			$out['refund'] = -$diff;
			update_post_meta( $order_id, '_amount', $course['price'] ); // revenue = what we keep
			update_post_meta( $order_id, '_refund_due', $out['refund'] );
		}

		update_post_meta( $order_id, '_course_changed_from', $order['course_title'] );
		update_post_meta( $order_id, '_course_id', $course['id'] );
		update_post_meta( $order_id, '_course_title', $course['title'] );

		return $out;
	}

	/** Public payment link for an unpaid order (Moneybag checkout, no OTP). */
	public static function pay_link( $order_id ) {
		return add_query_arg( 'token', self::ensure_order_token( $order_id ), rest_url( 'ois-cc/v1/pay-link' ) );
	}

	public static function mark_canceled( $order_id ) {
		update_post_meta( $order_id, '_status', 'canceled' );
	}

	public static function mark_failed( $order_id, $reason = '' ) {
		update_post_meta( $order_id, '_status', 'failed' );
		if ( $reason ) {
			update_post_meta( $order_id, '_fail_reason', $reason );
		}
	}

	/**
	 * Opaque token used to look up an order after the browser comes back
	 * from Moneybag's hosted checkout, where the SPA's in-memory session
	 * token is gone (full page reload) — a random token instead of the
	 * guessable, sequential order_id sidesteps IDOR on that public lookup.
	 */
	public static function ensure_order_token( $order_id ) {
		$token = get_post_meta( $order_id, '_order_token', true );
		if ( ! $token ) {
			$token = wp_generate_password( 24, false );
			update_post_meta( $order_id, '_order_token', $token );
		}
		return $token;
	}

	public static function get_order_by_token( $token ) {
		if ( ! $token ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_order_token',
				'meta_value'     => $token,
			)
		);
		return $posts ? self::get_order_data( $posts[0] ) : null;
	}

	public static function get_order_id_by_number( $order_number ) {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'title'          => $order_number,
			)
		);
		return $posts ? (int) $posts[0] : 0;
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
				'course'   => '',
				'from'     => '', // Y-m-d, inclusive; overrides `range` when set
				'to'       => '', // Y-m-d, inclusive
				'orderby'  => 'date',
				'order'    => 'DESC',
				'paged'    => 1,
				'per_page' => 20, // 0 = no limit (used for CSV export)
			)
		);

		list( $join, $where_sql ) = self::filter_sql( $args );

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

	/**
	 * JOINs + WHERE shared by the table, CSV export and stat cards, so all
	 * three always agree on what the current filters match.
	 */
	private static function filter_sql( $args ) {
		global $wpdb;

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

		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare(
				'(p.post_title LIKE %s OR pm_sid.meta_value LIKE %s OR pm_sname.meta_value LIKE %s OR pm_course.meta_value LIKE %s)',
				$like,
				$like,
				$like,
				$like
			);
		}

		if ( ! empty( $args['status'] ) ) {
			$where[] = self::status_where( $args['status'] );
		}

		// Filters on the title stored on the order (not the course ID) so
		// orders for since-deleted/renamed courses still match what's shown.
		if ( ! empty( $args['course'] ) ) {
			$where[] = $wpdb->prepare( 'pm_course.meta_value = %s', $args['course'] );
		}

		$range_clause = self::date_range_clause(
			isset( $args['range'] ) ? $args['range'] : 'all',
			isset( $args['from'] ) ? $args['from'] : '',
			isset( $args['to'] ) ? $args['to'] : ''
		);
		if ( $range_clause ) {
			$where[] = $range_clause;
		}

		return array( $join, implode( ' AND ', $where ) );
	}

	/** SQL condition for an admin status key (success / failed / canceled). */
	private static function status_where( $key ) {
		switch ( $key ) {
			case 'success':
				return "pm_status.meta_value = 'paid'";
			case 'failed':
				return "pm_status.meta_value = 'failed'";
			default:
				return "( pm_status.meta_value IS NULL OR pm_status.meta_value NOT IN ('paid','failed') )";
		}
	}

	/**
	 * Course filter options: only courses that currently exist (published),
	 * in the Courses menu order. Orders for since-deleted courses are still
	 * reachable through the search box.
	 */
	public static function get_course_titles() {
		return array_map(
			function ( $c ) {
				return $c['title'];
			},
			array_values( array_filter( OIS_CC_Courses::get_all_courses() ) )
		);
	}

	public static function date_range_clause( $range, $from = '', $to = '' ) {
		global $wpdb;

		// A custom from/to (either end optional) wins over the preset range.
		$from = self::valid_date( $from );
		$to   = self::valid_date( $to );
		if ( $from || $to ) {
			$clauses = array();
			if ( $from ) {
				$clauses[] = $wpdb->prepare( 'p.post_date >= %s', $from . ' 00:00:00' );
			}
			if ( $to ) {
				$clauses[] = $wpdb->prepare( 'p.post_date <= %s', $to . ' 23:59:59' );
			}
			return implode( ' AND ', $clauses );
		}

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

	/** Returns $date if it's a real Y-m-d date, else ''. */
	public static function valid_date( $date ) {
		$d = DateTime::createFromFormat( '!Y-m-d', (string) $date );
		return $d && $d->format( 'Y-m-d' ) === $date ? $date : '';
	}

	/**
	 * KPI numbers for the stat cards at the top of the Transactions page,
	 * for the same filters as the table (status excluded — the cards break
	 * results down by status themselves).
	 */
	public static function get_stats( $args = array() ) {
		global $wpdb;

		$args['status'] = '';
		list( $join, $where_sql ) = self::filter_sql( $args );

		$total_orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p $join WHERE $where_sql" ); // phpcs:ignore
		$paid_orders  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p $join WHERE $where_sql AND " . self::status_where( 'success' ) ); // phpcs:ignore
		$failed       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p $join WHERE $where_sql AND " . self::status_where( 'failed' ) ); // phpcs:ignore
		$canceled     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p $join WHERE $where_sql AND " . self::status_where( 'canceled' ) ); // phpcs:ignore
		$revenue      = (float) $wpdb->get_var( "SELECT SUM(CAST(pm_amount.meta_value AS DECIMAL(10,2))) FROM {$wpdb->posts} p $join WHERE $where_sql AND pm_status.meta_value = 'paid'" ); // phpcs:ignore

		return array(
			'total_orders' => $total_orders,
			'paid_orders'  => $paid_orders,
			'failed'       => $failed,
			'canceled'     => $canceled,
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
			'student_section'=> get_post_meta( $order_id, '_student_section', true ),
			'student_roll'   => get_post_meta( $order_id, '_student_roll', true ),
			'course_title'   => get_post_meta( $order_id, '_course_title', true ),
			'amount'         => (float) get_post_meta( $order_id, '_amount', true ),
			'status'         => get_post_meta( $order_id, '_status', true ),
			'txn_id'         => get_post_meta( $order_id, '_txn_id', true ),
			'payment_method' => get_post_meta( $order_id, '_payment_method', true ),
			'paid_at'        => get_post_meta( $order_id, '_paid_at', true ),
		);
	}
}
