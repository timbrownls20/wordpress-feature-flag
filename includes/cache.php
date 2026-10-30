<?php
/**
 * Purge page caches when an admin toggles a flag's mode.
 *
 * Constant flips, token rotation and schedule windows change state without
 * passing through here — see README → Caching.
 *
 * @package wordpress-feature-flags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge LiteSpeed Cache, WP Rocket and the object cache when available.
 */
function wpff_purge_caches() {
	// LiteSpeed Cache plugin; a no-op action when it is not installed.
	do_action( 'litespeed_purge_all' );

	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}

	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
}
add_action( 'wpff_mode_changed', 'wpff_purge_caches' );
