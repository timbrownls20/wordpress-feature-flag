<?php
/**
 * Preview audience: secret-link tokens, signed preview cookies, the
 * ?wpff-preview handler, "view as public" and no-cache headers for previewers.
 *
 * @package wordpress-feature-flags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The preview token for a flag: WPFF_FLAG_<KEY>_TOKEN, falling back to
 * WPFF_PREVIEW_TOKEN. Tokens under 12 characters disable link preview.
 *
 * @param string $key Flag key.
 * @return string Empty when link preview is disabled for the flag.
 */
function wpff_preview_token( $key ) {
	$token = '';
	foreach ( array( wpff_constant_name( $key ) . '_TOKEN', 'WPFF_PREVIEW_TOKEN' ) as $name ) {
		if ( defined( $name ) && is_string( constant( $name ) ) && '' !== constant( $name ) ) {
			$token = constant( $name );
			break;
		}
	}
	return strlen( $token ) >= 12 ? $token : '';
}

/**
 * Secret preview link for a flag.
 *
 * @param string $key Flag key.
 * @return string Empty when no usable token is configured.
 */
function wpff_preview_url( $key ) {
	$token = wpff_preview_token( $key );
	return '' === $token ? '' : add_query_arg( WPFF_QUERY_ARG, rawurlencode( $key . ':' . $token ), home_url( '/' ) );
}

/**
 * Cookie value for a flag: an HMAC of key|token, never the token itself.
 * Rotating the token changes the value, invalidating issued cookies.
 *
 * @param string $key   Flag key.
 * @param string $token Preview token.
 * @return string
 */
function wpff_cookie_signature( $key, $token ) {
	return hash_hmac( 'sha256', $key . '|' . $token, wp_salt( 'auth' ) );
}

/**
 * Whether this browser holds a valid preview cookie for the flag.
 *
 * @param string $key Flag key.
 * @return bool
 */
function wpff_has_preview_cookie( $key ) {
	$name = WPFF_COOKIE_PREFIX . $key;
	if ( empty( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
		return false;
	}
	$token = wpff_preview_token( $key );
	return '' !== $token && hash_equals( wpff_cookie_signature( $key, $token ), wp_unslash( $_COOKIE[ $name ] ) );
}

/**
 * Whether the current admin has switched on "view as public visitor".
 *
 * @return bool
 */
function wpff_viewing_as_public() {
	return ! empty( $_COOKIE[ WPFF_COOKIE_VIEW_PUBLIC ] ) && is_user_logged_in();
}

/**
 * Set (or, with an empty value, clear) a plugin cookie.
 *
 * @param string $name  Cookie name.
 * @param string $value Cookie value; '' clears.
 * @param int    $ttl   Lifetime in seconds.
 */
function wpff_set_cookie( $name, $value, $ttl = 0 ) {
	$options = array(
		'expires'  => '' === $value ? time() - YEAR_IN_SECONDS : time() + $ttl,
		'path'     => COOKIEPATH,
		'domain'   => COOKIE_DOMAIN,
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	);
	setcookie( $name, $value, $options );
	if ( '' === $value ) {
		unset( $_COOKIE[ $name ] );
	} else {
		$_COOKIE[ $name ] = $value;
	}
}

/**
 * The current request URI with the given query args removed, as a
 * site-relative URL safe to redirect to.
 *
 * @param string[] $args Query args to strip.
 * @return string
 */
function wpff_current_url_without( $args ) {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by wp_safe_redirect().
	return remove_query_arg( $args, $uri );
}

/**
 * Whether this request is AJAX, cron or REST, where preview params are ignored.
 *
 * @return bool
 */
function wpff_is_background_request() {
	return wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
}

/**
 * Handle ?wpff-preview=<flag>:<token> | <flag>:off | off, then 302 to the same
 * URL without the param so the token stays out of history and analytics.
 */
function wpff_handle_preview_param() {
	if ( ! isset( $_GET[ WPFF_QUERY_ARG ] ) || wpff_is_background_request() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- secret-token link, no nonce by design.
		return;
	}
	// Not sanitize_text_field(): it would mangle tokens containing `%xx`. The value is only
	// matched against registered keys and compared with hash_equals(), never output.
	$raw = is_string( $_GET[ WPFF_QUERY_ARG ] ) ? wp_unslash( $_GET[ WPFF_QUERY_ARG ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	if ( 'off' === $raw ) {
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( $name, WPFF_COOKIE_PREFIX ) && WPFF_COOKIE_VIEW_PUBLIC !== $name ) {
				wpff_set_cookie( $name, '' );
			}
		}
	} elseif ( false !== strpos( $raw, ':' ) ) {
		list( $key, $given ) = explode( ':', $raw, 2 );
		if ( wpff_get_flag( $key ) ) {
			$token = wpff_preview_token( $key );
			if ( 'off' === $given ) {
				wpff_set_cookie( WPFF_COOKIE_PREFIX . $key, '' );
			} elseif ( '' !== $token && hash_equals( $token, $given ) ) {
				$ttl = (int) apply_filters( 'wpff_cookie_ttl', 14 * DAY_IN_SECONDS, $key );
				wpff_set_cookie( WPFF_COOKIE_PREFIX . $key, wpff_cookie_signature( $key, $token ), $ttl );
			}
			// Wrong token: silently ignored (no oracle), still stripped below.
		}
	}

	nocache_headers();
	wp_safe_redirect( wpff_current_url_without( array( WPFF_QUERY_ARG ) ), 302 );
	exit;
}
add_action( 'template_redirect', 'wpff_handle_preview_param', 0 );

/**
 * URL that switches "view as public visitor" on or off for the current admin.
 *
 * @param bool $on Switch on (true) or off (false).
 * @return string
 */
function wpff_view_public_url( $on ) {
	return add_query_arg(
		array(
			'wpff-view-public' => $on ? '1' : '0',
			'_wpnonce'         => wp_create_nonce( 'wpff_view_public' ),
		),
		wpff_current_url_without( array( 'wpff-view-public', '_wpnonce' ) )
	);
}

/**
 * Handle the admin bar's "view as public" toggle (nonce + manage_options).
 */
function wpff_handle_view_public() {
	if ( ! isset( $_GET['wpff-view-public'] ) || wpff_is_background_request() ) {
		return;
	}
	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	if ( current_user_can( 'manage_options' ) && wp_verify_nonce( $nonce, 'wpff_view_public' ) ) {
		if ( '1' === $_GET['wpff-view-public'] ) {
			wpff_set_cookie( WPFF_COOKIE_VIEW_PUBLIC, '1', DAY_IN_SECONDS );
		} else {
			wpff_set_cookie( WPFF_COOKIE_VIEW_PUBLIC, '' );
		}
	}
	nocache_headers();
	wp_safe_redirect( wpff_current_url_without( array( 'wpff-view-public', '_wpnonce' ) ), 302 );
	exit;
}
add_action( 'template_redirect', 'wpff_handle_view_public', 0 );

/**
 * Stop pages rendered for preview-cookie holders being stored in page caches.
 * This cannot stop a cache serving an existing entry; see README → Caching.
 */
function wpff_no_cache_for_previewers() {
	foreach ( array_keys( $_COOKIE ) as $name ) {
		if ( 0 === strpos( $name, WPFF_COOKIE_PREFIX ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			if ( ! headers_sent() ) {
				header( 'X-LiteSpeed-Cache-Control: no-cache' );
				nocache_headers();
			}
			return;
		}
	}
}
add_action( 'template_redirect', 'wpff_no_cache_for_previewers', 1 );
