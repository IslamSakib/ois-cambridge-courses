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
		sessionToken: '',
		student: null,
		courses: [],
		selectedCourse: null,
		order: null,
		paymentMethod: 'card',
		busy: false,
		error: '',
		resendCooldown: 0,
	};

	function api( path, method, body ) {
		return fetch( OIS_CC.restUrl + path, {
			method: method || 'GET',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': OIS_CC.nonce,
			},
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				if ( ! res.ok ) {
					throw new Error( json && json.message ? json.message : 'Something went wrong.' );
				}
				return json;
			} );
		} );
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
	/* Step renderers                                                       */
	/* ------------------------------------------------------------------ */

	function logoHtml() {
		return (
			'<div class="ois-cc-logo">' +
				'<div class="ois-cc-logo-badge">OIS</div>' +
				'<div class="ois-cc-logo-text">' + esc( OIS_CC.schoolName ) + '<small>Cambridge Courses</small></div>' +
			'</div>'
		);
	}

	function errorHtml() {
		return state.error ? '<div class="ois-cc-error">' + esc( state.error ) + '</div>' : '';
	}

	function renderStudentId() {
		return (
			'<div class="ois-cc-center"><div class="ois-cc-card">' +
				logoHtml() +
				'<h2>Enter your Student ID</h2>' +
				'<p class="ois-cc-sub">We\'ll send a one-time code to your registered mobile number to verify it\'s you.</p>' +
				errorHtml() +
				'<form id="ois-cc-form-student">' +
					'<div class="ois-cc-field"><label for="ois-cc-student-id">Student ID</label>' +
					'<input class="ois-cc-input" id="ois-cc-student-id" name="student_id" placeholder="e.g. OIS-2024-0451" autocomplete="off" required></div>' +
					'<button class="ois-cc-btn" type="submit" ' + ( state.busy ? 'disabled' : '' ) + '>' + ( state.busy ? 'Checking…' : 'Continue' ) + '</button>' +
				'</form>' +
			'</div></div>'
		);
	}

	function renderOtp() {
		var boxes = '';
		for ( var i = 0; i < 6; i++ ) {
			boxes += '<input class="ois-cc-otp-box" type="text" inputmode="numeric" maxlength="1" data-otp-index="' + i + '">';
		}
		return (
			'<div class="ois-cc-center"><div class="ois-cc-card">' +
				logoHtml() +
				'<h2>Verify OTP</h2>' +
				'<p class="ois-cc-sub">Enter the 6-digit code sent to ' + esc( state.maskedMobile ) + '</p>' +
				errorHtml() +
				( state.testOtp ? '<div class="ois-cc-info">Test mode — OTP is <strong>' + esc( state.testOtp ) + '</strong></div>' : '' ) +
				'<form id="ois-cc-form-otp">' +
					'<div class="ois-cc-otp-row">' + boxes + '</div>' +
					'<button class="ois-cc-btn" type="submit" ' + ( state.busy ? 'disabled' : '' ) + '>' + ( state.busy ? 'Verifying…' : 'Verify & Continue' ) + '</button>' +
				'</form>' +
				'<div class="ois-cc-resend-row">' +
					'<button type="button" class="ois-cc-link-btn" id="ois-cc-back-to-id">&larr; Change Student ID</button>' +
					'<button type="button" class="ois-cc-link-btn" id="ois-cc-resend" ' + ( state.resendCooldown > 0 ? 'disabled' : '' ) + '>' +
						( state.resendCooldown > 0 ? 'Resend in ' + state.resendCooldown + 's' : 'Resend OTP' ) +
					'</button>' +
				'</div>' +
			'</div></div>'
		);
	}

	function topbarHtml() {
		var name = state.student ? state.student.name : '';
		var id = state.student ? state.student.id : '';
		return (
			'<div class="ois-cc-topbar">' +
				'<div class="ois-cc-student-chip"><strong>' + esc( name ) + '</strong>&nbsp;(ID: ' + esc( id ) + ')<span class="ois-cc-verified">Verified</span></div>' +
			'</div>'
		);
	}

	function courseCard( c ) {
		var img = c.image ? '<img class="ois-cc-course-thumb" src="' + esc( c.image ) + '" alt="' + esc( c.title ) + '">' : '<div class="ois-cc-course-thumb"></div>';
		var oldPrice = c.list_price > c.price ? '<span class="ois-cc-price-old">' + money( c.list_price ) + '</span>' : '';
		return (
			'<div class="ois-cc-course-card">' +
				img +
				'<div class="ois-cc-course-body">' +
					( c.category ? '<span class="ois-cc-tag">' + esc( c.category ) + '</span>' : '' ) +
					'<h3 class="ois-cc-course-title">' + esc( c.title ) + '</h3>' +
					'<div class="ois-cc-course-meta">' + esc( c.instructor || '' ) + ( c.duration ? ' · ' + esc( c.duration ) : '' ) + '</div>' +
					'<div class="ois-cc-price-row">' + oldPrice + '<span class="ois-cc-price">' + money( c.price ) + '</span></div>' +
					'<button class="ois-cc-btn" data-view-course="' + c.id + '">View Details</button>' +
				'</div>' +
			'</div>'
		);
	}

	function renderCourses() {
		var cards = state.courses.length
			? state.courses.map( courseCard ).join( '' )
			: '<p>No courses available right now.</p>';
		return (
			'<div>' + topbarHtml() +
				'<h2 style="padding:0 4px;">Choose a Cambridge Course</h2>' +
				'<div class="ois-cc-grid">' + cards + '</div>' +
			'</div>'
		);
	}

	function renderCourseDetail() {
		var c = state.selectedCourse;
		if ( ! c ) {
			return renderCourses();
		}
		var img = c.image ? '<img class="ois-cc-detail-image" src="' + esc( c.image ) + '" alt="' + esc( c.title ) + '">' : '<div class="ois-cc-detail-image"></div>';
		return (
			'<div>' + topbarHtml() +
				'<button type="button" class="ois-cc-link-btn ois-cc-back" id="ois-cc-back-to-courses">&larr; Back to courses</button>' +
				'<div class="ois-cc-detail-grid">' +
					'<div>' + img +
						'<h2 style="margin-bottom:0;">' + esc( c.title ) + '</h2>' +
						'<div class="ois-cc-course-meta">' + esc( c.instructor || '' ) + ( c.duration ? ' · ' + esc( c.duration ) : '' ) + '</div>' +
						'<p class="ois-cc-detail-desc">' + c.description + '</p>' +
					'</div>' +
					'<div class="ois-cc-summary-card">' +
						errorHtml() +
						'<div class="ois-cc-summary-row"><span>Course Fee</span><span>' + money( c.list_price || c.price ) + '</span></div>' +
						( c.list_price > c.price ? '<div class="ois-cc-summary-row"><span>Discount</span><span>-' + money( c.list_price - c.price ) + '</span></div>' : '' ) +
						'<div class="ois-cc-summary-row total"><span>Total</span><span>' + money( c.price ) + '</span></div>' +
						'<button class="ois-cc-btn" id="ois-cc-buy-now" style="margin-top:16px;" ' + ( state.busy ? 'disabled' : '' ) + '>' + ( state.busy ? 'Please wait…' : 'Buy Now' ) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>'
		);
	}

	function renderCheckout() {
		var s = state.student;
		var c = state.selectedCourse;
		var methods = [
			{ id: 'card', label: 'Card' },
			{ id: 'bkash', label: 'bKash' },
			{ id: 'nagad', label: 'Nagad' },
			{ id: 'bank', label: 'Bank Transfer' },
		];
		var methodsHtml = methods.map( function ( m ) {
			return '<div class="ois-cc-pay-method' + ( state.paymentMethod === m.id ? ' selected' : '' ) + '" data-pay-method="' + m.id + '">' + m.label + '</div>';
		} ).join( '' );

		return (
			'<div>' + topbarHtml() +
				'<button type="button" class="ois-cc-link-btn ois-cc-back" id="ois-cc-back-to-detail">&larr; Back</button>' +
				'<div class="ois-cc-checkout-grid">' +
					'<div class="ois-cc-form-card">' +
						'<h3>Student Details</h3>' +
						'<div class="ois-cc-field-grid">' +
							'<div class="ois-cc-field"><label>Full Name</label><input class="ois-cc-input" value="' + esc( s.name ) + '" readonly></div>' +
							'<div class="ois-cc-field"><label>Student ID</label><input class="ois-cc-input" value="' + esc( s.id ) + '" readonly></div>' +
							'<div class="ois-cc-field"><label>Class</label><input class="ois-cc-input" value="' + esc( s.class || '' ) + '" readonly></div>' +
							'<div class="ois-cc-field"><label>Mobile</label><input class="ois-cc-input" value="' + esc( state.maskedMobile ) + '" readonly></div>' +
						'</div>' +
						'<div class="ois-cc-field"><label>Course</label><input class="ois-cc-input" value="' + esc( c.title ) + '" readonly></div>' +
					'</div>' +
					'<div class="ois-cc-pay-card">' +
						errorHtml() +
						'<h3>Payment Method</h3>' +
						'<div class="ois-cc-pay-methods">' + methodsHtml + '</div>' +
						'<div class="ois-cc-summary-row total" style="border:none;padding:0 0 16px;"><span>Amount</span><span>' + money( c.price ) + '</span></div>' +
						'<button class="ois-cc-btn" id="ois-cc-pay-now" ' + ( state.busy ? 'disabled' : '' ) + '>' + ( state.busy ? 'Processing…' : 'Pay Now' ) + '</button>' +
						'<p class="ois-cc-sub" style="margin:12px 0 0;font-size:12px;">Dummy payment gateway — no real charge will be made.</p>' +
					'</div>' +
				'</div>' +
			'</div>'
		);
	}

	function renderThankYou() {
		var o = state.order;
		return (
			'<div class="ois-cc-thankyou">' +
				'<div class="ois-cc-check">&#10003;</div>' +
				'<h2>Payment Successful!</h2>' +
				'<p class="ois-cc-sub">Thank you, ' + esc( o.student_name ) + '. Your seat for <strong>' + esc( o.course_title ) + '</strong> is confirmed.</p>' +
				'<div class="ois-cc-order-card">' +
					'<div class="ois-cc-summary-row"><span>Order Number</span><span>' + esc( o.order_number ) + '</span></div>' +
					'<div class="ois-cc-summary-row"><span>Transaction ID</span><span>' + esc( o.txn_id ) + '</span></div>' +
					'<div class="ois-cc-summary-row"><span>Course</span><span>' + esc( o.course_title ) + '</span></div>' +
					'<div class="ois-cc-summary-row"><span>Payment Method</span><span>' + esc( o.payment_method ) + '</span></div>' +
					'<div class="ois-cc-summary-row total"><span>Amount Paid</span><span>' + money( o.amount ) + '</span></div>' +
				'</div>' +
				'<button class="ois-cc-btn" id="ois-cc-browse-more" style="max-width:260px;margin:24px auto 0;">Browse More Courses</button>' +
			'</div>'
		);
	}

	function render() {
		var html = '';
		switch ( state.step ) {
			case 'otp': html = renderOtp(); break;
			case 'courses': html = renderCourses(); break;
			case 'course-detail': html = renderCourseDetail(); break;
			case 'checkout': html = renderCheckout(); break;
			case 'thank-you': html = renderThankYou(); break;
			default: html = renderStudentId();
		}
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
			if ( state.resendCooldown <= 1 ) {
				clearInterval( resendTimer );
				setState( { resendCooldown: 0 } );
			} else {
				state.resendCooldown -= 1;
				render();
			}
		}, 1000 );
	}

	function requestOtp( studentId ) {
		setState( { busy: true, error: '' } );
		return api( '/verify-student', 'POST', { student_id: studentId } )
			.then( function ( res ) {
				startResendCooldown();
				setState( {
					busy: false,
					step: 'otp',
					studentId: studentId,
					maskedMobile: res.masked_mobile,
					testOtp: res.test_otp || '',
				} );
			} )
			.catch( function ( err ) {
				setState( { busy: false, error: err.message } );
			} );
	}

	function loadCourses() {
		return api( '/courses', 'GET' ).then( function ( courses ) {
			setState( { courses: courses } );
		} );
	}

	function bindEvents() {
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
					setState( { error: 'Please enter the full 6-digit code.' } );
					return;
				}
				setState( { busy: true, error: '' } );
				api( '/verify-otp', 'POST', { student_id: state.studentId, otp: otp } )
					.then( function ( res ) {
						setState( { busy: false, sessionToken: res.session_token, student: res.student, step: 'courses' } );
						return loadCourses();
					} )
					.catch( function ( err ) {
						setState( { busy: false, error: err.message } );
					} );
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

		root.querySelectorAll( '[data-view-course]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var id = parseInt( btn.getAttribute( 'data-view-course' ), 10 );
				var course = state.courses.filter( function ( c ) { return c.id === id; } )[ 0 ];
				setState( { step: 'course-detail', selectedCourse: course, error: '' } );
			} );
		} );

		var backToCourses = document.getElementById( 'ois-cc-back-to-courses' );
		if ( backToCourses ) {
			backToCourses.addEventListener( 'click', function () {
				setState( { step: 'courses', selectedCourse: null } );
			} );
		}

		var buyNow = document.getElementById( 'ois-cc-buy-now' );
		if ( buyNow ) {
			buyNow.addEventListener( 'click', function () {
				setState( { busy: true, error: '' } );
				api( '/create-order', 'POST', { session_token: state.sessionToken, course_id: state.selectedCourse.id } )
					.then( function ( res ) {
						setState( { busy: false, step: 'checkout', order: res } );
					} )
					.catch( function ( err ) {
						setState( { busy: false, error: err.message } );
					} );
			} );
		}

		var backToDetail = document.getElementById( 'ois-cc-back-to-detail' );
		if ( backToDetail ) {
			backToDetail.addEventListener( 'click', function () {
				setState( { step: 'course-detail' } );
			} );
		}

		root.querySelectorAll( '[data-pay-method]' ).forEach( function ( el ) {
			el.addEventListener( 'click', function () {
				setState( { paymentMethod: el.getAttribute( 'data-pay-method' ) } );
			} );
		} );

		var payNow = document.getElementById( 'ois-cc-pay-now' );
		if ( payNow ) {
			payNow.addEventListener( 'click', function () {
				setState( { busy: true, error: '' } );
				api( '/pay', 'POST', {
					session_token: state.sessionToken,
					order_id: state.order.order_id,
					payment_method: state.paymentMethod,
				} )
					.then( function ( res ) {
						setState( { busy: false, step: 'thank-you', order: res } );
					} )
					.catch( function ( err ) {
						setState( { busy: false, error: err.message } );
					} );
			} );
		}

		var browseMore = document.getElementById( 'ois-cc-browse-more' );
		if ( browseMore ) {
			browseMore.addEventListener( 'click', function () {
				setState( { step: 'courses', selectedCourse: null, order: null } );
			} );
		}
	}

	render();
} )();
