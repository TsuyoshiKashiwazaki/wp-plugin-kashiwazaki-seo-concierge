/**
 * Kashiwazaki SEO Concierge — front-end chat widget.
 * Vanilla JS, no jQuery. The chat panel is built on first open (lazy).
 */
( function () {
	'use strict';

	if ( typeof window.ksConcierge === 'undefined' ) {
		return;
	}

	var cfg = window.ksConcierge;
	var root = document.getElementById( 'ks-concierge-root' );
	if ( ! root ) {
		return;
	}

	var built = false;
	var panel = null;
	var messages = null;
	var input = null;
	var consentGiven = ! cfg.consent;

	root.removeAttribute( 'hidden' );
	if ( cfg.accent ) {
		root.style.setProperty( '--ks-accent', cfg.accent );
	}

	var tab = document.createElement( 'button' );
	tab.type = 'button';
	tab.className = 'ks-concierge__tab';
	tab.setAttribute( 'aria-haspopup', 'dialog' );
	tab.setAttribute( 'aria-expanded', 'false' );
	tab.setAttribute( 'aria-label', cfg.tabLabel );
	// Icon + label as separate spans so the label can collapse to an icon-only
	// pill on small screens (where a wide button overlaps scroll-top buttons).
	var tabIcon = el( 'span', 'ks-concierge__tab-icon', '💁' );
	tabIcon.setAttribute( 'aria-hidden', 'true' );
	tab.appendChild( tabIcon );
	tab.appendChild( el( 'span', 'ks-concierge__tab-text', cfg.tabLabel ) );
	root.appendChild( tab );

	tab.addEventListener( 'click', function () {
		if ( ! built ) {
			buildPanel();
		}
		togglePanel();
	} );

	function ga( name ) {
		// Use a clear, namespaced event name (e.g. ks_concierge_ask) for both GA4
		// (gtag) and GTM (dataLayer) so the plugin's events are easy to find and do
		// not collide with GA4's built-in events like "click".
		var eventName = 'ks_concierge_' + name;
		if ( cfg.ga4 && typeof window.gtag === 'function' ) {
			window.gtag( 'event', eventName, { plugin: 'kashiwazaki-seo-concierge' } );
		}
		if ( window.dataLayer && typeof window.dataLayer.push === 'function' ) {
			window.dataLayer.push( { event: eventName } );
		}
	}

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function buildPanel() {
		panel = el( 'div', 'ks-concierge__panel' );
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-modal', 'false' );
		var widgetTitle = cfg.title || 'Kashiwazaki SEO Concierge';
		panel.setAttribute( 'aria-label', widgetTitle );
		panel.hidden = true;

		var header = el( 'div', 'ks-concierge__header' );
		header.appendChild( el( 'h2', null, widgetTitle ) );
		var close = el( 'button', 'ks-concierge__close', '×' );
		close.type = 'button';
		close.setAttribute( 'aria-label', cfg.i18n.close );
		close.addEventListener( 'click', togglePanel );
		header.appendChild( close );
		panel.appendChild( header );

		messages = el( 'div', 'ks-concierge__messages' );
		messages.setAttribute( 'role', 'log' );
		messages.setAttribute( 'aria-live', 'polite' );
		panel.appendChild( messages );

		if ( cfg.initial ) {
			addBot( cfg.initial, [] );
		}

		if ( cfg.chips && cfg.chips.length ) {
			var chips = el( 'div', 'ks-concierge__chips' );
			cfg.chips.forEach( function ( chip ) {
				var b = el( 'button', 'ks-concierge__chip', chip );
				b.type = 'button';
				b.addEventListener( 'click', function () {
					input.value = chip;
					submit();
				} );
				chips.appendChild( b );
			} );
			panel.appendChild( chips );
		}

		var form = el( 'form', 'ks-concierge__form' );
		input = el( 'input', 'ks-concierge__input' );
		input.type = 'text';
		input.placeholder = cfg.i18n.placeholder;
		input.setAttribute( 'aria-label', cfg.i18n.placeholder );
		var send = el( 'button', 'ks-concierge__send', cfg.i18n.send );
		send.type = 'submit';
		form.appendChild( input );
		form.appendChild( send );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submit();
		} );
		panel.appendChild( form );

		root.appendChild( panel );
		built = true;

		panel.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				togglePanel();
				tab.focus();
			}
		} );
	}

	function togglePanel() {
		var open = panel.hidden;
		panel.hidden = ! open;
		tab.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		if ( open ) {
			ga( 'open' );
			if ( input ) {
				input.focus();
			}
		}
	}

	function addUser( text ) {
		var m = el( 'div', 'ks-concierge__msg ks-concierge__msg--user', text );
		messages.appendChild( m );
		messages.scrollTop = messages.scrollHeight;
	}

	function addBot( text, candidates ) {
		var row = el( 'div', 'ks-concierge__row ks-concierge__row--bot' );
		if ( cfg.avatar ) {
			var av = document.createElement( 'img' );
			av.className = 'ks-concierge__avatar';
			av.src = cfg.avatar;
			av.alt = '';
			av.setAttribute( 'aria-hidden', 'true' );
			row.appendChild( av );
		}
		var m = el( 'div', 'ks-concierge__msg ks-concierge__msg--bot' );
		m.appendChild( document.createTextNode( text ) );
		if ( candidates && candidates.length ) {
			var cards = el( 'div', 'ks-concierge__cards' );
			candidates.forEach( function ( c ) {
				if ( ! /^https?:\/\//i.test( c.url || '' ) ) {
					return;
				}
				var a = el( 'a', 'ks-concierge__card' );
				a.href = c.url;
				a.rel = 'noopener';
				a.appendChild( el( 'span', 'ks-concierge__card-title', c.title || c.url ) );
				if ( c.reason ) {
					a.appendChild( el( 'span', 'ks-concierge__card-reason', c.reason ) );
				}
				var meta = [];
				if ( typeof c.score === 'number' ) {
					meta.push( 'score ' + c.score.toFixed( 2 ) );
				}
				if ( c.lastmod ) {
					meta.push( String( c.lastmod ).substring( 0, 10 ) );
				}
				if ( meta.length ) {
					a.appendChild( el( 'span', 'ks-concierge__card-meta', meta.join( ' · ' ) ) );
				}
				a.addEventListener( 'click', function () {
					recordClick( c.url );
					ga( 'click' );
				} );
				cards.appendChild( a );
			} );
			m.appendChild( cards );
		}
		row.appendChild( m );
		messages.appendChild( row );
		messages.scrollTop = messages.scrollHeight;
	}

	function addTyping() {
		var t = el( 'div', 'ks-concierge__msg ks-concierge__msg--bot ks-concierge__typing', cfg.i18n.thinking );
		t.setAttribute( 'data-typing', '1' );
		messages.appendChild( t );
		messages.scrollTop = messages.scrollHeight;
		return t;
	}

	function submit() {
		var q = ( input.value || '' ).trim();
		if ( ! q ) {
			return;
		}
		if ( ! consentGiven ) {
			if ( window.confirm( cfg.i18n.consentText ) ) {
				consentGiven = true;
			} else {
				return;
			}
		}
		addUser( q );
		input.value = '';
		ga( 'ask' );
		var typing = addTyping();

		var body = { question: q };
		if ( cfg.consent ) {
			body.consent = consentGiven;
		}

		fetch( cfg.restAsk, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify( body )
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, data: data };
			} );
		} ).then( function ( result ) {
			if ( typing && typing.parentNode ) {
				typing.parentNode.removeChild( typing );
			}
			if ( ! result.ok || ! result.data ) {
				addBot( ( result.data && result.data.error ) || cfg.i18n.error, [] );
				return;
			}
			addBot( result.data.answer || '', result.data.candidates || [] );
			if ( result.data.fallback ) {
				ga( 'fallback' );
			}
		} ).catch( function () {
			if ( typing && typing.parentNode ) {
				typing.parentNode.removeChild( typing );
			}
			addBot( cfg.i18n.error, [] );
		} );
	}

	function recordClick( url ) {
		try {
			fetch( cfg.restClick, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce
				},
				body: JSON.stringify( { url: url } ),
				keepalive: true
			} );
		} catch ( e ) {}
	}
} )();
