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
	 *
	 * $remote = true means FEMS generates and checks the code; this then only
	 * records that one was requested, so the cooldown and attempt cap still
	 * apply locally. Returns true instead of an OTP in that case.
	 */
	public static function generate( $student_id, $remote = false ) {
		if ( get_transient( self::throttle_key( $student_id ) ) ) {
			return new WP_Error( 'too_soon', 'Please wait before requesting another OTP.' );
		}

		$otp = $remote ? true : str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );

		set_transient(
			self::otp_key( $student_id ),
			array(
				'hash'     => $remote ? '' : wp_hash_password( $otp ),
				'remote'   => $remote,
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

		$valid = empty( $data['remote'] )
			? wp_check_password( $submitted, $data['hash'] )
			: OIS_CC_FEMS_API::verify_fems_otp( $student_id, $submitted );

		if ( is_wp_error( $valid ) ) {
			return $valid; // FEMS unreachable — not the student's fault, don't count it.
		}

		if ( ! $valid ) {
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
