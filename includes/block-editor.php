<?php
/**
 * Block editor sidebar: a "Feature flag" panel on every block that supports
 * custom classes. It writes the ff--<key> / ff-not--<key> class that
 * wpff_render_block() already enforces, so no new block attributes are needed.
 *
 * @package wordpress-feature-flags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the panel script with the registered flags.
 */
function wpff_enqueue_block_editor() {
	wp_enqueue_script(
		'wpff-block-editor',
		plugins_url( 'assets/block-editor.js', WPFF_FILE ),
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-element', 'wp-hooks', 'wp-i18n' ),
		WPFF_VERSION,
		true
	);

	$flags = array();
	foreach ( wpff_flags() as $key => $flag ) {
		$flags[] = array(
			'key'   => $key,
			'label' => $flag['label'],
			'mode'  => wpff_effective_mode( $key ),
		);
	}
	wp_add_inline_script(
		'wpff-block-editor',
		'var wpffBlockEditor = ' . wp_json_encode(
			array(
				'flags'    => $flags,
				'adminUrl' => current_user_can( 'manage_options' ) ? wpff_admin_url() : '',
			)
		) . ';',
		'before'
	);
	wp_set_script_translations( 'wpff-block-editor', 'wordpress-feature-flags' );
}
add_action( 'enqueue_block_editor_assets', 'wpff_enqueue_block_editor' );
