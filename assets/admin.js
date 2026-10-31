/* Wintracker shortcode builder (Wintracker → Shortcode builder). No dependencies. */
( function () {
	'use strict';

	var cfg = window.wintrackerBuilder;
	var form = document.getElementById( 'wtb-form' );
	if ( ! cfg || ! form ) {
		return;
	}
	var $ = function ( id ) {
		return document.getElementById( id );
	};
	var TAGS = {
		latest: 'wintracker_latest_wins',
		biggest: 'wintracker_biggest_wins',
		multipliers: 'wintracker_top_multipliers',
		stats: 'wintracker_stats',
	};
	var FUNCS = {
		latest: 'wintracker_latest_wins',
		biggest: 'wintracker_biggest_wins',
		multipliers: 'wintracker_top_multipliers',
		stats: 'wintracker_stats',
	};
	var previewTimer = null;
	var previewRequest = 0;

	function value( name ) {
		var el = form.elements[ name ];
		if ( ! el ) {
			return '';
		}
		if ( el instanceof RadioNodeList ) {
			return el.value;
		}
		if ( el.type === 'checkbox' ) {
			return el.checked ? el.value : '';
		}
		return String( el.value ).trim();
	}

	function checked( name ) {
		return Array.prototype.slice
			.call( form.querySelectorAll( 'input[name="' + name + '"]:checked' ) )
			.map( function ( el ) {
				return el.value;
			} );
	}

	/** The shortcode's attributes, only those that differ from the defaults, in a readable order. */
	function attributes() {
		var widget = value( 'widget' );
		var atts = [];
		var add = function ( key, val ) {
			if ( val !== '' && val !== null && val !== undefined ) {
				atts.push( [ key, String( val ) ] );
			}
		};

		if ( widget === 'stats' ) {
			var type = value( 'stats_type' );
			add( type, value( type ) );
			if ( value( 'stats_layout' ) !== 'grid' ) {
				add( 'layout', value( 'stats_layout' ) );
			}
			if ( ! $( 'wtb-fields-auto' ).checked ) {
				add( 'fields', checked( 'fields[]' ).join( ',' ) );
			}
			if ( value( 'details' ) !== 'inline' ) {
				add( 'details', value( 'details' ) );
			}
		} else {
			add( 'casino', value( 'casino' ) );
			add( 'provider', value( 'provider' ) );
			add( 'game', value( 'game' ) );
			if ( widget !== 'latest' || value( 'period' ) !== 'all' ) {
				add( 'period', value( 'period' ) );
			}
			var limit = parseInt( value( 'limit' ), 10 ) || 10;
			if ( limit !== 10 ) {
				add( 'limit', Math.max( 1, Math.min( 100, limit ) ) );
			}
			if ( value( 'layout' ) !== 'table' ) {
				add( 'layout', value( 'layout' ) );
			}
			if ( ! $( 'wtb-columns-auto' ).checked ) {
				add( 'columns', checked( 'columns[]' ).join( ',' ) );
			}
			if ( value( 'currency' ) !== 'original' ) {
				add( 'currency', value( 'currency' ) );
			}
			add( 'images', value( 'images' ) );
		}
		// Quotes and brackets would end the shortcode early.
		add( 'title', value( 'title' ).replace( /["\[\]]/g, '' ) );
		if ( widget !== 'stats' && value( 'hide_empty' ) ) {
			atts.push( [ 'empty', '' ] );
		}
		return atts;
	}

	function shortcode( widget, atts ) {
		return '[' + TAGS[ widget ] + atts.map( function ( a ) {
			return ' ' + a[ 0 ] + '="' + a[ 1 ] + '"';
		} ).join( '' ) + ']';
	}

	function php( widget, atts ) {
		if ( ! atts.length ) {
			return '<?php echo ' + FUNCS[ widget ] + '(); ?>';
		}
		var esc = function ( s ) {
			return "'" + s.replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) + "'";
		};
		var lines = atts.map( function ( a ) {
			var v = /^\d+$/.test( a[ 1 ] ) && a[ 0 ] !== 'casino' && a[ 0 ] !== 'provider' ? a[ 1 ] : esc( a[ 1 ] );
			return "\t'" + a[ 0 ] + "' => " + v + ',';
		} );
		return '<?php\necho ' + FUNCS[ widget ] + '( array(\n' + lines.join( '\n' ) + '\n) );\n?>';
	}

	/** Shows the fields that apply to the chosen widget. */
	function toggle() {
		var widget = value( 'widget' );
		var isStats = widget === 'stats';
		var type = value( 'stats_type' );
		form.querySelectorAll( '[data-show]' ).forEach( function ( el ) {
			el.hidden = el.getAttribute( 'data-show' ) !== ( isStats ? 'stats' : 'wins' );
		} );
		[ 'casino', 'provider', 'game' ].forEach( function ( f ) {
			form.querySelector( '[data-for="' + f + '"]' ).hidden = isStats && type !== f;
		} );
		$( 'wtb-columns' ).hidden = $( 'wtb-columns-auto' ).checked;
		$( 'wtb-fields' ).hidden = $( 'wtb-fields-auto' ).checked;
		form.elements.period.options[ 0 ].textContent = widget === 'latest' ? cfg.i18n.defaultAll : cfg.i18n.defaultDay;
	}

	function update() {
		toggle();
		var widget = value( 'widget' );
		var atts = attributes();
		$( 'wtb-shortcode' ).textContent = shortcode( widget, atts );
		$( 'wtb-php' ).textContent = php( widget, atts );
		clearTimeout( previewTimer );
		previewTimer = setTimeout( function () {
			preview( widget, atts );
		}, 350 );
	}

	function preview( widget, atts ) {
		var box = $( 'wtb-preview' );
		if ( widget === 'stats' && ! value( value( 'stats_type' ) ) ) {
			box.innerHTML = '<p class="wtb-preview-empty"></p>';
			box.firstChild.textContent = value( 'stats_type' ) === 'game' ? cfg.i18n.pickGame : cfg.i18n.pickOne;
			return;
		}
		var id = ++previewRequest;
		box.classList.add( 'is-loading' );
		var body = new URLSearchParams();
		body.append( 'action', 'wintracker_preview' );
		body.append( '_ajax_nonce', cfg.nonce );
		body.append( 'tag', TAGS[ widget ] );
		atts.forEach( function ( a ) {
			body.append( 'atts[' + a[ 0 ] + ']', a[ 1 ] );
		} );
		fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( r ) {
				if ( id === previewRequest ) {
					box.innerHTML = r && r.success ? r.data.html : '';
				}
			} )
			.catch( function () {} )
			.then( function () {
				if ( id === previewRequest ) {
					box.classList.remove( 'is-loading' );
				}
			} );
	}

	/* Game search */
	var searchTimer = null;
	var q = $( 'wtb-game-q' );
	var results = $( 'wtb-game-results' );

	function pickGame( game ) {
		$( 'wtb-game' ).value = game ? game.id : '';
		$( 'wtb-game-picked' ).hidden = ! game;
		q.hidden = !! game;
		results.hidden = true;
		if ( game ) {
			$( 'wtb-game-picked' ).firstChild.textContent = game.name + ( game.provider ? ' · ' + game.provider : '' ) + ' (id ' + game.id + ')';
		} else {
			q.value = '';
			q.focus();
		}
		update();
	}

	q.addEventListener( 'input', function () {
		clearTimeout( searchTimer );
		var term = q.value.trim();
		if ( term.length < 2 ) {
			results.hidden = true;
			return;
		}
		searchTimer = setTimeout( function () {
			results.hidden = false;
			results.innerHTML = '<li class="wtb-muted"></li>';
			results.firstChild.textContent = cfg.i18n.searching;
			var url = cfg.ajax + '?action=wintracker_games&_ajax_nonce=' + encodeURIComponent( cfg.nonce ) + '&q=' + encodeURIComponent( term );
			fetch( url, { credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( r ) {
					var games = r && r.success ? r.data : [];
					results.innerHTML = '';
					if ( ! games.length ) {
						var li = document.createElement( 'li' );
						li.className = 'wtb-muted';
						li.textContent = cfg.i18n.noGames;
						results.appendChild( li );
					}
					games.forEach( function ( g ) {
						var li = document.createElement( 'li' );
						var b = document.createElement( 'button' );
						b.type = 'button';
						b.textContent = g.name;
						var small = document.createElement( 'small' );
						small.textContent = ( g.provider ? g.provider + ' · ' : '' ) + 'id ' + g.id + ' · ' + g.wins + ' wins';
						b.appendChild( small );
						b.addEventListener( 'click', function () {
							pickGame( g );
						} );
						li.appendChild( b );
						results.appendChild( li );
					} );
				} );
		}, 300 );
	} );
	$( 'wtb-game-clear' ).addEventListener( 'click', function () {
		pickGame( null );
	} );

	/* Copy buttons */
	document.querySelectorAll( '[data-copy]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var text = $( btn.getAttribute( 'data-copy' ) ).textContent;
			var done = function () {
				btn.textContent = cfg.i18n.copied;
				setTimeout( function () {
					btn.textContent = cfg.i18n.copy;
				}, 1500 );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( done );
			} else {
				var ta = document.createElement( 'textarea' );
				ta.value = text;
				document.body.appendChild( ta );
				ta.select();
				document.execCommand( 'copy' );
				ta.remove();
				done();
			}
		} );
	} );

	form.addEventListener( 'input', update );
	form.addEventListener( 'change', update );
	update();
} )();
