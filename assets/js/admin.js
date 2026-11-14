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

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.simple-seo-mask' ) : null;
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
}() );
