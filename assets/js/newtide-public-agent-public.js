/* NewTide Public Agent — front-end chat widget (non-streaming, a11y-first). */
( function () {
	'use strict';

	var cfg = window.NPA_WIDGET || {};
	var i18n = cfg.i18n || {};

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	function el( tag, cls, attrs ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				node.setAttribute( k, attrs[ k ] );
			} );
		}
		return node;
	}

	/* Corner arrows out / in. Inline so the control needs no extra request and
	   inherits the header's colour. */
	var EXPAND_ICON = '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 10V4h6v2H6v4H4Zm10-6h6v6h-2V6h-4V4ZM4 14h2v4h4v2H4v-6Zm14 0h2v6h-6v-2h4v-4Z"/></svg>';
	var SHRINK_ICON = '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10 4v6H4V8h4V4h2Zm4 0h2v4h4v2h-6V4ZM4 14h6v6H8v-4H4v-2Zm10 0h6v2h-4v4h-2v-6Z"/></svg>';

	/* Drag bounds, in rem. The floor keeps the panel usable; the ceiling stops a
	   line getting so long it is hard to read, which is the problem being
	   solved. The viewport caps in CSS apply on top of these. */
	var MIN_W = 16;
	var MAX_W = 64;
	var MIN_H = 14;
	var MAX_H = 60;
	var STEP = 2;

	function rootFontSize() {
		var px = parseFloat( window.getComputedStyle( document.documentElement ).fontSize );
		return px > 0 ? px : 16;
	}

	function clamp( value, min, max ) {
		return Math.min( max, Math.max( min, value ) );
	}

	function Widget( mount ) {
		this.mount = mount;
		this.agent = mount.getAttribute( 'data-agent' ) || '';
		this.agentToken = mount.getAttribute( 'data-agent-token' ) || '';
		this.greeting = mount.getAttribute( 'data-greeting' ) || '';
		this.label = mount.getAttribute( 'data-label' ) || 'Chat';
		this.header = mount.getAttribute( 'data-header' ) || this.label;
		this.placeholder = mount.getAttribute( 'data-placeholder' ) || t( 'input', 'Type your message' );
		this.errorText = mount.getAttribute( 'data-error' ) || t( 'error', 'Something went wrong.' );
		this.powered = '1' === mount.getAttribute( 'data-powered' );
		this.remember = '1' === mount.getAttribute( 'data-remember' );
		this.allowResize = '1' === mount.getAttribute( 'data-allow-resize' );
		this.position = mount.getAttribute( 'data-position' ) || 'bottom-right';
		this.expanded = false;
		this.autoOpen = parseInt( mount.getAttribute( 'data-auto-open' ), 10 ) || 0;

		var promptsRaw = mount.getAttribute( 'data-prompts' ) || '';
		this.prompts = promptsRaw
			? promptsRaw.split( '\n' ).map( function ( s ) { return s.trim(); } ).filter( function ( s ) { return s; } )
			: [];

		this.storageKey = 'npa_open_' + this.agent;
		this.conversationId = '';
		this.open = false;
		this.panel = null;
		this.greeted = false;

		this.launcher = mount.querySelector( '.newtide-public-agent__launcher' );
		if ( this.launcher ) {
			this.launcher.addEventListener( 'click', this.toggle.bind( this ) );
		}

		this.maybeAutoOpen();
	}

	Widget.prototype.readStored = function () {
		try {
			return '1' === window.localStorage.getItem( this.storageKey );
		} catch ( e ) {
			return false;
		}
	};

	Widget.prototype.writeStored = function ( isOpen ) {
		if ( ! this.remember ) {
			return;
		}
		try {
			window.localStorage.setItem( this.storageKey, isOpen ? '1' : '0' );
		} catch ( e ) {
			/* Storage unavailable (private mode) — non-fatal. */
		}
	};

	Widget.prototype.maybeAutoOpen = function () {
		var self = this;
		if ( this.remember && this.readStored() ) {
			this.openPanel();
			return;
		}
		if ( this.autoOpen > 0 ) {
			window.setTimeout( function () {
				if ( ! self.open ) {
					self.openPanel();
				}
			}, this.autoOpen * 1000 );
		}
	};

	Widget.prototype.toggle = function () {
		if ( this.open ) {
			this.close();
		} else {
			this.openPanel();
		}
	};

	Widget.prototype.openPanel = function () {
		if ( ! this.panel ) {
			this.build();
		}
		this.open = true;
		this.panel.hidden = false;
		this.launcher.setAttribute( 'aria-expanded', 'true' );
		if ( ! this.greeted ) {
			if ( this.greeting ) {
				this.addMessage( 'agent', this.greeting, false );
			}
			this.renderPrompts();
			this.greeted = true;
		}
		this.writeStored( true );
		var input = this.input;
		window.setTimeout( function () {
			input.focus();
		}, 30 );
	};

	Widget.prototype.close = function () {
		this.open = false;
		if ( this.panel ) {
			this.panel.hidden = true;
		}
		this.writeStored( false );
		this.launcher.setAttribute( 'aria-expanded', 'false' );
		this.launcher.focus();
	};

	Widget.prototype.renderPrompts = function () {
		if ( ! this.prompts.length ) {
			return;
		}
		var wrap = el( 'div', 'newtide-public-agent__prompts' );
		var self = this;
		this.prompts.forEach( function ( text ) {
			var chip = el( 'button', 'newtide-public-agent__prompt', { type: 'button' } );
			chip.textContent = text;
			chip.addEventListener( 'click', function () {
				self.sendMessage( text );
			} );
			wrap.appendChild( chip );
		} );
		this.promptsEl = wrap;
		this.log.appendChild( wrap );
		this.log.scrollTop = this.log.scrollHeight;
	};

	Widget.prototype.clearPrompts = function () {
		if ( this.promptsEl && this.promptsEl.parentNode ) {
			this.promptsEl.parentNode.removeChild( this.promptsEl );
		}
		this.promptsEl = null;
	};

	Widget.prototype.build = function () {
		var panel = el( 'div', 'newtide-public-agent__panel', {
			role: 'dialog',
			'aria-label': t( 'dialog', 'Chat' ),
			hidden: 'hidden'
		} );

		var header = el( 'div', 'newtide-public-agent__header' );
		var title = el( 'span', 'newtide-public-agent__title' );
		title.textContent = this.header;
		var closeBtn = el( 'button', 'newtide-public-agent__close', {
			type: 'button',
			'aria-label': t( 'close', 'Close chat' )
		} );
		closeBtn.innerHTML = '&times;';
		closeBtn.addEventListener( 'click', this.close.bind( this ) );

		// Start over. The server holds the conversation, so this only clears the
		// local view and flags the next message to drop the stored thread.
		var newBtn = el( 'button', 'newtide-public-agent__newchat', {
			type: 'button',
			'aria-label': t( 'newChat', 'Start a new chat' )
		} );
		newBtn.textContent = t( 'newChat', 'New chat' );
		newBtn.addEventListener( 'click', this.newConversation.bind( this ) );

		header.appendChild( title );
		header.appendChild( newBtn );

		/* Expand. The single most useful control here: a long answer in a 22rem
		   column is the thing visitors complain about, and one click fixes it. */
		if ( this.allowResize ) {
			var expandBtn = el( 'button', 'newtide-public-agent__expand', {
				type: 'button',
				'aria-label': t( 'expand', 'Expand chat' ),
				'aria-pressed': 'false'
			} );
			expandBtn.innerHTML = EXPAND_ICON;
			expandBtn.addEventListener( 'click', this.toggleExpand.bind( this ) );
			this.expandBtn = expandBtn;
			header.appendChild( expandBtn );
		}

		header.appendChild( closeBtn );

		var log = el( 'div', 'newtide-public-agent__log', {
			role: 'log',
			'aria-live': 'polite',
			'aria-atomic': 'false'
		} );

		var form = el( 'form', 'newtide-public-agent__form' );
		var input = el( 'input', 'newtide-public-agent__input', {
			type: 'text',
			'aria-label': this.placeholder,
			placeholder: this.placeholder,
			autocomplete: 'off'
		} );
		var send = el( 'button', 'newtide-public-agent__send', { type: 'submit' } );
		send.textContent = t( 'send', 'Send' );
		form.appendChild( input );
		form.appendChild( send );
		form.addEventListener( 'submit', this.onSubmit.bind( this ) );

		panel.appendChild( header );
		panel.appendChild( log );
		panel.appendChild( form );

		/* A drag grip on the corner away from the page edge. It is a real button
		   so it can be tabbed to and driven with the arrow keys — a resize that
		   only works with a mouse is not a resize for everybody. */
		if ( this.allowResize ) {
			var grip = el( 'button', 'newtide-public-agent__resize', {
				type: 'button',
				'aria-label': t( 'resize', 'Resize chat. Use the arrow keys to adjust, or drag.' )
			} );
			grip.addEventListener( 'pointerdown', this.onResizeStart.bind( this ) );
			grip.addEventListener( 'keydown', this.onResizeKey.bind( this ) );
			panel.appendChild( grip );
		}

		if ( this.powered ) {
			var powered = el( 'div', 'newtide-public-agent__powered' );
			var label = t( 'poweredBy', 'Powered by NewTide' );
			var url = cfg.poweredUrl || '';

			/* A new tab, so a visitor mid-conversation is never navigated off
			   the site they are chatting with. The URL comes from the server
			   and only an http(s) one is used. */
			if ( url && /^https?:\/\//i.test( url ) ) {
				var link = document.createElement( 'a' );
				link.className = 'newtide-public-agent__powered-link';
				link.href = url;
				link.target = '_blank';
				link.rel = 'noopener';
				link.textContent = label;
				powered.appendChild( link );
			} else {
				powered.textContent = label;
			}

			panel.appendChild( powered );
		}

		panel.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				this.close();
			}
		}.bind( this ) );

		this.mount.appendChild( panel );
		this.panel = panel;
		this.log = log;
		this.input = input;
		this.send = send;

		// After the panel exists, so a measured size has something to measure.
		this.restoreSize();
	};

	/* Clear the visible conversation and mark the next message as a fresh start.
	   The reset is not sent immediately — that would cost a request for a visitor
	   who never types again. The flag rides along with the next message, and an
	   abandoned thread expires on the server by itself. */
	Widget.prototype.newConversation = function () {
		this.resetPending = true;
		this.conversationId = '';
		this.greeted = false;

		if ( this.log ) {
			this.log.innerHTML = '';
		}
		this.promptsEl = null;

		if ( this.greeting ) {
			this.addMessage( 'agent', this.greeting, true );
		}
		this.renderPrompts();
		this.greeted = true;

		if ( this.input ) {
			this.input.focus();
		}
	};

	/* The agent writes Markdown, so the server sends a rendered, sanitized copy
	   alongside the plain text. Only that server-rendered HTML is ever inserted
	   as markup; anything else — the visitor's own message, an error, a reply
	   from a transport that sends no html — stays textContent. */
	/* ---------------------------------------------------------------------
	   Panel size.

	   Three things set it: the site owner's default (a class from PHP), the
	   visitor's expand toggle, and a drag. A visitor's choice wins for that
	   visitor and is remembered per site, because someone who widened the panel
	   to read a table wants it wide for the next question too.

	   Only the two custom properties are written; the viewport caps live in the
	   stylesheet, so nothing here can put the panel off screen.
	   --------------------------------------------------------------------- */

	Widget.prototype.storeKey = function () {
		return 'npa-panel:' + ( this.agent || 'default' );
	};

	Widget.prototype.saveSize = function () {
		try {
			window.localStorage.setItem( this.storeKey(), JSON.stringify( {
				w: this.customW || 0,
				h: this.customH || 0,
				expanded: !! this.expanded
			} ) );
		} catch ( e ) {
			/* Private browsing, or storage disabled. A size that does not
			   persist is a smaller problem than a widget that throws. */
		}
	};

	Widget.prototype.restoreSize = function () {
		if ( ! this.allowResize ) {
			return;
		}

		var saved = null;
		try {
			saved = JSON.parse( window.localStorage.getItem( this.storeKey() ) || 'null' );
		} catch ( e ) {
			saved = null;
		}

		if ( ! saved ) {
			return;
		}

		if ( saved.expanded ) {
			this.setExpanded( true );
			return;
		}

		if ( saved.w && saved.h ) {
			this.customW = clamp( parseFloat( saved.w ) || 0, MIN_W, MAX_W );
			this.customH = clamp( parseFloat( saved.h ) || 0, MIN_H, MAX_H );
			this.applySize();
		}
	};

	Widget.prototype.applySize = function () {
		if ( ! this.customW || ! this.customH ) {
			return;
		}

		this.mount.style.setProperty( '--npa-panel-w', this.customW + 'rem' );
		this.mount.style.setProperty( '--npa-panel-h', this.customH + 'rem' );
	};

	Widget.prototype.setExpanded = function ( on ) {
		this.expanded = !! on;
		this.mount.classList.toggle( 'newtide-public-agent--expanded', this.expanded );

		if ( this.expanded ) {
			/* An explicit size would beat the expanded class, since both write
			   the same properties and the inline one wins. */
			this.mount.style.removeProperty( '--npa-panel-w' );
			this.mount.style.removeProperty( '--npa-panel-h' );
		} else {
			this.applySize();
		}

		if ( this.expandBtn ) {
			this.expandBtn.setAttribute( 'aria-pressed', this.expanded ? 'true' : 'false' );
			this.expandBtn.setAttribute(
				'aria-label',
				this.expanded ? t( 'shrink', 'Shrink chat' ) : t( 'expand', 'Expand chat' )
			);
			this.expandBtn.innerHTML = this.expanded ? SHRINK_ICON : EXPAND_ICON;
		}
	};

	Widget.prototype.toggleExpand = function () {
		this.setExpanded( ! this.expanded );
		this.saveSize();

		// A taller panel reveals older messages; keep the newest in view.
		if ( this.log ) {
			this.log.scrollTop = this.log.scrollHeight;
		}
	};

	/* Measure what is on screen now, so a drag or a key press continues from the
	   current size whether that came from the owner's default, a previous drag,
	   or the expanded class. */
	Widget.prototype.currentSize = function () {
		var rem = rootFontSize();
		var rect = this.panel.getBoundingClientRect();

		return {
			w: clamp( rect.width / rem, MIN_W, MAX_W ),
			h: clamp( rect.height / rem, MIN_H, MAX_H )
		};
	};

	Widget.prototype.onResizeStart = function ( e ) {
		if ( e.button && 0 !== e.button ) {
			return;
		}

		e.preventDefault();

		var start = this.currentSize();
		var startX = e.clientX;
		var startY = e.clientY;
		var rem = rootFontSize();
		// Anchored to the right, the panel grows as the pointer moves left.
		var dir = 'bottom-left' === this.position ? 1 : -1;
		var self = this;

		// An expanded panel being dragged becomes an explicitly sized one.
		if ( this.expanded ) {
			this.setExpanded( false );
		}

		var move = function ( ev ) {
			self.customW = clamp( start.w + ( ( ev.clientX - startX ) * dir ) / rem, MIN_W, MAX_W );
			self.customH = clamp( start.h + ( ( startY - ev.clientY ) / rem ), MIN_H, MAX_H );
			self.applySize();
		};

		var up = function () {
			window.removeEventListener( 'pointermove', move );
			window.removeEventListener( 'pointerup', up );
			window.removeEventListener( 'pointercancel', up );
			self.mount.classList.remove( 'newtide-public-agent--resizing' );
			self.saveSize();
		};

		this.mount.classList.add( 'newtide-public-agent--resizing' );
		window.addEventListener( 'pointermove', move );
		window.addEventListener( 'pointerup', up );
		window.addEventListener( 'pointercancel', up );
	};

	Widget.prototype.onResizeKey = function ( e ) {
		var keys = { ArrowLeft: 1, ArrowRight: 1, ArrowUp: 1, ArrowDown: 1 };

		if ( ! keys[ e.key ] ) {
			return;
		}

		e.preventDefault();

		if ( this.expanded ) {
			this.setExpanded( false );
		}

		var size = this.currentSize();
		this.customW = size.w;
		this.customH = size.h;

		// Left and right follow the grip, which sits on the inward edge.
		var dir = 'bottom-left' === this.position ? 1 : -1;

		if ( 'ArrowLeft' === e.key ) {
			this.customW = clamp( this.customW - STEP * dir, MIN_W, MAX_W );
		} else if ( 'ArrowRight' === e.key ) {
			this.customW = clamp( this.customW + STEP * dir, MIN_W, MAX_W );
		} else if ( 'ArrowUp' === e.key ) {
			this.customH = clamp( this.customH + STEP, MIN_H, MAX_H );
		} else {
			this.customH = clamp( this.customH - STEP, MIN_H, MAX_H );
		}

		this.applySize();
		this.saveSize();
	};

	Widget.prototype.addMessage = function ( who, text, announce, html ) {
		var msg = el( 'div', 'newtide-public-agent__msg newtide-public-agent__msg--' + who );
		var bubble = el( 'div', 'newtide-public-agent__bubble' );
		if ( 'agent' === who && html ) {
			bubble.innerHTML = html;
			bubble.classList.add( 'newtide-public-agent__bubble--rich' );
		} else {
			bubble.textContent = text;
		}
		if ( announce ) {
			var prefix = 'agent' === who ? t( 'received', 'Assistant replied' ) : t( 'sent', 'You said' );
			bubble.setAttribute( 'aria-label', prefix + ': ' + text );
		}
		msg.appendChild( bubble );
		this.log.appendChild( msg );
		this.log.scrollTop = this.log.scrollHeight;
		return msg;
	};

	Widget.prototype.showTyping = function () {
		this.typing = el( 'div', 'newtide-public-agent__msg newtide-public-agent__msg--agent newtide-public-agent__typing' );
		var bubble = el( 'div', 'newtide-public-agent__bubble', { 'aria-label': t( 'typing', 'Assistant is typing…' ) } );
		bubble.innerHTML = '<span></span><span></span><span></span>';
		this.typing.appendChild( bubble );
		this.log.appendChild( this.typing );
		this.log.scrollTop = this.log.scrollHeight;
	};

	Widget.prototype.hideTyping = function () {
		if ( this.typing && this.typing.parentNode ) {
			this.typing.parentNode.removeChild( this.typing );
		}
		this.typing = null;
	};

	Widget.prototype.setBusy = function ( busy ) {
		this.input.disabled = busy;
		this.send.disabled = busy;
	};

	Widget.prototype.onSubmit = function ( e ) {
		e.preventDefault();
		var text = this.input.value.trim();
		if ( ! text ) {
			return;
		}
		this.input.value = '';
		this.sendMessage( text );
	};

	Widget.prototype.sendMessage = function ( text ) {
		text = ( text || '' ).trim();
		if ( ! text ) {
			return;
		}

		this.clearPrompts();
		this.addMessage( 'user', text, true );
		this.setBusy( true );
		this.showTyping();

		var body = {
			message: text,
			conversation_id: this.conversationId,
			new_conversation: this.resetPending || false,
			// Which agent this mount is for. The token is the server's own
			// signature over the id; without both the proxy answers as the
			// site default, so per-page and shortcode agents need them sent.
			agent_id: this.agent,
			agent_token: this.agentToken,
			context: {
				page_url: window.location.href,
				page_title: document.title,
				locale: cfg.locale || ''
			}
		};

		this.resetPending = false;

		fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify( body )
		} ).then( function ( r ) {
			return r.json().then( function ( data ) {
				return { ok: r.ok, data: data };
			} );
		} ).then( function ( res ) {
			this.hideTyping();
			this.setBusy( false );
			if ( res.ok && res.data && res.data.reply ) {
				if ( res.data.conversation_id ) {
					this.conversationId = res.data.conversation_id;
				}
				this.addMessage( 'agent', res.data.reply, true, res.data.reply_html );
			} else {
				var msg = ( res.data && res.data.error && res.data.error.message ) || this.errorText;
				this.addMessage( 'agent', msg, true );
			}
			this.input.focus();
		}.bind( this ) ).catch( function () {
			this.hideTyping();
			this.setBusy( false );
			this.addMessage( 'agent', this.errorText, true );
			this.input.focus();
		}.bind( this ) );
	};

	function init() {
		var mounts = document.querySelectorAll( '[data-npa-widget]' );
		Array.prototype.forEach.call( mounts, function ( m ) {
			if ( ! m.getAttribute( 'data-npa-ready' ) ) {
				m.setAttribute( 'data-npa-ready', '1' );
				new Widget( m );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
