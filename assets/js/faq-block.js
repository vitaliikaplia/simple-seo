/**
 * The block "Questions and answers" in the block editor: a list of questions with their answers,
 * added and removed here; the site renders it in PHP (Simple_SEO_FAQ::render), so nothing is saved
 * as HTML. Labels come translated in window.simpleSeoFaq.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor || ! wp.components ) {
		return;
	}

	const el = wp.element.createElement;
	const RichText = wp.blockEditor.RichText;
	const Button = wp.components.Button;
	const l10n = window.simpleSeoFaq || {};

	wp.blocks.registerBlockType( 'simple-seo/faq', {
		title: l10n.title || 'Questions and answers',
		description: l10n.description || '',
		icon: 'editor-help',
		category: 'text',
		attributes: {
			items: { type: 'array', default: [] },
		},
		supports: { html: false, anchor: true, className: true },

		edit: function ( props ) {
			const items = Array.isArray( props.attributes.items ) ? props.attributes.items : [];
			const blockProps = wp.blockEditor.useBlockProps( { className: 'simple-seo-faq' } );
			const set = function ( list ) {
				props.setAttributes( { items: list } );
			};
			const update = function ( index, key, value ) {
				set(
					items.map( function ( item, n ) {
						if ( n !== index ) {
							return item;
						}
						const copy = Object.assign( {}, item );
						copy[ key ] = value;
						return copy;
					} )
				);
			};
			// A new block starts with one empty question to type into.
			const list = items.length ? items : [ { question: '', answer: '' } ];

			return el(
				'div',
				blockProps,
				list.map( function ( item, index ) {
					return el(
						'div',
						{ className: 'simple-seo-faq-item', key: index },
						el( RichText, {
							tagName: 'p',
							className: 'simple-seo-faq-question',
							value: item.question || '',
							allowedFormats: [],
							placeholder: l10n.question || 'Question',
							onChange: function ( value ) {
								if ( ! items.length ) {
									set( [ { question: value, answer: '' } ] );
									return;
								}
								update( index, 'question', value );
							},
						} ),
						el( RichText, {
							tagName: 'p',
							className: 'simple-seo-faq-answer',
							value: item.answer || '',
							allowedFormats: [ 'core/bold', 'core/italic', 'core/link' ],
							placeholder: l10n.answer || 'Answer',
							onChange: function ( value ) {
								if ( ! items.length ) {
									set( [ { question: '', answer: value } ] );
									return;
								}
								update( index, 'answer', value );
							},
						} ),
						items.length
							? el(
									Button,
									{
										variant: 'link',
										isDestructive: true,
										onClick: function () {
											set(
												items.filter( function ( entry, n ) {
													return n !== index;
												} )
											);
										},
									},
									l10n.remove || 'Remove'
							  )
							: null
					);
				} ),
				el(
					Button,
					{
						variant: 'secondary',
						onClick: function () {
							set( list.concat( [ { question: '', answer: '' } ] ) );
						},
					},
					l10n.add || 'Add a question'
				)
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp );
