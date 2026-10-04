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
		add_filter( 'rest_post_dispatch', array( $this, 'no_store' ), 10, 3 );
	}

	/**
	 * Never let a page/CDN cache hold these responses — a cached
	 * order-status would keep showing "pending" after payment.
	 */
	public function no_store( $response, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/' . self::NS . '/' ) && $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, max-age=0' );
		}
		return $response;
	}

	/**
	 * Per-IP fixed-window limiter. Stops one client from walking Student IDs
	 * (each hit texts a real family) or brute-forcing OTPs across IDs.
	 * REMOTE_ADDR only — X-Forwarded-For is client-controlled.
	 *
	 * ponytail: transient-based, so counts are approximate under heavy
	 * concurrency; move to an object-cache counter if abuse gets serious.
	 */
	private static function rate_limited( $bucket, $max, $window ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown'; // phpcs:ignore
		$key = 'ois_cc_rl_' . $bucket . '_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= $max ) {
			return true;
		}
		set_transient( $key, $hits + 1, $window );
		return false;
	}

	private static function too_many() {
		return new WP_Error( 'rate_limited', 'Too many attempts. Please wait a few minutes and try again.', array( 'status' => 429 ) );
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
					'captcha'    => array( 'required' => false, 'type' => 'string' ),
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
					'payment_method' => array( 'required' => false, 'type' => 'string' ),
					'email'          => array( 'required' => false, 'type' => 'string' ),
					'return_url'     => array( 'required' => false, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/order-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'order_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array( 'required' => true, 'type' => 'string' ),
					'txn'   => array( 'required' => false, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/moneybag-ipn',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'moneybag_ipn' ),
				'permission_callback' => '__return_true',
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

	/**
	 * Google reCAPTCHA v3 (invisible, score-based), on only once both keys
	 * are set in Settings. Fails closed: an unreachable Google means no OTP.
	 */
	public static function captcha_enabled() {
		return get_option( 'ois_cc_recaptcha_site_key' ) && get_option( 'ois_cc_recaptcha_secret' );
	}

	public static function captcha_ok( $token ) {
		if ( ! self::captcha_enabled() ) {
			return true;
		}
		if ( '' === (string) $token ) {
			return false;
		}
		$res = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => get_option( 'ois_cc_recaptcha_secret' ),
					'response' => $token,
					'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '', // phpcs:ignore
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		// A token minted for another action (or another site's form) doesn't count.
		return ! empty( $body['success'] )
			&& isset( $body['action'] ) && 'verify_student' === $body['action']
			&& isset( $body['score'] ) && (float) $body['score'] >= (float) get_option( 'ois_cc_recaptcha_threshold', 0.5 );
	}

	// Every send and resend carries a fresh v3 token.
	public function verify_student( WP_REST_Request $request ) {
		if ( ! self::captcha_ok( $request->get_param( 'captcha' ) ) ) {
			return new WP_Error( 'captcha_failed', 'Automated request suspected.', array( 'status' => 400 ) );
		}
		return $this->send_otp( $request );
	}

	private function send_otp( WP_REST_Request $request ) {
		if ( self::rate_limited( 'send', 8, 15 * MINUTE_IN_SECONDS ) ) {
			return self::too_many();
		}

		$student_id = sanitize_text_field( $request->get_param( 'student_id' ) );
		$student    = OIS_CC_FEMS_API::get_student( $student_id );

		if ( is_wp_error( $student ) ) {
			return self::student_lookup_error( $student );
		}

		$channel = OIS_CC_FEMS_API::otp_channel();

		$otp = OIS_CC_OTP::generate( $student['id'], (bool) $channel );
		if ( is_wp_error( $otp ) ) {
			return new WP_Error( $otp->get_error_code(), $otp->get_error_message(), array( 'status' => 429 ) );
		}

		$sent = $channel
			? OIS_CC_FEMS_API::send_fems_otp( $student['id'], $channel )
			: OIS_CC_FEMS_API::send_otp_sms( $student['mobile'], $otp );
		if ( is_wp_error( $sent ) ) {
			return new WP_Error( 'sms_failed', 'Could not send OTP. Please try again shortly.', array( 'status' => 502 ) );
		}

		$response = array(
			'masked_mobile' => OIS_CC_FEMS_API::mask_mobile( $student['mobile'] ),
			'masked_email'  => OIS_CC_FEMS_API::mask_email( isset( $student['email'] ) ? $student['email'] : '' ),
			'otp_channel'   => 'email' === $channel ? 'email' : 'mobile',
		);

		// Test mode: surface the OTP in the response so testers aren't
		// blocked without a real SMS gateway configured yet. (FEMS-sent codes
		// never pass through here, so there's nothing to show for those.)
		if ( get_option( 'ois_cc_test_mode' ) && ! $channel ) {
			$response['test_otp'] = $otp;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * "Not found" is the student's to fix; anything else (timeouts, cURL
	 * errors) is ours — say so without leaking transport details.
	 */
	private static function student_lookup_error( WP_Error $e ) {
		if ( in_array( $e->get_error_code(), array( 'not_found', 'invalid_id' ), true ) ) {
			return new WP_Error( $e->get_error_code(), $e->get_error_message(), array( 'status' => 404 ) );
		}
		error_log( '[OIS-CC FEMS lookup] ' . $e->get_error_code() . ': ' . $e->get_error_message() );
		return new WP_Error( 'fems_unavailable', 'Student records are temporarily unavailable.', array( 'status' => 503 ) );
	}

	public function resend_otp( WP_REST_Request $request ) {
		return $this->verify_student( $request );
	}

	public function verify_otp( WP_REST_Request $request ) {
		if ( self::rate_limited( 'verify', 20, 15 * MINUTE_IN_SECONDS ) ) {
			return self::too_many();
		}

		$student_id = sanitize_text_field( $request->get_param( 'student_id' ) );
		$otp        = sanitize_text_field( $request->get_param( 'otp' ) );

		$check = OIS_CC_OTP::verify( $student_id, $otp );
		if ( is_wp_error( $check ) ) {
			return new WP_Error( $check->get_error_code(), $check->get_error_message(), array( 'status' => 400 ) );
		}

		$student = OIS_CC_FEMS_API::get_student( $student_id );
		if ( is_wp_error( $student ) ) {
			return self::student_lookup_error( $student );
		}

		$token = OIS_CC_OTP::start_session( $student );

		// The full mobile/email stay server-side in the session; the frontend
		// already has the masked versions from verify-student.
		$public_student = $student;
		unset( $public_student['mobile'], $public_student['email'] );

		return rest_ensure_response(
			array(
				'session_token' => $token,
				'student'       => $public_student,
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

		$public_student = $student;
		unset( $public_student['mobile'], $public_student['email'] );

		return rest_ensure_response(
			array(
				'order_id'     => $order['order_id'],
				'order_number' => $order['order_number'],
				'student'      => $public_student,
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

		// No Moneybag key configured: the dummy auto-approve gateway runs only
		// with Test Mode on — otherwise removing the key would hand out free
		// "paid" orders.
		if ( ! OIS_CC_Moneybag_API::is_live() ) {
			if ( ! get_option( 'ois_cc_test_mode' ) ) {
				return new WP_Error( 'gateway_unavailable', 'Online payment is temporarily unavailable. Please try again later.', array( 'status' => 503 ) );
			}

			$payment_method = sanitize_text_field( (string) $request->get_param( 'payment_method' ) ) ?: 'dummy';
			$result         = OIS_CC_Payment_Gateway::process( $order_id, $payment_method );

			if ( empty( $result['success'] ) ) {
				return new WP_Error( 'payment_failed', ! empty( $result['message'] ) ? $result['message'] : 'Payment failed.', array( 'status' => 402 ) );
			}

			OIS_CC_Orders::mark_paid( $order_id, $result['txn_id'], $payment_method );

			return rest_ensure_response( OIS_CC_Orders::get_order_data( $order_id ) );
		}

		// Moneybag requires a customer email; there's no email step on our
		// side, so use the one FEMS has on file, else the site admin's.
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! $email ) {
			$email = ! empty( $student['email'] ) ? $student['email'] : get_option( 'admin_email' );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'A valid email is required to continue to payment.', array( 'status' => 400 ) );
		}

		$return_url = esc_url_raw( (string) $request->get_param( 'return_url' ) );
		if ( ! $return_url ) {
			return new WP_Error( 'missing_return_url', 'Missing return URL.', array( 'status' => 400 ) );
		}

		$token = OIS_CC_Orders::ensure_order_token( $order_id );

		$checkout = OIS_CC_Moneybag_API::create_checkout(
			$order,
			array(
				'name'  => $order['student_name'],
				'email' => $email,
				'phone'   => self::normalize_phone( $student['mobile'] ),
				'country' => 'Bangladesh',
			),
			array(
				'success' => add_query_arg( array( 'ois_cc_token' => $token, 'ois_cc_result' => 'success' ), $return_url ),
				'fail'    => add_query_arg( array( 'ois_cc_token' => $token, 'ois_cc_result' => 'fail' ), $return_url ),
				'cancel'  => add_query_arg( array( 'ois_cc_token' => $token, 'ois_cc_result' => 'cancel' ), $return_url ),
				'ipn'     => rest_url( self::NS . '/moneybag-ipn' ),
			)
		);

		if ( is_wp_error( $checkout ) ) {
			return new WP_Error( 'checkout_failed', $checkout->get_error_message(), array( 'status' => 502 ) );
		}

		if ( ! empty( $checkout['session_id'] ) ) {
			update_post_meta( $order_id, '_moneybag_session_id', $checkout['session_id'] );
		}

		return rest_ensure_response( array( 'redirect' => $checkout['checkout_url'] ) );
	}

	/**
	 * Public, token-scoped status check for the page the browser lands back
	 * on after Moneybag's hosted checkout. The redirect out was untrusted
	 * (any query param on it could be forged), so if the order is still
	 * pending and a transaction id came back, this re-checks with Moneybag's
	 * verify API before trusting "paid" — the IPN handler below is the
	 * other, server-to-server path to the same mark_paid() call.
	 */
	public function order_status( WP_REST_Request $request ) {
		$order = OIS_CC_Orders::get_order_by_token( sanitize_text_field( $request->get_param( 'token' ) ) );
		if ( ! $order ) {
			return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
		}

		if ( OIS_CC_Orders::is_open( $order['status'] ) && OIS_CC_Moneybag_API::is_live() ) {
			$txn_id = sanitize_text_field( (string) $request->get_param( 'txn' ) );
			if ( $txn_id ) {
				update_post_meta( $order['order_id'], '_moneybag_txn_id', $txn_id );
			} else {
				$txn_id = get_post_meta( $order['order_id'], '_moneybag_txn_id', true );
			}

			if ( $txn_id ) {
				$record = OIS_CC_Moneybag_API::verify_transaction( $txn_id );
				if ( ! is_wp_error( $record ) && OIS_CC_Moneybag_API::matches_order( $record, $order ) ) {
					OIS_CC_Orders::mark_paid( $order['order_id'], $txn_id, isset( $record['payment_method'] ) ? $record['payment_method'] : 'moneybag' );
					$order = OIS_CC_Orders::get_order_data( $order['order_id'] );
				}
			}
		}

		return rest_ensure_response( $order );
	}

	/**
	 * Server-to-server payment confirmation — the authoritative path (the
	 * browser redirect back is only a UX nicety, never trusted on its own).
	 */
	public function moneybag_ipn( WP_REST_Request $request ) {
		$raw = $request->get_body();

		if ( ! OIS_CC_Moneybag_API::verify_webhook_signature( $raw, $request->get_header( 'x-webhook-signature' ), $request->get_header( 'x-webhook-timestamp' ) ) ) {
			return new WP_Error( 'invalid_signature', 'Invalid signature.', array( 'status' => 401 ) );
		}

		// ponytail: payload field names aren't in the public docs — logged so
		// the lookups below can be corrected against a real delivery.
		error_log( '[OIS-CC moneybag IPN] ' . $raw );

		$payload = json_decode( $raw, true );
		$data    = isset( $payload['data'] ) ? $payload['data'] : $payload;

		$order_number = '';
		foreach ( array( 'order_id', 'merchant_order_id' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$order_number = $data[ $key ];
				break;
			}
		}

		$txn_id = '';
		foreach ( array( 'transaction_id', 'trx_id' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$txn_id = $data[ $key ];
				break;
			}
		}

		if ( ! $order_number ) {
			return rest_ensure_response( array( 'received' => true ) );
		}

		$order_id = OIS_CC_Orders::get_order_id_by_number( sanitize_text_field( $order_number ) );
		if ( ! $order_id ) {
			return rest_ensure_response( array( 'received' => true ) );
		}

		$order = OIS_CC_Orders::get_order_data( $order_id );
		if ( 'paid' === $order['status'] ) {
			return rest_ensure_response( array( 'received' => true ) ); // Success is final
		}

		// Order status follows Moneybag's webhook. The signature above already
		// proves the delivery is genuine, so Failed / Canceled are taken as
		// sent. Success additionally needs the verify API to confirm the
		// payment is for this order and the full amount (a transaction from a
		// cheaper order must never unlock this one).
		$status = strtolower( (string) ( isset( $data['status'] ) ? $data['status'] : '' ) );
		if ( '' === $status ) {
			// Fall back to the event name ("payment.cancelled" -> "cancelled")
			// in case this event type carries no status field.
			$event  = isset( $payload['event'] ) ? $payload['event'] : ( isset( $payload['type'] ) ? $payload['type'] : '' );
			$status = strtolower( (string) substr( strrchr( '.' . $event, '.' ), 1 ) );
		}
		update_post_meta( $order_id, '_moneybag_status', sanitize_key( $status ) );

		if ( in_array( $status, array( 'success', 'successful', 'completed', 'paid' ), true ) ) {
			if ( ! $txn_id ) {
				return rest_ensure_response( array( 'received' => true ) );
			}
			$txn_id = sanitize_text_field( $txn_id );
			$record = OIS_CC_Moneybag_API::verify_transaction( $txn_id );
			if ( is_wp_error( $record ) ) {
				return new WP_Error( 'verify_failed', 'Could not verify transaction.', array( 'status' => 502 ) ); // non-2xx so Moneybag retries
			}
			update_post_meta( $order_id, '_moneybag_txn_id', $txn_id );
			if ( OIS_CC_Moneybag_API::matches_order( $record, $order ) ) {
				OIS_CC_Orders::mark_paid( $order_id, $txn_id, ! empty( $record['payment_method'] ) ? $record['payment_method'] : 'moneybag' );
			}
		} elseif ( in_array( $status, array( 'cancelled', 'canceled', 'cancel' ), true ) ) {
			OIS_CC_Orders::mark_canceled( $order_id );
		} elseif ( in_array( $status, array( 'failed', 'failure', 'fail', 'declined', 'expired' ), true ) ) {
			OIS_CC_Orders::mark_failed( $order_id, $status );
		}

		return rest_ensure_response( array( 'received' => true ) );
	}

	/**
	 * Local 11-digit form (01XXXXXXXXX) — what bKash/Nagad/card pages expect
	 * to prefill in Bangladesh. Moneybag accepts both this and +880.
	 */
	public static function normalize_phone( $mobile ) {
		$digits = preg_replace( '/\D/', '', (string) $mobile );
		if ( 0 === strpos( $digits, '880' ) ) {
			$digits = substr( $digits, 3 );
		}
		if ( 10 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = '0' . $digits;
		}
		return $digits;
	}
}
