<?php
/**
 * Front-end admin bar: per-flag status plus the "view as public" toggle.
 *
 * @package wordpress-feature-flags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add the "Flags" node.
 *
 * @param WP_Admin_Bar $bar Admin bar.
 */
function wpff_admin_bar( $bar ) {
	if ( is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$public = wpff_viewing_as_public();

	$bar->add_node(
		array(
			'id'    => 'wpff',
			'title' => $public ? __( 'Flags (public view)', 'wordpress-feature-flags' ) : __( 'Flags', 'wordpress-feature-flags' ),
			'href'  => wpff_admin_url(),
		)
	);

	foreach ( wpff_flags() as $key => $flag ) {
		$seen = wpff_flag( $key ) ? __( 'you see it', 'wordpress-feature-flags' ) : __( 'hidden', 'wordpress-feature-flags' );
		$bar->add_node(
			array(
				'parent' => 'wpff',
				'id'     => 'wpff-flag-' . $key,
				/* translators: 1: flag key, 2: mode, 3: "you see it" / "hidden" */
				'title'  => esc_html( sprintf( __( '%1$s: %2$s — %3$s', 'wordpress-feature-flags' ), $key, wpff_effective_mode( $key ), $seen ) ),
			)
		);
	}

	$bar->add_node(
		array(
			'parent' => 'wpff',
			'id'     => 'wpff-view-public',
			'title'  => $public ? __( 'Back to admin view', 'wordpress-feature-flags' ) : __( 'View as public visitor', 'wordpress-feature-flags' ),
			'href'   => wpff_view_public_url( ! $public ),
		)
	);
}
add_action( 'admin_bar_menu', 'wpff_admin_bar', 100 );
