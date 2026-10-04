<?php
/**
 * Runnable check for the plugin's security-critical logic, with minimal
 * WordPress stubs. Run: php tests/security-check.php  (exit 0 = all pass)
 */

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['opts']       = array();
$GLOBALS['transients'] = array();
function get_option( $k, $d = false ) { return isset( $GLOBALS['opts'][ $k ] ) ? $GLOBALS['opts'][ $k ] : $d; }
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function add_action() {}
function wp_remote_post( $u, $a ) { return $GLOBALS['remote']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_filter() {}
class WP_Error { public $code; function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; } }

$base = dirname( __DIR__ ) . '/ois-cambridge-courses/includes/';
require $base . 'class-moneybag-api.php';
require $base . 'class-fems-api.php';
require $base . 'class-rest-api.php';
require $base . 'class-admin.php';

$fails = 0;
function check( $name, $cond ) {
	global $fails;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	$fails += $cond ? 0 : 1;
}
function priv( $class, $method ) {
	$m = new ReflectionMethod( $class, $method );
	$m->setAccessible( true );
	return function () use ( $m ) { return $m->invokeArgs( null, func_get_args() ); };
}

/* ---- payment must match the order it's applied to ---- */
$order = array( 'order_number' => 'OIS-20260929-2', 'amount' => 16300.0 );
$paid  = array( 'order_id' => 'OIS-20260929-2', 'amount' => '16300.0000', 'status' => 'SUCCESS', 'verified' => true, 'currency' => 'BDT' );
check( 'genuine payment for this order is accepted', OIS_CC_Moneybag_API::matches_order( $paid, $order ) );
check( 'txn from a different (cheaper) order is rejected', ! OIS_CC_Moneybag_API::matches_order( array_merge( $paid, array( 'order_id' => 'OIS-20260929-1', 'amount' => '6400' ) ), $order ) );
check( 'underpayment is rejected', ! OIS_CC_Moneybag_API::matches_order( array_merge( $paid, array( 'amount' => '16299.00' ) ), $order ) );
check( 'unpaid status is rejected', ! OIS_CC_Moneybag_API::matches_order( array_merge( $paid, array( 'status' => 'FAILED', 'verified' => false ) ), $order ) );
check( 'wrong currency is rejected', ! OIS_CC_Moneybag_API::matches_order( array_merge( $paid, array( 'currency' => 'USD' ) ), $order ) );
check( 'record without order_id is rejected', ! OIS_CC_Moneybag_API::matches_order( array( 'status' => 'SUCCESS', 'amount' => '99999' ), $order ) );

/* ---- webhook signature + replay window ---- */
$GLOBALS['opts']['ois_cc_moneybag_webhook_secret'] = 'whsec_test';
$body = '{"order_id":"x"}';
$now  = (string) time();
$sig  = 'sha256=' . hash_hmac( 'sha256', $now . '.' . $body, 'whsec_test' );
check( 'valid signature accepted', OIS_CC_Moneybag_API::verify_webhook_signature( $body, $sig, $now ) );
check( 'tampered body rejected', ! OIS_CC_Moneybag_API::verify_webhook_signature( $body . ' ', $sig, $now ) );
check( 'missing signature rejected', ! OIS_CC_Moneybag_API::verify_webhook_signature( $body, '', $now ) );
$old    = (string) ( time() - 3600 );
$oldsig = 'sha256=' . hash_hmac( 'sha256', $old . '.' . $body, 'whsec_test' );
check( 'replayed (1h old) delivery rejected', ! OIS_CC_Moneybag_API::verify_webhook_signature( $body, $oldsig, $old ) );
$GLOBALS['opts']['ois_cc_moneybag_webhook_secret'] = '';
check( 'no secret configured = reject everything', ! OIS_CC_Moneybag_API::verify_webhook_signature( $body, $sig, $now ) );

/* ---- per-IP rate limit ---- */
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$rl  = priv( 'OIS_CC_REST_API', 'rate_limited' );
$hit = array();
for ( $i = 0; $i < 10; $i++ ) { $hit[] = $rl( 'send', 8, 900 ); }
check( 'first 8 requests allowed, then blocked', array_slice( $hit, 0, 8 ) === array_fill( 0, 8, false ) && $hit[8] && $hit[9] );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
check( 'other IPs unaffected', false === $rl( 'send', 8, 900 ) );

/* ---- CSV formula injection ---- */
$cell = priv( 'OIS_CC_Admin', 'csv_cell' );
check( '=HYPERLINK neutralised', "'=HYPERLINK(\"x\")" === $cell( '=HYPERLINK("x")' ) );
check( '+/-/@ neutralised', "'+1" === $cell( '+1' ) && "'-1" === $cell( '-1' ) && "'@x" === $cell( '@x' ) );
check( 'normal values untouched', 'Devdoot Biswas' === $cell( 'Devdoot Biswas' ) && '6400' === $cell( '6400' ) );

/* ---- PII masking ---- */
check( 'email masked to first 3 + last 3', 'inf****hhg@gmail.com' === OIS_CC_FEMS_API::mask_email( 'infoschoolhhg@gmail.com' ) );
check( 'short email never fully revealed', 'j****n@gmail.com' === OIS_CC_FEMS_API::mask_email( 'jhon@gmail.com' ) && '****@yahoo.com' === OIS_CC_FEMS_API::mask_email( 'ab@yahoo.com' ) );
check( 'not an email -> empty', '' === OIS_CC_FEMS_API::mask_email( 'nope' ) && '' === OIS_CC_FEMS_API::mask_email( '@x.com' ) );

/* ---- phone sent to Moneybag in local 01XXXXXXXXX form ---- */
check( '+880 / 880 / 0-prefixed / bare 10-digit all normalise', array( '01912345690', '01912345690', '01912345690', '01912345690' ) === array_map( array( 'OIS_CC_REST_API', 'normalize_phone' ), array( '+8801912345690', '8801912345690', '01912345690', '1912345690' ) ) );

/* ---- admin status model: only Success / Failed / Canceled ---- */
require dirname( __DIR__ ) . '/ois-cambridge-courses/includes/class-orders.php';
check( 'paid -> Success, failed -> Failed', 'Success' === OIS_CC_Orders::status_label( 'paid' ) && 'Failed' === OIS_CC_Orders::status_label( 'failed' ) );
check( 'pending / canceled / empty -> Canceled', 'Canceled' === OIS_CC_Orders::status_label( 'pending' ) && 'Canceled' === OIS_CC_Orders::status_label( 'canceled' ) && 'Canceled' === OIS_CC_Orders::status_label( '' ) );
check( 'canceled orders can still be upgraded by a verified payment', OIS_CC_Orders::is_open( 'canceled' ) && ! OIS_CC_Orders::is_open( 'paid' ) && ! OIS_CC_Orders::is_open( 'failed' ) );
check( 'mobile masked', '019******90' === OIS_CC_FEMS_API::mask_mobile( '01912345690' ) );
$find = priv( 'OIS_CC_FEMS_API', 'find_email' );
check( 'student email preferred over guardian', 'kid@ois.edu.bd' === $find( array( 'student_email' => 'kid@ois.edu.bd', 'guardian' => array( 'email' => 'dad@gmail.com' ) ) ) );
check( 'invalid email ignored', '' === $find( array( 'email' => 'not-an-email' ) ) );

/* ---- reCAPTCHA v3: off until both keys set, then score + action checked, fails closed ---- */
check( 'captcha off without keys', OIS_CC_REST_API::captcha_ok( '' ) );
$GLOBALS['opts']['ois_cc_recaptcha_site_key']  = 'site';
$GLOBALS['opts']['ois_cc_recaptcha_secret']    = 'secret';
$GLOBALS['opts']['ois_cc_recaptcha_threshold'] = 0.4;
$GLOBALS['remote'] = array( 'body' => '{"success":true,"score":0.7,"action":"verify_student"}' );
check( 'human score accepted', OIS_CC_REST_API::captcha_ok( 'tok' ) );
check( 'missing token rejected', ! OIS_CC_REST_API::captcha_ok( '' ) );
$GLOBALS['remote'] = array( 'body' => '{"success":true,"score":0.3,"action":"verify_student"}' );
check( 'score below threshold rejected', ! OIS_CC_REST_API::captcha_ok( 'tok' ) );
$GLOBALS['remote'] = array( 'body' => '{"success":true,"score":0.9,"action":"login"}' );
check( 'token for another action rejected', ! OIS_CC_REST_API::captcha_ok( 'tok' ) );
$GLOBALS['remote'] = array( 'body' => '{"success":false}' );
check( 'invalid token rejected', ! OIS_CC_REST_API::captcha_ok( 'tok' ) );
$GLOBALS['remote'] = new WP_Error( 'http' );
check( 'Google unreachable -> rejected', ! OIS_CC_REST_API::captcha_ok( 'tok' ) );

// Access: the accounts role sees Transactions, any other non-admin does not.
function user_can( $id, $cap ) { return false; }
function get_userdata( $id ) { return (object) array( 'roles' => $GLOBALS['roles'] ); }
$admin = new OIS_CC_Admin();
$GLOBALS['roles'] = array( OIS_CC_Admin::ROLE );
check( 'accounts role granted transactions', array( 'exist' ) === $admin->map_meta_cap( array(), OIS_CC_Admin::CAP, 5, array() ) );
$GLOBALS['roles'] = array( 'contributor' );
check( 'other role denied transactions', array( 'do_not_allow' ) === $admin->map_meta_cap( array(), OIS_CC_Admin::CAP, 5, array() ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
