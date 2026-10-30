<?php
/**
 * Plugin Name:       WordPress Feature Flags
 * Description:       Feature flags for WordPress: hide menu items, pages, blocks and code paths until a flag goes live, with admin and secret-link preview.
 * Version:           1.0.0
 * Requires at least: 5.4
 * Requires PHP:      7.4
 * Author:            Tim Brown
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wordpress-feature-flags
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Dual version bump: keep in step with the Version header above.
define( 'WPFF_VERSION', '1.0.0' );
define( 'WPFF_FILE', __FILE__ );
define( 'WPFF_DIR', plugin_dir_path( __FILE__ ) );

define( 'WPFF_OPTION', 'wpff_modes' );
define( 'WPFF_META_FLAG', '_wpff_flag' );
define( 'WPFF_META_INVERT', '_wpff_flag_invert' );
define( 'WPFF_COOKIE_PREFIX', 'wpff_preview_' );
define( 'WPFF_COOKIE_VIEW_PUBLIC', 'wpff_preview__view_public' );
define( 'WPFF_QUERY_ARG', 'wpff-preview' );

require_once WPFF_DIR . 'includes/registry.php';
require_once WPFF_DIR . 'includes/ui-flags.php';
require_once WPFF_DIR . 'includes/preview.php';
require_once WPFF_DIR . 'includes/integrations.php';
require_once WPFF_DIR . 'includes/block-editor.php';
require_once WPFF_DIR . 'includes/cache.php';
require_once WPFF_DIR . 'includes/admin.php';
require_once WPFF_DIR . 'includes/admin-bar.php';

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'wordpress-feature-flags', false, dirname( plugin_basename( WPFF_FILE ) ) . '/languages' );
	}
);
