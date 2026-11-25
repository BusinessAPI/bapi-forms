/**
 * Editor script for the "BusinessAPI Forms: Registration form" block.
 *
 * Hand-written (no build step). The preview comes from the server, which in the
 * editor renders a placeholder card and never creates a form session.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var blockEditor = wp.blockEditor;
	var components = wp.components;
	var ServerSideRender = wp.serverSideRender;

	var data = window.bapiFormsBlock || {};
	var products = Array.isArray( data.products ) ? data.products : [];

	function findProduct( slug ) {
		for ( var i = 0; i < products.length; i++ ) {
			if ( products[ i ].slug === slug ) {
				return products[ i ];
			}
		}
		return null;
	}

	function productOptions( current ) {
		var options = [ { value: '', label: __( 'Choose a form', 'businessapi-forms' ) } ];
		products.forEach( function ( product ) {
			// Only switched-on forms are offered, plus whatever this block already uses.
			if ( product.active || product.slug === current ) {
				options.push( {
					value: product.slug,
					label: product.active
						? product.label
						: product.label + ' ' + __( '(switched off)', 'businessapi-forms' ),
				} );
			}
		} );
		if ( current && ! findProduct( current ) ) {
			options.push( { value: current, label: current + ' ' + __( '(unknown)', 'businessapi-forms' ) } );
		}
		return options;
	}

	function productSelect( props, hideLabel ) {
		var options = productOptions( props.attributes.product );
		return el( components.SelectControl, {
			label: __( 'Form', 'businessapi-forms' ),
			hideLabelFromVision: !! hideLabel,
			value: props.attributes.product,
			options: options,
			help: options.length < 2
				? __( 'No forms are switched on yet. Turn products on under BusinessAPI Forms > Products.', 'businessapi-forms' )
				: undefined,
			onChange: function ( value ) {
				props.setAttributes( { product: value } );
			},
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
		} );
	}

	function Edit( props ) {
		var blockProps = blockEditor.useBlockProps();
		var product = props.attributes.product;

		var inspector = el(
			blockEditor.InspectorControls,
			null,
			el(
				components.PanelBody,
				{ title: __( 'Form settings', 'businessapi-forms' ) },
				productSelect( props, false )
			)
		);

		var body;
		if ( ! product ) {
			body = el(
				components.Placeholder,
				{
					icon: 'feedback',
					label: __( 'BusinessAPI Forms: Registration form', 'businessapi-forms' ),
					instructions: __( 'Choose which registration form to show on this page.', 'businessapi-forms' ),
				},
				productSelect( props, true )
			);
		} else {
			body = el( ServerSideRender, {
				block: 'bapi-forms/registration-form',
				attributes: { product: product },
				httpMethod: 'POST',
			} );
		}

		return el( 'div', blockProps, inspector, body );
	}

	wp.blocks.registerBlockType( 'bapi-forms/registration-form', {
		edit: Edit,
		// Rendered on the server, like the shortcode.
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
