/**
 * Kashiwazaki SEO Concierge — admin sandbox.
 */
( function () {
	'use strict';

	if ( typeof window.ksConciergeAdmin === 'undefined' ) {
		return;
	}

	var cfg = window.ksConciergeAdmin;

	// AI tab: when the provider dropdown changes, repopulate that role's API base
	// URL and model fields with the newly selected provider's defaults, so stale
	// values from the previous provider are not left behind.
	( function () {
		var defs = cfg.providerDefaults;
		if ( ! defs ) {
			return;
		}
		[ 'embed', 'chat' ].forEach( function ( role ) {
			var sel = document.getElementById( 'ks_' + role + '_provider' );
			if ( ! sel ) {
				return;
			}
			sel.addEventListener( 'change', function () {
				var d = defs[ role ] && defs[ role ][ sel.value ];
				if ( ! d ) {
					return;
				}
				var baseField = document.getElementById( 'ks_' + role + '_api_base' );
				var modelField = document.getElementById( 'ks_' + ( 'embed' === role ? 'embeddings_model' : 'chat_model' ) );
				if ( baseField ) {
					baseField.value = d.base;
				}
				if ( modelField && d.model ) {
					modelField.value = d.model;
				}
			} );
		} );
	} )();

	// Chat reply icon: wire up the WordPress media library picker + URL preview.
	// NOTE: this must run BEFORE the sandbox early-return below, because the image
	// field lives on the Display tab where the sandbox elements do not exist.
	( function () {
		var imageFields = document.querySelectorAll( '[data-ks-image-field]' );
		Array.prototype.forEach.call( imageFields, function ( field ) {
			var urlInput  = field.querySelector( '.ks-image-field__url' );
			var preview   = field.querySelector( '.ks-image-field__preview' );
			var selectBtn = field.querySelector( '.ks-image-field__select' );
			var clearBtn  = field.querySelector( '.ks-image-field__clear' );
			var frame;
			if ( ! urlInput ) {
				return;
			}
			function sync() {
				var v = urlInput.value.replace( /^\s+|\s+$/g, '' );
				if ( v ) {
					preview.src = v;
					preview.style.display = '';
				} else {
					preview.removeAttribute( 'src' );
					preview.style.display = 'none';
				}
			}
			urlInput.addEventListener( 'input', sync );
			if ( selectBtn ) {
				selectBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					if ( typeof window.wp === 'undefined' || ! window.wp.media ) {
						return;
					}
					if ( frame ) {
						frame.open();
						return;
					}
					frame = window.wp.media( {
						title: '回答アイコン画像を選択',
						library: { type: 'image' },
						button: { text: '選択' },
						multiple: false
					} );
					frame.on( 'select', function () {
						var att = frame.state().get( 'selection' ).first().toJSON();
						urlInput.value = att.url || '';
						sync();
					} );
					frame.open();
				} );
			}
			if ( clearBtn ) {
				clearBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					urlInput.value = '';
					sync();
				} );
			}
		} );
	} )();

	var btn = document.getElementById( 'ks-sandbox-run' );
	var input = document.getElementById( 'ks-sandbox-q' );
	var result = document.getElementById( 'ks-sandbox-result' );

	if ( ! btn || ! input || ! result ) {
		return;
	}

	btn.addEventListener( 'click', function () {
		var q = ( input.value || '' ).trim();
		if ( ! q ) {
			return;
		}
		result.textContent = '…';
		var data = new FormData();
		data.append( 'action', 'ks_concierge_sandbox' );
		data.append( 'nonce', cfg.nonce );
		data.append( 'question', q );

		fetch( cfg.ajaxUrl, { method: 'POST', body: data } )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					result.textContent = ( res && res.data && res.data.message ) || 'Error';
					return;
				}
				render( res.data );
			} )
			.catch( function () {
				result.textContent = 'Error';
			} );
	} );

	function render( d ) {
		result.innerHTML = '';
		var ans = document.createElement( 'p' );
		ans.textContent = d.answer || '';
		result.appendChild( ans );

		if ( d.fallback ) {
			var fb = document.createElement( 'p' );
			fb.className = 'ks-sandbox-meta';
			fb.textContent = 'fallback: ' + ( d.source || '' );
			result.appendChild( fb );
		}

		( d.candidates || [] ).forEach( function ( c ) {
			var card = document.createElement( 'div' );
			card.className = 'ks-sandbox-card';
			var a = document.createElement( 'a' );
			a.href = c.url;
			a.target = '_blank';
			a.rel = 'noopener';
			a.textContent = c.title || c.url;
			card.appendChild( a );
			if ( c.reason ) {
				var r = document.createElement( 'div' );
				r.textContent = c.reason;
				card.appendChild( r );
			}
			var meta = document.createElement( 'div' );
			meta.className = 'ks-sandbox-meta';
			meta.textContent = ( typeof c.score === 'number' ? 'score ' + c.score.toFixed( 4 ) : '' ) + ( c.lastmod ? ' · ' + String( c.lastmod ).substring( 0, 10 ) : '' );
			card.appendChild( meta );
			result.appendChild( card );
		} );
	}

} )();
