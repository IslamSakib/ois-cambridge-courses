<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payment processing. No real gateway credentials exist yet, so this always
 * runs the dummy path (auto-approves, generates a fake transaction ID).
 *
 * To wire in the school's real gateway later: hook 'ois_cc_process_payment'
 * — return an array( 'success' => bool, 'txn_id' => string, 'message' => string )
 * from your callback and this class's dummy default is skipped.
 */
class OIS_CC_Payment_Gateway {

	public static function process( $order_id, $payment_method ) {
		$result = apply_filters( 'ois_cc_process_payment', null, $order_id, $payment_method );

		if ( is_array( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'txn_id'  => 'DUMMY-' . strtoupper( wp_generate_password( 10, false ) ),
			'message' => 'Payment approved (dummy gateway — no real charge was made).',
		);
	}
}
