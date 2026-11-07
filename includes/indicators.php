<?php
/**
 * Subtle front-end indicators on flag-gated content.
 *
 * Shown only to logged-in users who can preview the flag (and not while
 * "view as public visitor" is on). Visitors and preview-link holders get
 * unchanged markup. Two styles:
 * - amber dashed outline: you see it, the public does not (preview / scheduled);
 * - faint grey dotted outline: gated, but the public sees it too right now.
 *
 * @package wordpress-feature-flags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether to mark content gated by this flag for the current viewer.
 *
 * @param string $key Flag key.
 * @return bool
 */
function wpff_show_indicator( $key ) {
	if ( is_admin() || wp_doing_ajax() || ! is_user_logged_in() || wpff_viewing_as_public() || ! wpff_get_flag( $key ) ) {
		return false;
	}
	/**
	 * Filter whether gated content is marked for the current viewer.
	 *
	 * @param bool   $show Default: the user can preview the flag.
	 * @param string $key  Flag key.
	 */
	return (bool) apply_filters( 'wpff_show_indicators', wpff_can_preview( $key ), $key );
}

/**
 * Indicator state for a visible gate: `live` when the public sees it too, else `preview`.
 *
 * @param string $key    Flag key.
 * @param bool   $invert Gate shows only while the flag is off.
 * @return string
 */
function wpff_indicator_state( $key, $invert = false ) {
	$live = wpff_is_live( $key );
	return ( $invert ? ! $live : $live ) ? 'live' : 'preview';
}

/**
 * Tooltip text for a gate, e.g. "Feature flag: store (preview — hidden from public)".
 *
 * @param string $key    Flag key.
 * @param bool   $invert Gate shows only while the flag is off.
 * @return string
 */
function wpff_indicator_label( $key, $invert = false ) {
	$state = 'live' === wpff_indicator_state( $key, $invert )
		? __( 'public sees this', 'wordpress-feature-flags' )
		: __( 'hidden from public', 'wordpress-feature-flags' );
	/* translators: 1: flag key, 2: " while OFF" or "", 3: "public sees this" / "hidden from public" */
	return sprintf( __( 'Feature flag: %1$s%2$s — %3$s', 'wordpress-feature-flags' ), $key, $invert ? __( ' (while OFF)', 'wordpress-feature-flags' ) : '', $state );
}

/**
 * Wrap visible gated output (wrapper block, shortcode) in a marked element.
 * Returns the content unchanged when no indicator applies.
 *
 * @param string $content Rendered content.
 * @param string $key     Flag key.
 * @param bool   $invert  Gate shows only while the flag is off.
 * @return string
 */
function wpff_mark( $content, $key, $invert = false ) {
	if ( '' === trim( $content ) || ! wpff_show_indicator( $key ) ) {
		return $content;
	}
	$block = (bool) preg_match( '/<(?:p|div|section|article|aside|header|footer|figure|ul|ol|table|h[1-6]|blockquote|form|nav|main|pre|hr)\b/i', $content );
	$tag   = $block ? 'div' : 'span';
	return sprintf(
		'<%1$s class="wpff-mark wpff-mark--%2$s" title="%3$s">%4$s</%1$s>',
		$tag,
		esc_attr( wpff_indicator_state( $key, $invert ) ),
		esc_attr( wpff_indicator_label( $key, $invert ) ),
		$content
	);
}

/**
 * Flags named by ff--/ff-not-- classes in a class string.
 *
 * @param string $classes Space-separated class list.
 * @return array<int, array{0: string, 1: bool}> [key, invert] pairs.
 */
function wpff_class_gates( $classes ) {
	$gates = array();
	foreach ( preg_split( '/\s+/', trim( (string) $classes ) ) as $class ) {
		if ( 0 === strpos( $class, 'ff--' ) ) {
			$gates[] = array( substr( $class, 4 ), false );
		} elseif ( 0 === strpos( $class, 'ff-not--' ) ) {
			$gates[] = array( substr( $class, 8 ), true );
		}
	}
	return $gates;
}

/**
 * Inline styles. Class-gated blocks and Elementor elements already carry their
 * ff-- class in the markup, so they are outlined by per-flag selectors.
 */
function wpff_indicator_styles() {
	$preview = array( '.wpff-mark--preview', 'li.wpff-mark--preview > a' );
	$live    = array( '.wpff-mark--live', 'li.wpff-mark--live > a' );
	$shown   = false;

	foreach ( array_keys( wpff_flags() ) as $key ) {
		if ( ! wpff_show_indicator( $key ) ) {
			continue;
		}
		$shown = true;
		foreach ( array( false, true ) as $invert ) {
			$selector = '.' . ( $invert ? 'ff-not--' : 'ff--' ) . $key;
			if ( 'live' === wpff_indicator_state( $key, $invert ) ) {
				$live[] = $selector;
			} else {
				$preview[] = $selector;
			}
		}
	}
	if ( ! $shown ) {
		return;
	}

	$css  = 'li.wpff-mark{outline:0!important}';
	$css .= implode( ',', $preview ) . '{outline:1px dashed rgba(217,119,6,.55)!important;outline-offset:3px}';
	$css .= implode( ',', $live ) . '{outline:1px dotted rgba(100,116,139,.3)!important;outline-offset:3px}';
	$css .= implode( ':hover,', $preview ) . ':hover{outline-color:rgba(217,119,6,.9)!important}';
	$css .= implode( ':hover,', $live ) . ':hover{outline-color:rgba(100,116,139,.7)!important}';
	$css .= '#wpadminbar .wpff-page-flag--preview > .ab-item{color:#f0b849!important}';

	echo "<style id=\"wpff-indicators\">{$css}</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from validated flag keys and literals.
}
add_action( 'wp_head', 'wpff_indicator_styles', 100 );

/**
 * Add a tooltip to visible class-gated blocks (WordPress 6.2+, which has the HTML tag processor).
 *
 * @param string $content Rendered block.
 * @param array  $block   Parsed block.
 * @return string
 */
function wpff_indicator_block_title( $content, $block ) {
	if ( '' === $content || empty( $block['attrs']['className'] ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $content;
	}
	$labels = array();
	foreach ( wpff_class_gates( $block['attrs']['className'] ) as $gate ) {
		if ( wpff_show_indicator( $gate[0] ) ) {
			$labels[] = wpff_indicator_label( $gate[0], $gate[1] );
		}
	}
	if ( ! $labels ) {
		return $content;
	}
	$html = new WP_HTML_Tag_Processor( $content );
	if ( $html->next_tag() && null === $html->get_attribute( 'title' ) ) {
		$html->set_attribute( 'title', implode( '; ', $labels ) );
		return $html->get_updated_html();
	}
	return $content;
}
add_filter( 'render_block', 'wpff_indicator_block_title', 11, 2 );

/**
 * Mark visible flag-gated menu items. Runs after wpff_filter_menu_items() has dropped hidden ones.
 *
 * @param WP_Post[] $items Menu items.
 * @return WP_Post[]
 */
function wpff_indicator_menu_items( $items ) {
	foreach ( $items as $item ) {
		$key = (string) get_post_meta( $item->ID, WPFF_META_FLAG, true );
		if ( '' === $key || ! wpff_show_indicator( $key ) ) {
			continue;
		}
		$invert        = (bool) get_post_meta( $item->ID, WPFF_META_INVERT, true );
		$item->classes = array_merge( (array) $item->classes, array( 'wpff-mark', 'wpff-mark--' . wpff_indicator_state( $key, $invert ) ) );
		if ( '' === (string) $item->attr_title ) {
			$item->attr_title = wpff_indicator_label( $key, $invert );
		}
	}
	return $items;
}
add_filter( 'wp_nav_menu_objects', 'wpff_indicator_menu_items', 11 );

/**
 * Admin bar note when the current page itself is gated.
 *
 * @param WP_Admin_Bar $bar Admin bar.
 */
function wpff_indicator_admin_bar( $bar ) {
	$post_id = is_admin() ? 0 : wpff_current_gated_id();
	$key     = $post_id ? (string) get_post_meta( $post_id, WPFF_META_FLAG, true ) : '';
	if ( '' === $key || ! wpff_show_indicator( $key ) ) {
		return;
	}
	$invert = (bool) get_post_meta( $post_id, WPFF_META_INVERT, true );
	$bar->add_node(
		array(
			'id'    => 'wpff-page-flag',
			'title' => esc_html( '⚑ ' . $key . ( $invert ? ' (OFF)' : '' ) ),
			'href'  => current_user_can( 'manage_options' ) ? wpff_admin_url() : false,
			'meta'  => array(
				'class' => 'wpff-page-flag--' . wpff_indicator_state( $key, $invert ),
				'title' => wpff_indicator_label( $key, $invert ),
			),
		)
	);
}
add_action( 'admin_bar_menu', 'wpff_indicator_admin_bar', 101 );
