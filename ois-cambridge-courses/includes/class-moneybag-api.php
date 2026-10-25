<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps Moneybag's hosted-checkout payment gateway
 * (https://developers.moneybag.com.bd/docs). No credentials means dummy
 * mode stays on (see OIS_CC_Payment_Gateway) — set the Merchant API Key
 * under Cambridge Courses > Settings to go live.
 */
class OIS_CC_Moneybag_API {

	const WEBHOOK_TOLERANCE = 5 * MINUTE_IN_SECONDS;
	const SYNC_DAYS         = 3; // only Failed orders this young are re-checked

	public static function is_live() {
		return (bool) get_option( 'ois_cc_moneybag_api_key' );
	}

	private static function base_url() {
		$url = get_option( 'ois_cc_moneybag_base_url' );
		return $url ? untrailingslashit( $url ) : 'https://sandbox.api.moneybag.com.bd/api/v2';
	}

	private static function headers() {
		return array(
			'X-Merchant-API-Key' => get_option( 'ois_cc_moneybag_api_key' ),
			'Content-Type'       => 'application/json',
		);
	}

	/**
	 * Create a hosted checkout session. $customer needs name/email/phone.
	 * $urls needs success/fail/cancel/ipn. Returns array( 'checkout_url',
	 * 'session_id', ... ) or WP_Error.
	 */
	public static function create_checkout( $order, $customer, $urls ) {
		$response = wp_remote_post(
			self::base_url() . '/payments/checkout',
			array(
				'timeout' => 20,
				'headers' => self::headers(),
				'body'    => wp_json_encode(
					array(
						'order_id'          => $order['order_number'],
						'order_amount'      => number_format( (float) $order['amount'], 2, '.', '' ),
						'order_description' => $order['course_title'],
						'currency'          => 'BDT',
						'success_url'       => $urls['success'],
						'fail_url'          => $urls['fail'],
						'cancel_url'        => $urls['cancel'],
						'ipn_url'           => $urls['ipn'],
						'customer'          => $customer,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['data']['checkout_url'] ) ) {
			return new WP_Error( 'moneybag_checkout_failed', ! empty( $body['message'] ) ? $body['message'] : 'Could not start payment.' );
		}

		return $body['data'];
	}

	/**
	 * Server-side authoritative status check for one transaction. Returns
	 * the verification record or WP_Error.
	 */
	public static function verify_transaction( $transaction_id ) {
		$response = wp_remote_get(
			self::base_url() . '/payments/verify/' . rawurlencode( $transaction_id ),
			array(
				'timeout' => 20,
				'headers' => self::headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['data'] ) ) {
			return new WP_Error( 'verify_failed', ! empty( $body['message'] ) ? $body['message'] : 'Could not verify payment.' );
		}

		// Docs show verify's `data` as an array of records; normalize to one.
		$data = $body['data'];
		return isset( $data[0] ) ? $data[0] : $data;
	}

	/**
	 * A verified payment only counts for an order if it's for *that* order
	 * and covers its full amount — otherwise a transaction ID from a cheap
	 * paid order could be replayed to mark a pricier one paid.
	 */
	public static function matches_order( $record, $order ) {
		if ( ! is_array( $record ) || ! self::is_paid_status( $record ) ) {
			return false;
		}
		if ( ! isset( $record['order_id'] ) || (string) $record['order_id'] !== (string) $order['order_number'] ) {
			return false;
		}
		if ( ! isset( $record['amount'] ) || (float) $record['amount'] + 0.009 < (float) $order['amount'] ) {
			return false;
		}
		return empty( $record['currency'] ) || 'BDT' === strtoupper( $record['currency'] );
	}

	public static function is_paid_status( $record ) {
		if ( empty( $record ) ) {
			return false;
		}
		if ( ! empty( $record['verified'] ) ) {
			return true;
		}
		$status = isset( $record['status'] ) ? strtolower( $record['status'] ) : '';
		return in_array( $status, array( 'success', 'successful', 'completed', 'paid' ), true );
	}

	/**
	 * Cron: re-verify Failed orders from the last SYNC_DAYS days so a payment
	 * Moneybag corrects later — with or without a webhook — flips the order to
	 * Success. Same verify + matches_order gate as the IPN.
	 *
	 * ponytail: capped at 50 orders per run; raise or paginate if more than
	 * that are ever Failed within the window.
	 */
	public static function reconcile_open_orders( $days = self::SYNC_DAYS, $limit = 50 ) {
		$out = array( 'checked' => 0, 'paid' => 0, 'errors' => 0 );
		if ( ! self::is_live() ) {
			return $out;
		}
		$ids = get_posts(
			array(
				'post_type'      => OIS_CC_Orders::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'date_query'     => $days ? array( array( 'after' => $days . ' days ago' ) ) : array(),
				'meta_query'     => array(
					array( 'key' => '_status', 'value' => 'failed' ),
				),
			)
		);
		foreach ( $ids as $order_id ) {
			$r = self::recheck_order( $order_id );
			$out['checked']++;
			if ( 'paid' === $r ) {
				$out['paid']++;
			} elseif ( 'error' === $r ) {
				$out['errors']++;
			}
		}
		return $out;
	}

	/** Only Failed orders from the last SYNC_DAYS days can be synced (button and cron). */
	public static function sync_eligible( $order_id ) {
		return 'failed' === get_post_meta( $order_id, '_status', true )
			&& time() - (int) get_post_time( 'U', true, $order_id ) <= self::SYNC_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Re-verify one Failed order. Returns 'paid', 'unchanged', 'error' or
	 * 'ineligible' (not Failed, or older than SYNC_DAYS days).
	 *
	 * ponytail: with no stored transaction id (abandoned or old cancelled
	 * orders) the order number is tried as the verify id; if Moneybag doesn't
	 * know it, that's 'unchanged', not an error. matches_order() still has to
	 * confirm order number + full amount, so a stray hit can't mark it paid.
	 */
	public static function recheck_order( $order_id ) {
		if ( ! self::is_live() ) {
			return 'error';
		}
		if ( ! self::sync_eligible( $order_id ) ) {
			return 'ineligible';
		}
		$txn_id   = get_post_meta( $order_id, '_moneybag_txn_id', true );
		$fallback = ! $txn_id;
		if ( $fallback ) {
			$txn_id = get_post_meta( $order_id, '_order_number', true );
		}
		if ( ! $txn_id ) {
			return 'unchanged';
		}
		$record = self::verify_transaction( $txn_id );
		if ( is_wp_error( $record ) ) {
			return $fallback ? 'unchanged' : 'error';
		}
		if ( ! self::matches_order( $record, OIS_CC_Orders::get_order_data( $order_id ) ) ) {
			return 'unchanged';
		}
		foreach ( array( 'transaction_id', 'trx_id' ) as $key ) {
			if ( $fallback && ! empty( $record[ $key ] ) ) {
				$txn_id = $record[ $key ];
				break;
			}
		}
		update_post_meta( $order_id, '_moneybag_txn_id', $txn_id );
		OIS_CC_Orders::mark_paid( $order_id, $txn_id, ! empty( $record['payment_method'] ) ? $record['payment_method'] : 'moneybag' );
		return 'paid';
	}

	/**
	 * Verify an inbound IPN/webhook request: HMAC-SHA256 of
	 * "<timestamp>.<raw-body>" against the X-Webhook-Signature header
	 * (format "sha256=<hex>"), using the secret from Settings.
	 *
	 * ponytail: the webhook *payload* field names aren't in the public docs
	 * (full spec needs the authenticated Moneybag dashboard) — only this
	 * signature scheme is documented. handle_ipn() in class-rest-api.php
	 * logs the raw body on first delivery so the field lookups there can be
	 * corrected once a real payload is seen.
	 */
	public static function verify_webhook_signature( $raw_body, $signature_header, $timestamp_header ) {
		$secret = get_option( 'ois_cc_moneybag_webhook_secret' );
		if ( ! $secret || ! $signature_header || ! $timestamp_header ) {
			return false;
		}

		// Reject stale deliveries so a captured request can't be replayed later.
		$ts = is_numeric( $timestamp_header ) ? (int) $timestamp_header : strtotime( $timestamp_header );
		if ( $ts && abs( time() - $ts ) > self::WEBHOOK_TOLERANCE ) {
			return false;
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $timestamp_header . '.' . $raw_body, $secret );
		return hash_equals( $expected, $signature_header );
	}
}
