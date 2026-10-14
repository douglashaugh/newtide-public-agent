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

	/* Drag bounds, in rem. The floor keeps the panel usable; the ceiling stops a
	   line getting so long it is hard to read, which is the problem being
	   solved. The viewport caps in CSS apply on top of these. */
	var MIN_W = 16;
	var MAX_W = 64;
	var MIN_H = 14;
	var MAX_H = 60;
	var STEP = 2;

	/* Pixels the pointer must travel before a press on the grip counts as a
	   drag rather than a click. */
	var DRAG_MIN = 4;

	/* Stamped on a saved size. Sizes saved before 0.16.0 carry none and are
	   not restored: they were dragged out because the panel could not grow on
	   its own then, and honouring them now would switch auto-grow off. */
	var SIZE_VERSION = 2;

	/* Copy and done icons. Inline, like the rest, so they inherit colour. */
	var COPY_ICON = '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16 1H6a2 2 0 0 0-2 2v12h2V3h10V1Zm3 4H10a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2Zm0 16H10V7h9v14Z"/></svg>';
	var DONE_ICON = '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false"><path fill="currentColor" d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z"/></svg>';

	function escapeHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	/* Auto-grow widths, in rem: the three owner presets (kept in step with the
	   stylesheet), so a grown panel is always a size the owner could have
	   chosen. Height needs no steps — the panel is as tall as its content — so
	   growing it only lifts the ceiling. */
	var GROW_WIDTHS = [ 33, 40, 48 ];

	/* A reply taller than this share of the largest log the screen allows is
	   one a visitor would have to scroll through, so it earns a wider panel. */
	var TALL_SHARE = 0.6;

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
		this.autoGrow = '1' === mount.getAttribute( 'data-auto-grow' );
		this.allowCopy = '1' === mount.getAttribute( 'data-copy' );
		this.allowCharts = '1' === mount.getAttribute( 'data-charts' );
		// Set once the visitor drags the panel: from then on their size wins.
		this.userSized = false;
		this.position = mount.getAttribute( 'data-position' ) || 'bottom-right';
		this.autoOpen = parseInt( mount.getAttribute( 'data-auto-open' ), 10 ) || 0;

		var promptsRaw = mount.getAttribute( 'data-prompts' ) || '';
		// Conversation starters: three at most, whatever an older save holds.
		this.prompts = promptsRaw
			? promptsRaw.split( '\n' ).map( function ( s ) { return s.trim(); } ).filter( function ( s ) { return s; } ).slice( 0, 3 )
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
		var wrap = el( 'div', 'newtide-public-agent__prompts', {
			role: 'group',
			'aria-label': t( 'tryAsking', 'Try asking' )
		} );
		var lead = el( 'div', 'newtide-public-agent__prompts-label', { 'aria-hidden': 'true' } );
		lead.textContent = t( 'tryAsking', 'Try asking' );
		wrap.appendChild( lead );
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

		/* Copy the whole conversation. Hidden until there is a reply to copy,
		   so it never offers an empty clipboard. */
		if ( this.allowCopy ) {
			var copyAll = el( 'button', 'newtide-public-agent__copyall', {
				type: 'button',
				'aria-label': t( 'copyAll', 'Copy the whole conversation' ),
				title: t( 'copyAll', 'Copy the whole conversation' ),
				hidden: 'hidden'
			} );
			copyAll.innerHTML = COPY_ICON + '<span class="newtide-public-agent__copyall-text"></span>';
			copyAll.lastChild.textContent = t( 'copy', 'Copy' );
			copyAll.addEventListener( 'click', this.copyConversation.bind( this ) );
			this.copyAllBtn = copyAll;
			header.appendChild( copyAll );
		}

		header.appendChild( newBtn );

		header.appendChild( closeBtn );

		var log = el( 'div', 'newtide-public-agent__log', {
			role: 'log',
			'aria-live': 'polite',
			'aria-atomic': 'false'
		} );

		var form = el( 'form', 'newtide-public-agent__form' );
		/* A textarea that grows with what is typed, so a long question can be
		   read back before it is sent. Enter still sends; Shift+Enter is a new
		   line. */
		var input = el( 'textarea', 'newtide-public-agent__input', {
			rows: '1',
			'aria-label': this.placeholder,
			placeholder: this.placeholder,
			autocomplete: 'off'
		} );
		input.addEventListener( 'input', this.fitInput.bind( this ) );
		input.addEventListener( 'keydown', this.onInputKey.bind( this ) );
		var send = el( 'button', 'newtide-public-agent__send', { type: 'submit' } );
		send.textContent = t( 'send', 'Send' );
		form.appendChild( input );
		form.appendChild( send );
		form.addEventListener( 'submit', this.onSubmit.bind( this ) );

		panel.appendChild( header );
		panel.appendChild( log );
		panel.appendChild( form );

		/* Says "Copied" to a screen reader. Separate from the log, whose live
		   region announces messages and should not read out button feedback. */
		var status = el( 'div', 'newtide-public-agent__status', {
			role: 'status',
			'aria-live': 'polite'
		} );
		panel.appendChild( status );
		this.status = status;

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
			var credit = powered;

			if ( url && /^https?:\/\//i.test( url ) ) {
				var link = document.createElement( 'a' );
				link.className = 'newtide-public-agent__powered-link';
				link.href = url;
				link.target = '_blank';
				link.rel = 'noopener';
				powered.appendChild( link );
				credit = link;
			}

			/* "Powered by" and the logo. Both schemes' logos are in the page and
			   the stylesheet shows the one matching the panel, so a visitor
			   whose device switches to dark mode mid-visit sees it follow. The
			   hidden one is display:none, so a screen reader reads the alt once.
			   Without logo addresses this is the plain-text credit again. */
			var logos = cfg.poweredLogos || {};
			if ( logos.light && logos.dark ) {
				powered.classList.add( 'newtide-public-agent__powered--logo' );
				var lead = el( 'span', 'newtide-public-agent__powered-label' );
				lead.textContent = t( 'poweredByLabel', 'Powered by' );
				credit.appendChild( lead );

				[ 'light', 'dark' ].forEach( function ( scheme ) {
					var img = el( 'img', 'newtide-public-agent__powered-logo newtide-public-agent__powered-logo--' + scheme, {
						src: logos[ scheme ],
						alt: t( 'poweredByLogo', 'NewTide' ),
						width: '367',
						height: '64'
					} );
					credit.appendChild( img );
				} );
			} else {
				credit.textContent = label;
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

		if ( this.autoGrow ) {
			// Read before anything inline is written, so this is the owner's preset.
			this.baseW = parseFloat( window.getComputedStyle( this.mount ).getPropertyValue( '--npa-panel-w' ) ) || GROW_WIDTHS[ 0 ];
			this.growW = this.baseW;
			this.mount.classList.add( 'newtide-public-agent--autogrow' );
		}

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

		// A fresh conversation starts compact again, unless the visitor chose a size.
		this.resetGrow();

		if ( this.copyAllBtn ) {
			this.copyAllBtn.hidden = true;
		}

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

	   Three things set it: the site owner's default (a class from PHP),
	   auto-grow, and a drag. A visitor's drag wins for that visitor and is
	   remembered per site, because someone who widened the panel to read a
	   table wants it wide for the next question too.

	   0.10 to 0.15 also had an expand button in the header. Auto-grow replaced
	   it in 0.16.0, and a remembered "expanded" is ignored rather than restored:
	   with no button left there would be no way back out of it.

	   Only the two custom properties are written; the viewport caps live in the
	   stylesheet, so nothing here can put the panel off screen.
	   --------------------------------------------------------------------- */

	Widget.prototype.storeKey = function () {
		return 'npa-panel:' + ( this.agent || 'default' );
	};

	Widget.prototype.saveSize = function () {
		try {
			window.localStorage.setItem( this.storeKey(), JSON.stringify( {
				v: SIZE_VERSION,
				w: this.customW || 0,
				h: this.customH || 0
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

		if ( ! saved || SIZE_VERSION !== saved.v || ! ( saved.w && saved.h ) ) {
			return;
		}

		// A remembered size is the visitor's choice. The panel is still hidden
		// here, so there is nothing to measure.
		this.stopGrowing( false );

		this.customW = clamp( parseFloat( saved.w ) || 0, MIN_W, MAX_W );
		this.customH = clamp( parseFloat( saved.h ) || 0, MIN_H, MAX_H );
		this.applySize();
	};

	Widget.prototype.applySize = function () {
		if ( ! this.customW || ! this.customH ) {
			return;
		}

		this.mount.style.setProperty( '--npa-panel-w', this.customW + 'rem' );
		this.mount.style.setProperty( '--npa-panel-h', this.customH + 'rem' );
	};

	/* Measure what is on screen now, so a drag or a key press continues from the
	   current size whether that came from the owner's default, a previous drag,
	   or auto-grow. */
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

		/* Only a real drag is a choice. A click on the grip, or a pointer that
		   wobbles a pixel, used to save whatever size the panel happened to be —
		   often a short one early in a conversation — and that pinned it there,
		   with auto-grow off, for every later visit. */
		var moved = false;

		var move = function ( ev ) {
			if ( ! moved ) {
				if ( Math.abs( ev.clientX - startX ) < DRAG_MIN && Math.abs( ev.clientY - startY ) < DRAG_MIN ) {
					return;
				}
				moved = true;
				self.stopGrowing( true );
			}

			self.customW = clamp( start.w + ( ( ev.clientX - startX ) * dir ) / rem, MIN_W, MAX_W );
			self.customH = clamp( start.h + ( ( startY - ev.clientY ) / rem ), MIN_H, MAX_H );
			self.applySize();
		};

		var up = function () {
			window.removeEventListener( 'pointermove', move );
			window.removeEventListener( 'pointerup', up );
			window.removeEventListener( 'pointercancel', up );
			self.mount.classList.remove( 'newtide-public-agent--resizing' );
			if ( moved ) {
				self.saveSize();
			}
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

		this.stopGrowing( true );

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

	/* ---------------------------------------------------------------------
	   Auto-grow.

	   The panel is as tall as its content, so lifting the height ceiling (the
	   --autogrow class) is all that growing taller takes. Width steps through
	   the presets once the panel is as tall as the screen allows (one step per
	   message), and at once when a message needs it: a table or code block
	   that has to scroll sideways, or one too long to read without scrolling. It
	   never shrinks mid-conversation — a panel that jumps about is worse than
	   a big one — and stops for good once the visitor sizes it themselves.
	   --------------------------------------------------------------------- */

	Widget.prototype.stopGrowing = function ( keepSize ) {
		if ( ! this.autoGrow || this.userSized ) {
			return;
		}

		/* Carry the grown size into the visitor's own, so grabbing the grip
		   continues from what is on screen rather than snapping back. */
		if ( keepSize && this.panel && ! this.panel.hidden ) {
			var size = this.currentSize();
			this.customW = size.w;
			this.customH = size.h;
			this.applySize();
		}

		this.userSized = true;
		this.mount.classList.remove( 'newtide-public-agent--autogrow' );
	};

	Widget.prototype.resetGrow = function () {
		if ( ! this.autoGrow || this.userSized ) {
			return;
		}
		this.setGrowWidth( this.baseW );
	};

	Widget.prototype.setGrowWidth = function ( w ) {
		this.growW = w;
		if ( w > this.baseW ) {
			this.mount.style.setProperty( '--npa-panel-w', w + 'rem' );
		} else {
			this.mount.style.removeProperty( '--npa-panel-w' );
		}
	};

	/* Whether this reply would read better in a wider panel. */
	Widget.prototype.needsRoom = function ( msg ) {
		// A chart is easier to read with room: give it at least the middle width.
		if ( msg.querySelector( '.newtide-public-agent__chart-plot' ) && this.growW < GROW_WIDTHS[ 1 ] ) {
			return true;
		}

		var wide = msg.querySelectorAll( 'table, pre' );
		for ( var i = 0; i < wide.length; i++ ) {
			if ( wide[ i ].scrollWidth > wide[ i ].clientWidth + 1 ) {
				return true;
			}
		}

		// The tallest the log can be: the panel's ceiling less its header and form.
		var cap = parseFloat( window.getComputedStyle( this.panel ).maxHeight );
		if ( ! ( cap > 0 ) ) {
			cap = window.innerHeight - 6 * rootFontSize();
		}
		var chrome = this.panel.offsetHeight - this.log.clientHeight;

		return msg.offsetHeight > TALL_SHARE * ( cap - chrome );
	};

	Widget.prototype.grow = function ( msg ) {
		if ( ! this.autoGrow || this.userSized || ! this.panel || this.panel.hidden ) {
			return;
		}

		var start = this.growW;
		var panel = this.panel;

		/* Measure with the transition off, or every reading would be the width
		   the panel is animating away from. */
		panel.style.transition = 'none';

		/* Growing taller has run out once the log scrolls: the panel is at the
		   screen's height. From there each message widens it one step, so the
		   panel keeps visibly growing with the conversation instead of turning
		   into a fixed box — which is how it looked to the first person to
		   try it. One step per message, so it builds up rather than jumping. */
		var log = this.log;
		var full = function () {
			return log.scrollHeight > log.clientHeight + 1;
		};
		var steps = 0;

		while ( this.needsRoom( msg ) || ( 0 === steps && full() ) ) {
			steps++;
			var next = 0;
			for ( var i = 0; i < GROW_WIDTHS.length; i++ ) {
				if ( GROW_WIDTHS[ i ] > this.growW ) {
					next = GROW_WIDTHS[ i ];
					break;
				}
			}
			if ( ! next ) {
				break;
			}

			var before = panel.getBoundingClientRect().width;
			var prev = this.growW;
			this.setGrowWidth( next );

			// Already as wide as the screen allows; a bigger number changes nothing.
			if ( panel.getBoundingClientRect().width <= before + 1 ) {
				this.setGrowWidth( prev );
				break;
			}
		}

		var end = this.growW;

		// Replay the change from where it started, this time animated.
		if ( end !== start ) {
			this.setGrowWidth( start );
			void panel.offsetWidth;
			panel.style.transition = '';
			this.setGrowWidth( end );
		} else {
			panel.style.transition = '';
		}

		this.log.scrollTop = this.log.scrollHeight;
	};

	/* ---------------------------------------------------------------------
	   Copy.

	   A visitor can copy one exchange (their question and the answer) from
	   the button under a reply, or the whole conversation from the header,
	   to paste into an email or a document. Both go on the clipboard twice:
	   as HTML, so a mail client or word processor keeps the agent's lists,
	   bold and tables, and as plain text for anywhere that takes only text.
	   Only real exchanges count — the greeting and error messages are left
	   out, since neither is something anyone wants to paste.
	   --------------------------------------------------------------------- */

	/* Record a message as part of the conversation, keeping the text it was
	   built from: the visitor's words, or the agent's Markdown, which reads
	   well as plain text. A reply also gets its copy button. */
	Widget.prototype.markTurn = function ( msg, text ) {
		msg.setAttribute( 'data-npa-turn', '' );
		msg.npaText = String( text || '' );

		if ( ! this.allowCopy || ! msg.classList.contains( 'newtide-public-agent__msg--agent' ) ) {
			return msg;
		}

		var btn = el( 'button', 'newtide-public-agent__copy', {
			type: 'button',
			'aria-label': t( 'copyTurn', 'Copy this question and answer' ),
			title: t( 'copyTurn', 'Copy this question and answer' )
		} );
		btn.innerHTML = COPY_ICON;
		btn.addEventListener( 'click', this.copyTurn.bind( this, msg, btn ) );
		msg.appendChild( btn );

		if ( this.copyAllBtn ) {
			this.copyAllBtn.hidden = false;
		}

		return msg;
	};

	/* One entry per message: who said it, as plain text and as HTML. */
	Widget.prototype.entryFor = function ( msg ) {
		var isAgent = msg.classList.contains( 'newtide-public-agent__msg--agent' );
		var bubble = msg.querySelector( '.newtide-public-agent__bubble' );
		var text = msg.npaText || ( bubble ? bubble.textContent : '' );
		var html;

		/* A chart pastes as its data: a table in the HTML, tab-separated
		   rows in the text. An SVG does not survive a mail client, and the
		   agent's chart JSON is no use to whoever reads the email. */
		if ( isAgent && window.NPAChart ) {
			text = text.replace( /```chart[^\n]*\n([\s\S]*?)```/gi, function ( whole, json ) {
				var parsed = window.NPAChart.parse( json );
				return parsed ? window.NPAChart.toText( parsed.spec ) : whole;
			} );
		}

		if ( isAgent && bubble && bubble.classList.contains( 'newtide-public-agent__bubble--rich' ) ) {
			// Already sanitized on the server; this is the markup on screen.
			// The clone loses the parsed spec, so pair each copy with its original.
			var clone = bubble.cloneNode( true );
			var live = bubble.querySelectorAll( '.newtide-public-agent__chart' );
			Array.prototype.forEach.call( clone.querySelectorAll( '.newtide-public-agent__chart' ), function ( fig, n ) {
				var spec = live[ n ] && live[ n ].npaSpec;
				var out = document.createElement( 'div' );
				if ( spec ) {
					if ( spec.title ) {
						var cap = document.createElement( 'p' );
						var strong = document.createElement( 'strong' );
						strong.textContent = spec.title + ( spec.subtitle ? ' — ' + spec.subtitle : '' );
						cap.appendChild( strong );
						out.appendChild( cap );
					}
					out.appendChild( window.NPAChart.toTable( spec ) );
				}
				fig.parentNode.replaceChild( out, fig );
			} );
			html = clone.innerHTML;
		} else {
			html = '<p>' + escapeHtml( text ).replace( /\n/g, '<br>' ) + '</p>';
		}

		return {
			who: isAgent ? t( 'assistant', 'Assistant' ) : t( 'you', 'You' ),
			text: text,
			html: html
		};
	};

	/* Build what goes on the clipboard. Tables get inline borders, because a
	   mail client drops the widget's stylesheet and a borderless table pastes
	   as an unreadable grid of words. */
	Widget.prototype.clipFor = function ( entries, withHeading ) {
		var textParts = [];
		var htmlParts = [];

		if ( withHeading ) {
			var when = new Date().toLocaleString();
			var heading = this.header || t( 'transcript', 'Chat transcript' );
			textParts.push( heading + ' — ' + when + '\n' + window.location.href );
			htmlParts.push(
				'<p><strong>' + escapeHtml( heading ) + '</strong> — ' + escapeHtml( when ) + '<br>' +
				'<a href="' + escapeHtml( window.location.href ) + '">' + escapeHtml( document.title || window.location.href ) + '</a></p>'
			);
		}

		entries.forEach( function ( e ) {
			textParts.push( e.who + ':\n' + e.text );
			htmlParts.push( '<p><strong>' + escapeHtml( e.who ) + ':</strong></p>' + e.html );
		} );

		var holder = document.createElement( 'div' );
		holder.innerHTML = htmlParts.join( '' );
		Array.prototype.forEach.call( holder.querySelectorAll( 'table' ), function ( table ) {
			table.setAttribute( 'style', 'border-collapse:collapse;margin:0 0 1em' );
		} );
		Array.prototype.forEach.call( holder.querySelectorAll( 'th, td' ), function ( cell ) {
			cell.setAttribute( 'style', 'border:1px solid #c3c4c7;padding:4px 8px;text-align:left;vertical-align:top' );
		} );

		return {
			text: textParts.join( '\n\n' ),
			html: '<div>' + holder.innerHTML + '</div>'
		};
	};

	/* Put HTML and plain text on the clipboard. The async Clipboard API needs
	   a secure (https) page; elsewhere, select a rendered copy and use the
	   older copy command, which carries the formatting the same way. */
	Widget.prototype.writeClipboard = function ( clip ) {
		if ( window.isSecureContext && navigator.clipboard && navigator.clipboard.write && window.ClipboardItem ) {
			return navigator.clipboard.write( [
				new window.ClipboardItem( {
					'text/html': new Blob( [ clip.html ], { type: 'text/html' } ),
					'text/plain': new Blob( [ clip.text ], { type: 'text/plain' } )
				} )
			] ).then( function () {
				return true;
			}, function () {
				return false;
			} );
		}

		var ok = false;
		var active = document.activeElement;
		var stage = document.createElement( 'div' );
		stage.className = 'newtide-public-agent__clipstage';
		stage.innerHTML = clip.html;
		document.body.appendChild( stage );

		try {
			var range = document.createRange();
			range.selectNodeContents( stage );
			var sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( range );
			ok = document.execCommand( 'copy' );
			sel.removeAllRanges();
		} catch ( e ) {
			ok = false;
		}

		document.body.removeChild( stage );
		if ( active && active.focus ) {
			active.focus();
		}

		return Promise.resolve( ok );
	};

	/* Confirm on the button itself, where the visitor is looking, and to a
	   screen reader through the status region. */
	Widget.prototype.flagCopied = function ( btn, ok ) {
		var said = ok ? t( 'copied', 'Copied' ) : t( 'copyFailed', 'Could not copy. Try selecting the text instead.' );
		var label = btn.getAttribute( 'aria-label' );
		var icon = btn.querySelector( 'svg' );

		if ( this.status ) {
			this.status.textContent = said;
		}
		if ( ! ok ) {
			return;
		}

		btn.classList.add( 'is-copied' );
		if ( icon ) {
			icon.outerHTML = DONE_ICON;
		}
		btn.setAttribute( 'aria-label', said );

		window.clearTimeout( btn.npaTimer );
		btn.npaTimer = window.setTimeout( function () {
			btn.classList.remove( 'is-copied' );
			var done = btn.querySelector( 'svg' );
			if ( done ) {
				done.outerHTML = COPY_ICON;
			}
			btn.setAttribute( 'aria-label', label );
		}, 2000 );
	};

	/* One exchange: this reply and the question it answered. */
	Widget.prototype.copyTurn = function ( msg, btn ) {
		var entries = [];
		var prev = msg.previousElementSibling;

		while ( prev && ! ( prev.hasAttribute( 'data-npa-turn' ) && prev.classList.contains( 'newtide-public-agent__msg--user' ) ) ) {
			/* Walk past error messages to the question. Reaching the previous
			   answer first means this reply has no question of its own. */
			if ( prev.hasAttribute( 'data-npa-turn' ) ) {
				break;
			}
			prev = prev.previousElementSibling;
		}
		if ( prev && prev.classList.contains( 'newtide-public-agent__msg--user' ) ) {
			entries.push( this.entryFor( prev ) );
		}
		entries.push( this.entryFor( msg ) );

		var self = this;
		this.writeClipboard( this.clipFor( entries, false ) ).then( function ( ok ) {
			self.flagCopied( btn, ok );
		} );
	};

	Widget.prototype.copyConversation = function () {
		var self = this;
		var turns = this.log.querySelectorAll( '[data-npa-turn]' );
		var entries = Array.prototype.map.call( turns, function ( m ) {
			return self.entryFor( m );
		} );

		if ( ! entries.length ) {
			return;
		}

		var btn = this.copyAllBtn;
		this.writeClipboard( this.clipFor( entries, true ) ).then( function ( ok ) {
			self.flagCopied( btn, ok );
		} );
	};

	/* Turn each ```chart block in a reply into a chart (charts.js). With charts
	   switched off it becomes the data table instead, so a visitor never sees
	   the raw JSON. A bubble holding a chart takes the full width. */
	Widget.prototype.drawCharts = function ( bubble ) {
		if ( ! window.NPAChart ) {
			return;
		}
		var blocks = bubble.querySelectorAll( 'pre > code.language-chart' );
		var self = this;
		Array.prototype.forEach.call( blocks, function ( code ) {
			if ( window.NPAChart.draw( code.parentNode, { draw: self.allowCharts } ) ) {
				bubble.classList.add( 'newtide-public-agent__bubble--chart' );
			}
		} );
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

		// Charts are drawn once the bubble is on the page, so they can measure it.
		if ( 'agent' === who && html ) {
			this.drawCharts( bubble );
		}

		this.log.scrollTop = this.log.scrollHeight;
		// The visitor's own long questions count too, not only the agent's replies.
		this.grow( msg );
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

	/* Fit the textarea to what is typed. The stylesheet caps it, after which
	   it scrolls. */
	Widget.prototype.fitInput = function () {
		var input = this.input;
		input.style.height = 'auto';
		input.style.height = input.scrollHeight + 'px';
	};

	Widget.prototype.onInputKey = function ( e ) {
		/* Enter sends, as it did when this was a one-line input. Not mid-way
		   through composing a character (IME), and not on a touch keyboard,
		   whose Enter key is the only way to start a new line. */
		if ( 'Enter' !== e.key || e.shiftKey || e.isComposing ) {
			return;
		}
		if ( window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches ) {
			return;
		}
		this.onSubmit( e );
	};

	Widget.prototype.onSubmit = function ( e ) {
		e.preventDefault();
		var text = this.input.value.trim();
		if ( ! text ) {
			return;
		}
		this.input.value = '';
		this.fitInput();
		this.sendMessage( text );
	};

	Widget.prototype.sendMessage = function ( text ) {
		text = ( text || '' ).trim();
		if ( ! text ) {
			return;
		}

		this.clearPrompts();
		this.markTurn( this.addMessage( 'user', text, true ), text );
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
				this.markTurn( this.addMessage( 'agent', res.data.reply, true, res.data.reply_html ), res.data.reply );
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
