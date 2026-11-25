/**
 * Rooms page: greys out the choices the save would refuse (Core\Mapping::problems), as the owner picks.
 *   1. A HostelMate bed already picked in another row ("used by …"). A whole room can be picked
 *      by several rows: the save allows that too.
 *   2. Another HostelMate room than the one the other beds of the same website room are matched to.
 * A row's own choice is never greyed out (a disabled option isn't sent with the form, so the row
 * would be unmatched without the owner seeing it); when it breaks a rule, the row says why.
 * Without this script nothing is greyed out, and the save still refuses (the server checks every save).
 *
 * @package HostelMate\HBook
 */
( function () {
	'use strict';

	var table, selects, texts;

	function each( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	}

	function room( value ) {
		return value.split( '|' )[ 0 ];
	}

	function bed( value ) {
		return value.split( '|' )[ 1 ] || '';
	}

	// The translated text with %s filled in. A function, so "$&" or "$'" in a room name stay as typed.
	function fill( attr, value ) {
		return table.getAttribute( attr ).replace( '%s', function () {
			return value;
		} );
	}

	function names( list ) {
		return list.map( function ( s ) {
			return s.getAttribute( 'data-label' );
		} ).join( ', ' );
	}

	function refresh() {
		var byBed = {}; // "roomId|bedId" → selects that picked that bed.
		each( selects, function ( s ) {
			if ( '' !== s.value && '' !== bed( s.value ) ) {
				( byBed[ s.value ] = byBed[ s.value ] || [] ).push( s );
			}
		} );

		each( selects, function ( s ) {
			// HostelMate rooms the other beds of this website room are matched to.
			var siblingRooms = [];
			each( selects, function ( o ) {
				if ( o !== s && o.getAttribute( 'data-accom' ) === s.getAttribute( 'data-accom' ) && '' !== o.value && -1 === siblingRooms.indexOf( room( o.value ) ) ) {
					siblingRooms.push( room( o.value ) );
				}
			} );

			each( s.options, function ( opt ) {
				if ( '' === opt.value ) {
					return; // "Not in HostelMate" is always allowed.
				}
				if ( ! texts.has( opt ) ) {
					texts.set( opt, opt.textContent ); // Added after the page loaded.
				}
				var others = '' === bed( opt.value ) ? [] : ( byBed[ opt.value ] || [] ).filter( function ( o ) {
					return o !== s;
				} );
				var split = siblingRooms.length > 0 && -1 === siblingRooms.indexOf( room( opt.value ) );
				opt.disabled = opt.value !== s.value && ( others.length > 0 || split );
				opt.textContent = texts.get( opt ) + ( others.length > 0 ? ' — ' + fill( 'data-used-by', names( others ) ) : '' );
			} );

			// The row's own choice breaks a rule (a save just refused, or the browser restored old choices): say why.
			var note = document.getElementById( s.getAttribute( 'aria-describedby' ) );
			var lines = [];
			if ( '' !== s.value ) {
				var sharing = '' === bed( s.value ) ? [] : byBed[ s.value ].filter( function ( o ) {
					return o !== s;
				} );
				if ( sharing.length > 0 ) {
					lines.push( fill( 'data-also', names( sharing ) ) );
				}
				if ( siblingRooms.length > 0 && ( siblingRooms.length > 1 || siblingRooms[ 0 ] !== room( s.value ) ) ) {
					lines.push( table.getAttribute( 'data-split' ) );
				}
			}
			if ( note ) {
				note.textContent = lines.join( ' ' );
				note.hidden = 0 === lines.length;
			}
		} );
	}

	function init() {
		table = document.getElementById( 'hm-map' );
		if ( ! table ) {
			return;
		}
		selects = table.querySelectorAll( 'select[data-accom]' );
		texts = new WeakMap(); // Each option's own text, before any "used by".
		each( selects, function ( s ) {
			each( s.options, function ( opt ) {
				texts.set( opt, opt.textContent );
			} );
			s.addEventListener( 'change', refresh );
			s.addEventListener( 'input', refresh );
		} );
		refresh();
		// Back/forward and reload can bring back earlier choices without a change event.
		window.addEventListener( 'pageshow', refresh );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
