( function () {
	'use strict';

	var root = document.getElementById( 'ois-cc-app' );
	if ( ! root || typeof OIS_CC === 'undefined' ) {
		return;
	}

	var state = {
		step: 'student-id',
		studentId: '',
		maskedMobile: '',
		maskedEmail: '',
		otpChannel: 'mobile',
		sessionToken: '',
		student: null,
		courses: [],
		selectedCourse: null,
		order: null,
		busy: false,
		error: '',
		resendCooldown: 0,
		openCourse: null, // accordion: null = first course open
	};

	// Errors carry the server's `code` so friendly() can explain them in
	// plain words — the raw server message is never shown to students.
	function apiError( code, status ) {
		var e = new Error( code );
		e.code = code;
		e.status = status || 0;
		return e;
	}

	function api( path, method, body ) {
		method = method || 'GET';
		// GETs get a unique URL so no page/proxy cache can answer with stale data.
		var url = OIS_CC.restUrl + path + ( 'GET' === method ? ( path.indexOf( '?' ) === -1 ? '?' : '&' ) + '_=' + Date.now() : '' );
		return fetch( url, {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': OIS_CC.nonce,
			},
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( json ) {
				if ( ! res.ok ) {
					throw apiError( json && json.code ? json.code : 'server_error', res.status );
				}
				return json;
			} );
		}, function () {
			throw apiError( 'network', 0 );
		} );
	}

	var FRIENDLY = {
		network: 'We couldn\'t reach our server. Please check your internet connection and try again.',
		not_found: 'We couldn\'t find that Student ID. Please check the number on your school ID card and try again.',
		invalid_id: 'Please enter your Student ID.',
		too_soon: 'We\'ve just sent you a code. Please wait a minute before asking for another one.',
		rate_limited: 'Too many attempts from this device. For your security, please wait 15 minutes and try again.',
		sms_failed: 'We couldn\'t send the verification code right now. Please try again in a few minutes.',
		fems_unavailable: 'Our student records system is not responding right now. Please try again in a few minutes.',
		expired: 'This code has expired. Tap “Resend code” to get a new one.',
		locked: 'Too many incorrect codes. Tap “Resend code” to get a new one.',
		incorrect: 'That code doesn\'t match. Please check the SMS and try again.',
		session_expired: 'For your security, your session has timed out. Please enter your Student ID again.',
		course_not_found: 'This course is no longer available. Please choose another one.',
		order_failed: 'We couldn\'t start your order. Please try again.',
		order_not_found: 'We couldn\'t find this order. Please start again.',
		checkout_failed: 'We couldn\'t connect to the payment gateway. No money was taken — please try again in a few minutes.',
		gateway_unavailable: 'Online payment is temporarily unavailable. No money was taken — please try again later.',
		payment_failed: 'The payment didn\'t go through. No money was taken — please try again.',
		invalid_email: 'We need a valid email address for your payment receipt.',
		popup_blocked: 'Your browser blocked the invoice window. Please allow pop-ups for this site and tap “Download invoice” again.',
		captcha_failed: 'We couldn\'t confirm this request came from a person. Please refresh the page and try again.',
		unconfirmed: 'We couldn\'t confirm your payment yet. If money was deducted, don\'t pay again — email us your order number and we\'ll sort it out.',
	};
	var FRIENDLY_DEFAULT = 'Something went wrong on our side. Please try again in a few minutes.';

	function friendly( err ) {
		var code = err && err.code ? err.code : String( err || '' );
		return FRIENDLY[ code ] || FRIENDLY_DEFAULT;
	}

	// The verified session survives the full-page round trip to Moneybag
	// (per tab only, and the server expires it after 30 minutes anyway).
	var SESSION_KEY = 'ois_cc_session';

	function saveSession() {
		try {
			sessionStorage.setItem( SESSION_KEY, JSON.stringify( {
				sessionToken: state.sessionToken,
				student: state.student,
				studentId: state.studentId,
				maskedMobile: state.maskedMobile,
				maskedEmail: state.maskedEmail,
			} ) );
		} catch ( e ) {}
	}

	function restoreSession() {
		try {
			var saved = JSON.parse( sessionStorage.getItem( SESSION_KEY ) || 'null' );
			if ( saved && saved.sessionToken ) {
				Object.assign( state, saved );
			}
		} catch ( e ) {}
	}

	function clearSession() {
		try {
			sessionStorage.removeItem( SESSION_KEY );
		} catch ( e ) {}
		state.sessionToken = '';
		state.student = null;
	}

	// Any "your session is gone" error sends the student back to step 1.
	function handleError( err ) {
		if ( err && 'session_expired' === err.code ) {
			clearSession();
			setState( { busy: false, step: 'student-id', error: friendly( err ) } );
			return;
		}
		setState( { busy: false, error: friendly( err ) } );
	}

	function setState( patch ) {
		Object.assign( state, patch );
		render();
	}

	function money( n ) {
		return '৳' + Number( n || 0 ).toLocaleString( undefined, { minimumFractionDigits: 0 } );
	}

	function esc( s ) {
		var div = document.createElement( 'div' );
		div.textContent = s == null ? '' : String( s );
		return div.innerHTML;
	}

	/* ------------------------------------------------------------------ */
	/* Views                                                                */
	/*                                                                      */
	/* Every screen sits on the same "stage": a navy frame with a blush     */
	/* blob in one corner. Short steps use a two-column split (form left,   */
	/* photo + tagline right); the course list uses a full-width panel.     */
	/* ------------------------------------------------------------------ */

	function logoHtml() {
		return (
			'<div class="ois-cc-logo">' +
				'<span class="ois-cc-logo-dot" aria-hidden="true"></span>' +
				'<span class="ois-cc-logo-text">' + esc( OIS_CC.brand ) + '</span>' +
			'</div>'
		);
	}

	function checkIconSvg() {
		return '<svg width="10" height="10" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8.5L6.2 12L13 4" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	}

	// The scattered "brush dash" accent from the reference, in brand maroon.
	function dashesSvg( cls ) {
		var dashes = [ [ 18, 8 ], [ 52, 2 ], [ 6, 30 ], [ 40, 24 ], [ 74, 18 ], [ 26, 50 ] ];
		return (
			'<svg class="ois-cc-dashes ' + cls + '" viewBox="0 0 100 70" aria-hidden="true">' +
				dashes.map( function ( d ) {
					return '<rect x="' + d[ 0 ] + '" y="' + d[ 1 ] + '" width="20" height="7" rx="3.5" transform="rotate(-28 ' + ( d[ 0 ] + 10 ) + ' ' + ( d[ 1 ] + 3.5 ) + ')"/>';
				} ).join( '' ) +
			'</svg>'
		);
	}

	function supportHtml() {
		return 'Still stuck? Email <a href="mailto:' + OIS_CC.supportEmail + '">' + esc( OIS_CC.supportEmail ) + '</a>';
	}

	function errorHtml() {
		return state.error
			? '<div class="ois-cc-error" role="alert"><strong>' + esc( state.error ) + '</strong><span>' + supportHtml() + '</span></div>'
			: '';
	}

	var STEPS = [
		{ key: 'student-id', label: 'Student ID' },
		{ key: 'otp', label: 'Verify' },
		{ key: 'courses', label: 'Choose & pay' },
	];

	function stepperHtml() {
		var idx = STEPS.map( function ( s ) { return s.key; } ).indexOf( state.step );
		if ( idx === -1 ) {
			idx = STEPS.length; // confirming / thank-you / failed: every step done
		}
		return (
			'<ol class="ois-cc-stepper" aria-label="Progress">' +
				STEPS.map( function ( s, i ) {
					var cls = i < idx ? 'done' : ( i === idx ? 'current' : '' );
					return '<li class="' + cls + '"' + ( i === idx ? ' aria-current="step"' : '' ) + '><span class="ois-cc-step-num">' + ( i < idx ? checkIconSvg() : '0' + ( i + 1 ) ) + '</span>' + esc( s.label ) + '</li>';
				} ).join( '' ) +
			'</ol>'
		);
	}

	function stage( inner, wide ) {
		return (
			'<div class="ois-cc-stage' + ( wide ? ' is-wide' : '' ) + '">' +
				'<span class="ois-cc-stage-blob" aria-hidden="true"></span>' +
				inner +
			'</div>'
		);
	}

	function split( paneHtml, tagline ) {
		return stage(
			'<div class="ois-cc-split">' +
				'<section class="ois-cc-pane">' + logoHtml() + '<div class="ois-cc-pane-body">' + paneHtml + '</div></section>' +
				'<figure class="ois-cc-visual' + ( OIS_CC.image ? '' : ' no-image' ) + '">' +
					( OIS_CC.image ? '<img src="' + esc( OIS_CC.image ) + '" alt="">' : '' ) +
					dashesSvg( 'is-top' ) +
					'<figcaption class="ois-cc-visual-blob"><span class="ois-cc-tagline">' + tagline + '</span></figcaption>' +
					dashesSvg( 'is-bottom' ) +
				'</figure>' +
			'</div>'
		);
	}

	function btn( label, busyLabel, attrs ) {
		return '<button class="ois-cc-btn" ' + ( attrs || '' ) + ( state.busy ? ' disabled' : '' ) + '>' + ( state.busy ? busyLabel : label ) + '</button>';
	}

	function renderStudentId() {
		return split(
			stepperHtml() +
			'<h2 class="ois-cc-title">Enroll in Cambridge English Qualification</h2>' +
			'<p class="ois-cc-sub">Please enter your Student ID to proceed. A one-time verification code (OTP) will be sent to the mobile number registered with the school.</p>' +
			errorHtml() +
			'<form id="ois-cc-form-student" novalidate>' +
				'<label class="ois-cc-field"><span>Student ID</span>' +
				'<input class="ois-cc-input" id="ois-cc-student-id" name="student_id" inputmode="numeric" placeholder="e.g. 100*****67" autocomplete="off" value="' + esc( state.studentId ) + '" required></label>' +
				btn( 'Continue', 'Checking…', 'type="submit"' ) +
			'</form>' +
			'<p class="ois-cc-fine">For currently enrolled OIS students only.</p>',
			'Your Cambridge journey starts <em>here</em>'
		);
	}

	function renderOtp() {
		var boxes = '';
		for ( var i = 0; i < 6; i++ ) {
			boxes += '<input class="ois-cc-otp-box" type="text" inputmode="numeric" autocomplete="' + ( i ? 'off' : 'one-time-code' ) + '" maxlength="1" placeholder=" " aria-label="Digit ' + ( i + 1 ) + '" data-otp-index="' + i + '">';
		}
		var dest = state.otpChannel === 'email' && state.maskedEmail ? state.maskedEmail : state.maskedMobile;
		return split(
			stepperHtml() +
			'<h2 class="ois-cc-title">Verify Your Mobile Number</h2>' +
			'<p class="ois-cc-sub">A 6-digit verification code has been sent to <strong class="ois-cc-nowrap">' + esc( dest ) + '</strong>.</p>' +
			errorHtml() +
			( state.testOtp ? '<div class="ois-cc-info">Test mode — code is <strong>' + esc( state.testOtp ) + '</strong></div>' : '' ) +
			'<form id="ois-cc-form-otp" novalidate>' +
				'<div class="ois-cc-otp-row">' + boxes + '</div>' +
				btn( 'Verify &amp; continue', 'Checking…', 'type="submit"' ) +
			'</form>' +
			'<div class="ois-cc-resend-row">' +
				'<button type="button" class="ois-cc-link-btn" id="ois-cc-back-to-id">&larr; Change Student ID</button>' +
				'<button type="button" class="ois-cc-link-btn" id="ois-cc-resend" ' + ( state.resendCooldown > 0 ? 'disabled' : '' ) + '>' +
					( state.resendCooldown > 0 ? 'Resend in ' + state.resendCooldown + 's' : 'Resend code' ) +
				'</button>' +
			'</div>',
			'One quick check, then you\'re <em>in</em>'
		);
	}

	// Known fields get a friendly label, an icon and a fixed order; anything
	// else FEMS sends (whatever that ends up being) still renders, using the
	// key itself prettified into a label.
	var STUDENT_FIELD_LABELS = { name: 'Name', id: 'Student ID', class: 'Class', section: 'Section', roll: 'Roll' };
	var STUDENT_FIELD_ORDER = [ 'name', 'id', 'class', 'section', 'roll' ];

	// 24x24 stroke icons (Lucide-style paths).
	var ICONS = {
		name: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		id: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="12" r="2"/><path d="M14 10h4M14 14h4"/>',
		class: '<path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c3 2 9 2 12 0v-5"/>',
		section: '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 5a3 3 0 0 1 0 6M18 14a6 6 0 0 1 3 6"/>',
		roll: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
		mobile: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
		email: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
		other: '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>',
		book: '<path d="M2 4h7a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H2z"/><path d="M22 4h-7a3 3 0 0 0-3 3v13a2 2 0 0 1 2-2h8z"/>',
		clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		chevron: '<path d="M6 9l6 6 6-6"/>',
		arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
		shield: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
	};

	function icon( name ) {
		return '<svg class="ois-cc-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ( ICONS[ name ] || ICONS.other ) + '</svg>';
	}

	function fieldLabel( key ) {
		if ( STUDENT_FIELD_LABELS[ key ] ) {
			return STUDENT_FIELD_LABELS[ key ];
		}
		return key.replace( /_/g, ' ' ).replace( /\b\w/g, function ( ch ) { return ch.toUpperCase(); } );
	}

	function studentInfoHtml() {
		var s = state.student || {};
		var has = function ( k ) { return s[ k ] !== undefined && s[ k ] !== null && s[ k ] !== ''; };
		var keys = STUDENT_FIELD_ORDER.filter( has );
		Object.keys( s ).forEach( function ( k ) {
			if ( keys.indexOf( k ) === -1 && has( k ) ) {
				keys.push( k );
			}
		} );

		var rows = keys.map( function ( k ) { return [ k, fieldLabel( k ), s[ k ] ]; } );
		rows.push( [ 'mobile', 'Mobile', state.maskedMobile ] );
		if ( state.maskedEmail ) {
			rows.push( [ 'email', 'Email', state.maskedEmail ] );
		}

		return (
			'<aside class="ois-cc-student-panel">' +
				'<div class="ois-cc-student-panel-head">' +
					'<h3>Student Details</h3>' +
					'<span class="ois-cc-verified">' + icon( 'shield' ) + 'Verified</span>' +
				'</div>' +
				'<dl class="ois-cc-detail-list">' +
					rows.map( function ( r ) {
						return '<div class="ois-cc-detail-row">' + icon( r[ 0 ] ) + '<dt>' + esc( r[ 1 ] ) + '</dt><dd>' + esc( r[ 2 ] ) + '</dd></div>';
					} ).join( '' ) +
				'</dl>' +
				'<button type="button" class="ois-cc-link-btn ois-cc-signout" id="ois-cc-try-again">Not you? Start over</button>' +
			'</aside>'
		);
	}

	// Accordion row: collapsed = tag + title + price; open = blurb, meta
	// and the Pay button (the layout from the design reference).
	function productCard( c, i ) {
		var oldPrice = c.list_price > c.price ? '<s class="ois-cc-price-old">' + money( c.list_price ) + '</s>' : '';
		var buying = state.busy && state.selectedCourse && state.selectedCourse.id === c.id;
		var openId = null === state.openCourse && state.courses[ 0 ] ? state.courses[ 0 ].id : state.openCourse;
		var open = openId === c.id;
		var meta = [ [ 'section', c.instructor ], [ 'clock', c.duration ] ].filter( function ( m ) { return m[ 1 ]; } );
		return (
			'<article class="ois-cc-course' + ( open ? ' is-open' : '' ) + '" style="--i:' + i + '">' +
				'<button type="button" class="ois-cc-course-head" data-toggle-course="' + c.id + '" aria-expanded="' + open + '">' +
					'<span class="ois-cc-course-icon tone-' + ( i % 4 ) + '">' + icon( 'book' ) + '</span>' +
					'<span class="ois-cc-course-name">' +
						( c.category ? '<span class="ois-cc-tag">' + esc( c.category ) + '</span>' : '' ) +
						'<span class="ois-cc-course-title">' + esc( c.title ) + '</span>' +
					'</span>' +
					( open ? '' : '<span class="ois-cc-course-peek">' + money( c.price ) + '</span>' ) +
					'<span class="ois-cc-chevron">' + icon( 'chevron' ) + '</span>' +
				'</button>' +
				( open
					? '<div class="ois-cc-course-body">' +
						'<div class="ois-cc-course-info">' +
							( c.excerpt ? '<p class="ois-cc-course-desc">' + esc( c.excerpt ) + '</p>' : '' ) +
							( meta.length ? '<div class="ois-cc-course-meta">' + meta.map( function ( m ) { return '<span>' + icon( m[ 0 ] ) + esc( m[ 1 ] ) + '</span>'; } ).join( '' ) + '</div>' : '' ) +
						'</div>' +
						'<div class="ois-cc-product-buy">' +
							'<div class="ois-cc-price-row">' + oldPrice + '<span class="ois-cc-price">' + money( c.price ) + '</span></div>' +
							'<button class="ois-cc-btn ois-cc-btn-sm" data-buy-course="' + c.id + '"' + ( state.busy ? ' disabled' : '' ) + '>' +
								( buying ? 'Redirecting…' : 'Pay now ' + icon( 'arrow' ) ) +
							'</button>' +
						'</div>' +
					'</div>'
					: '' ) +
			'</article>'
		);
	}

	function renderCourses() {
		var cards = state.courses.length
			? state.courses.map( productCard ).join( '' )
			: '<p class="ois-cc-sub">No courses are open for registration right now.</p>';
		return stage(
			'<div class="ois-cc-panel">' +
				'<header class="ois-cc-panel-head">' + logoHtml() + stepperHtml() + '</header>' +
				'<div class="ois-cc-courses-layout">' +
					studentInfoHtml() +
					'<section class="ois-cc-courses-main">' +
						'<span class="ois-cc-accent" aria-hidden="true"></span>' +
						'<h2 class="ois-cc-title">Select a Level to Pay</h2>' +
						'<p class="ois-cc-sub">Select a Cambridge English Qualification level offered by Oxford International School to view the level details and proceed with secure online payment through Moneybag.</p>' +
						errorHtml() +
						'<div class="ois-cc-course-list">' + cards + '</div>' +
					'</section>' +
				'</div>' +
			'</div>',
			true
		);
	}

	function renderConfirming() {
		return split(
			stepperHtml() +
			'<div class="ois-cc-spinner" aria-hidden="true"></div>' +
			'<h2 class="ois-cc-title">Confirming your payment…</h2>' +
			'<p class="ois-cc-sub">We\'re checking with Moneybag. This only takes a moment, so please keep this page open.</p>',
			'Almost <em>there</em>'
		);
	}

	function renderPaymentFailed() {
		var o = state.order || {};
		return split(
			stepperHtml() +
			'<div class="ois-cc-status is-fail" aria-hidden="true">!</div>' +
			'<h2 class="ois-cc-title">Payment not completed</h2>' +
			errorHtml() +
			( ! state.error ? '<p class="ois-cc-sub">Your payment didn\'t go through, and no money was taken. You can try again whenever you\'re ready.</p>' : '' ) +
			( o.order_number ? '<p class="ois-cc-sub">Order reference: <strong>' + esc( o.order_number ) + '</strong></p>' : '' ) +
			'<button class="ois-cc-btn" id="ois-cc-try-again">Start over</button>',
			'No charge was <em>made</em>'
		);
	}

	function renderThankYou() {
		var o = state.order;
		var rows = [
			[ 'Order number', o.order_number ],
			[ 'Transaction ID', o.txn_id ],
			[ 'Course', o.course_title ],
			[ 'Payment method', o.payment_method ],
		].filter( function ( r ) { return r[ 1 ]; } ).map( function ( r ) {
			return '<div class="ois-cc-summary-row"><dt>' + esc( r[ 0 ] ) + '</dt><dd>' + esc( r[ 1 ] ) + '</dd></div>';
		} ).join( '' );
		return split(
			stepperHtml() +
			'<div class="ois-cc-status is-ok" aria-hidden="true">' + checkIconSvg() + '</div>' +
			'<h2 class="ois-cc-title">Payment successful</h2>' +
			'<p class="ois-cc-sub">Thank you, ' + esc( o.student_name ) + '. Your seat for <strong>' + esc( o.course_title ) + '</strong> is confirmed.</p>' +
			errorHtml() +
			'<dl class="ois-cc-order-card">' + rows +
				'<div class="ois-cc-summary-row total"><dt>Amount paid</dt><dd>' + money( o.amount ) + '</dd></div>' +
			'</dl>' +
			'<div class="ois-cc-actions">' +
				'<button class="ois-cc-btn" id="ois-cc-download-receipt">Download invoice</button>' +
				'<button class="ois-cc-btn ois-cc-btn-outline" id="ois-cc-browse-more">Browse more courses</button>' +
			'</div>' +
			'<p class="ois-cc-fine">Questions about your registration? Email <a href="mailto:' + OIS_CC.supportEmail + '">' + esc( OIS_CC.supportEmail ) + '</a> with your order number.</p>',
			'See you in <em>class</em>'
		);
	}

	var ONES = [ '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen' ];
	var TENS = [ '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety' ];

	function wordsUnder1000( n ) {
		var out = [];
		if ( n >= 100 ) {
			out.push( ONES[ Math.floor( n / 100 ) ] + ' Hundred' );
			n %= 100;
		}
		if ( n ) {
			var w = n < 20 ? ONES[ n ] : TENS[ Math.floor( n / 10 ) ] + ( n % 10 ? '-' + ONES[ n % 10 ] : '' );
			out.push( out.length ? 'and ' + w : w );
		}
		return out.join( ' ' );
	}

	// 11950 -> "Eleven Thousand, Nine Hundred and Fifty" (the FEMS invoice style).
	function amountInWords( n ) {
		n = Math.round( Number( n ) || 0 );
		if ( ! n ) {
			return 'Zero';
		}
		var scales = [ '', ' Thousand', ' Million', ' Billion' ];
		var parts = [];
		for ( var i = 0; n; i++, n = Math.floor( n / 1000 ) ) {
			if ( n % 1000 ) {
				parts.unshift( wordsUnder1000( n % 1000 ) + scales[ i ] );
			}
		}
		return parts.join( ', ' );
	}

	function pad2( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	// "2026-09-30 14:05:00" (WP local time) -> "30th September, 2026".
	function longDate( str ) {
		var d = str ? new Date( String( str ).replace( ' ', 'T' ) ) : new Date();
		if ( isNaN( d ) ) {
			d = new Date();
		}
		var day = d.getDate();
		var sfx = ( day % 10 === 1 && day !== 11 ) ? 'st' : ( day % 10 === 2 && day !== 12 ) ? 'nd' : ( day % 10 === 3 && day !== 13 ) ? 'rd' : 'th';
		return day + sfx + ' ' + d.toLocaleString( 'en-GB', { month: 'long' } ) + ', ' + d.getFullYear();
	}

	// Invoice = a standalone print-ready page laid out like the school's FEMS
	// payment invoice (one copy, not bank/school/student). The browser's print
	// dialog offers "Save as PDF" on desktop and mobile, so no PDF library.
	function openReceipt( o ) {
		var now = new Date();
		var printed = now.getFullYear() + '-' + pad2( now.getMonth() + 1 ) + '-' + pad2( now.getDate() ) + ' ' + pad2( now.getHours() ) + ':' + pad2( now.getMinutes() ) + ':' + pad2( now.getSeconds() );
		var amount = Number( o.amount || 0 ).toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
		var info = [
			[ 'Student name', o.student_name ],
			[ 'Student Id', o.student_id ],
			[ 'Class', o.student_class ],
			[ 'Invoice No', o.order_number ],
			[ 'Transaction Id', o.txn_id, 'Section', o.student_section ],
			[ 'Payment Method', o.payment_method, 'Roll', o.student_roll ],
		].filter( function ( r ) { return r[ 1 ] || r[ 3 ]; } ).map( function ( r ) {
			return '<tr><th>' + r[ 0 ] + '</th><td' + ( r[ 2 ] ? '' : ' colspan="3"' ) + '>' + esc( r[ 1 ] ) + '</td>' +
				( r[ 2 ] ? '<th>' + r[ 2 ] + '</th><td>' + esc( r[ 3 ] ) + '</td>' : '' ) + '</tr>';
		} ).join( '' );

		var html =
			'<!doctype html><html><head><meta charset="utf-8"><title>Payment Invoice ' + esc( o.order_number ) + '</title>' +
			'<meta name="viewport" content="width=device-width,initial-scale=1">' +
			'<style>' +
				'@page{size:A4;margin:12mm}' +
				'*{box-sizing:border-box}' +
				'body{font-family:Arial,Helvetica,sans-serif;color:#111;margin:0;padding:24px;background:#fff;font-size:14px}' +
				'.inv{max-width:680px;margin:0 auto;border:1.5px solid #111;padding:22px 22px 12px;min-height:900px;display:flex;flex-direction:column}' +
				'.hd{display:flex;align-items:center;gap:16px}' +
				'.hd img{width:150px;height:auto;flex-shrink:0}' +
				'.hd div{flex:1;text-align:center;line-height:1.35}' +
				'.hd b{display:block;font-size:22px}' +
				'.ttl{border:1.5px solid #111;text-align:center;padding:6px 8px;margin:16px 0 12px;line-height:1.35}' +
				'.ttl b{display:block;font-size:18px}' +
				'.info{border-collapse:collapse;margin-bottom:14px}' +
				'.info th{text-align:left;padding:3px 24px 3px 0;white-space:nowrap}' +
				'.info td{padding:3px 36px 3px 0}' +
				'.items{width:100%;border-collapse:collapse}' +
				'.items th,.items td{border:1.5px solid #111;padding:6px 8px;text-align:left;vertical-align:top}' +
				'.items .sl{width:42px}.items .amt{width:130px;text-align:right;white-space:nowrap}' +
				'.items tfoot .amt{font-weight:700;font-size:16px;vertical-align:middle}' +
				'.items tfoot td{font-weight:700}' +
				'.paid{display:inline-block;margin:30px 14px 0 0;border:4px solid #0a7d31;outline:2px solid #0a7d31;outline-offset:4px;border-radius:8px;color:#0a7d31;font-family:Impact,"Arial Black",sans-serif;font-size:44px;line-height:1;padding:8px 22px;letter-spacing:.14em;transform:rotate(-12deg);opacity:.85}' +
				'.ft{margin-top:auto;border-top:1px solid #111;padding-top:6px;display:flex;justify-content:space-between;gap:12px;font-size:12px}' +
				'@media print{body{padding:0}.inv{min-height:calc(297mm - 25mm)}}' +
			'</style></head><body><div class="inv">' +
				'<div class="hd">' +
					( OIS_CC.logo ? '<img src="' + esc( OIS_CC.logo ) + '" alt="">' : '' ) +
					'<div><b>' + esc( OIS_CC.schoolName ) + '</b>House: 34, Road: 27 (Old), New 16, Dhanmondi, Dhaka-1209<br>9115392, 01765855555</div>' +
				'</div>' +
				'<div class="ttl"><b>Payment Invoice for ' + esc( o.course_title ) + '</b>Date of payment: ' + esc( longDate( o.paid_at ) ) + '</div>' +
				'<table class="info">' + info + '</table>' +
				'<table class="items">' +
					'<thead><tr><th class="sl">SL</th><th>Description</th><th class="amt">Amount</th></tr></thead>' +
					'<tbody><tr><td class="sl">1</td><td>' + esc( OIS_CC.brand ) + ' (' + esc( o.course_title ) + ')</td><td class="amt">' + amount + '</td></tr></tbody>' +
					'<tfoot><tr><td colspan="2">Total<br>Amount in words: ' + esc( amountInWords( o.amount ) ) + ' Only (BDT)</td><td class="amt">' + amount + '</td></tr></tfoot>' +
				'</table>' +
				'<div style="text-align:right"><span class="paid">PAID</span></div>' +
				'<div class="ft"><span>Printed Date: ' + printed + '</span><span>Powered By: fems.education</span></div>' +
			'</div><script>window.onload=function(){window.print();};<\/script></body></html>';

		var w = window.open( '', '_blank' );
		if ( ! w ) {
			setState( { error: friendly( 'popup_blocked' ) } );
			return;
		}
		w.document.open();
		w.document.write( html );
		w.document.close();
	}

	// Entrance animation plays only when the step changes — not on every
	// re-render (an error appearing, busy state), which would feel jittery.
	var lastStep = null;

	function render() {
		var html = '';
		switch ( state.step ) {
			case 'otp': html = renderOtp(); break;
			case 'courses': html = renderCourses(); break;
			case 'confirming': html = renderConfirming(); break;
			case 'payment-failed': html = renderPaymentFailed(); break;
			case 'thank-you': html = renderThankYou(); break;
			default: html = renderStudentId();
		}
		root.classList.toggle( 'is-entering', state.step !== lastStep );
		lastStep = state.step;
		root.innerHTML = html;
		bindEvents();
	}

	/* ------------------------------------------------------------------ */
	/* Event handling                                                       */
	/* ------------------------------------------------------------------ */

	var resendTimer = null;

	function startResendCooldown() {
		clearInterval( resendTimer );
		setState( { resendCooldown: 60 } );
		resendTimer = setInterval( function () {
			state.resendCooldown = Math.max( 0, state.resendCooldown - 1 );

			// Update the countdown in place instead of calling render() — a full
			// re-render every second would recreate the OTP input boxes and wipe
			// out whatever digits the student has typed so far.
			var resendBtn = document.getElementById( 'ois-cc-resend' );
			if ( resendBtn ) {
				resendBtn.textContent = state.resendCooldown > 0 ? 'Resend in ' + state.resendCooldown + 's' : 'Resend code';
				resendBtn.disabled = state.resendCooldown > 0;
			}

			if ( state.resendCooldown <= 0 ) {
				clearInterval( resendTimer );
			}
		}, 1000 );
	}

	// reCAPTCHA v3: invisible, so a fresh token is fetched for every send
	// (and resend). Resolves '' when off or Google fails to load — the
	// server then decides (it rejects only when the captcha is on).
	function captchaToken() {
		return new Promise( function ( resolve ) {
			if ( ! OIS_CC.captchaKey || ! window.grecaptcha ) {
				resolve( '' );
				return;
			}
			window.grecaptcha.ready( function () {
				window.grecaptcha.execute( OIS_CC.captchaKey, { action: 'verify_student' } ).then( resolve, function () { resolve( '' ); } );
			} );
		} );
	}

	function requestOtp( studentId ) {
		setState( { busy: true, error: '' } );
		return captchaToken().then( function ( captcha ) {
			return api( '/verify-student', 'POST', { student_id: studentId, captcha: captcha } );
		} )
			.then( function ( res ) {
				startResendCooldown();
				setState( {
					busy: false,
					step: 'otp',
					studentId: studentId,
					maskedMobile: res.masked_mobile,
					maskedEmail: res.masked_email || '',
					otpChannel: res.otp_channel || 'mobile',
					testOtp: res.test_otp || '',
				} );
			} )
			.catch( handleError );
	}

	function loadCourses() {
		return api( '/courses', 'GET' ).then( function ( courses ) {
			setState( { courses: courses } );
		} ).catch( handleError );
	}

	function goToCourses() {
		if ( ! state.sessionToken || ! state.student ) {
			setState( { step: 'student-id', order: null, error: '' } );
			return;
		}
		setState( { step: 'courses', selectedCourse: null, order: null, error: '' } );
		if ( ! state.courses.length ) {
			loadCourses();
		}
	}

	function bindEvents() {
		root.querySelectorAll( '[data-toggle-course]' ).forEach( function ( head ) {
			head.addEventListener( 'click', function () {
				var id = parseInt( head.getAttribute( 'data-toggle-course' ), 10 );
				setState( { openCourse: 'true' === head.getAttribute( 'aria-expanded' ) ? -1 : id } );
			} );
		} );

		var formStudent = document.getElementById( 'ois-cc-form-student' );
		if ( formStudent ) {
			formStudent.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var id = document.getElementById( 'ois-cc-student-id' ).value.trim();
				if ( id ) {
					requestOtp( id );
				}
			} );
		}

		var otpBoxes = root.querySelectorAll( '.ois-cc-otp-box' );
		if ( otpBoxes.length ) {
			otpBoxes[ 0 ].focus();
			otpBoxes.forEach( function ( box, idx ) {
				box.addEventListener( 'input', function () {
					box.value = box.value.replace( /\D/g, '' ).slice( 0, 1 );
					if ( box.value && otpBoxes[ idx + 1 ] ) {
						otpBoxes[ idx + 1 ].focus();
					}
				} );
				box.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'Backspace' && ! box.value && otpBoxes[ idx - 1 ] ) {
						otpBoxes[ idx - 1 ].focus();
					}
				} );
				box.addEventListener( 'paste', function ( e ) {
					var text = ( e.clipboardData || window.clipboardData ).getData( 'text' ).replace( /\D/g, '' );
					if ( text.length ) {
						e.preventDefault();
						text.split( '' ).slice( 0, otpBoxes.length ).forEach( function ( ch, i ) {
							if ( otpBoxes[ i ] ) {
								otpBoxes[ i ].value = ch;
							}
						} );
						otpBoxes[ Math.min( text.length, otpBoxes.length ) - 1 ].focus();
					}
				} );
			} );
		}

		var formOtp = document.getElementById( 'ois-cc-form-otp' );
		if ( formOtp ) {
			formOtp.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var otp = Array.prototype.map.call( otpBoxes, function ( b ) { return b.value; } ).join( '' );
				if ( otp.length !== 6 ) {
					setState( { error: 'Please enter all 6 digits of the code we texted you.' } );
					return;
				}
				setState( { busy: true, error: '' } );
				api( '/verify-otp', 'POST', { student_id: state.studentId, otp: otp } )
					.then( function ( res ) {
						setState( { busy: false, sessionToken: res.session_token, student: res.student, step: 'courses' } );
						saveSession();
						return loadCourses();
					} )
					.catch( handleError );
			} );
		}

		var backToId = document.getElementById( 'ois-cc-back-to-id' );
		if ( backToId ) {
			backToId.addEventListener( 'click', function () {
				setState( { step: 'student-id', error: '' } );
			} );
		}

		var resend = document.getElementById( 'ois-cc-resend' );
		if ( resend ) {
			resend.addEventListener( 'click', function () {
				if ( state.resendCooldown > 0 ) {
					return;
				}
				requestOtp( state.studentId );
			} );
		}

		root.querySelectorAll( '[data-buy-course]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var id = parseInt( btn.getAttribute( 'data-buy-course' ), 10 );
				var course = state.courses.filter( function ( c ) { return c.id === id; } )[ 0 ];
				if ( ! course ) {
					return;
				}
				setState( { busy: true, error: '', selectedCourse: course } );
				// No checkout page of our own: create the order and go straight
				// to Moneybag (the receipt email comes from FEMS server-side).
				api( '/create-order', 'POST', { session_token: state.sessionToken, course_id: course.id } )
					.then( function ( order ) {
						return api( '/pay', 'POST', {
							session_token: state.sessionToken,
							order_id: order.order_id,
							return_url: window.location.href.split( '?' )[ 0 ],
						} );
					} )
					.then( function ( res ) {
						if ( res.redirect ) {
							window.location.href = res.redirect;
							return;
						}
						setState( { busy: false, step: 'thank-you', order: res } ); // dummy gateway
					} )
					.catch( handleError );
			} );
		} );

		var receiptBtn = document.getElementById( 'ois-cc-download-receipt' );
		if ( receiptBtn ) {
			receiptBtn.addEventListener( 'click', function () {
				openReceipt( state.order );
			} );
		}

		var browseMore = document.getElementById( 'ois-cc-browse-more' );
		if ( browseMore ) {
			browseMore.addEventListener( 'click', goToCourses );
		}

		var tryAgain = document.getElementById( 'ois-cc-try-again' );
		if ( tryAgain ) {
			tryAgain.addEventListener( 'click', function () {
				clearSession();
				setState( { step: 'student-id', order: null, error: '' } );
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Return from Moneybag's hosted checkout                              */
	/* ------------------------------------------------------------------ */

	var TXN_PARAM_NAMES = [ 'transaction_id', 'trx_id', 'txn_id', 'moneybag_transaction_id' ];

	function pollOrderStatus( token, txn, result, attempt ) {
		var path = '/order-status?token=' + encodeURIComponent( token ) + ( txn ? '&txn=' + encodeURIComponent( txn ) : '' );
		api( path, 'GET' )
			.then( function ( order ) {
				if ( 'paid' === order.status ) {
					setState( { step: 'thank-you', order: order } );
					return;
				}
				if ( 'failed' === order.status || 'canceled' === order.status || 'cancel' === result ) {
					setState( { step: 'payment-failed', order: order, error: '' } );
					return;
				}
				if ( attempt >= 15 ) {
					setState( { step: 'payment-failed', order: order, error: friendly( 'unconfirmed' ) } );
					return;
				}
				setTimeout( function () { pollOrderStatus( token, txn, result, attempt + 1 ); }, 2000 );
			} )
			.catch( function ( err ) {
				setState( { step: 'payment-failed', error: friendly( err ) } );
			} );
	}

	function checkReturnFromGateway() {
		var params = new URLSearchParams( window.location.search );
		var token = params.get( 'ois_cc_token' );
		if ( ! token ) {
			return false;
		}

		var result = params.get( 'ois_cc_result' ) || '';
		var txn = '';
		TXN_PARAM_NAMES.some( function ( name ) {
			txn = params.get( name ) || '';
			return !! txn;
		} );

		// Drop the query string so a page refresh doesn't re-trigger this.
		window.history.replaceState( null, '', window.location.pathname + window.location.hash );

		setState( { step: 'confirming' } );
		pollOrderStatus( token, txn, result, 0 );
		return true;
	}

	restoreSession();
	if ( ! checkReturnFromGateway() ) {
		if ( state.sessionToken && state.student ) {
			goToCourses(); // reload mid-flow: pick up where the student left off
		} else {
			render();
		}
	}
} )();
