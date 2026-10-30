<?php
/**
 * Flag registry: registration, mode precedence, schedule window and the
 * visitor check wpff_flag().
 *
 * @package wordpress-feature-flags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry state. Flags live here for the request only; they are registered
 * in code on every load via the `wpff_register` action.
 */
final class WPFF_Registry {

	/** @var array<string, array> Registered flags keyed by flag key. */
	public static $flags = array();

	/** @var bool Whether the `wpff_register` action has fired. */
	public static $booted = false;
}

/**
 * Fire `wpff_register` once, lazily, on the first lookup after plugins_loaded.
 *
 * @param string $caller Function name used in the _doing_it_wrong() notice.
 * @return bool False when called too early (lookups must then fail closed).
 */
function wpff_boot( $caller = __FUNCTION__ ) {
	if ( WPFF_Registry::$booted ) {
		return true;
	}
	if ( ! did_action( 'plugins_loaded' ) ) {
		_doing_it_wrong( esc_html( $caller ), esc_html__( 'Feature flags cannot be checked before plugins_loaded.', 'wordpress-feature-flags' ), '1.0.0' );
		return false;
	}
	WPFF_Registry::$booted = true;
	do_action( 'wpff_register' );
	return true;
}

/**
 * Whether a string is a valid flag key: lowercase a-z 0-9 _ -, max 40 chars,
 * not starting with `not-`. Keys must also start with a letter or digit so they
 * cannot collide with the reserved `_view_public` cookie.
 *
 * @param mixed $key Candidate key.
 * @return bool
 */
function wpff_valid_key( $key ) {
	return is_string( $key )
		&& 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,39}$/', $key )
		&& 0 !== strpos( $key, 'not-' );
}

/**
 * Valid modes.
 *
 * @return string[]
 */
function wpff_modes() {
	return array( 'off', 'preview', 'on' );
}

/**
 * Parse a 'Y-m-d H:i' string in the site timezone.
 *
 * @param string $value Date string.
 * @return int|null Timestamp, or null when the string is invalid.
 */
function wpff_parse_time( $value ) {
	$date = DateTime::createFromFormat( '!Y-m-d H:i', $value, wp_timezone() );
	if ( ! $date || $date->format( 'Y-m-d H:i' ) !== $value ) {
		return null;
	}
	return $date->getTimestamp();
}

/**
 * Register a flag. Call inside the `wpff_register` action.
 *
 * @param string $key  Flag key.
 * @param array  $args See docs/spec.md §3.
 * @return bool True on success.
 */
function wpff_register( $key, $args = array() ) {
	if ( ! doing_action( 'wpff_register' ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Register flags inside the wpff_register action.', 'wordpress-feature-flags' ), '1.0.0' );
		return false;
	}
	if ( ! wpff_valid_key( $key ) ) {
		/* translators: %s: flag key */
		_doing_it_wrong( __FUNCTION__, esc_html( sprintf( __( 'Invalid feature flag key "%s".', 'wordpress-feature-flags' ), is_scalar( $key ) ? (string) $key : gettype( $key ) ) ), '1.0.0' );
		return false;
	}
	if ( isset( WPFF_Registry::$flags[ $key ] ) ) {
		/* translators: %s: flag key */
		_doing_it_wrong( __FUNCTION__, esc_html( sprintf( __( 'Feature flag "%s" is already registered.', 'wordpress-feature-flags' ), $key ) ), '1.0.0' );
		return false;
	}

	$flag = wp_parse_args(
		(array) $args,
		array(
			'label'        => $key,
			'description'  => '',
			'owner'        => '',
			'default'      => 'off',
			'constant'     => '',
			'admin_toggle' => false,
			'starts'       => '',
			'ends'         => '',
			'redirect'     => '',
			'preview_cap'  => 'manage_options',
		)
	);

	if ( ! in_array( $flag['default'], wpff_modes(), true ) ) {
		/* translators: %s: flag key */
		_doing_it_wrong( __FUNCTION__, esc_html( sprintf( __( 'Feature flag "%s" has an invalid default mode; using "off".', 'wordpress-feature-flags' ), $key ) ), '1.0.0' );
		$flag['default'] = 'off';
	}

	// Fail closed on unparseable dates: a bad start never arrives, a bad end has already passed.
	$flag['starts_ts'] = 0;
	$flag['ends_ts']   = 0;
	foreach ( array( 'starts', 'ends' ) as $field ) {
		$flag[ $field ] = (string) $flag[ $field ];
		if ( '' === $flag[ $field ] ) {
			continue;
		}
		$ts = wpff_parse_time( $flag[ $field ] );
		if ( null === $ts ) {
			/* translators: 1: field name, 2: flag key */
			_doing_it_wrong( __FUNCTION__, esc_html( sprintf( __( 'Invalid "%1$s" date for feature flag "%2$s"; expected Y-m-d H:i.', 'wordpress-feature-flags' ), $field, $key ) ), '1.0.0' );
			$ts = 'starts' === $field ? PHP_INT_MAX : 1;
		}
		$flag[ $field . '_ts' ] = $ts;
	}

	$flag['key']          = $key;
	$flag['admin_toggle'] = (bool) $flag['admin_toggle'];
	$flag['constant']     = (string) $flag['constant'];
	$flag['redirect']     = (string) $flag['redirect'];
	$flag['preview_cap']  = (string) $flag['preview_cap'];

	WPFF_Registry::$flags[ $key ] = $flag;
	return true;
}

/**
 * All registered flags, keyed by flag key.
 *
 * @return array<string, array>
 */
function wpff_flags() {
	wpff_boot( __FUNCTION__ );
	return WPFF_Registry::$flags;
}

/**
 * A single registered flag.
 *
 * @param string $key Flag key.
 * @return array|null Null when unregistered (or called too early).
 */
function wpff_get_flag( $key ) {
	if ( ! wpff_boot( __FUNCTION__ ) || ! is_string( $key ) ) {
		return null;
	}
	return isset( WPFF_Registry::$flags[ $key ] ) ? WPFF_Registry::$flags[ $key ] : null;
}

/**
 * Name of the wp-config override constant for a key: `event-2027` → `WPFF_FLAG_EVENT_2027`.
 *
 * @param string $key Flag key.
 * @return string
 */
function wpff_constant_name( $key ) {
	return 'WPFF_FLAG_' . strtoupper( str_replace( '-', '_', $key ) );
}

/**
 * Turn a constant value into a mode: 'on'|'preview'|'off', or true = on / false = preview.
 *
 * @param mixed $value Constant value.
 * @return string|null Null when the value is not a valid mode.
 */
function wpff_mode_from_value( $value ) {
	if ( true === $value ) {
		return 'on';
	}
	if ( false === $value ) {
		return 'preview';
	}
	return is_string( $value ) && in_array( $value, wpff_modes(), true ) ? $value : null;
}

/**
 * The flag's configured mode and where it came from (first valid wins):
 * WPFF_FLAG_<KEY> constant > legacy constant > admin option > default.
 *
 * @param string $key Flag key.
 * @return array{0: string, 1: string} [mode, source]; source is `constant:NAME`, `admin`, `default` or `unregistered`.
 */
function wpff_mode( $key ) {
	$flag = wpff_get_flag( $key );
	if ( ! $flag ) {
		return array( 'off', 'unregistered' );
	}

	$constants = array( wpff_constant_name( $key ) );
	if ( '' !== $flag['constant'] ) {
		$constants[] = $flag['constant'];
	}
	foreach ( $constants as $name ) {
		if ( defined( $name ) ) {
			$mode = wpff_mode_from_value( constant( $name ) );
			if ( null !== $mode ) {
				return array( $mode, 'constant:' . $name );
			}
		}
	}

	if ( $flag['admin_toggle'] ) {
		$saved = get_option( WPFF_OPTION, array() );
		if ( is_array( $saved ) && isset( $saved[ $key ] ) ) {
			$mode = wpff_mode_from_value( $saved[ $key ] );
			if ( null !== $mode ) {
				return array( $mode, 'admin' );
			}
		}
	}

	return array( $flag['default'], 'default' );
}

/**
 * Where now falls relative to the flag's schedule window.
 *
 * @param string $key Flag key.
 * @return string `none` (no window), `before`, `within` or `after`.
 */
function wpff_window_state( $key ) {
	$flag = wpff_get_flag( $key );
	if ( ! $flag || ( ! $flag['starts_ts'] && ! $flag['ends_ts'] ) ) {
		return 'none';
	}
	$now = time();
	if ( $flag['starts_ts'] && $now < $flag['starts_ts'] ) {
		return 'before';
	}
	if ( $flag['ends_ts'] && $now >= $flag['ends_ts'] ) {
		return 'after';
	}
	return 'within';
}

/**
 * Mode after applying the schedule window: `on` outside its window behaves as `preview`.
 *
 * @param string $key Flag key.
 * @return string
 */
function wpff_effective_mode( $key ) {
	list( $mode ) = wpff_mode( $key );
	if ( 'on' === $mode && in_array( wpff_window_state( $key ), array( 'before', 'after' ), true ) ) {
		return 'preview';
	}
	return $mode;
}

/**
 * Whether the flag is on for everyone right now. Visitor-independent: use in
 * cron, REST and emails.
 *
 * @param string $key Flag key.
 * @return bool
 */
function wpff_is_live( $key ) {
	return null !== wpff_get_flag( $key ) && 'on' === wpff_effective_mode( $key );
}

/**
 * Whether the current user may see the flag in preview mode by capability.
 *
 * @param string $key Flag key.
 * @return bool
 */
function wpff_can_preview( $key ) {
	$flag = wpff_get_flag( $key );
	$can  = $flag && is_user_logged_in() && current_user_can( $flag['preview_cap'] );
	return (bool) apply_filters( 'wpff_can_preview', $can, $key );
}

/**
 * Whether the flag is on for the current visitor. This is the check feature
 * code uses. Unregistered flags are off for everyone.
 *
 * @param string $key Flag key.
 * @return bool
 */
function wpff_flag( $key ) {
	if ( ! wpff_get_flag( $key ) ) {
		return false;
	}

	$mode = wpff_effective_mode( $key );
	if ( 'on' === $mode ) {
		$enabled = true;
	} elseif ( 'preview' === $mode && ! wpff_viewing_as_public() ) {
		$enabled = wpff_can_preview( $key ) || wpff_has_preview_cookie( $key );
	} else {
		$enabled = false;
	}

	return (bool) apply_filters( 'wpff_flag_enabled', $enabled, $key, $mode );
}

/**
 * Gate helper used by menus, posts, shortcodes, blocks and Elementor.
 *
 * @param string $key    Flag key.
 * @param bool   $invert True = show only while the flag is OFF for this visitor.
 * @return bool Whether the gated thing should be shown.
 */
function wpff_visible( $key, $invert = false ) {
	$on = wpff_flag( $key );
	return $invert ? ! $on : $on;
}
