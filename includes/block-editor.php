<?php
/**
 * Block editor support:
 * - a "Feature flag" sidebar panel on every block that supports custom classes. It
 *   writes the ff--<key> / ff-not--<key> class that wpff_render_block() enforces;
 * - the wpff/feature-flag wrapper block, a dynamic block that renders its inner
 *   blocks only when the gate passes.
 *
 * @package wordpress-feature-flags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the editor script and the wrapper block.
 */
function wpff_register_block() {
	wp_register_script(
		'wpff-block-editor',
		plugins_url( 'assets/block-editor.js', WPFF_FILE ),
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-element', 'wp-hooks', 'wp-i18n' ),
		WPFF_VERSION,
		true
	);
	wp_set_script_translations( 'wpff-block-editor', 'wordpress-feature-flags' );

	register_block_type(
		'wpff/feature-flag',
		array(
			'editor_script'   => 'wpff-block-editor',
			'attributes'      => array(
				'flag'   => array(
					'type'    => 'string',
					'default' => '',
				),
				'invert' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'render_callback' => 'wpff_render_flag_block',
		)
	);
}
add_action( 'init', 'wpff_register_block' );

/**
 * Render the wrapper block: its inner blocks when the gate passes, else nothing.
 * A block with no flag picked yet is not a gate and renders its content.
 *
 * @param array  $attributes Block attributes.
 * @param string $content    Rendered inner blocks.
 * @return string
 */
function wpff_render_flag_block( $attributes, $content ) {
	$flag = isset( $attributes['flag'] ) ? (string) $attributes['flag'] : '';
	if ( '' === $flag ) {
		return $content;
	}
	return wpff_visible( $flag, ! empty( $attributes['invert'] ) ) ? $content : '';
}

/**
 * Enqueue the editor script with the registered flags.
 */
function wpff_enqueue_block_editor() {
	wp_enqueue_script( 'wpff-block-editor' );

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
}
add_action( 'enqueue_block_editor_assets', 'wpff_enqueue_block_editor' );
