<?php
/**
 * Uninstall: delete the admin-toggle and admin-created flag options. Post and menu-item meta
 * (_wpff_flag, _wpff_flag_invert) is deliberately left in place.
 *
 * @package wordpress-feature-flags
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wpff_modes' );
delete_option( 'wpff_ui_flags' );
