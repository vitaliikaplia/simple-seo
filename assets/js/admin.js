/**
 * Simple SEO admin: the "Insert structure" buttons next to title and description fields, the image
 * pickers, the search result preview, bulk editing (with alt texts of images filled from their
 * names), the rows of Local SEO for a local business and the import that goes on by itself.
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

	/*
	 * The search result preview (Simple_SEO_Snippet): redone from the form while the SEO fields, the
	 * title, the excerpt and the address change. Until something changes it shows what PHP printed,
	 * with the filters of the theme, which the browser cannot run.
	 */
	var SEPARATOR_MARK = '\x1F';

	// The width search engines give a title and a description, and the font they measure it in.
	var LIMITS = {
		desktop: { title: [ 600, '20px Arial, sans-serif' ], description: [ 920, '14px Arial, sans-serif' ] },
		mobile: { title: [ 660, '18px Arial, sans-serif' ], description: [ 680, '14px Arial, sans-serif' ] }
	};

	// Fewer characters than this leave room for words that would help the page.
	var SHORT = { title: 30, description: 70 };

	var canvas = null;

	// What the search result preview shows now: the content analysis checks the same title and description.
	var lastSnippet = null;

	function textWidth( text, font ) {
		canvas = canvas || document.createElement( 'canvas' );
		var context = canvas.getContext( '2d' );
		context.font = font;
		// As Simple_SEO_Text_Width measures on the server: the widths of the letters, no kerning.
		context.fontKerning = 'none';

		return context.measureText( text ).width;
	}

	/** The longest start of the parts that fits with an ellipsis after it. */
	function fitting( parts, glue, width, font ) {
		var low  = 0;
		var high = parts.length;
		while ( low < high ) {
			var middle = Math.ceil( ( low + high ) / 2 );
			if ( textWidth( parts.slice( 0, middle ).join( glue ) + ' …', font ) <= width ) {
				low = middle;
			} else {
				high = middle - 1;
			}
		}

		return parts.slice( 0, low ).join( glue );
	}

	/** What a search engine shows of a text in the width it gives: cut at a word, with an ellipsis. */
	function fit( text, limit ) {
		if ( textWidth( text, limit[ 1 ] ) <= limit[ 0 ] ) {
			return { text: text, cut: false };
		}

		var kept = fitting( text.split( ' ' ), ' ', limit[ 0 ], limit[ 1 ] );
		if ( '' === kept ) {
			// One long word: as many characters as fit.
			kept = fitting( Array.from( text ), '', limit[ 0 ], limit[ 1 ] );
		}

		return { text: kept.replace( /[\s,.;:–—-]+$/, '' ) + ' …', cut: true };
	}

	/** Text without tags and entities, on one line, as Simple_SEO_Variables::plain() makes it. */
	function plain( value ) {
		var parsed = new DOMParser().parseFromString( '<body>' + String( null == value ? '' : value ), 'text/html' );

		return ( parsed.body.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	}

	/** A template filled as Simple_SEO_Variables::replace() fills it. */
	function fillTemplate( template, values, separator ) {
		if ( '' === template.trim() ) {
			return '';
		}

		var text = template.replace( /\{([a-z][a-z0-9_]*)\}/g, function ( match, name ) {
			if ( ! Object.prototype.hasOwnProperty.call( values, name ) ) {
				return match;
			}
			if ( 'sep' === name ) {
				return SEPARATOR_MARK;
			}

			return String( values[ name ] ).split( SEPARATOR_MARK ).join( '' );
		} );

		text = text.replace( /\s+/g, ' ' );
		// Separators with nothing between them collapse to one; none at the start or the end.
		text = text.replace( new RegExp( SEPARATOR_MARK + '(?:\\s*' + SEPARATOR_MARK + ')+', 'g' ), SEPARATOR_MARK );
		text = text.replace( new RegExp( '^[\\s' + SEPARATOR_MARK + ']+|[\\s' + SEPARATOR_MARK + ']+$', 'g' ), '' );

		return text.split( SEPARATOR_MARK ).join( separator ).trim();
	}

	/** "https://example.com › blog › post", as search engines show an address. */
	function addressParts( url ) {
		var parsed;
		try {
			parsed = new URL( url );
		} catch ( error ) {
			return [ url ];
		}

		return [ parsed.origin ].concat( parsed.pathname.split( '/' ).filter( Boolean ).map( function ( segment ) {
			try {
				return decodeURIComponent( segment );
			} catch ( error ) {
				return segment;
			}
		} ) );
	}

	/** The block editor's data, on its screens. */
	function blockEditor() {
		var data   = window.wp && window.wp.data;
		var select = data && 'function' === typeof data.select ? data.select( 'core/editor' ) : null;

		return select && 'function' === typeof select.getEditedPostAttribute && document.body.classList.contains( 'block-editor-page' ) ? select : null;
	}

	function fieldValue( id ) {
		var element = document.getElementById( id );

		return element ? element.value : null;
	}

	/** What the form has now; whatever this screen has no field for stays as printed. */
	function readForm( data ) {
		var source = {
			seoTitle: fieldValue( 'simple-seo-field-title' ),
			seoDescription: fieldValue( 'simple-seo-field-description' ),
			index: fieldValue( 'simple-seo-field-index' ),
			url: null
		};
		var block = 'post' === data.kind ? blockEditor() : null;

		if ( 'term' === data.kind ) {
			source.title   = fieldValue( 'name' );
			source.excerpt = fieldValue( 'description' );
			source.slug    = fieldValue( 'slug' );
		} else if ( block ) {
			source.title   = block.getEditedPostAttribute( 'title' );
			source.excerpt = block.getEditedPostAttribute( 'excerpt' );
			source.slug    = block.getEditedPostAttribute( 'slug' );
			source.url     = 'function' === typeof block.getPermalink ? block.getPermalink() : null;
		} else {
			source.title   = fieldValue( 'title' );
			source.excerpt = fieldValue( 'excerpt' );
			source.slug    = fieldValue( 'post_name' );
			// The classic editor prints the address the post has or will have, the slug as typed.
			var shown = document.querySelector( '#sample-permalink a' );
			source.url = shown && /^https?:\/\//.test( shown.textContent.trim() ) ? shown.textContent.trim() : null;
		}

		[ 'title', 'excerpt', 'slug' ].forEach( function ( key ) {
			if ( null == source[ key ] ) {
				source[ key ] = data.raw[ key ];
			}
		} );

		return source;
	}

	function snippetNote( data, which, text, cut ) {
		var count = Array.from( text ).length;
		var name  = which + ( '' === text ? 'None' : ( cut ? 'Cut' : ( count < SHORT[ which ] ? 'Short' : 'Fits' ) ) );
		var note  = document.createElement( 'span' );

		note.textContent = data.l10n[ name ].replace( '%s', String( count ) );
		// No title of ours leaves the theme's: nothing to warn about.
		if ( /(Cut|Short)$/.test( name ) || 'descriptionNone' === name ) {
			note.className = 'is-warning';
		}

		return note;
	}

	function renderSnippet( state ) {
		var data    = state.data;
		var source  = readForm( data );
		var initial = state.initial;
		var changed = source.title !== initial.title || source.excerpt !== initial.excerpt;

		var values = Object.assign( {}, data.values, { title: plain( source.title ) } );
		if ( 'term' === data.kind ) {
			values.excerpt = plain( source.excerpt );
		} else if ( data.excerptField && ! data.protected ) {
			values.excerpt = plain( source.excerpt ) || data.excerptFromText;
		}

		var texts = {};
		[ 'title', 'description' ].forEach( function ( which ) {
			var key = 'seo' + which.charAt( 0 ).toUpperCase() + which.slice( 1 );
			var own = null !== source[ key ] ? source[ key ] : data.own[ which ];
			texts[ which ] = changed || source[ key ] !== initial[ key ]
				? fillTemplate( '' !== own.trim() ? own : data.templates[ which ], values, data.separator )
				: data[ which ];
		} );

		var noindex = data.noindex;
		if ( null !== source.index && source.index !== initial.index ) {
			noindex = 'noindex' === source.index || ( 'index' === source.index ? ! data.public : data.typeNoindex );
		}

		var url   = source.url || data.url || data.home;
		var parts = addressParts( url );
		if ( 'term' === data.kind && source.slug !== data.raw.slug && '' !== source.slug.trim() ) {
			var at = parts.lastIndexOf( data.raw.slug );
			if ( at > 0 ) {
				parts[ at ] = source.slug.trim().toLowerCase().replace( /\s+/g, '-' );
			}
		}

		lastSnippet = { title: texts.title, description: texts.description, url: url };

		var limits      = LIMITS[ state.view ];
		// Without a title of ours search engines show the theme's: close to the post's own title.
		var title       = fit( texts.title || plain( source.title ), limits.title );
		var description = fit( texts.description, limits.description );

		state.elements.title.textContent       = title.text;
		state.elements.description.textContent = description.text;
		state.elements.url.textContent         = parts.join( ' › ' );
		state.elements.noindex.hidden          = ! noindex;
		state.root.classList.toggle( 'is-noindex', noindex );

		state.elements.notes.textContent = '';
		state.elements.notes.appendChild( snippetNote( data, 'title', texts.title, title.cut ) );
		state.elements.notes.appendChild( snippetNote( data, 'description', texts.description, description.cut ) );
	}

	function initSnippet( root ) {
		var data;
		try {
			data = JSON.parse( root.getAttribute( 'data-simple-seo-snippet' ) );
		} catch ( error ) {
			return;
		}

		var state = {
			root: root,
			data: data,
			view: 'desktop',
			elements: {
				title: root.querySelector( '.simple-seo-snippet-title' ),
				description: root.querySelector( '.simple-seo-snippet-description' ),
				url: root.querySelector( '.simple-seo-snippet-url' ),
				noindex: root.querySelector( '.simple-seo-snippet-noindex' ),
				notes: root.querySelector( '.simple-seo-snippet-notes' )
			}
		};
		state.initial = readForm( data );

		var waiting = false;
		function schedule() {
			if ( waiting ) {
				return;
			}
			waiting = true;
			window.setTimeout( function () {
				waiting = false;
				renderSnippet( state );
			}, 60 );
		}

		var views = root.querySelector( '.simple-seo-snippet-views' );
		views.hidden = false;
		views.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-simple-seo-view]' );
			if ( ! button ) {
				return;
			}
			state.view = button.getAttribute( 'data-simple-seo-view' );
			Array.prototype.forEach.call( views.querySelectorAll( 'button' ), function ( other ) {
				other.setAttribute( 'aria-pressed', String( other === button ) );
			} );
			root.classList.toggle( 'is-mobile', 'mobile' === state.view );
			renderSnippet( state );
		} );

		document.addEventListener( 'input', schedule );
		document.addEventListener( 'change', schedule );

		// The classic editor rewrites the address once the slug is edited.
		var slugBox = document.getElementById( 'edit-slug-box' );
		if ( slugBox && window.MutationObserver ) {
			new MutationObserver( schedule ).observe( slugBox, { childList: true, subtree: true, characterData: true } );
		}

		// The block editor keeps the title, the excerpt and the address in its store: redo the card
		// only when one of them changes, not on every keystroke in the text.
		if ( 'post' === data.kind && window.wp && window.wp.data && 'function' === typeof window.wp.data.subscribe ) {
			var last = '';
			window.wp.data.subscribe( function () {
				var block = blockEditor();
				if ( ! block ) {
					return;
				}
				var now = JSON.stringify( [ block.getEditedPostAttribute( 'title' ), block.getEditedPostAttribute( 'excerpt' ), 'function' === typeof block.getPermalink ? block.getPermalink() : '' ] );
				if ( now !== last ) {
					last = now;
					schedule();
				}
			} );
		}

		renderSnippet( state );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-simple-seo-snippet]' ), initSnippet );

	/*
	 * The content analysis (Simple_SEO_Analysis), SEO and readability: redone through its REST route
	 * a moment after the text, the keyphrases, the title or the description change; the server's
	 * checks are drawn as they come.
	 */
	function editorContent() {
		var block = blockEditor();
		if ( block && 'function' === typeof block.getEditedPostContent ) {
			return block.getEditedPostContent();
		}

		var classic = window.tinymce && 'function' === typeof window.tinymce.get ? window.tinymce.get( 'content' ) : null;
		if ( classic && ! classic.isHidden() ) {
			return classic.getContent();
		}

		return fieldValue( 'content' );
	}

	function initAnalysis( root ) {
		var data;
		try {
			data = JSON.parse( root.getAttribute( 'data-simple-seo-analysis' ) );
		} catch ( error ) {
			return;
		}

		var seo     = root.querySelector( '[data-part="seo"]' );
		var reading = root.querySelector( '[data-part="readability"]' );
		var failed  = root.querySelector( '.simple-seo-analysis-error' );
		var sent    = null;
		var timer   = null;
		var running = false;

		function payload() {
			var body = {
				keyphrase: fieldValue( 'simple-seo-field-keyphrase' ),
				keyphrases: fieldValue( 'simple-seo-field-keyphrases' ),
				content: editorContent()
			};
			if ( lastSnippet ) {
				body.title       = lastSnippet.title;
				body.description = lastSnippet.description;
				body.url         = lastSnippet.url;
			}
			// What this screen has no field for, the server takes as saved.
			Object.keys( body ).forEach( function ( key ) {
				if ( null == body[ key ] ) {
					delete body[ key ];
				}
			} );

			return JSON.stringify( body );
		}

		// One part of the panel: its score and its checks.
		function drawPart( part, result, heading ) {
			var score = part.querySelector( '.simple-seo-analysis-score' );
			var list  = part.querySelector( '.simple-seo-analysis-checks' );
			score.className = 'simple-seo-analysis-score is-' + result.rating;
			score.firstChild.textContent = heading;
			list.textContent = '';
			result.checks.forEach( function ( check ) {
				var item = document.createElement( 'li' );
				item.className   = 'is-' + check.status;
				item.textContent = check.text;
				list.appendChild( item );
			} );
		}

		function draw( result ) {
			drawPart( seo, result, data.score.replace( '%s', String( result.score ) ) );
			drawPart( reading, result.readability, 'none' === result.readability.rating ? data.unscored : data.readability.replace( '%s', String( result.readability.score ) ) );
		}

		function send() {
			if ( running ) {
				soon( 500 );
				return;
			}
			var body = payload();
			if ( body === sent ) {
				return;
			}
			// A failed request is not repeated for the same text: the next change sends it again.
			sent    = body;
			running = true;
			window.fetch( data.url + ( -1 === data.url.indexOf( '?' ) ? '?' : '&' ) + '_locale=user', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
				body: body
			} ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}
				return response.json();
			} ).then( function ( result ) {
				failed.hidden = true;
				draw( result );
			} ).catch( function () {
				failed.hidden = false;
			} ).then( function () {
				running = false;
			} );
		}

		function soon( delay ) {
			window.clearTimeout( timer );
			timer = window.setTimeout( send, delay );
		}

		// The page came with the analysis of what it had then.
		sent = payload();
		document.addEventListener( 'input', function ( event ) {
			if ( event.target && /^simple-seo-field-(?:keyphrases?|title|description)$/.test( event.target.id ) ) {
				soon( 800 );
			}
		} );
		// The text of the editors changes without events of their own: look every few seconds.
		window.setInterval( function () {
			soon( 0 );
		}, 3000 );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-simple-seo-analysis]' ), initAnalysis );

	/*
	 * Bulk editing (Simple_SEO_Bulk): the rows changed are saved together through the REST route,
	 * without reloading the page; each row then shows what the page has now.
	 */
	function initBulk( root ) {
		var data;
		try {
			data = JSON.parse( root.getAttribute( 'data-simple-seo-bulk' ) );
		} catch ( error ) {
			return;
		}

		var buttons = root.querySelectorAll( '.simple-seo-bulk-save' );
		var status  = root.querySelector( '.simple-seo-bulk-status' );
		var changed = {};
		var running = false;

		function key( row ) {
			return row.getAttribute( 'data-object' ) + ':' + row.getAttribute( 'data-id' );
		}

		function values( row ) {
			var item = { object: row.getAttribute( 'data-object' ), id: parseInt( row.getAttribute( 'data-id' ), 10 ) };
			Array.prototype.forEach.call( row.querySelectorAll( '[data-field]:not([disabled])' ), function ( field ) {
				item[ field.getAttribute( 'data-field' ) ] = field.value;
			} );

			return item;
		}

		function update() {
			var count = Object.keys( changed ).length;
			Array.prototype.forEach.call( buttons, function ( button ) {
				button.disabled = running || 0 === count;
			} );
			if ( ! running && count ) {
				status.textContent = data.unsaved + ' ' + count;
			}
		}

		function show( row, item ) {
			var error = row.querySelector( '.simple-seo-bulk-error' );
			error.hidden      = ! item.error;
			error.textContent = item.error || '';
			if ( item.error ) {
				return;
			}
			Object.keys( item.now ).forEach( function ( name ) {
				var now = row.querySelector( '[data-now="' + name + '"]' );
				if ( now ) {
					now.textContent = item.now[ name ] ? data.now.replace( '%s', item.now[ name ] ) : data.none;
				}
			} );
		}

		function save() {
			var rows = Object.keys( changed ).map( function ( name ) {
				return changed[ name ];
			} );
			if ( running || ! rows.length ) {
				return;
			}

			var sent = rows.map( values );
			running = true;
			status.textContent = data.saving;
			update();
			window.fetch( data.url + ( -1 === data.url.indexOf( '?' ) ? '?' : '&' ) + '_locale=user', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
				body: JSON.stringify( { items: sent } )
			} ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}
				return response.json();
			} ).then( function ( result ) {
				var done = 0;
				result.items.forEach( function ( item, index ) {
					var row = rows[ index ];
					show( row, item );
					// A row typed into again while it was saved stays changed.
					if ( ! item.error && JSON.stringify( values( row ) ) === JSON.stringify( sent[ index ] ) ) {
						delete changed[ key( row ) ];
						row.classList.remove( 'is-changed' );
						done++;
					}
				} );
				status.textContent = data.saved.replace( '%s', String( done ) );
			} ).catch( function () {
				status.textContent = data.failed;
			} ).then( function () {
				running = false;
				update();
			} );
		}

		root.addEventListener( 'input', function ( event ) {
			var row = event.target && event.target.closest ? event.target.closest( 'tr[data-id]' ) : null;
			if ( row && event.target.hasAttribute( 'data-field' ) ) {
				changed[ key( row ) ] = row;
				row.classList.add( 'is-changed' );
				update();
			}
		} );
		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', save );
		} );
		// Images: the empty alt texts take the text of their names, changed but not saved yet.
		var suggest = root.querySelector( '.simple-seo-bulk-suggest' );
		if ( suggest ) {
			suggest.addEventListener( 'click', function () {
				Array.prototype.forEach.call( root.querySelectorAll( 'input[data-field="alt"][data-suggest]:not([disabled])' ), function ( field ) {
					if ( '' === field.value.trim() ) {
						field.value = field.getAttribute( 'data-suggest' );
						field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					}
				} );
			} );
		}
		// Leaving with changes not saved asks first.
		window.addEventListener( 'beforeunload', function ( event ) {
			if ( Object.keys( changed ).length ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-simple-seo-bulk]' ), initBulk );

	// Local SEO: the coordinates, map, price range and hours are shown for a local business only.
	var localType = document.querySelector( '[data-simple-seo-local-type]' );
	if ( localType ) {
		localType.addEventListener( 'change', function () {
			var option = localType.options[ localType.selectedIndex ];
			var local  = option && '1' === option.getAttribute( 'data-local' );
			Array.prototype.forEach.call( document.querySelectorAll( '.simple-seo-local-only' ), function ( element ) {
				element.hidden = ! local;
			} );
		} );
	}

	// The import goes on by itself, a request at a time; the button stays for a page that stops.
	var next = document.querySelector( '[data-simple-seo-continue]' );
	if ( next ) {
		window.setTimeout( function () {
			next.click();
		}, 300 );
	}
}() );
