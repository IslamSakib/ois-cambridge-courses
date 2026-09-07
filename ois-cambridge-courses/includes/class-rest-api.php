<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes backing the frontend wizard. All routes are public
 * (students aren't logged into WordPress) but every state-changing step
 * after OTP verification requires the session token issued by verify-otp,
 * so a course order can't be created for an ID that never proved mobile
 * ownership.
 */
class OIS_CC_REST_API {

	const NS = 'ois-cc/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NS,
			'/verify-student',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify_student' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'student_id' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/resend-otp',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resend_otp' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'student_id' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/verify-otp',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify_otp' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'student_id' => array( 'required' => true, 'type' => 'string' ),
					'otp'        => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/courses',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_courses' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/create-order',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_order' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'session_token' => array( 'required' => true, 'type' => 'string' ),
					'course_id'     => array( 'required' => true, 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pay',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'pay' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'session_token'  => array( 'required' => true, 'type' => 'string' ),
					'order_id'       => array( 'required' => true, 'type' => 'integer' ),
					'payment_method' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
	}

	private function require_session( WP_REST_Request $request ) {
		$student = OIS_CC_OTP::get_session( $request->get_param( 'session_token' ) );
		if ( ! $student ) {
			return new WP_Error( 'session_expired', 'Your session has expired. Please verify your Student ID again.', array( 'status' => 401 ) );
		}
		return $student;
	}

	public function verify_student( WP_REST_Request $request ) {
		$student_id = sanitize_text_field( $request->get_param( 'student_id' ) );
		$student    = OIS_CC_FEMS_API::get_student( $student_id );

		if ( is_wp_error( $student ) ) {
			return new WP_Error( $student->get_error_code(), $student->get_error_message(), array( 'status' => 404 ) );
		}

		$otp = OIS_CC_OTP::generate( $student['id'] );
		if ( is_wp_error( $otp ) ) {
			return new WP_Error( $otp->get_error_code(), $otp->get_error_message(), array( 'status' => 429 ) );
		}

		$sms_result = OIS_CC_FEMS_API::send_otp_sms( $student['mobile'], $otp );
		if ( is_wp_error( $sms_result ) ) {
			return new WP_Error( 'sms_failed', 'Could not send OTP. Please try again shortly.', array( 'status' => 502 ) );
		}

		$response = array(
			'masked_mobile' => OIS_CC_FEMS_API::mask_mobile( $student['mobile'] ),
		);

		// Test mode: surface the OTP in the response so testers aren't
		// blocked without a real SMS gateway configured yet.
		if ( get_option( 'ois_cc_test_mode' ) ) {
			$response['test_otp'] = $otp;
		}

		return rest_ensure_response( $response );
	}

	public function resend_otp( WP_REST_Request $request ) {
		return $this->verify_student( $request );
	}

	public function verify_otp( WP_REST_Request $request ) {
		$student_id = sanitize_text_field( $request->get_param( 'student_id' ) );
		$otp        = sanitize_text_field( $request->get_param( 'otp' ) );

		$check = OIS_CC_OTP::verify( $student_id, $otp );
		if ( is_wp_error( $check ) ) {
			return new WP_Error( $check->get_error_code(), $check->get_error_message(), array( 'status' => 400 ) );
		}

		$student = OIS_CC_FEMS_API::get_student( $student_id );
		if ( is_wp_error( $student ) ) {
			return new WP_Error( $student->get_error_code(), $student->get_error_message(), array( 'status' => 404 ) );
		}

		$token = OIS_CC_OTP::start_session( $student );

		return rest_ensure_response(
			array(
				'session_token' => $token,
				'student'       => array(
					'name'  => $student['name'],
					'id'    => $student['id'],
					'class' => $student['class'],
				),
			)
		);
	}

	public function get_courses() {
		return rest_ensure_response( OIS_CC_Courses::get_all_courses() );
	}

	public function create_order( WP_REST_Request $request ) {
		$student = $this->require_session( $request );
		if ( is_wp_error( $student ) ) {
			return $student;
		}

		$course = OIS_CC_Courses::get_course_data( (int) $request->get_param( 'course_id' ) );
		if ( ! $course ) {
			return new WP_Error( 'course_not_found', 'Course not found.', array( 'status' => 404 ) );
		}

		$order = OIS_CC_Orders::create_order( $student, $course );
		if ( ! $order ) {
			return new WP_Error( 'order_failed', 'Could not create order.', array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'order_id'     => $order['order_id'],
				'order_number' => $order['order_number'],
				'student'      => $student,
				'course'       => $course,
			)
		);
	}

	public function pay( WP_REST_Request $request ) {
		$student = $this->require_session( $request );
		if ( is_wp_error( $student ) ) {
			return $student;
		}

		$order_id = (int) $request->get_param( 'order_id' );
		$order    = OIS_CC_Orders::get_order_data( $order_id );

		if ( ! $order || (string) $order['student_id'] !== (string) $student['id'] ) {
			return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
		}

		if ( 'paid' === $order['status'] ) {
			return rest_ensure_response( $order );
		}

		$payment_method = sanitize_text_field( $request->get_param( 'payment_method' ) );
		$result         = OIS_CC_Payment_Gateway::process( $order_id, $payment_method );

		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'payment_failed', ! empty( $result['message'] ) ? $result['message'] : 'Payment failed.', array( 'status' => 402 ) );
		}

		OIS_CC_Orders::mark_paid( $order_id, $result['txn_id'], $payment_method );

		return rest_ensure_response( OIS_CC_Orders::get_order_data( $order_id ) );
	}
}
