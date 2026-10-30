<?php
/**
 * Field-driven gates: menu items, posts/pages/products, shortcode, Gutenberg
 * blocks, Elementor elements and body classes. All evaluated server-side.
 *
 * @package wordpress-feature-flags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitise a submitted flag key: '' clears, registered keys pass, anything
 * else (unregistered, malformed) returns null meaning "leave unchanged".
 *
 * @param mixed $value Submitted value.
 * @return string|null
 */
function wpff_submitted_flag( $value ) {
	$value = is_string( $value ) ? sanitize_key( wp_unslash( $value ) ) : '';
	if ( '' === $value ) {
		return '';
	}
	return wpff_get_flag( $value ) ? $value : null;
}

/**
 * Persist the flag/invert meta pair on a post or menu item.
 *
 * @param int         $post_id Post ID.
 * @param string|null $flag    Flag key, '' to clear, null to leave unchanged.
 * @param bool        $invert  Show only while the flag is off.
 */
function wpff_save_gate_meta( $post_id, $flag, $invert ) {
	if ( null === $flag ) {
		return;
	}
	if ( '' === $flag ) {
		delete_post_meta( $post_id, WPFF_META_FLAG );
		delete_post_meta( $post_id, WPFF_META_INVERT );
		return;
	}
	update_post_meta( $post_id, WPFF_META_FLAG, $flag );
	if ( $invert ) {
		update_post_meta( $post_id, WPFF_META_INVERT, '1' );
	} else {
		delete_post_meta( $post_id, WPFF_META_INVERT );
	}
}

/**
 * Whether a post or menu item carrying gate meta is visible to this visitor.
 * Objects without a flag are always visible.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function wpff_post_visible( $post_id ) {
	$flag = get_post_meta( $post_id, WPFF_META_FLAG, true );
	if ( ! is_string( $flag ) || '' === $flag ) {
		return true;
	}
	return wpff_visible( $flag, (bool) get_post_meta( $post_id, WPFF_META_INVERT, true ) );
}

/**
 * Render the flag select + invert checkbox shared by menu items and the meta box.
 *
 * @param string $flag_name   Select field name.
 * @param string $invert_name Checkbox field name.
 * @param string $current     Current flag key.
 * @param bool   $invert      Current invert state.
 * @param string $id_suffix   Unique suffix for element IDs.
 */
function wpff_render_gate_fields( $flag_name, $invert_name, $current, $invert, $id_suffix ) {
	$flags = wpff_flags();
	?>
	<label for="wpff-flag-<?php echo esc_attr( $id_suffix ); ?>"><?php esc_html_e( 'Feature flag', 'wordpress-feature-flags' ); ?></label><br />
	<select id="wpff-flag-<?php echo esc_attr( $id_suffix ); ?>" name="<?php echo esc_attr( $flag_name ); ?>" class="widefat">
		<option value=""><?php esc_html_e( '— None —', 'wordpress-feature-flags' ); ?></option>
		<?php foreach ( $flags as $key => $flag ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $flag['label'] . ' (' . $key . ')' ); ?></option>
		<?php endforeach; ?>
		<?php if ( '' !== $current && ! isset( $flags[ $current ] ) ) : ?>
			<?php /* translators: %s: flag key */ ?>
			<option value="<?php echo esc_attr( $current ); ?>" selected><?php echo esc_html( sprintf( __( '%s (unregistered — hidden)', 'wordpress-feature-flags' ), $current ) ); ?></option>
		<?php endif; ?>
	</select>
	<br />
	<label for="wpff-invert-<?php echo esc_attr( $id_suffix ); ?>">
		<input type="checkbox" id="wpff-invert-<?php echo esc_attr( $id_suffix ); ?>" name="<?php echo esc_attr( $invert_name ); ?>" value="1" <?php checked( $invert ); ?> />
		<?php esc_html_e( 'Show only while flag is OFF', 'wordpress-feature-flags' ); ?>
	</label>
	<?php
}

/*
 * 6.1 Menu items
 */

/**
 * Per-item fields on Appearance → Menus.
 *
 * @param int     $item_id   Menu item ID.
 * @param WP_Post $menu_item Menu item.
 */
function wpff_menu_item_fields( $item_id, $menu_item ) {
	$item_id = (int) $item_id;
	?>
	<p class="field-wpff description description-wide">
		<input type="hidden" name="wpff_menu_marker[<?php echo esc_attr( $item_id ); ?>]" value="1" />
		<?php
		wpff_render_gate_fields(
			'wpff_menu_flag[' . $item_id . ']',
			'wpff_menu_invert[' . $item_id . ']',
			(string) get_post_meta( $item_id, WPFF_META_FLAG, true ),
			(bool) get_post_meta( $item_id, WPFF_META_INVERT, true ),
			'menu-' . $item_id
		);
		?>
	</p>
	<?php
}
add_action( 'wp_nav_menu_item_custom_fields', 'wpff_menu_item_fields', 10, 2 );

/**
 * Save menu item fields — only when the form posted our marker for this item,
 * so programmatic updates (AJAX add, Customizer, imports) never clobber flags.
 *
 * @param int $menu_id         Menu ID.
 * @param int $menu_item_db_id Menu item ID.
 */
function wpff_save_menu_item( $menu_id, $menu_item_db_id ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
	if ( empty( $_POST['wpff_menu_marker'][ $menu_item_db_id ] ) || ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}
	$nonce = isset( $_POST['update-nav-menu-nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['update-nav-menu-nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'update-nav_menu' ) ) {
		return;
	}
	$flag   = isset( $_POST['wpff_menu_flag'][ $menu_item_db_id ] ) ? wpff_submitted_flag( $_POST['wpff_menu_flag'][ $menu_item_db_id ] ) : '';
	$invert = ! empty( $_POST['wpff_menu_invert'][ $menu_item_db_id ] );
	// phpcs:enable
	wpff_save_gate_meta( $menu_item_db_id, $flag, $invert );
}
add_action( 'wp_update_nav_menu_item', 'wpff_save_menu_item', 10, 2 );

/**
 * Drop hidden menu items and all their descendants on the front end.
 *
 * @param WP_Post[] $items Sorted menu items.
 * @return WP_Post[]
 */
function wpff_filter_menu_items( $items ) {
	$hidden = array();
	foreach ( $items as $item ) {
		if ( ! wpff_post_visible( $item->ID ) ) {
			$hidden[ (int) $item->ID ] = true;
		}
	}
	if ( ! $hidden ) {
		return $items;
	}

	// Propagate to descendants until stable (items are usually parent-first, so one pass suffices).
	do {
		$changed = false;
		foreach ( $items as $item ) {
			if ( ! isset( $hidden[ (int) $item->ID ] ) && isset( $hidden[ (int) $item->menu_item_parent ] ) ) {
				$hidden[ (int) $item->ID ] = true;
				$changed                   = true;
			}
		}
	} while ( $changed );

	return array_values(
		array_filter(
			$items,
			function ( $item ) use ( $hidden ) {
				return ! isset( $hidden[ (int) $item->ID ] );
			}
		)
	);
}
add_filter( 'wp_nav_menu_objects', 'wpff_filter_menu_items' );

/*
 * 6.2 Posts / pages / products
 */

/**
 * Post types that get the gate meta box.
 *
 * @return string[]
 */
function wpff_gate_post_types() {
	$types = array( 'page', 'post' );
	if ( class_exists( 'WooCommerce' ) ) {
		$types[] = 'product';
	}
	return (array) apply_filters( 'wpff_gate_post_types', $types );
}

/**
 * Register the side meta box.
 */
function wpff_add_meta_box() {
	add_meta_box( 'wpff-gate', __( 'Feature flag', 'wordpress-feature-flags' ), 'wpff_render_meta_box', wpff_gate_post_types(), 'side' );
}
add_action( 'add_meta_boxes', 'wpff_add_meta_box' );

/**
 * Meta box body.
 *
 * @param WP_Post $post Post being edited.
 */
function wpff_render_meta_box( $post ) {
	wp_nonce_field( 'wpff_save_post_' . $post->ID, 'wpff_post_nonce' );
	wpff_render_gate_fields(
		'wpff_post_flag',
		'wpff_post_invert',
		(string) get_post_meta( $post->ID, WPFF_META_FLAG, true ),
		(bool) get_post_meta( $post->ID, WPFF_META_INVERT, true ),
		'post'
	);
	echo '<p class="description">' . esc_html__( 'Visitors who cannot see the flag are redirected away from this page.', 'wordpress-feature-flags' ) . '</p>';
}

/**
 * Save the meta box: nonce + edit_post + autosave/revision guard; unregistered keys rejected.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post.
 */
function wpff_save_post( $post_id, $post ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, wpff_gate_post_types(), true ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$nonce = isset( $_POST['wpff_post_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wpff_post_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'wpff_save_post_' . $post_id ) ) {
		return;
	}
	$flag   = isset( $_POST['wpff_post_flag'] ) ? wpff_submitted_flag( $_POST['wpff_post_flag'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in wpff_submitted_flag().
	$invert = ! empty( $_POST['wpff_post_invert'] );
	wpff_save_gate_meta( $post_id, $flag, $invert );
}
add_action( 'save_post', 'wpff_save_post', 10, 2 );

/**
 * The post ID behind the current front-end request, if any.
 *
 * @return int
 */
function wpff_current_gated_id() {
	if ( is_singular() ) {
		return (int) get_queried_object_id();
	}
	if ( function_exists( 'is_shop' ) && function_exists( 'wc_get_page_id' ) && is_shop() ) {
		return (int) wc_get_page_id( 'shop' );
	}
	if ( is_home() && ! is_front_page() ) {
		return (int) get_option( 'page_for_posts' );
	}
	return 0;
}

/**
 * Normalise a URL for loop detection: scheme-less, no query, no trailing slash.
 *
 * @param string $url URL.
 * @return string
 */
function wpff_normalise_url( $url ) {
	$parts = wp_parse_url( $url );
	$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
	$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
	return $host . '/' . trim( $path, '/' );
}

/**
 * Redirect visitors away from posts they cannot see; 404 instead of looping.
 */
function wpff_gate_request() {
	$post_id = wpff_current_gated_id();
	if ( ! $post_id || wpff_post_visible( $post_id ) ) {
		return;
	}

	nocache_headers();

	$flag   = wpff_get_flag( (string) get_post_meta( $post_id, WPFF_META_FLAG, true ) );
	$target = $flag && '' !== $flag['redirect'] ? $flag['redirect'] : home_url( '/' );
	if ( 0 === strpos( $target, '/' ) && 0 !== strpos( $target, '//' ) ) {
		$target = home_url( $target );
	}

	$target = wp_validate_redirect( $target, home_url( '/' ) );

	// Redirecting to ourselves, or away from a hidden front page (whose default target is itself), would loop.
	$current = home_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
	if ( wpff_normalise_url( $current ) === wpff_normalise_url( $target ) || is_front_page() ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		return;
	}

	wp_redirect( $target, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated above with a home fallback.
	exit;
}
add_action( 'template_redirect', 'wpff_gate_request', 5 );

/*
 * 6.3 Shortcode
 */

/**
 * [feature_flag name="store"]…[/feature_flag] and [feature_flag name="store" show="off"]…[/feature_flag].
 *
 * @param array|string $atts    Attributes.
 * @param string|null  $content Enclosed content.
 * @return string
 */
function wpff_shortcode( $atts, $content = null ) {
	$atts = shortcode_atts(
		array(
			'name' => '',
			'show' => 'on',
		),
		$atts,
		'feature_flag'
	);
	if ( null === $content || ! wpff_visible( (string) $atts['name'], 'off' === $atts['show'] ) ) {
		return '';
	}
	return do_shortcode( $content );
}
add_shortcode( 'feature_flag', 'wpff_shortcode' );

/*
 * 6.4 / 6.5 Class convention: ff--<key> (only when on), ff-not--<key> (only when off)
 */

/**
 * Evaluate ff--/ff-not-- classes in a class string. All conditions must pass.
 *
 * @param mixed $classes Space-separated class list.
 * @return bool|null Null when no flag classes are present (leave untouched).
 */
function wpff_classes_visible( $classes ) {
	if ( ! is_string( $classes ) || false === strpos( $classes, 'ff-' ) ) {
		return null;
	}
	$found = false;
	foreach ( preg_split( '/\s+/', trim( $classes ) ) as $class ) {
		if ( 0 === strpos( $class, 'ff--' ) ) {
			$found = true;
			if ( ! wpff_visible( substr( $class, 4 ) ) ) {
				return false;
			}
		} elseif ( 0 === strpos( $class, 'ff-not--' ) ) {
			$found = true;
			if ( ! wpff_visible( substr( $class, 8 ), true ) ) {
				return false;
			}
		}
	}
	return $found ? true : null;
}

/**
 * Strip Gutenberg blocks whose Additional CSS class gates them.
 *
 * @param string $content Rendered block.
 * @param array  $block   Parsed block.
 * @return string
 */
function wpff_render_block( $content, $block ) {
	if ( empty( $block['attrs']['className'] ) ) {
		return $content;
	}
	return false === wpff_classes_visible( $block['attrs']['className'] ) ? '' : $content;
}
add_filter( 'render_block', 'wpff_render_block', 10, 2 );

/**
 * Elementor should_render gate. Widgets keep custom classes in `_css_classes`,
 * sections/columns/containers in `css_classes`.
 *
 * Verified against: NOT YET — confirm the hook names and setting keys against the
 * installed Elementor version before relying on this; fall back to the shortcode if they've moved.
 *
 * @param bool   $should_render Whether Elementor will render the element.
 * @param object $element       Elementor element.
 * @return bool
 */
function wpff_elementor_should_render( $should_render, $element ) {
	if ( ! $should_render || ! is_object( $element ) || ! method_exists( $element, 'get_settings' ) ) {
		return $should_render;
	}
	// Keep everything visible inside the Elementor editor so gated elements can still be edited.
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
		return $should_render;
	}
	$classes = trim( $element->get_settings( '_css_classes' ) . ' ' . $element->get_settings( 'css_classes' ) );
	return false !== wpff_classes_visible( $classes );
}
foreach ( array( 'widget', 'section', 'column', 'container' ) as $wpff_element_type ) {
	add_filter( "elementor/frontend/{$wpff_element_type}/should_render", 'wpff_elementor_should_render', 10, 2 );
}
unset( $wpff_element_type );

/*
 * 6.6 Body classes
 */

/**
 * Add ff-on--<key> / ff-off--<key> for every registered flag.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function wpff_body_class( $classes ) {
	foreach ( array_keys( wpff_flags() ) as $key ) {
		$classes[] = sanitize_html_class( ( wpff_flag( $key ) ? 'ff-on--' : 'ff-off--' ) . $key );
	}
	return $classes;
}
add_filter( 'body_class', 'wpff_body_class' );
