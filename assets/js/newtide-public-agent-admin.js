/* NewTide Public Agent — admin actions (Test connection, Run tests). */
( function () {
	'use strict';

	var cfg = window.NPA_ADMIN || {};

	function post( action ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function onTestConnection( btn ) {
		var out = document.getElementById( 'npa-test-result' );
		btn.disabled = true;
		if ( out ) {
			out.className = 'npa-test-result';
			out.textContent = cfg.testingText || 'Testing…';
		}

		post( 'npa_test_connection' ).then( function ( res ) {
			btn.disabled = false;
			if ( ! out ) {
				return;
			}
			if ( res && res.success ) {
				var d = res.data || {};
				out.className = 'npa-test-result ' + ( d.ok ? 'is-ok' : 'is-error' );
				out.textContent = ( d.ok ? '✓ ' : '✕ ' ) + ( d.message || '' ) +
					( d.latency ? ' (' + d.latency + ' ms)' : '' );
			} else {
				out.className = 'npa-test-result is-error';
				out.textContent = ( res && res.data && res.data.message ) || cfg.errorText;
			}
		} ).catch( function () {
			btn.disabled = false;
			if ( out ) {
				out.className = 'npa-test-result is-error';
				out.textContent = cfg.errorText || 'Request failed.';
			}
		} );
	}

	function onRunTests( btn ) {
		var status = document.getElementById( 'npa-tests-status' );
		var results = document.getElementById( 'npa-tests-results' );
		btn.disabled = true;
		if ( status ) {
			status.className = 'npa-test-result';
			status.textContent = cfg.runningText || 'Running…';
		}

		post( 'npa_run_tests' ).then( function ( res ) {
			btn.disabled = false;
			if ( res && res.success ) {
				var d = res.data || {};
				if ( status ) {
					var pass = d.passed === d.total;
					status.className = 'npa-test-result ' + ( pass ? 'is-ok' : 'is-error' );
					status.textContent = d.passed + '/' + d.total;
				}
				if ( results && typeof d.html === 'string' ) {
					results.innerHTML = d.html;
				}
			} else if ( status ) {
				status.className = 'npa-test-result is-error';
				status.textContent = ( res && res.data && res.data.message ) || cfg.errorText;
			}
		} ).catch( function () {
			btn.disabled = false;
			if ( status ) {
				status.className = 'npa-test-result is-error';
				status.textContent = cfg.errorText || 'Request failed.';
			}
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		if ( e.target && e.target.id === 'npa-test-connection' ) {
			onTestConnection( e.target );
		} else if ( e.target && e.target.id === 'npa-run-tests' ) {
			onRunTests( e.target );
		}
	} );
}() );
