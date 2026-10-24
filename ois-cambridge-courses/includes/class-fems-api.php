<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the school's student-records API (FEMS) and SMS gateway.
 *
 * Neither has been provided yet, so both fall back to a built-in dummy mode:
 * any Student ID resolves to a fake student record, and OTPs are logged
 * instead of texted. Once real endpoints exist, set them under
 * Cambridge Courses > Settings and dummy mode switches off automatically.
 */
class OIS_CC_FEMS_API {

	public static function is_live() {
		return (bool) get_option( 'ois_cc_fems_api_url' );
	}

	/**
	 * Look up a student by ID. Returns the student's full record as given by
	 * FEMS (id, name, class, mobile, plus whatever else FEMS includes, e.g.
	 * section/roll) so the frontend can display it without this class having
	 * to know every field FEMS might ever add. Or WP_Error if not found.
	 */
	public static function get_student( $student_id ) {
		$student_id = trim( $student_id );
		if ( '' === $student_id ) {
			return new WP_Error( 'invalid_id', 'Student ID is required.' );
		}

		if ( ! self::is_live() ) {
			return array(
				'id'      => $student_id,
				'name'    => 'Test Student ' . $student_id,
				'class'   => 'Cambridge Year 10',
				'section' => 'A',
				'roll'    => '07',
				'mobile'  => '01700000000',
				'email'   => 'teststudent@gmail.com',
			);
		}

		$response = wp_remote_get(
			add_query_arg( 'student_id', $student_id, get_option( 'ois_cc_fems_api_url' ) ),
			array(
				'timeout' => 15,
				'headers' => self::auth_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// FEMS nests the record under `data` (student_id/student_name/...,
		// mobile under `guardian.mobile`) rather than returning it flat, so
		// it's mapped explicitly instead of passed through.
		if ( 200 !== $code || empty( $body['status'] ) || empty( $body['data']['student_id'] ) ) {
			return new WP_Error( 'not_found', 'Student ID not found.' );
		}

		$data = $body['data'];

		return array(
			'id'      => (string) $data['student_id'],
			'name'    => isset( $data['student_name'] ) ? $data['student_name'] : '',
			'class'   => isset( $data['class_name'] ) ? $data['class_name'] : '',
			'section' => isset( $data['section_name'] ) ? $data['section_name'] : '',
			'roll'    => isset( $data['roll'] ) ? (string) $data['roll'] : '',
			'mobile'  => isset( $data['guardian']['mobile'] ) ? $data['guardian']['mobile'] : '',
			'email'   => self::find_email( $data ),
		);
	}

	/**
	 * First valid email in the FEMS record, from any key containing "email"
	 * — FEMS's exact field name isn't documented. Top-level (the student's
	 * own) wins over nested (e.g. guardian), since it's breadth-first.
	 */
	private static function find_email( $data ) {
		$queue = array( $data );
		while ( $queue ) {
			$node = array_shift( $queue );
			foreach ( (array) $node as $key => $value ) {
				if ( is_array( $value ) ) {
					$queue[] = $value;
				} elseif ( false !== stripos( (string) $key, 'email' ) && is_email( trim( (string) $value ) ) ) {
					return trim( $value );
				}
			}
		}
		return '';
	}

	private static function auth_headers() {
		$key = get_option( 'ois_cc_fems_api_key' );
		return $key ? array( 'X-Api-Key' => $key ) : array();
	}

	/**
	 * Send an OTP SMS. Returns true, or WP_Error on failure. In dummy mode
	 * (no gateway configured) it just logs — the OTP is still returned to
	 * the browser by the REST layer when test mode is on, so testers aren't
	 * blocked waiting for real SMS.
	 */
	public static function send_otp_sms( $mobile, $otp ) {
		return self::send_sms( $mobile, sprintf( 'Your Oxford International School OTP is %s. It expires in 5 minutes.', $otp ) );
	}

	/** Any SMS through the gateway in Settings; logs instead when none is set. */
	public static function send_sms( $mobile, $message ) {
		$gateway_url = get_option( 'ois_cc_sms_api_url' );

		if ( ! $gateway_url ) {
			error_log( sprintf( '[OIS-CC dummy SMS] To %s: %s', $mobile, $message ) );
			return true;
		}

		$response = wp_remote_post(
			$gateway_url,
			array(
				'timeout' => 15,
				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), self::sms_auth_headers() ),
				'body'    => wp_json_encode(
					array(
						'to'      => $mobile,
						'message' => $message,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'sms_failed', 'Failed to send SMS.' );
		}

		return true;
	}

	private static function sms_auth_headers() {
		$key = get_option( 'ois_cc_sms_api_key' );
		return $key ? array( 'Authorization' => 'Bearer ' . $key ) : array();
	}

	/**
	 * Which FEMS channel ('email' or 'mobile') sends OTPs, or '' to use the
	 * built-in generator + SMS gateway above. FEMS OTP only applies once the
	 * FEMS lookup is live — dummy students don't exist on FEMS.
	 */
	public static function otp_channel() {
		if ( ! self::is_live() ) {
			return '';
		}
		$channel = get_option( 'ois_cc_otp_channel', 'mobile' );
		return in_array( $channel, array( 'email', 'mobile' ), true ) ? $channel : '';
	}

	private static function otp_base_url() {
		return 'https://api.fems.education/api/v2/auth/forgot/';
	}

	/**
	 * Ask FEMS to generate + deliver an OTP (its forgot-password flow).
	 * Returns true or WP_Error.
	 */
	public static function send_fems_otp( $student_id, $channel ) {
		$result = self::fems_otp_post( 'otp-send', array( 'user_name' => $student_id, 'notify_type' => $channel ) );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Returns true when FEMS accepts the OTP, false when it rejects it, or
	 * WP_Error on a transport failure (so the caller doesn't burn an attempt).
	 *
	 * ponytail: FEMS verify-otp only documents `otp` as required — user_name
	 * is sent too, and if FEMS echoes an id back it must match, but if FEMS
	 * doesn't bind OTPs to users server-side, one account's OTP could pass
	 * for another. Confirm with FEMS.
	 */
	public static function verify_fems_otp( $student_id, $otp ) {
		$result = self::fems_otp_post( 'verify-otp', array( 'user_name' => $student_id, 'otp' => $otp ) );
		if ( is_wp_error( $result ) ) {
			return 'fems_rejected' === $result->get_error_code() ? false : $result;
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		foreach ( array( 'user_name', 'student_id' ) as $key ) {
			if ( isset( $data[ $key ] ) && (string) $data[ $key ] !== (string) $student_id ) {
				return false;
			}
		}
		return true;
	}

	private static function fems_otp_post( $endpoint, $body ) {
		$response = wp_remote_post(
			self::otp_base_url() . $endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code   = wp_remote_retrieve_response_code( $response );
		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		// FEMS signals failure with HTTP 4xx and/or status:false / success:false.
		$ok = $code >= 200 && $code < 300 && is_array( $result )
			&& ( ! empty( $result['status'] ) || ! empty( $result['success'] ) );

		if ( ! $ok ) {
			if ( $code >= 500 || ! is_array( $result ) ) {
				return new WP_Error( 'fems_unavailable', 'Verification service is unavailable. Please try again shortly.' );
			}
			return new WP_Error( 'fems_rejected', ! empty( $result['message'] ) ? $result['message'] : 'Request was rejected.' );
		}

		return $result;
	}

	/**
	 * "infoschoolhhg@gmail.com" -> "inf****hhg@gmail.com": first 3 + last 3
	 * of the name part. Short names keep less visible so the mask never
	 * reveals the whole name (<= 6 chars: first char + last char only).
	 */
	public static function mask_email( $email ) {
		$email = (string) $email;
		$at    = strrpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			return '';
		}
		$local = substr( $email, 0, $at );
		$len   = strlen( $local );
		$keep  = $len > 6 ? 3 : ( $len > 2 ? 1 : 0 );
		$head  = substr( $local, 0, $keep );
		$tail  = $keep ? substr( $local, -$keep ) : '';
		return $head . str_repeat( '*', 4 ) . $tail . substr( $email, $at );
	}

	public static function mask_mobile( $mobile ) {
		$mobile = (string) $mobile;
		$len    = strlen( $mobile );
		if ( $len <= 5 ) {
			return $mobile;
		}
		return substr( $mobile, 0, 3 ) . str_repeat( '*', $len - 5 ) . substr( $mobile, -2 );
	}
}
