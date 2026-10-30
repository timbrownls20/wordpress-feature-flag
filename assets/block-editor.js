/**
 * Feature flag panel for every block that supports custom CSS classes.
 * Plain ES5, no build step. Stores the choice as an ff--<key> / ff-not--<key>
 * class, which the server strips or renders (see includes/integrations.php).
 */
( function ( wp, config ) {
	if ( ! wp || ! wp.hooks || ! wp.blockEditor || ! wp.element.useState || ! config ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var ExternalLink = wp.components.ExternalLink;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	var ON = 'ff--';
	var OFF = 'ff-not--';

	/**
	 * Split a className into the first flag class and everything else.
	 */
	function parse( className ) {
		var classes = ( className || '' ).split( /\s+/ );
		var result = { flag: '', invert: false, rest: [] };
		for ( var i = 0; i < classes.length; i++ ) {
			var c = classes[ i ];
			if ( ! c ) {
				continue;
			}
			if ( ! result.flag && c.indexOf( OFF ) === 0 ) {
				result.flag = c.slice( OFF.length );
				result.invert = true;
			} else if ( ! result.flag && c.indexOf( ON ) === 0 ) {
				result.flag = c.slice( ON.length );
			} else {
				result.rest.push( c );
			}
		}
		return result;
	}

	function build( rest, flag, invert ) {
		var classes = rest.slice();
		if ( flag ) {
			classes.push( ( invert ? OFF : ON ) + flag );
		}
		return classes.length ? classes.join( ' ' ) : undefined;
	}

	function flagOptions( current ) {
		var options = [ { label: __( '— None (always shown) —', 'wordpress-feature-flags' ), value: '' } ];
		var known = false;
		for ( var i = 0; i < config.flags.length; i++ ) {
			var f = config.flags[ i ];
			if ( f.key === current ) {
				known = true;
			}
			options.push( { label: f.label + ' (' + f.key + ') — ' + f.mode, value: f.key } );
		}
		if ( current && ! known ) {
			options.push( {
				/* translators: %s: flag key */
				label: sprintf( __( '%s (unregistered — hidden)', 'wordpress-feature-flags' ), current ),
				value: current,
			} );
		}
		return options;
	}

	var withFlagPanel = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( ! wp.blocks.hasBlockSupport( props.name, 'customClassName', true ) ) {
				return el( BlockEdit, props );
			}
			var state = parse( props.attributes.className );
			// "Enabled" with no flag picked yet can't live in the class, so keep it in local state.
			var enabledState = wp.element.useState( !! state.flag );
			var enabled = enabledState[ 0 ] || !! state.flag;
			var setEnabled = enabledState[ 1 ];

			function update( flag, invert ) {
				props.setAttributes( { className: build( state.rest, flag, invert ) } );
			}

			var controls = [
				el( ToggleControl, {
					key: 'enable',
					label: __( 'Show or hide this block with a feature flag', 'wordpress-feature-flags' ),
					checked: enabled,
					onChange: function ( value ) {
						setEnabled( value );
						if ( ! value ) {
							update( '', false );
						}
					},
				} ),
			];

			if ( enabled ) {
				controls.push(
					el( SelectControl, {
						key: 'flag',
						label: __( 'Feature flag', 'wordpress-feature-flags' ),
						value: state.flag,
						options: flagOptions( state.flag ),
						onChange: function ( value ) {
							update( value, state.invert );
						},
						help: state.flag ? '' : __( 'Pick a flag — the block is shown to everyone until you do.', 'wordpress-feature-flags' ),
					} )
				);
			}

			if ( state.flag ) {
				controls.push(
					el( ToggleControl, {
						key: 'invert',
						label: __( 'Show only while flag is OFF', 'wordpress-feature-flags' ),
						help: state.invert
							? __( 'Visitors see this block until the flag goes live — e.g. a "coming soon" notice.', 'wordpress-feature-flags' )
							: __( 'Visitors see this block only once the flag is on for them.', 'wordpress-feature-flags' ),
						checked: state.invert,
						onChange: function ( value ) {
							update( state.flag, value );
						},
					} )
				);
			}

			if ( enabled && config.adminUrl ) {
				controls.push( el( ExternalLink, { key: 'manage', href: config.adminUrl }, __( 'Manage feature flags', 'wordpress-feature-flags' ) ) );
			}

			return el(
				Fragment,
				null,
				el( BlockEdit, props ),
				el(
					InspectorControls,
					null,
					el( PanelBody, { title: __( 'Feature flag', 'wordpress-feature-flags' ), initialOpen: true }, controls )
				)
			);
		};
	}, 'wpffWithFlagPanel' );

	wp.hooks.addFilter( 'editor.BlockEdit', 'wordpress-feature-flags/flag-panel', withFlagPanel );
}( window.wp, window.wpffBlockEditor ) );
