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
	 * Look up a student by ID. Returns array( 'id', 'name', 'class', 'mobile' )
	 * or WP_Error if the ID doesn't exist.
	 */
	public static function get_student( $student_id ) {
		$student_id = trim( $student_id );
		if ( '' === $student_id ) {
			return new WP_Error( 'invalid_id', 'Student ID is required.' );
		}

		if ( ! self::is_live() ) {
			return array(
				'id'     => $student_id,
				'name'   => 'Test Student ' . $student_id,
				'class'  => 'Cambridge Year 10',
				'mobile' => '01700000000',
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

		if ( 200 !== $code || empty( $body['id'] ) ) {
			return new WP_Error( 'not_found', 'Student ID not found.' );
		}

		return array(
			'id'     => $body['id'],
			'name'   => isset( $body['name'] ) ? $body['name'] : '',
			'class'  => isset( $body['class'] ) ? $body['class'] : '',
			'mobile' => isset( $body['mobile'] ) ? $body['mobile'] : '',
		);
	}

	private static function auth_headers() {
		$key = get_option( 'ois_cc_fems_api_key' );
		return $key ? array( 'Authorization' => 'Bearer ' . $key ) : array();
	}

	/**
	 * Send an OTP SMS. Returns true, or WP_Error on failure. In dummy mode
	 * (no gateway configured) it just logs — the OTP is still returned to
	 * the browser by the REST layer when test mode is on, so testers aren't
	 * blocked waiting for real SMS.
	 */
	public static function send_otp_sms( $mobile, $otp ) {
		$gateway_url = get_option( 'ois_cc_sms_api_url' );

		if ( ! $gateway_url ) {
			error_log( sprintf( '[OIS-CC dummy SMS] To %s: your Oxford International School OTP is %s', $mobile, $otp ) );
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
						'message' => sprintf( 'Your Oxford International School OTP is %s. It expires in 5 minutes.', $otp ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'sms_failed', 'Failed to send OTP SMS.' );
		}

		return true;
	}

	private static function sms_auth_headers() {
		$key = get_option( 'ois_cc_sms_api_key' );
		return $key ? array( 'Authorization' => 'Bearer ' . $key ) : array();
	}

	public static function mask_mobile( $mobile ) {
		$mobile = (string) $mobile;
		$len    = strlen( $mobile );
		if ( $len <= 4 ) {
			return $mobile;
		}
		return str_repeat( '*', $len - 4 ) . substr( $mobile, -4 );
	}
}
