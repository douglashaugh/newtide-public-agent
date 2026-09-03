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
		header.appendChild( title );
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

		if ( this.powered ) {
			var powered = el( 'div', 'newtide-public-agent__powered' );
			powered.textContent = t( 'poweredBy', 'Powered by NewTide' );
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
	};

	Widget.prototype.addMessage = function ( who, text, announce ) {
		var msg = el( 'div', 'newtide-public-agent__msg newtide-public-agent__msg--' + who );
		var bubble = el( 'div', 'newtide-public-agent__bubble' );
		bubble.textContent = text;
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
				this.addMessage( 'agent', res.data.reply, true );
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
