/**
 * Simple SEO: the button "Write the alt text with AI" of an image, in the media library and on the
 * screen of the image (Simple_SEO_AI::media_field()). The AI's text goes into the alt text field
 * as if typed there: the media library saves it at once, the screen of the image with "Update".
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[data-simple-seo-ai-alt]' ) : null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();

		var data;
		try {
			data = JSON.parse( button.getAttribute( 'data-simple-seo-ai-alt' ) );
		} catch ( error ) {
			return;
		}

		// The alt text field next to the button: of the details of the image in the media library, or
		// of the screen of the image.
		var scope  = button.closest( '.attachment-details' ) || document;
		var field  = scope.querySelector( '[data-setting="alt"] textarea, [data-setting="alt"] input, #attachment_alt' );
		var status = button.parentNode.querySelector( '.simple-seo-ai-status' );
		if ( ! field || button.disabled ) {
			return;
		}

		button.disabled = true;
		if ( status ) {
			status.textContent = data.working;
		}
		window.fetch( data.url + ( -1 === data.url.indexOf( '?' ) ? '?' : '&' ) + '_locale=user', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
			body: JSON.stringify( { id: data.id } )
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return null;
			} ).then( function ( result ) {
				if ( ! response.ok || ! result || 'string' !== typeof result.alt ) {
					throw { text: result && 'string' === typeof result.message ? result.message : data.failed };
				}
				return result.alt;
			} );
		} ).then( function ( alt ) {
			field.value = alt;
			field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			if ( status ) {
				status.textContent = '';
			}
		} ).catch( function ( problem ) {
			if ( status ) {
				status.textContent = problem && problem.text ? problem.text : data.failed;
			}
		} ).then( function () {
			button.disabled = false;
		} );
	} );
}() );
