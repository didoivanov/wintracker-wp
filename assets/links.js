/* Wintracker → Links: page search per row, Automatic / Don't link, bulk actions. No dependencies. */
( function () {
	'use strict';

	var cfg = window.wintrackerLinks;
	var table = document.querySelector( '.wtl-table' );
	if ( ! cfg || ! table ) {
		return;
	}
	var timer = null;

	function shownIds() {
		return Array.prototype.map.call( table.querySelectorAll( 'tbody tr[data-id]' ), function ( tr ) {
			return tr.getAttribute( 'data-id' );
		} );
	}

	function save( ids, act, postId ) {
		var rows = ids.map( function ( id ) {
			return table.querySelector( 'tr[data-id="' + id + '"]' );
		} ).filter( Boolean );
		rows.forEach( function ( tr ) {
			tr.classList.add( 'is-saving' );
			tr.querySelector( '.wtl-status' ).textContent = cfg.i18n.saving;
		} );
		var body = new URLSearchParams();
		body.append( 'action', 'wintracker_link_set' );
		body.append( '_ajax_nonce', cfg.nonce );
		body.append( 'kind', cfg.kind );
		body.append( 'act', act );
		if ( postId ) {
			body.append( 'post_id', postId );
		}
		ids.forEach( function ( id ) {
			body.append( 'ids[]', id );
		} );
		shownIds().forEach( function ( id ) {
			body.append( 'shown[]', id );
		} );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( r ) {
				if ( ! r || ! r.success ) {
					throw new Error( 'save failed' );
				}
				Object.keys( r.data ).forEach( function ( id ) {
					var tr = table.querySelector( 'tr[data-id="' + id + '"]' );
					if ( tr ) {
						tr.querySelector( '.wtl-status' ).innerHTML = r.data[ id ].status;
						tr.querySelector( '.wtl-page' ).innerHTML = r.data[ id ].page;
					}
				} );
			} )
			.catch( function () {
				window.alert( cfg.i18n.failed );
			} )
			.then( function () {
				rows.forEach( function ( tr ) {
					tr.classList.remove( 'is-saving' );
				} );
			} );
	}

	/* Page search in a row */
	function results( tr ) {
		return tr.querySelector( '.wtl-results' );
	}

	function search( input ) {
		var tr = input.closest( 'tr' );
		var list = results( tr );
		var q = input.value.trim();
		if ( q.length < 2 ) {
			list.hidden = true;
			return;
		}
		var url = cfg.ajax + '?action=wintracker_link_posts&_ajax_nonce=' + encodeURIComponent( cfg.nonce ) +
			'&kind=' + encodeURIComponent( cfg.kind ) + '&q=' + encodeURIComponent( q );
		fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( r ) {
				var posts = r && r.success ? r.data : [];
				list.innerHTML = '';
				if ( ! posts.length ) {
					var li = document.createElement( 'li' );
					li.className = 'wtl-muted';
					li.textContent = cfg.i18n.none;
					list.appendChild( li );
				}
				posts.forEach( function ( p ) {
					var li = document.createElement( 'li' );
					var b = document.createElement( 'button' );
					b.type = 'button';
					b.textContent = p.title;
					if ( p.status ) {
						var s = document.createElement( 'small' );
						s.textContent = ' (' + p.status + ')';
						b.appendChild( s );
					}
					b.addEventListener( 'click', function () {
						list.hidden = true;
						input.value = '';
						save( [ tr.getAttribute( 'data-id' ) ], 'post', p.id );
					} );
					li.appendChild( b );
					list.appendChild( li );
				} );
				list.hidden = false;
			} );
	}

	table.addEventListener( 'input', function ( e ) {
		if ( e.target.classList.contains( 'wtl-q' ) ) {
			clearTimeout( timer );
			var input = e.target;
			timer = setTimeout( function () {
				search( input );
			}, 250 );
		}
	} );

	table.addEventListener( 'keydown', function ( e ) {
		if ( e.target.classList.contains( 'wtl-q' ) && e.key === 'Escape' ) {
			results( e.target.closest( 'tr' ) ).hidden = true;
		}
		if ( e.target.classList.contains( 'wtl-q' ) && e.key === 'Enter' ) {
			e.preventDefault();
			var first = results( e.target.closest( 'tr' ) ).querySelector( 'button' );
			if ( first ) {
				first.click();
			}
		}
	} );

	table.addEventListener( 'click', function ( e ) {
		var tr = e.target.closest( 'tr[data-id]' );
		if ( ! tr ) {
			return;
		}
		if ( e.target.classList.contains( 'wtl-auto' ) ) {
			save( [ tr.getAttribute( 'data-id' ) ], 'auto' );
		} else if ( e.target.classList.contains( 'wtl-none' ) ) {
			save( [ tr.getAttribute( 'data-id' ) ], 'none' );
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest( '.wtl-pick' ) ) {
			table.querySelectorAll( '.wtl-results' ).forEach( function ( l ) {
				l.hidden = true;
			} );
		}
	} );

	/* Bulk */
	var all = document.getElementById( 'wtl-all' );
	if ( all ) {
		all.addEventListener( 'change', function () {
			table.querySelectorAll( '.wtl-check' ).forEach( function ( c ) {
				c.checked = all.checked;
			} );
		} );
	}
	var apply = document.getElementById( 'wtl-bulk-apply' );
	if ( apply ) {
		apply.addEventListener( 'click', function () {
			var act = document.getElementById( 'wtl-bulk' ).value;
			var ids = Array.prototype.filter.call( table.querySelectorAll( '.wtl-check' ), function ( c ) {
				return c.checked;
			} ).map( function ( c ) {
				return c.value;
			} );
			if ( ! act ) {
				window.alert( cfg.i18n.pickBulk );
				return;
			}
			if ( ! ids.length ) {
				window.alert( cfg.i18n.pickRows );
				return;
			}
			save( ids, act ).then( function () {
				table.querySelectorAll( '.wtl-check' ).forEach( function ( c ) {
					c.checked = false;
				} );
				if ( all ) {
					all.checked = false;
				}
			} );
		} );
	}
} )();
