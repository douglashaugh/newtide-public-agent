/* NewTide Agent block — buildless editor script (no JSX).
   The front end is rendered server-side (dynamic block); save() returns null. */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;

	function field( label, value, onChange, help ) {
		return el( TextControl, {
			label: label,
			value: value || '',
			onChange: onChange,
			help: help
		} );
	}

	registerBlockType( 'newtide/agent', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var blockProps = useBlockProps();

			var controls = el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'Agent settings', 'newtide-public-agent' ), initialOpen: true },
					field( __( 'Agent ID (override)', 'newtide-public-agent' ), a.agent, function ( v ) {
						set( { agent: v } );
					}, __( 'Leave blank to use the global default.', 'newtide-public-agent' ) ),
					field( __( 'Launcher label', 'newtide-public-agent' ), a.label, function ( v ) {
						set( { label: v } );
					} ),
					field( __( 'Greeting', 'newtide-public-agent' ), a.greeting, function ( v ) {
						set( { greeting: v } );
					} ),
					el( SelectControl, {
						label: __( 'Position', 'newtide-public-agent' ),
						value: a.position || '',
						options: [
							{ label: __( 'Default', 'newtide-public-agent' ), value: '' },
							{ label: __( 'Bottom right', 'newtide-public-agent' ), value: 'bottom-right' },
							{ label: __( 'Bottom left', 'newtide-public-agent' ), value: 'bottom-left' }
						],
						onChange: function ( v ) {
							set( { position: v } );
						}
					} ),
					field( __( 'Accent colour (hex)', 'newtide-public-agent' ), a.accent, function ( v ) {
						set( { accent: v } );
					}, __( 'e.g. #2563eb. Leave blank for the global default.', 'newtide-public-agent' ) )
				)
			);

			var preview = el(
				'div',
				blockProps,
				el(
					'div',
					{
						style: {
							display: 'inline-block',
							padding: '0.6rem 1.1rem',
							borderRadius: '999px',
							background: a.accent || '#2563eb',
							color: '#fff',
							fontWeight: 600
						}
					},
					a.label || __( 'Chat with us', 'newtide-public-agent' )
				),
				el(
					'p',
					{ style: { margin: '0.5rem 0 0', color: '#666', fontStyle: 'italic' } },
					__( 'NewTide Agent widget (shown on the front end).', 'newtide-public-agent' )
				)
			);

			return el( wp.element.Fragment, {}, controls, preview );
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
