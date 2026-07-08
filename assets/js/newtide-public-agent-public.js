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
		this.greeting = mount.getAttribute( 'data-greeting' ) || '';
		this.label = mount.getAttribute( 'data-label' ) || 'Chat';
		this.conversationId = '';
		this.open = false;
		this.panel = null;
		this.greeted = false;

		this.launcher = mount.querySelector( '.newtide-public-agent__launcher' );
		if ( this.launcher ) {
			this.launcher.addEventListener( 'click', this.toggle.bind( this ) );
		}
	}

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
		if ( ! this.greeted && this.greeting ) {
			this.addMessage( 'agent', this.greeting, false );
			this.greeted = true;
		}
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
		this.launcher.setAttribute( 'aria-expanded', 'false' );
		this.launcher.focus();
	};

	Widget.prototype.build = function () {
		var panel = el( 'div', 'newtide-public-agent__panel', {
			role: 'dialog',
			'aria-label': t( 'dialog', 'Chat' ),
			hidden: 'hidden'
		} );

		var header = el( 'div', 'newtide-public-agent__header' );
		var title = el( 'span', 'newtide-public-agent__title' );
		title.textContent = this.label;
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
			'aria-label': t( 'input', 'Type your message' ),
			placeholder: t( 'input', 'Type your message' ),
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

		this.addMessage( 'user', text, true );
		this.input.value = '';
		this.setBusy( true );
		this.showTyping();

		var body = {
			message: text,
			conversation_id: this.conversationId,
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
				var msg = ( res.data && res.data.error && res.data.error.message ) || t( 'error', 'Something went wrong.' );
				this.addMessage( 'agent', msg, true );
			}
			this.input.focus();
		}.bind( this ) ).catch( function () {
			this.hideTyping();
			this.setBusy( false );
			this.addMessage( 'agent', t( 'error', 'Something went wrong.' ), true );
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
