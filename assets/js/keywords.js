/**
 * Simple SEO: the keyword research — the filters, topics and article plan of its page, and the
 * button by the focus keyphrase in the SEO block of a post (Simple_SEO_Keywords).
 */
( function () {
	'use strict';

	var config = window.simpleSeoKeywords;
	if ( ! config ) {
		return;
	}
	var text = config.text || {};

	function post( url, body ) {
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce
			},
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( data && data.message ? data.message : text.failed );
				}
				return data;
			} );
		} );
	}

	function element( tag, className, content ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== content ) {
			node.textContent = content;
		}
		return node;
	}

	/*
	 * The page.
	 */

	function initPage( root ) {
		var rows    = Array.prototype.slice.call( root.querySelectorAll( 'tbody tr[data-kw]' ) );
		var status  = root.querySelector( '.simple-seo-kw-status' );
		var parent  = root.querySelector( '.simple-seo-kw-parent' );
		var plan    = root.querySelector( '.simple-seo-kw-outline' );
		var topics  = root.querySelector( '[data-kw-topics]' );
		var filters = root.querySelector( '.simple-seo-kw-filters' );
		var current = { filter: 'all', topic: '' };

		function say( message ) {
			if ( status ) {
				status.textContent = message || '';
			}
		}

		function visible() {
			return rows.filter( function ( row ) {
				return ! row.hidden;
			} );
		}

		function apply() {
			rows.forEach( function ( row ) {
				var show = 'all' === current.filter
					|| ( 'questions' === current.filter && '1' === row.getAttribute( 'data-kw-question' ) )
					|| ( 'topic' === current.filter && row.getAttribute( 'data-kw-topic' ) === current.topic );
				row.hidden = ! show;
			} );
			Array.prototype.forEach.call( filters.querySelectorAll( '[data-kw-filter]' ), function ( button ) {
				var on = button.getAttribute( 'data-kw-filter' ) === current.filter
					&& ( 'topic' !== current.filter || button.getAttribute( 'data-kw-topic' ) === current.topic );
				button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				button.classList.toggle( 'is-pressed', on );
			} );
		}

		filters.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-kw-filter]' );
			if ( button ) {
				current.filter = button.getAttribute( 'data-kw-filter' );
				current.topic  = button.getAttribute( 'data-kw-topic' ) || '';
				apply();
			}
		} );

		// The topics of the AI take the place of those of the words.
		function setTopics( list ) {
			var counts = {};
			rows.forEach( function ( row ) {
				row.setAttribute( 'data-kw-topic', '' );
			} );
			list.forEach( function ( topic ) {
				topic.keywords.forEach( function ( keyword ) {
					rows.forEach( function ( row ) {
						if ( row.getAttribute( 'data-kw' ) === keyword ) {
							row.setAttribute( 'data-kw-topic', topic.name );
						}
					} );
				} );
			} );
			rows.forEach( function ( row ) {
				var name = row.getAttribute( 'data-kw-topic' ) || '';
				var cell = row.querySelector( '.simple-seo-kw-topic' );
				if ( cell ) {
					cell.textContent = name;
				}
				if ( name ) {
					counts[ name ] = ( counts[ name ] || 0 ) + 1;
				}
			} );
			topics.textContent = '';
			list.forEach( function ( topic ) {
				if ( ! counts[ topic.name ] ) {
					return;
				}
				var button = element( 'button', 'button button-small', topic.name + ' (' + counts[ topic.name ] + ')' );
				button.type = 'button';
				button.setAttribute( 'data-kw-filter', 'topic' );
				button.setAttribute( 'data-kw-topic', topic.name );
				button.setAttribute( 'aria-pressed', 'false' );
				topics.appendChild( button );
				topics.appendChild( document.createTextNode( ' ' ) );
			} );
			current = { filter: 'all', topic: '' };
			apply();
		}

		var group = root.querySelector( '[data-kw-group]' );
		if ( group ) {
			group.addEventListener( 'click', function () {
				group.disabled = true;
				say( text.grouping );
				post( config.topics, {
					seed: root.getAttribute( 'data-seed' ),
					language: root.getAttribute( 'data-language' ),
					keywords: rows.map( function ( row ) {
						return row.getAttribute( 'data-kw' );
					} )
				} ).then( function ( data ) {
					setTopics( data.topics || [] );
					if ( parent && data.parent ) {
						parent.textContent = text.parent + ' ' + data.parent;
						parent.hidden      = false;
					}
					say( '' );
				} ).catch( function ( error ) {
					say( error.message );
				} ).then( function () {
					group.disabled = false;
				} );
			} );
		}

		function outlineText( outline ) {
			var lines = [ outline.title, '' ];
			outline.sections.forEach( function ( section ) {
				lines.push( '## ' + section.heading );
				section.subheadings.forEach( function ( subheading ) {
					lines.push( '### ' + subheading );
				} );
				if ( section.keywords.length ) {
					lines.push( text.covers + ' ' + section.keywords.join( ', ' ) );
				}
				lines.push( '' );
			} );
			if ( outline.faq.length ) {
				lines.push( '## ' + text.faq );
				outline.faq.forEach( function ( question ) {
					lines.push( '- ' + question );
				} );
			}
			return lines.join( '\n' ).trim();
		}

		function showOutline( outline, keyphrase ) {
			plan.textContent = '';
			plan.appendChild( element( 'h2', 'simple-seo-kw-outline-title', outline.title ) );
			outline.sections.forEach( function ( section ) {
				var block = element( 'div', 'simple-seo-kw-section' );
				block.appendChild( element( 'h3', '', section.heading ) );
				if ( section.subheadings.length ) {
					var list = element( 'ul' );
					section.subheadings.forEach( function ( subheading ) {
						list.appendChild( element( 'li', '', subheading ) );
					} );
					block.appendChild( list );
				}
				if ( section.keywords.length ) {
					block.appendChild( element( 'p', 'description', text.covers + ' ' + section.keywords.join( ', ' ) ) );
				}
				plan.appendChild( block );
			} );
			if ( outline.faq.length ) {
				var faq = element( 'div', 'simple-seo-kw-section' );
				faq.appendChild( element( 'h3', '', text.faq ) );
				var questions = element( 'ul' );
				outline.faq.forEach( function ( question ) {
					questions.appendChild( element( 'li', '', question ) );
				} );
				faq.appendChild( questions );
				plan.appendChild( faq );
			}

			var actions = element( 'p', 'simple-seo-kw-outline-actions' );
			var copy    = element( 'button', 'button', text.copy );
			copy.type   = 'button';
			copy.addEventListener( 'click', function () {
				var done = function () {
					say( text.copied );
				};
				if ( window.navigator.clipboard ) {
					window.navigator.clipboard.writeText( outlineText( outline ) ).then( done, function () {
						say( text.failed );
					} );
				}
			} );
			actions.appendChild( copy );

			if ( root.hasAttribute( 'data-draft' ) ) {
				var create  = element( 'button', 'button button-primary', text.create );
				create.type = 'button';
				create.addEventListener( 'click', function () {
					create.disabled = true;
					say( text.creating );
					post( config.draft, { outline: outline, keyphrase: keyphrase } ).then( function ( data ) {
						var link  = element( 'a', 'button', text.open );
						link.href = data.edit;
						actions.replaceChild( link, create );
						say( '' );
					} ).catch( function ( error ) {
						create.disabled = false;
						say( error.message );
					} );
				} );
				actions.appendChild( document.createTextNode( ' ' ) );
				actions.appendChild( create );
			}
			plan.appendChild( actions );
			plan.hidden = false;
		}

		var outlineButton = root.querySelector( '[data-kw-outline]' );
		if ( outlineButton ) {
			outlineButton.addEventListener( 'click', function () {
				var shown     = visible();
				var keyphrase = 'topic' === current.filter && shown.length ? shown[ 0 ].getAttribute( 'data-kw' ) : root.getAttribute( 'data-seed' );
				outlineButton.disabled = true;
				say( text.planning );
				post( config.outline, {
					seed: root.getAttribute( 'data-seed' ),
					topic: 'topic' === current.filter ? current.topic : '',
					language: root.getAttribute( 'data-language' ),
					keywords: shown.map( function ( row ) {
						return row.getAttribute( 'data-kw' );
					} )
				} ).then( function ( data ) {
					showOutline( data, keyphrase );
					say( '' );
				} ).catch( function ( error ) {
					say( error.message );
				} ).then( function () {
					outlineButton.disabled = false;
				} );
			} );
		}

		apply();
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-simple-seo-kw-page]' ), initPage );

	/*
	 * The SEO block of a post.
	 */

	function title() {
		var data = window.wp && window.wp.data;
		var edit = data && data.select ? data.select( 'core/editor' ) : null;
		if ( edit && edit.getEditedPostAttribute ) {
			return edit.getEditedPostAttribute( 'title' ) || '';
		}
		var field = document.getElementById( 'title' );
		return field ? field.value : '';
	}

	function changed( field ) {
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function initButton( button ) {
		var focus = document.getElementById( 'simple-seo-field-keyphrase' );
		var more  = document.getElementById( 'simple-seo-field-keyphrases' );
		var box   = element( 'div', 'simple-seo-kw-ideas' );
		box.hidden = true;
		var place = button.closest( 'p' ) || button.parentNode;
		place.parentNode.insertBefore( box, place.nextSibling );

		function render( data ) {
			box.textContent = '';
			if ( ! data.items || ! data.items.length ) {
				box.appendChild( element( 'p', 'description', text.none ) );
				return;
			}
			var list = element( 'ul' );
			data.items.slice( 0, config.max ).forEach( function ( item ) {
				var line = element( 'li' );
				line.appendChild( element( 'span', 'simple-seo-kw-idea', item.keyword ) );
				if ( item.question ) {
					line.appendChild( element( 'span', 'simple-seo-kw-tag', text.question ) );
				}
				if ( item.gsc ) {
					line.appendChild( element( 'span', 'description', ' ' + item.gsc.impressions + ' / ' + item.gsc.clicks + ' / ' + item.gsc.position ) );
				}
				var use  = element( 'button', 'button-link', text.focus );
				use.type = 'button';
				use.addEventListener( 'click', function () {
					if ( focus ) {
						focus.value = item.keyword;
						changed( focus );
					}
				} );
				line.appendChild( use );
				if ( more ) {
					var add  = element( 'button', 'button-link', text.add );
					add.type = 'button';
					add.addEventListener( 'click', function () {
						var lines = more.value.split( /\r?\n/ ).filter( function ( one ) {
							return '' !== one.trim();
						} );
						if ( lines.indexOf( item.keyword ) < 0 ) {
							lines.push( item.keyword );
							more.value = lines.join( '\n' );
							changed( more );
						}
					} );
					line.appendChild( add );
				}
				list.appendChild( line );
			} );
			box.appendChild( list );
			var link  = element( 'a', '', text.more );
			link.href = config.page + '&q=' + encodeURIComponent( data.seed ) + '&lang=' + encodeURIComponent( data.language );
			link.target = '_blank';
			box.appendChild( element( 'p' ) ).appendChild( link );
		}

		button.addEventListener( 'click', function () {
			var seed = ( focus && focus.value.trim() ) || title().trim();
			box.hidden = false;
			if ( ! seed ) {
				box.textContent = text.type;
				return;
			}
			button.disabled = true;
			box.textContent = text.finding;
			post( config.url, { seed: seed, language: button.getAttribute( 'data-simple-seo-kw' ) } ).then( render ).catch( function ( error ) {
				box.textContent = error.message;
			} ).then( function () {
				button.disabled = false;
			} );
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-simple-seo-kw]' ), initButton );
}() );
