<?php
/**
 * Tools → Feature flags.
 *
 * @package wordpress-feature-flags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Tools page.
 */
function wpff_admin_menu() {
	add_management_page(
		__( 'Feature flags', 'wordpress-feature-flags' ),
		__( 'Feature flags', 'wordpress-feature-flags' ),
		'manage_options',
		'wpff-flags',
		'wpff_render_admin_page'
	);
}
add_action( 'admin_menu', 'wpff_admin_menu' );

/**
 * URL of the Tools page.
 *
 * @return string
 */
function wpff_admin_url() {
	return admin_url( 'tools.php?page=wpff-flags' );
}

/**
 * Store an admin-set mode and fire wpff_mode_changed.
 *
 * @param string $key  Registered flag key.
 * @param string $mode One of wpff_modes().
 */
function wpff_set_admin_mode( $key, $mode ) {
	$saved = get_option( WPFF_OPTION, array() );
	$saved = is_array( $saved ) ? $saved : array();
	$old   = isset( $saved[ $key ] ) ? $saved[ $key ] : null;

	$saved[ $key ] = $mode;
	if ( false === get_option( WPFF_OPTION, false ) ) {
		add_option( WPFF_OPTION, $saved, '', 'no' );
	} else {
		update_option( WPFF_OPTION, $saved, false );
	}

	/**
	 * Fires after an admin changes a flag's mode. Integrations purge caches here.
	 *
	 * @param string      $key  Flag key.
	 * @param string      $mode New admin-set mode.
	 * @param string|null $old  Previous admin-set mode, null if none.
	 */
	do_action( 'wpff_mode_changed', $key, $mode, $old );
}

/**
 * Redirect back to Tools → Feature flags with a result code.
 *
 * @param string $result Result code shown as a notice.
 */
function wpff_admin_redirect( $result ) {
	wp_safe_redirect( add_query_arg( 'wpff-result', $result, wpff_admin_url() ) );
	exit;
}

/**
 * Save an admin-set mode (admin_toggle flags only).
 */
function wpff_handle_save_mode() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wordpress-feature-flags' ), 403 );
	}
	check_admin_referer( 'wpff_save_mode' );

	$key  = isset( $_POST['wpff_key'] ) ? sanitize_key( wp_unslash( $_POST['wpff_key'] ) ) : '';
	$mode = isset( $_POST['wpff_mode'] ) ? sanitize_key( wp_unslash( $_POST['wpff_mode'] ) ) : '';
	$flag = wpff_get_flag( $key );

	if ( ! $flag || ! $flag['admin_toggle'] || ! in_array( $mode, wpff_modes(), true ) ) {
		wpff_admin_redirect( 'error' );
	}
	wpff_set_admin_mode( $key, $mode );
	wpff_admin_redirect( 'saved' );
}
add_action( 'admin_post_wpff_save_mode', 'wpff_handle_save_mode' );

/**
 * Flag keys referenced in post content: ff--<key> / ff-not--<key> block classes
 * and wpff/feature-flag wrapper blocks.
 *
 * @param string $content Post content.
 * @return string[]
 */
function wpff_flags_in_content( $content ) {
	$keys = array();
	if ( preg_match_all( '/(?<![\w-])ff(?:-not)?--([a-z0-9][a-z0-9_-]{0,39})/', $content, $m ) ) {
		$keys = $m[1];
	}
	if ( false !== strpos( $content, 'wp:wpff/feature-flag' ) ) {
		$keys = array_merge( $keys, wpff_flags_in_blocks( parse_blocks( $content ) ) );
	}
	return array_values( array_unique( $keys ) );
}

/**
 * Flag keys set on wpff/feature-flag blocks, recursively.
 *
 * @param array $blocks Parsed blocks.
 * @return string[]
 */
function wpff_flags_in_blocks( $blocks ) {
	$keys = array();
	foreach ( $blocks as $block ) {
		if ( 'wpff/feature-flag' === $block['blockName'] && ! empty( $block['attrs']['flag'] ) && is_string( $block['attrs']['flag'] ) ) {
			$keys[] = $block['attrs']['flag'];
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$keys = array_merge( $keys, wpff_flags_in_blocks( $block['innerBlocks'] ) );
		}
	}
	return $keys;
}

/**
 * Every post, menu item or piece of block content using a flag, grouped by
 * flag key (any status except revisions and auto-drafts).
 *
 * @return array<string, int[]>
 */
function wpff_flag_usage() {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- admin-only report, one query per source for all flags.
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", WPFF_META_FLAG ) );
	$posts = $wpdb->get_results(
		"SELECT ID, post_content FROM {$wpdb->posts}
		WHERE post_type <> 'revision' AND post_status NOT IN ( 'auto-draft', 'inherit' )
		AND ( post_content LIKE '%ff--%' OR post_content LIKE '%ff-not--%' OR post_content LIKE '%wp:wpff/feature-flag%' )"
	);
	// phpcs:enable

	$usage = array();
	foreach ( $rows as $row ) {
		$usage[ $row->meta_value ][] = (int) $row->post_id;
	}
	foreach ( $posts as $post ) {
		foreach ( wpff_flags_in_content( $post->post_content ) as $key ) {
			$usage[ $key ][] = (int) $post->ID;
		}
	}
	foreach ( $usage as $key => $ids ) {
		$usage[ $key ] = array_values( array_unique( $ids ) );
	}
	if ( $usage ) {
		_prime_post_caches( array_unique( array_merge( ...array_values( $usage ) ) ), false, true );
	}
	return $usage;
}

/**
 * Render the "where used" list for a set of post IDs. Posts may use a flag through
 * the page-level field, block classes or wrapper blocks.
 *
 * @param int[] $post_ids Post / menu item IDs.
 */
function wpff_render_usage( $post_ids ) {
	if ( ! $post_ids ) {
		echo '&mdash;';
		return;
	}
	echo '<ul style="margin:0">';
	foreach ( $post_ids as $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			continue;
		}
		$invert = get_post_meta( $post_id, WPFF_META_INVERT, true ) ? ' <em>' . esc_html__( '(only while off)', 'wordpress-feature-flags' ) . '</em>' : '';
		if ( 'nav_menu_item' === $post->post_type ) {
			$item  = wp_setup_nav_menu_item( $post );
			$menus = wp_get_object_terms( $post_id, 'nav_menu', array( 'fields' => 'ids' ) );
			$link  = ! is_wp_error( $menus ) && $menus ? admin_url( 'nav-menus.php?action=edit&menu=' . (int) $menus[0] ) : admin_url( 'nav-menus.php' );
			/* translators: %s: menu item title */
			$label = sprintf( __( 'Menu item: %s', 'wordpress-feature-flags' ), $item->title );
		} else {
			$link  = get_edit_post_link( $post_id );
			$type  = get_post_type_object( $post->post_type );
			$label = ( $type ? $type->labels->singular_name : $post->post_type ) . ': ' . ( '' !== $post->post_title ? $post->post_title : '#' . $post_id ) . ' (' . $post->post_status . ')';
		}
		if ( $link ) {
			echo '<li><a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>' . $invert . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $invert built from escaped parts.
		} else {
			echo '<li>' . esc_html( $label ) . $invert . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $invert built from escaped parts.
		}
	}
	echo '</ul>';
}

/**
 * Format a flag's schedule window for display.
 *
 * @param array $flag Registered flag.
 * @return string
 */
function wpff_window_label( $flag ) {
	if ( '' === $flag['starts'] && '' === $flag['ends'] ) {
		return __( 'none', 'wordpress-feature-flags' );
	}
	$states = array(
		'before' => __( 'before window', 'wordpress-feature-flags' ),
		'within' => __( 'within window', 'wordpress-feature-flags' ),
		'after'  => __( 'after window', 'wordpress-feature-flags' ),
	);
	$state = wpff_window_state( $flag['key'] );
	return sprintf(
		'%s → %s (%s)',
		'' !== $flag['starts'] ? $flag['starts'] : '…',
		'' !== $flag['ends'] ? $flag['ends'] : '…',
		isset( $states[ $state ] ) ? $states[ $state ] : $state
	);
}

/**
 * Render Tools → Feature flags.
 */
function wpff_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$flags = wpff_flags();
	$usage = wpff_flag_usage();
	$yes   = __( 'Yes', 'wordpress-feature-flags' );
	$no    = __( 'No', 'wordpress-feature-flags' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Feature flags', 'wordpress-feature-flags' ); ?></h1>

		<?php
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$result  = isset( $_GET['wpff-result'] ) ? sanitize_key( wp_unslash( $_GET['wpff-result'] ) ) : '';
		$editing = isset( $_GET['wpff-edit'] ) ? sanitize_key( wp_unslash( $_GET['wpff-edit'] ) ) : '';
		// phpcs:enable
		$editing  = wpff_is_ui_flag( $editing ) ? $editing : '';
		$messages = array(
			'saved'        => array( 'success', __( 'Flag mode saved.', 'wordpress-feature-flags' ) ),
			'added'        => array( 'success', __( 'Flag added.', 'wordpress-feature-flags' ) ),
			'updated'      => array( 'success', __( 'Flag updated.', 'wordpress-feature-flags' ) ),
			'deleted'      => array( 'success', __( 'Flag deleted.', 'wordpress-feature-flags' ) ),
			'exists'       => array( 'error', __( 'A flag with that key already exists.', 'wordpress-feature-flags' ) ),
			'invalid-key'  => array( 'error', __( 'Invalid key: use lowercase letters, numbers, dashes and underscores (max 40), not starting with "not-".', 'wordpress-feature-flags' ) ),
			'invalid-date' => array( 'error', __( 'Invalid schedule date.', 'wordpress-feature-flags' ) ),
			'in-use'       => array( 'error', __( 'That flag is still used by pages or menu items. Clear them first, or they will be hidden.', 'wordpress-feature-flags' ) ),
			'error'        => array( 'error', __( 'Nothing was saved.', 'wordpress-feature-flags' ) ),
		);
		?>
		<?php if ( isset( $messages[ $result ] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $messages[ $result ][0] ); ?> is-dismissible"><p><?php echo esc_html( $messages[ $result ][1] ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! $flags ) : ?>
			<p><?php esc_html_e( 'No feature flags yet. Add one below, or register flags in code on the wpff_register action.', 'wordpress-feature-flags' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Flag', 'wordpress-feature-flags' ); ?></th>
						<th><?php esc_html_e( 'Mode (source)', 'wordpress-feature-flags' ); ?></th>
						<th><?php esc_html_e( 'Window', 'wordpress-feature-flags' ); ?></th>
						<th><?php esc_html_e( 'Live for public?', 'wordpress-feature-flags' ); ?></th>
						<th><?php esc_html_e( 'What you see now', 'wordpress-feature-flags' ); ?></th>
						<th><?php esc_html_e( 'Preview link', 'wordpress-feature-flags' ); ?></th>
						<th><?php esc_html_e( 'Where used', 'wordpress-feature-flags' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $flags as $key => $flag ) : ?>
					<?php list( $mode, $source ) = wpff_mode( $key ); ?>
					<tr>
						<td>
							<strong><code><?php echo esc_html( $key ); ?></code></strong><br />
							<?php echo esc_html( $flag['label'] ); ?>
							<?php if ( '' !== $flag['owner'] ) : ?>
								<br /><small><?php echo esc_html( sprintf( /* translators: %s: owner */ __( 'Owner: %s', 'wordpress-feature-flags' ), $flag['owner'] ) ); ?></small>
							<?php endif; ?>
							<?php if ( '' !== $flag['description'] ) : ?>
								<p class="description"><?php echo esc_html( $flag['description'] ); ?></p>
							<?php endif; ?>
						</td>
						<td>
							<strong><?php echo esc_html( $mode ); ?></strong> <small>(<?php echo esc_html( $source ); ?>)</small>
							<?php if ( $flag['admin_toggle'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px">
									<?php wp_nonce_field( 'wpff_save_mode' ); ?>
									<input type="hidden" name="action" value="wpff_save_mode" />
									<input type="hidden" name="wpff_key" value="<?php echo esc_attr( $key ); ?>" />
									<select name="wpff_mode" aria-label="<?php esc_attr_e( 'Admin-set mode', 'wordpress-feature-flags' ); ?>">
										<?php foreach ( wpff_modes() as $option ) : ?>
											<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $mode, $option ); ?>><?php echo esc_html( $option ); ?></option>
										<?php endforeach; ?>
									</select>
									<?php submit_button( __( 'Save', 'wordpress-feature-flags' ), 'small', 'submit', false ); ?>
									<?php if ( 0 === strpos( $source, 'constant:' ) ) : ?>
										<br /><small><?php esc_html_e( 'A constant currently overrides the admin setting.', 'wordpress-feature-flags' ); ?></small>
									<?php endif; ?>
								</form>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( wpff_window_label( $flag ) ); ?></td>
						<td><?php echo esc_html( wpff_is_live( $key ) ? $yes : $no ); ?></td>
						<td><?php echo esc_html( wpff_flag( $key ) ? __( 'Visible', 'wordpress-feature-flags' ) : __( 'Hidden', 'wordpress-feature-flags' ) ); ?></td>
						<td>
							<?php $preview = wpff_preview_url( $key ); ?>
							<?php if ( '' !== $preview ) : ?>
								<input type="text" readonly class="regular-text" value="<?php echo esc_attr( $preview ); ?>" /><br />
								<a href="<?php echo esc_url( add_query_arg( WPFF_QUERY_ARG, $key . ':off', home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Clear preview in this browser', 'wordpress-feature-flags' ); ?></a>
							<?php else : ?>
								<small><?php echo esc_html( sprintf( /* translators: %s: constant name */ __( 'Disabled — define %s or WPFF_PREVIEW_TOKEN (12+ chars).', 'wordpress-feature-flags' ), wpff_constant_name( $key ) . '_TOKEN' ) ); ?></small>
							<?php endif; ?>
						</td>
						<td>
							<?php wpff_render_usage( isset( $usage[ $key ] ) ? $usage[ $key ] : array() ); ?>
							<?php if ( wpff_is_ui_flag( $key ) ) : ?>
								<?php wpff_render_ui_flag_actions( $key, ! empty( $usage[ $key ] ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php $stale = array_diff_key( $usage, $flags ); ?>
		<?php if ( $stale ) : ?>
			<h2><?php esc_html_e( 'Stale references', 'wordpress-feature-flags' ); ?></h2>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'These items reference flags that are not registered — via the page field, a menu item, a block\'s Feature flag panel or a Feature flag block. Unregistered flags are off, so that content is currently hidden (or, if set to "show only while off", shown). Clear the reference or register the flag.', 'wordpress-feature-flags' ); ?></p>
			</div>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Unregistered key', 'wordpress-feature-flags' ); ?></th>
						<th><?php esc_html_e( 'Where used', 'wordpress-feature-flags' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $stale as $key => $post_ids ) : ?>
					<tr>
						<td><code><?php echo esc_html( $key ); ?></code></td>
						<td><?php wpff_render_usage( $post_ids ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2 id="wpff-flag-form">
			<?php
			echo esc_html(
				'' !== $editing
					/* translators: %s: flag key */
					? sprintf( __( 'Edit flag: %s', 'wordpress-feature-flags' ), $editing )
					: __( 'Add a flag', 'wordpress-feature-flags' )
			);
			?>
		</h2>
		<p><?php esc_html_e( 'Flags added here can be picked on pages, blocks and menu items straight away. Flags registered in code by plugins and themes are listed above too.', 'wordpress-feature-flags' ); ?></p>
		<?php wpff_render_ui_flag_form( $editing ); ?>
	</div>
	<?php
}
