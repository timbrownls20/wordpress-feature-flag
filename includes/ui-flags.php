<?php
/**
 * Flags created in Tools → Feature flags (no code). Stored in the
 * `wpff_ui_flags` option and registered on `wpff_register` after code flags,
 * always with admin_toggle so their mode is set from the same screen.
 *
 * @package wordpress-feature-flags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPFF_UI_OPTION', 'wpff_ui_flags' );

/**
 * Stored UI flag definitions, keyed by flag key.
 *
 * @return array<string, array>
 */
function wpff_ui_flags() {
	$flags = get_option( WPFF_UI_OPTION, array() );
	return is_array( $flags ) ? $flags : array();
}

/**
 * Whether a key belongs to a flag created in the admin UI.
 *
 * @param string $key Flag key.
 * @return bool
 */
function wpff_is_ui_flag( $key ) {
	return isset( wpff_ui_flags()[ $key ] );
}

/**
 * Preview audiences offered for UI flags (capability => label).
 *
 * @return array<string, string>
 */
function wpff_ui_preview_caps() {
	return array(
		'manage_options'    => __( 'Administrators', 'wordpress-feature-flags' ),
		'edit_others_posts' => __( 'Editors and above', 'wordpress-feature-flags' ),
		'edit_posts'        => __( 'Contributors and above', 'wordpress-feature-flags' ),
	);
}

/**
 * Register UI flags. Runs late so a code registration of the same key wins.
 */
function wpff_register_ui_flags() {
	foreach ( wpff_ui_flags() as $key => $flag ) {
		if ( isset( WPFF_Registry::$flags[ $key ] ) ) {
			continue;
		}
		wpff_register(
			$key,
			array(
				'label'        => isset( $flag['label'] ) ? $flag['label'] : $key,
				'description'  => isset( $flag['description'] ) ? $flag['description'] : '',
				'owner'        => __( 'Admin UI', 'wordpress-feature-flags' ),
				'default'      => 'off',
				'admin_toggle' => true,
				'starts'       => isset( $flag['starts'] ) ? $flag['starts'] : '',
				'ends'         => isset( $flag['ends'] ) ? $flag['ends'] : '',
				'redirect'     => isset( $flag['redirect'] ) ? $flag['redirect'] : '',
				'preview_cap'  => isset( $flag['preview_cap'] ) ? $flag['preview_cap'] : 'manage_options',
			)
		);
	}
}
add_action( 'wpff_register', 'wpff_register_ui_flags', 100 );

/**
 * Turn a datetime-local value (Y-m-dTH:i) into the registry's Y-m-d H:i.
 *
 * @param string $value Submitted value.
 * @return string|null '' when empty, null when invalid.
 */
function wpff_ui_date( $value ) {
	$value = str_replace( 'T', ' ', trim( $value ) );
	if ( '' === $value ) {
		return '';
	}
	$value = substr( $value, 0, 16 ); // Drop seconds if the browser sent them.
	return null === wpff_parse_time( $value ) ? null : $value;
}

/**
 * Create or update a UI flag.
 */
function wpff_handle_save_ui_flag() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wordpress-feature-flags' ), 403 );
	}
	check_admin_referer( 'wpff_save_ui_flag' );

	$is_new = empty( $_POST['wpff_editing'] );
	$key    = isset( $_POST['wpff_key'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['wpff_key'] ) ) ) ) : '';

	if ( ! wpff_valid_key( $key ) ) {
		wpff_admin_redirect( 'invalid-key' );
	}
	if ( $is_new && wpff_get_flag( $key ) ) {
		wpff_admin_redirect( 'exists' );
	}
	if ( ! $is_new && ! wpff_is_ui_flag( $key ) ) {
		wpff_admin_redirect( 'error' );
	}

	$starts = wpff_ui_date( isset( $_POST['wpff_starts'] ) ? sanitize_text_field( wp_unslash( $_POST['wpff_starts'] ) ) : '' );
	$ends   = wpff_ui_date( isset( $_POST['wpff_ends'] ) ? sanitize_text_field( wp_unslash( $_POST['wpff_ends'] ) ) : '' );
	if ( null === $starts || null === $ends ) {
		wpff_admin_redirect( 'invalid-date' );
	}

	$redirect = isset( $_POST['wpff_redirect'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wpff_redirect'] ) ) ) : '';
	if ( '' !== $redirect && 0 !== strpos( $redirect, '/' ) ) {
		$redirect = esc_url_raw( $redirect );
	}

	$cap = isset( $_POST['wpff_preview_cap'] ) ? sanitize_key( wp_unslash( $_POST['wpff_preview_cap'] ) ) : '';
	if ( ! isset( wpff_ui_preview_caps()[ $cap ] ) ) {
		$cap = 'manage_options';
	}

	$label = isset( $_POST['wpff_label'] ) ? sanitize_text_field( wp_unslash( $_POST['wpff_label'] ) ) : '';

	$flags         = wpff_ui_flags();
	$flags[ $key ] = array(
		'label'       => '' !== $label ? $label : $key,
		'description' => isset( $_POST['wpff_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wpff_description'] ) ) : '',
		'starts'      => $starts,
		'ends'        => $ends,
		'redirect'    => $redirect,
		'preview_cap' => $cap,
	);
	update_option( WPFF_UI_OPTION, $flags );

	if ( $is_new ) {
		$mode = isset( $_POST['wpff_mode'] ) ? sanitize_key( wp_unslash( $_POST['wpff_mode'] ) ) : 'off';
		wpff_set_admin_mode( $key, in_array( $mode, wpff_modes(), true ) ? $mode : 'off' );
		wpff_admin_redirect( 'added' );
	}

	// Window, redirect or audience may have changed what visitors see.
	do_action( 'wpff_mode_changed', $key, wpff_mode( $key )[0], null );
	wpff_admin_redirect( 'updated' );
}
add_action( 'admin_post_wpff_save_ui_flag', 'wpff_handle_save_ui_flag' );

/**
 * Delete a UI flag. Refused while posts or menu items still reference it,
 * because deleting it would hide them all.
 */
function wpff_handle_delete_ui_flag() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wordpress-feature-flags' ), 403 );
	}
	check_admin_referer( 'wpff_delete_ui_flag' );

	$key   = isset( $_POST['wpff_key'] ) ? sanitize_key( wp_unslash( $_POST['wpff_key'] ) ) : '';
	$flags = wpff_ui_flags();
	if ( ! isset( $flags[ $key ] ) ) {
		wpff_admin_redirect( 'error' );
	}
	$usage = wpff_flag_usage();
	if ( ! empty( $usage[ $key ] ) ) {
		wpff_admin_redirect( 'in-use' );
	}

	unset( $flags[ $key ] );
	update_option( WPFF_UI_OPTION, $flags );

	$modes = get_option( WPFF_OPTION, array() );
	if ( is_array( $modes ) && isset( $modes[ $key ] ) ) {
		unset( $modes[ $key ] );
		update_option( WPFF_OPTION, $modes, false );
	}

	do_action( 'wpff_mode_changed', $key, 'off', null );
	wpff_admin_redirect( 'deleted' );
}
add_action( 'admin_post_wpff_delete_ui_flag', 'wpff_handle_delete_ui_flag' );

/**
 * Render the add / edit form for a UI flag.
 *
 * @param string $editing Key being edited, '' to add a new flag.
 */
function wpff_render_ui_flag_form( $editing = '' ) {
	$flags = wpff_ui_flags();
	$flag  = wp_parse_args(
		'' !== $editing && isset( $flags[ $editing ] ) ? $flags[ $editing ] : array(),
		array(
			'label'       => '',
			'description' => '',
			'starts'      => '',
			'ends'        => '',
			'redirect'    => '',
			'preview_cap' => 'manage_options',
		)
	);
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'wpff_save_ui_flag' ); ?>
		<input type="hidden" name="action" value="wpff_save_ui_flag" />
		<?php if ( '' !== $editing ) : ?>
			<input type="hidden" name="wpff_editing" value="1" />
			<input type="hidden" name="wpff_key" value="<?php echo esc_attr( $editing ); ?>" />
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wpff-new-key"><?php esc_html_e( 'Key', 'wordpress-feature-flags' ); ?></label></th>
				<td>
					<?php if ( '' !== $editing ) : ?>
						<code><?php echo esc_html( $editing ); ?></code>
					<?php else : ?>
						<input type="text" id="wpff-new-key" name="wpff_key" class="regular-text" required maxlength="40" pattern="[a-z0-9][a-z0-9_\-]*" placeholder="new-checkout" />
						<p class="description"><?php esc_html_e( 'Lowercase letters, numbers, dashes and underscores; max 40 characters; cannot start with "not-". Cannot be changed later.', 'wordpress-feature-flags' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpff-new-label"><?php esc_html_e( 'Label', 'wordpress-feature-flags' ); ?></label></th>
				<td><input type="text" id="wpff-new-label" name="wpff_label" class="regular-text" value="<?php echo esc_attr( $flag['label'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="wpff-new-description"><?php esc_html_e( 'Description', 'wordpress-feature-flags' ); ?></label></th>
				<td><textarea id="wpff-new-description" name="wpff_description" class="large-text" rows="2"><?php echo esc_textarea( $flag['description'] ); ?></textarea></td>
			</tr>
			<?php if ( '' === $editing ) : ?>
				<tr>
					<th scope="row"><label for="wpff-new-mode"><?php esc_html_e( 'Mode', 'wordpress-feature-flags' ); ?></label></th>
					<td>
						<select id="wpff-new-mode" name="wpff_mode">
							<option value="off"><?php esc_html_e( 'off — hidden from everyone', 'wordpress-feature-flags' ); ?></option>
							<option value="preview" selected><?php esc_html_e( 'preview — visible to the preview audience and preview-link holders', 'wordpress-feature-flags' ); ?></option>
							<option value="on"><?php esc_html_e( 'on — visible to everyone', 'wordpress-feature-flags' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Change it any time from the flags table.', 'wordpress-feature-flags' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><label for="wpff-new-cap"><?php esc_html_e( 'Preview audience', 'wordpress-feature-flags' ); ?></label></th>
				<td>
					<select id="wpff-new-cap" name="wpff_preview_cap">
						<?php foreach ( wpff_ui_preview_caps() as $cap => $cap_label ) : ?>
							<option value="<?php echo esc_attr( $cap ); ?>" <?php selected( $flag['preview_cap'], $cap ); ?>><?php echo esc_html( $cap_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Logged-in users who see the flag while it is in preview mode.', 'wordpress-feature-flags' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Schedule (optional)', 'wordpress-feature-flags' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Starts', 'wordpress-feature-flags' ); ?> <input type="datetime-local" name="wpff_starts" value="<?php echo esc_attr( str_replace( ' ', 'T', $flag['starts'] ) ); ?>" /></label>
					<label><?php esc_html_e( 'Ends', 'wordpress-feature-flags' ); ?> <input type="datetime-local" name="wpff_ends" value="<?php echo esc_attr( str_replace( ' ', 'T', $flag['ends'] ) ); ?>" /></label>
					<p class="description"><?php esc_html_e( 'Site timezone. When the mode is "on", the flag is only public inside this window; outside it, it behaves as preview.', 'wordpress-feature-flags' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpff-new-redirect"><?php esc_html_e( 'Redirect hidden pages to', 'wordpress-feature-flags' ); ?></label></th>
				<td>
					<input type="text" id="wpff-new-redirect" name="wpff_redirect" class="regular-text" value="<?php echo esc_attr( $flag['redirect'] ); ?>" placeholder="/coming-soon/" />
					<p class="description"><?php esc_html_e( 'A path or URL on this site. Leave blank for the home page.', 'wordpress-feature-flags' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( '' !== $editing ? __( 'Update flag', 'wordpress-feature-flags' ) : __( 'Add flag', 'wordpress-feature-flags' ), 'primary', 'submit', false ); ?>
		<?php if ( '' !== $editing ) : ?>
			<a class="button" href="<?php echo esc_url( wpff_admin_url() ); ?>"><?php esc_html_e( 'Cancel', 'wordpress-feature-flags' ); ?></a>
		<?php endif; ?>
	</form>
	<?php
}

/**
 * Edit / delete controls for a UI flag row.
 *
 * @param string $key   Flag key.
 * @param bool   $inuse Whether posts or menu items reference it.
 */
function wpff_render_ui_flag_actions( $key, $inuse ) {
	?>
	<p style="margin-top:6px">
		<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'wpff-edit', $key, wpff_admin_url() ) . '#wpff-flag-form' ); ?>"><?php esc_html_e( 'Edit', 'wordpress-feature-flags' ); ?></a>
	</p>
	<?php if ( $inuse ) : ?>
		<small><?php esc_html_e( 'Clear "Where used" before deleting.', 'wordpress-feature-flags' ); ?></small>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wpff_delete_ui_flag' ); ?>
			<input type="hidden" name="action" value="wpff_delete_ui_flag" />
			<input type="hidden" name="wpff_key" value="<?php echo esc_attr( $key ); ?>" />
			<?php submit_button( __( 'Delete', 'wordpress-feature-flags' ), 'small delete', 'submit', false ); ?>
		</form>
	<?php endif; ?>
	<?php
}
