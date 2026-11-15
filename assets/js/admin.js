/**
 * Simple SEO admin: the "Insert structure" buttons next to title and description fields.
 *
 * Empty field: the structure goes in as it is. Plain text without variables: the text takes the
 * place of {title} (or {excerpt}) in the structure, so the editor's words stay. A field that
 * already has variables: replaced by the structure.
 */
( function () {
	'use strict';

	var CONTENT_VARIABLES = [ '{title}', '{excerpt}' ];

	function fill( field, mask ) {
		var value = field.value.trim();

		if ( '' === value || -1 !== value.indexOf( '{' ) ) {
			return mask;
		}

		for ( var i = 0; i < CONTENT_VARIABLES.length; i++ ) {
			if ( -1 !== mask.indexOf( CONTENT_VARIABLES[ i ] ) ) {
				return mask.replace( CONTENT_VARIABLES[ i ], value );
			}
		}

		return mask;
	}

	/** Image fields: a media library frame for "Choose image", and "Remove image". */
	function imagePicker( event ) {
		var choose = event.target.closest( '.simple-seo-image-choose' );
		var remove = event.target.closest( '.simple-seo-image-remove' );
		var box    = ( choose || remove ) ? ( choose || remove ).closest( '.simple-seo-image' ) : null;
		if ( ! box ) {
			return false;
		}
		event.preventDefault();

		var input   = document.getElementById( box.getAttribute( 'data-target' ) );
		var preview = box.querySelector( '.simple-seo-image-preview' );
		var image   = preview ? preview.querySelector( 'img' ) : null;
		var unset   = box.querySelector( '.simple-seo-image-remove' );

		function show( url ) {
			if ( image ) {
				image.src = url;
			}
			[ preview, unset ].forEach( function ( element ) {
				if ( element ) {
					element.hidden = ! url;
				}
			} );
		}

		if ( remove ) {
			input.value = '';
			show( '' );
			return true;
		}
		if ( ! window.wp || ! window.wp.media ) {
			return true;
		}

		// One media frame per picker, made on the first click: a new one every time would pile up in the page.
		if ( ! box.simpleSeoFrame ) {
			var frame = window.wp.media( {
				title: box.getAttribute( 'data-title' ),
				button: { text: box.getAttribute( 'data-button' ) },
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var chosen = frame.state().get( 'selection' ).first().toJSON();
				input.value = chosen.id;
				show( chosen.sizes && chosen.sizes.thumbnail ? chosen.sizes.thumbnail.url : chosen.url );
			} );
			box.simpleSeoFrame = frame;
		}
		box.simpleSeoFrame.open();

		return true;
	}

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest || imagePicker( event ) ) {
			return;
		}

		var button = event.target.closest( '.simple-seo-mask' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();

		var field = document.getElementById( button.getAttribute( 'data-target' ) );
		if ( ! field ) {
			return;
		}

		field.value = fill( field, button.getAttribute( 'data-mask' ) || '' );
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		field.focus();
	} );

	// The import goes on by itself, a request at a time; the button stays for a page that stops.
	var next = document.querySelector( '[data-simple-seo-continue]' );
	if ( next ) {
		window.setTimeout( function () {
			next.click();
		}, 300 );
	}
}() );
