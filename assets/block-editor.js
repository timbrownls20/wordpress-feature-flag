/**
 * Block editor support. Plain ES5, no build step.
 *
 * 1. A "Feature flag" panel on every block that supports custom CSS classes. It stores
 *    the choice as an ff--<key> / ff-not--<key> class, which the server strips or renders
 *    (see includes/integrations.php).
 * 2. The wpff/feature-flag wrapper block: nest any blocks inside it; the server renders
 *    them only when the gate passes (see includes/block-editor.php).
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
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var useBlockProps = wp.blockEditor.useBlockProps;
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

	function findFlag( key ) {
		for ( var i = 0; i < config.flags.length; i++ ) {
			if ( config.flags[ i ].key === key ) {
				return config.flags[ i ];
			}
		}
		return null;
	}

	/**
	 * One-line summary of a wrapper block's gate, used in the canvas header and List View.
	 */
	function gateSummary( attributes ) {
		if ( ! attributes.flag ) {
			return __( 'Feature flag: none picked', 'wordpress-feature-flags' );
		}
		return attributes.invert
			/* translators: %s: flag key */
			? sprintf( __( 'Feature flag: %s (while OFF)', 'wordpress-feature-flags' ), attributes.flag )
			/* translators: %s: flag key */
			: sprintf( __( 'Feature flag: %s', 'wordpress-feature-flags' ), attributes.flag );
	}

	function gateHelp( attributes ) {
		var flag = findFlag( attributes.flag );
		if ( ! attributes.flag ) {
			return __( 'Pick a flag in the block settings. Until then the content is shown to everyone.', 'wordpress-feature-flags' );
		}
		if ( ! flag ) {
			return __( 'This flag is not registered, so it counts as off.', 'wordpress-feature-flags' );
		}
		return attributes.invert
			/* translators: %s: flag mode */
			? sprintf( __( 'Shown until the flag is on for the visitor (now: %s).', 'wordpress-feature-flags' ), flag.mode )
			/* translators: %s: flag mode */
			: sprintf( __( 'Shown only when the flag is on for the visitor (now: %s).', 'wordpress-feature-flags' ), flag.mode );
	}

	// Inline SVG: the editor canvas iframe doesn't load the Dashicons font.
	function flagIcon() {
		return el(
			'svg',
			{ width: 16, height: 16, viewBox: '0 0 24 24', 'aria-hidden': true, focusable: false },
			el( 'path', { d: 'M6 3h1.5v18H6zM8.5 4h10l-2.5 4 2.5 4h-10z', fill: 'currentColor' } )
		);
	}

	var headerStyle = {
		display: 'flex',
		alignItems: 'center',
		gap: '6px',
		padding: '4px 8px',
		marginBottom: '8px',
		fontSize: '12px',
		fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
		background: '#f0f0f1',
		color: '#1e1e1e',
		borderRadius: '2px',
	};

	function editFlagBlock( props ) {
		var attributes = props.attributes;
		var wrapperProps = {
			style: { outline: '1px dashed #949494', outlineOffset: '4px', padding: '4px' },
		};
		wrapperProps = useBlockProps ? useBlockProps( wrapperProps ) : wrapperProps;

		return el(
			'div',
			wrapperProps,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Feature flag', 'wordpress-feature-flags' ), initialOpen: true },
					el( SelectControl, {
						label: __( 'Feature flag', 'wordpress-feature-flags' ),
						value: attributes.flag,
						options: flagOptions( attributes.flag ),
						onChange: function ( value ) {
							props.setAttributes( { flag: value } );
						},
						help: gateHelp( attributes ),
					} ),
					el( ToggleControl, {
						label: __( 'Show only while flag is OFF', 'wordpress-feature-flags' ),
						checked: !! attributes.invert,
						onChange: function ( value ) {
							props.setAttributes( { invert: value } );
						},
					} ),
					config.adminUrl
						? el( ExternalLink, { href: config.adminUrl }, __( 'Manage feature flags', 'wordpress-feature-flags' ) )
						: null
				)
			),
			el(
				'div',
				{ style: headerStyle, contentEditable: false },
				flagIcon(),
				el( 'strong', null, gateSummary( attributes ) ),
				el( 'span', { style: { color: '#757575' } }, '— ' + gateHelp( attributes ) )
			),
			el( InnerBlocks, { templateLock: false } )
		);
	}

	wp.blocks.registerBlockType( 'wpff/feature-flag', {
		// v3 = iframe-ready (WP 6.3+); older editors treat any version above 1 like v2.
		apiVersion: useBlockProps ? 3 : 1,
		title: __( 'Feature flag', 'wordpress-feature-flags' ),
		description: __( 'Show or hide the blocks inside based on a feature flag.', 'wordpress-feature-flags' ),
		category: 'design',
		icon: flagIcon(),
		keywords: [ __( 'visibility', 'wordpress-feature-flags' ), __( 'hide', 'wordpress-feature-flags' ), __( 'coming soon', 'wordpress-feature-flags' ) ],
		supports: { html: false, customClassName: false },
		attributes: {
			flag: { type: 'string', default: '' },
			invert: { type: 'boolean', default: false },
		},
		// Names the block "Feature flag: store" in List View and breadcrumbs.
		__experimentalLabel: function ( attributes ) {
			return gateSummary( attributes );
		},
		edit: editFlagBlock,
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );
}( window.wp, window.wpffBlockEditor ) );
