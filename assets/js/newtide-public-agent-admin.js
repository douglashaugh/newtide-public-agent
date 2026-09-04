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

/* Appearance tab: custom-icon picker (media/emoji/built-in), theme-palette
   swatches, and a live widget preview that mirrors every control. */
( function () {
	'use strict';

	var appr = window.NPA_APPEARANCE;
	var form = document.querySelector( '.npa-appearance-form' );
	if ( ! appr || ! form ) {
		return;
	}

	var OPTION       = 'npa_options';
	var widget       = document.getElementById( 'npa-preview-widget' );
	var iconSlot     = form.querySelector( '[data-npa-preview="icon"]' );
	var accentInput  = document.getElementById( 'npa-accent' );
	var accentHex    = document.getElementById( 'npa-accent-hex' );
	var emojiInput   = document.getElementById( 'npa-icon-emoji' );
	var builtinInput = document.getElementById( 'npa-icon-builtin' );
	var idInput      = document.getElementById( 'npa-icon-id' );
	var thumb        = document.getElementById( 'npa-icon-thumb' );
	var removeBtn    = document.getElementById( 'npa-icon-remove' );

	function field( name ) {
		return form.querySelector( '[name="' + OPTION + '[' + name + ']"]' );
	}

	function escAttr( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' );
	}
	function escHtml( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	function iconType() {
		var checked = form.querySelector( '[data-npa-icon-type]:checked' );
		return checked ? checked.value : 'default';
	}

	// Build launcher-icon markup for the current source (mirrors the PHP renderer).
	function iconMarkup() {
		var type = iconType();
		if ( type === 'image' ) {
			var url = thumb && thumb.getAttribute( 'data-url' );
			if ( url ) {
				return '<img class="newtide-public-agent__launcher-icon newtide-public-agent__launcher-icon--image" src="' + escAttr( url ) + '" alt="" aria-hidden="true" />';
			}
		} else if ( type === 'emoji' ) {
			var e = emojiInput ? emojiInput.value.trim() : '';
			if ( e ) {
				return '<span class="newtide-public-agent__launcher-icon newtide-public-agent__launcher-icon--emoji" aria-hidden="true">' + escHtml( e ) + '</span>';
			}
		} else if ( type === 'builtin' ) {
			var slug = ( builtinInput && builtinInput.value ) || 'chat';
			return ( appr.icons && ( appr.icons[ slug ] || appr.icons.chat ) ) || '';
		}
		return ( appr.icons && appr.icons.chat ) || '';
	}

	// Replace the single class beginning with `prefix` (shape/size/theme groups).
	function swapClass( prefix, value ) {
		widget.className = widget.className
			.split( /\s+/ )
			.filter( function ( c ) { return c.indexOf( prefix ) !== 0; } )
			.join( ' ' );
		if ( value ) {
			widget.classList.add( prefix + value );
		}
	}

	var POSITIONS = [ 'bottom-right', 'bottom-left', 'top-right', 'top-left' ];

	function updatePreview() {
		if ( ! widget ) {
			return;
		}

		if ( accentInput ) {
			widget.style.setProperty( '--npa-accent', accentInput.value );
			if ( accentHex ) {
				accentHex.textContent = accentInput.value;
			}
		}

		var themeSel = field( 'theme' );
		swapClass( 'newtide-public-agent--theme-', themeSel && themeSel.value !== 'auto' ? themeSel.value : '' );

		var shapeSel = field( 'launcher_shape' );
		swapClass( 'newtide-public-agent--shape-', shapeSel ? shapeSel.value : 'pill' );

		var sizeSel = field( 'launcher_size' );
		swapClass( 'newtide-public-agent--size-', sizeSel ? sizeSel.value : 'medium' );

		var posSel = field( 'position' );
		POSITIONS.forEach( function ( p ) {
			widget.classList.remove( 'newtide-public-agent--' + p );
		} );
		if ( posSel ) {
			widget.classList.add( 'newtide-public-agent--' + posSel.value );
		}

		var header   = field( 'header_title' );
		var headerEl = form.querySelector( '[data-npa-preview="header"]' );
		if ( header && headerEl ) {
			headerEl.textContent = header.value;
		}

		// Launcher label — the pill's visible wording. (On the real widget this
		// is also the launcher's accessible name; the preview button is
		// aria-hidden, so there is nothing to mirror here.)
		var label   = field( 'launcher_label' );
		var labelEl = form.querySelector( '[data-npa-preview="label"]' );
		if ( label && labelEl ) {
			labelEl.textContent = label.value;
		}

		var powered   = field( 'powered_by' );
		var poweredEl = form.querySelector( '[data-npa-preview="powered"]' );
		if ( poweredEl ) {
			poweredEl.hidden = ! ( powered && powered.checked );
		}

		if ( iconSlot ) {
			iconSlot.innerHTML = iconMarkup();
		}
	}

	function showPanel( type ) {
		form.querySelectorAll( '.npa-iconpanel' ).forEach( function ( p ) {
			p.hidden = p.getAttribute( 'data-icon-panel' ) !== type;
		} );
	}

	function setIconType( type ) {
		var radio = form.querySelector( '[data-npa-icon-type][value="' + type + '"]' );
		if ( radio ) {
			radio.checked = true;
		}
		showPanel( type );
	}

	// Icon source radios.
	form.querySelectorAll( '[data-npa-icon-type]' ).forEach( function ( r ) {
		r.addEventListener( 'change', function () {
			showPanel( r.value );
			updatePreview();
		} );
	} );

	// Media picker for a custom image.
	var uploadBtn = document.getElementById( 'npa-icon-upload' );
	var frame;
	if ( uploadBtn && window.wp && window.wp.media ) {
		uploadBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( frame ) {
				frame.open();
				return;
			}
			frame = window.wp.media( {
				title: appr.frameTitle,
				button: { text: appr.frameButton },
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				var url = ( att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url ) || att.url;
				if ( idInput ) {
					idInput.value = att.id;
				}
				if ( thumb ) {
					thumb.setAttribute( 'data-url', url );
					thumb.innerHTML = '<img src="' + escAttr( url ) + '" alt="" />';
				}
				if ( removeBtn ) {
					removeBtn.hidden = false;
				}
				setIconType( 'image' );
				updatePreview();
			} );
			frame.open();
		} );
	}

	if ( removeBtn ) {
		removeBtn.addEventListener( 'click', function () {
			if ( idInput ) {
				idInput.value = '0';
			}
			if ( thumb ) {
				thumb.removeAttribute( 'data-url' );
				thumb.innerHTML = '';
			}
			removeBtn.hidden = true;
			updatePreview();
		} );
	}

	if ( emojiInput ) {
		emojiInput.addEventListener( 'input', updatePreview );
	}

	// Built-in icon grid.
	form.querySelectorAll( '.npa-icon-choice' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			form.querySelectorAll( '.npa-icon-choice' ).forEach( function ( b ) {
				b.classList.remove( 'is-active' );
				b.setAttribute( 'aria-pressed', 'false' );
			} );
			btn.classList.add( 'is-active' );
			btn.setAttribute( 'aria-pressed', 'true' );
			if ( builtinInput ) {
				builtinInput.value = btn.getAttribute( 'data-icon' );
			}
			updatePreview();
		} );
	} );

	if ( accentInput ) {
		accentInput.addEventListener( 'input', updatePreview );
	}

	// Theme-palette swatches (recommended colours from the active theme).
	var paletteWrap  = document.getElementById( 'npa-palette' );
	var paletteLabel = document.getElementById( 'npa-palette-label' );
	var paletteEmpty = document.getElementById( 'npa-palette-empty' );
	if ( paletteWrap ) {
		var pal = appr.palette || [];
		if ( ! pal.length ) {
			if ( paletteEmpty ) {
				paletteEmpty.hidden = false;
			}
		} else {
			if ( paletteLabel ) {
				paletteLabel.hidden = false;
			}
			pal.forEach( function ( c ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'npa-swatch';
				b.style.background = c.color;
				b.title = c.name + ' — ' + c.color;
				b.setAttribute( 'aria-label', c.name + ' ' + c.color );
				b.addEventListener( 'click', function () {
					if ( accentInput ) {
						accentInput.value = c.color;
					}
					updatePreview();
				} );
				paletteWrap.appendChild( b );
			} );
		}
	}

	// Generic preview controls (theme/shape/size/position/header/powered).
	form.querySelectorAll( '[data-npa-preview-control]' ).forEach( function ( el ) {
		var evt = ( el.tagName === 'SELECT' || el.type === 'checkbox' ) ? 'change' : 'input';
		el.addEventListener( evt, updatePreview );
	} );

	updatePreview();
}() );

/* Additional Agents tab: repeater (add/remove rows), per-row mode + icon
   toggles, and a per-row media picker. */
( function () {
	'use strict';

	var agentsCfg = window.NPA_AGENTS || {};
	var form      = document.querySelector( '.npa-agents-form' );
	if ( ! form ) {
		return;
	}

	var list     = document.getElementById( 'npa-agents-list' );
	var tpl      = document.getElementById( 'npa-agent-row-template' );
	var addBtn   = document.getElementById( 'npa-add-agent' );
	var emptyMsg = document.getElementById( 'npa-agents-empty' );
	// New rows get indexes past the highest server-rendered one (sanitize reindexes).
	var counter  = list ? list.querySelectorAll( '[data-agent-row]' ).length : 0;

	function renumber() {
		var rows = list.querySelectorAll( '[data-agent-row]' );
		rows.forEach( function ( row, i ) {
			var num = row.querySelector( '.npa-agent-row__num' );
			if ( num ) {
				num.textContent = '#' + ( i + 1 );
			}
		} );
		if ( emptyMsg ) {
			emptyMsg.hidden = rows.length > 0;
		}
	}

	function toggleMode( row ) {
		var sel = row.querySelector( '[data-agent-mode-select]' );
		if ( ! sel ) {
			return;
		}
		row.querySelectorAll( '[data-agent-mode]' ).forEach( function ( el ) {
			el.hidden = el.getAttribute( 'data-agent-mode' ) !== sel.value;
		} );
	}

	function toggleIcon( row ) {
		var sel = row.querySelector( '[data-agent-icon-select]' );
		if ( ! sel ) {
			return;
		}
		row.querySelectorAll( '[data-agent-icon-panel]' ).forEach( function ( el ) {
			el.hidden = el.getAttribute( 'data-agent-icon-panel' ) !== sel.value;
		} );
	}

	function wireRow( row ) {
		var modeSel = row.querySelector( '[data-agent-mode-select]' );
		if ( modeSel ) {
			modeSel.addEventListener( 'change', function () { toggleMode( row ); } );
		}
		var iconSel = row.querySelector( '[data-agent-icon-select]' );
		if ( iconSel ) {
			iconSel.addEventListener( 'change', function () { toggleIcon( row ); } );
		}

		var nameInput = row.querySelector( '.npa-agent-name-input' );
		var nameLabel = row.querySelector( '.npa-agent-row__name' );
		if ( nameInput && nameLabel ) {
			nameInput.addEventListener( 'input', function () {
				nameLabel.textContent = nameInput.value || 'New agent';
			} );
		}

		var remove = row.querySelector( '[data-agent-remove]' );
		if ( remove ) {
			remove.addEventListener( 'click', function () {
				row.parentNode.removeChild( row );
				renumber();
			} );
		}

		var upload = row.querySelector( '.npa-agent-icon-upload' );
		if ( upload && window.wp && window.wp.media ) {
			upload.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var frame = window.wp.media( {
					title: agentsCfg.frameTitle || 'Choose an icon',
					button: { text: agentsCfg.frameButton || 'Use this image' },
					library: { type: 'image' },
					multiple: false
				} );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					var url = ( att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url ) || att.url;
					var id = row.querySelector( '.npa-agent-icon-id' );
					var thumb = row.querySelector( '.npa-agent-icon-thumb' );
					if ( id ) {
						id.value = att.id;
					}
					if ( thumb ) {
						thumb.setAttribute( 'data-url', url );
						thumb.innerHTML = '<img src="' + String( url ).replace( /"/g, '&quot;' ) + '" alt="" />';
					}
				} );
				frame.open();
			} );
		}
	}

	if ( list ) {
		list.querySelectorAll( '[data-agent-row]' ).forEach( wireRow );
		renumber();
	}

	if ( addBtn && tpl && list ) {
		addBtn.addEventListener( 'click', function () {
			var html = tpl.innerHTML.replace( /__i__/g, String( counter++ ) );
			var wrap = document.createElement( 'div' );
			wrap.innerHTML = html.trim();
			var row = wrap.firstElementChild;
			if ( ! row ) {
				return;
			}
			list.appendChild( row );
			wireRow( row );
			toggleMode( row );
			toggleIcon( row );
			renumber();
			var n = row.querySelector( '.npa-agent-name-input' );
			if ( n ) {
				n.focus();
			}
		} );
	}
}() );

/* Agent tab: in-admin "Test drive" chat — talks to the real configured agent
   through the same REST proxy the front-end widget uses. */
( function () {
	'use strict';

	var cfg  = window.NPA_ADMIN || {};
	var form = document.getElementById( 'npa-td-form' );
	var log  = document.getElementById( 'npa-td-log' );
	var input = document.getElementById( 'npa-td-input' );
	var send  = document.getElementById( 'npa-td-send' );
	if ( ! form || ! log || ! input || ! cfg.restUrl ) {
		return;
	}

	var conversationId = '';

	function bubble( who, text, cls ) {
		var hint = log.querySelector( '.npa-testdrive__hint' );
		if ( hint ) {
			hint.remove();
		}
		var row = document.createElement( 'div' );
		row.className = 'npa-td-msg npa-td-msg--' + who + ( cls ? ' ' + cls : '' );
		var b = document.createElement( 'div' );
		b.className = 'npa-td-bubble';
		b.textContent = text;
		row.appendChild( b );
		log.appendChild( row );
		log.scrollTop = log.scrollHeight;
		return row;
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var msg = input.value.trim();
		if ( ! msg ) {
			return;
		}

		bubble( 'user', msg );
		input.value = '';
		input.disabled = true;
		send.disabled = true;

		var typing = bubble( 'agent', '…', 'is-typing' );

		fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.restNonce
			},
			body: JSON.stringify( { message: msg, conversation_id: conversationId } )
		} ).then( function ( r ) {
			return r.json().then( function ( data ) {
				return { ok: r.ok, data: data };
			} );
		} ).then( function ( res ) {
			typing.remove();
			if ( res.ok && res.data && res.data.reply ) {
				if ( res.data.conversation_id ) {
					conversationId = res.data.conversation_id;
				}
				bubble( 'agent', res.data.reply );
			} else {
				var m = ( res.data && res.data.error && res.data.error.message ) || cfg.errorText || 'Request failed.';
				bubble( 'agent', m, 'is-error' );
			}
		} ).catch( function () {
			typing.remove();
			bubble( 'agent', cfg.errorText || 'Request failed.', 'is-error' );
		} ).then( function () {
			input.disabled = false;
			send.disabled = false;
			input.focus();
		} );
	} );
}() );
