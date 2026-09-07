<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OTP generation/verification (transient-backed) and post-verification
 * session tokens, so a student can't skip straight to checkout without
 * actually verifying their mobile number.
 */
class OIS_CC_OTP {

	const TTL          = 5 * MINUTE_IN_SECONDS;
	const RESEND_WAIT  = 60; // seconds
	const MAX_ATTEMPTS = 5;
	const SESSION_TTL  = 30 * MINUTE_IN_SECONDS;

	private static function otp_key( $student_id ) {
		return 'ois_cc_otp_' . md5( $student_id );
	}

	private static function throttle_key( $student_id ) {
		return 'ois_cc_otp_throttle_' . md5( $student_id );
	}

	/**
	 * Generate + store a fresh OTP for this student ID. Returns the plain
	 * OTP (caller is responsible for sending it) or WP_Error if the resend
	 * cooldown hasn't elapsed yet.
	 */
	public static function generate( $student_id ) {
		if ( get_transient( self::throttle_key( $student_id ) ) ) {
			return new WP_Error( 'too_soon', 'Please wait before requesting another OTP.' );
		}

		$otp = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );

		set_transient(
			self::otp_key( $student_id ),
			array(
				'hash'     => wp_hash_password( $otp ),
				'attempts' => 0,
			),
			self::TTL
		);
		set_transient( self::throttle_key( $student_id ), 1, self::RESEND_WAIT );

		return $otp;
	}

	/**
	 * Verify a submitted OTP. Returns true, or WP_Error describing why not.
	 */
	public static function verify( $student_id, $submitted ) {
		$data = get_transient( self::otp_key( $student_id ) );

		if ( ! $data ) {
			return new WP_Error( 'expired', 'OTP expired or not requested. Please request a new one.' );
		}

		if ( $data['attempts'] >= self::MAX_ATTEMPTS ) {
			delete_transient( self::otp_key( $student_id ) );
			return new WP_Error( 'locked', 'Too many incorrect attempts. Please request a new OTP.' );
		}

		if ( ! wp_check_password( $submitted, $data['hash'] ) ) {
			$data['attempts']++;
			set_transient( self::otp_key( $student_id ), $data, self::TTL );
			return new WP_Error( 'incorrect', 'Incorrect OTP. Please try again.' );
		}

		delete_transient( self::otp_key( $student_id ) );
		return true;
	}

	/**
	 * Issue a session token proving this student passed OTP verification.
	 * Stored server-side keyed by the token; the token itself is opaque to
	 * the client.
	 */
	public static function start_session( $student ) {
		$token = wp_generate_password( 32, false );
		set_transient( 'ois_cc_session_' . $token, $student, self::SESSION_TTL );
		return $token;
	}

	public static function get_session( $token ) {
		if ( ! $token ) {
			return null;
		}
		return get_transient( 'ois_cc_session_' . $token );
	}
}
