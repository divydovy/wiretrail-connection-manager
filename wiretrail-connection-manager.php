<?php
/**
 * Plugin Name:       Wiretrail Connection Manager
 * Plugin URI:        https://github.com/divydovy/wiretrail-connection-manager
 * Description:       See which plugins and themes on your site talk to which third-party services, how healthy those connections are, and whether personal data is being sent.
 * Version:           0.2.2
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            David Lockie
 * Author URI:        https://profiles.wordpress.org/divydovy/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wiretrail-connection-manager
 *
 * @package ConnectionsManager
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define( 'CMGR_VERSION', '0.2.2' );
define( 'CMGR_SCHEMA_VERSION', '1' );
define( 'CMGR_PLUGIN_SLUG', basename( __DIR__ ) );
define( 'CMGR_MAX_SCAN_SIZE', 10 * 1024 ); // Only the first 10 KB of a request body is scanned.
define( 'CMGR_SLOW_REQUEST_MS', 2000 ); // Connections averaging at least this are reported as slow.

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------

function cmgr_is_enabled() {
	return (bool) get_option( 'cmgr_enabled', true );
}

function cmgr_get_retention_days() {
	return max( 1, min( 90, (int) get_option( 'cmgr_retention_days', 7 ) ) );
}

function cmgr_sanitize_bool( $value ) {
	return $value ? 1 : 0;
}

function cmgr_sanitize_retention_days( $value ) {
	return max( 1, min( 90, absint( $value ) ) );
}

function cmgr_sanitize_domain_lines( $value ) {
	$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
	$lines = array_filter( array_map( 'trim', $lines ) );
	$lines = array_map(
		static function ( $line ) {
			return preg_replace( '/[^a-z0-9.*:-]/i', '', strtolower( $line ) );
		},
		$lines
	);

	return implode( "\n", array_unique( array_filter( $lines ) ) );
}

// ---------------------------------------------------------------------------
// Database setup
// ---------------------------------------------------------------------------

function cmgr_events_table() {
	global $wpdb;
	return $wpdb->prefix . 'wiretrail_events';
}

function cmgr_alerts_table() {
	global $wpdb;
	return $wpdb->prefix . 'wiretrail_alerts';
}

function cmgr_create_tables() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();
	$events_table    = cmgr_events_table();
	$alerts_table    = cmgr_alerts_table();

	dbDelta(
		"CREATE TABLE {$events_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id varchar(80) NOT NULL,
			source_plugin varchar(191) DEFAULT '',
			target_url text NOT NULL,
			target_host varchar(191) NOT NULL,
			method varchar(20) NOT NULL DEFAULT 'GET',
			response_status int NULL,
			response_size bigint(20) unsigned DEFAULT 0,
			duration_ms int unsigned DEFAULT 0,
			error text NULL,
			pii_detected tinyint(1) DEFAULT 0,
			pii_types text NULL,
			is_error tinyint(1) DEFAULT 0,
			redaction_applied tinyint(1) DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY source_plugin (source_plugin),
			KEY target_host (target_host),
			KEY is_error (is_error),
			KEY created_at (created_at)
		) {$charset_collate};"
	);

	dbDelta(
		"CREATE TABLE {$alerts_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(80) NOT NULL,
			severity varchar(20) NOT NULL,
			message text NOT NULL,
			event_id varchar(80) DEFAULT '',
			target_host varchar(191) DEFAULT '',
			source_plugin varchar(191) DEFAULT '',
			acknowledged tinyint(1) DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY type (type),
			KEY target_host (target_host),
			KEY acknowledged (acknowledged)
		) {$charset_collate};"
	);
}

function cmgr_install() {
	cmgr_create_tables();
	update_option( 'cmgr_schema_version', CMGR_SCHEMA_VERSION );

	add_option( 'cmgr_enabled', 1 );
	add_option( 'cmgr_retention_days', 7 );

	if ( ! wp_next_scheduled( 'cmgr_daily_retention_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'cmgr_daily_retention_cleanup' );
	}
}

add_action( 'plugins_loaded', 'cmgr_maybe_install' );

function cmgr_maybe_install() {
	if ( get_option( 'cmgr_schema_version' ) !== CMGR_SCHEMA_VERSION ) {
		cmgr_install();
	}
}

function cmgr_prune_old_events() {
	global $wpdb;

	$days = cmgr_get_retention_days();
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', cmgr_events_table(), $days ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', cmgr_alerts_table(), $days ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

add_action( 'cmgr_daily_retention_cleanup', 'cmgr_prune_old_events' );

// ---------------------------------------------------------------------------
// Ignored hosts - traffic to these hosts is never recorded
// ---------------------------------------------------------------------------

function cmgr_get_ignored_domains() {
	$defaults = array(
		// WordPress.org update and translation checks: high volume, always core.
		'api.wordpress.org',
		'downloads.wordpress.org',
		'wordpress.org', // Dashboard news feed.
		'*.wordpress.org',
		// Loopback requests (cron, site health).
		'localhost',
		'127.0.0.1',
		'::1',
		// Payment gateways are out of scope.
		'api.stripe.com',
		'*.stripe.com',
		'api.paypal.com',
		'api.sandbox.paypal.com',
		'*.paypal.com',
		'*.braintreegateway.com',
	);

	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( $site_host ) {
		$defaults[] = $site_host;
	}

	$custom = get_option( 'cmgr_ignored_domains', '' );
	if ( $custom ) {
		$defaults = array_merge( $defaults, array_filter( array_map( 'trim', explode( "\n", $custom ) ) ) );
	}

	/**
	 * Filters the list of hosts whose outbound traffic is not recorded.
	 *
	 * @param string[] $domains Host names. Supports leading wildcards such as *.example.com.
	 */
	$domains = apply_filters( 'cmgr_ignored_domains', $defaults );

	return array_values( array_unique( array_filter( (array) $domains ) ) );
}

/**
 * Check if a host matches the ignore list. Supports wildcard *.domain.com.
 */
function cmgr_is_ignored( $host ) {
	$host = strtolower( (string) $host );

	foreach ( cmgr_get_ignored_domains() as $pattern ) {
		$pattern = strtolower( trim( (string) $pattern ) );
		if ( '' === $pattern ) {
			continue;
		}

		if ( $pattern === $host ) {
			return true;
		}

		if ( 0 === strpos( $pattern, '*.' ) ) {
			$suffix = substr( $pattern, 1 ); // .example.com.
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
	}

	return false;
}

// ---------------------------------------------------------------------------
// Source detection
// ---------------------------------------------------------------------------

function cmgr_detect_source_plugin() {
	$trace      = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 80 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
	$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );
	$theme_dir  = wp_normalize_path( get_theme_root() );
	$plugin_map = cmgr_get_plugin_path_map();

	foreach ( $trace as $frame ) {
		if ( empty( $frame['file'] ) ) {
			continue;
		}

		$file = wp_normalize_path( $frame['file'] );

		if ( 0 === strpos( $file, $plugin_dir . '/' ) ) {
			$relative = substr( $file, strlen( $plugin_dir ) + 1 );
			$parts    = explode( '/', $relative );

			if ( CMGR_PLUGIN_SLUG === $parts[0] ) {
				continue;
			}

			return $parts[0];
		}

		// Symlinked plugins report their real path in backtraces.
		foreach ( $plugin_map as $path => $slug ) {
			if ( 0 === strpos( $file, $path . '/' ) || $file === $path ) {
				if ( CMGR_PLUGIN_SLUG === $slug ) {
					continue 2;
				}
				return $slug;
			}
		}

		if ( 0 === strpos( $file, $theme_dir . '/' ) ) {
			$relative = substr( $file, strlen( $theme_dir ) + 1 );
			$parts    = explode( '/', $relative );
			return 'theme:' . $parts[0];
		}
	}

	return 'wp-core';
}

function cmgr_get_plugin_path_map() {
	static $map = null;

	if ( null !== $map ) {
		return $map;
	}

	$map     = array();
	$entries = glob( wp_normalize_path( WP_PLUGIN_DIR ) . '/*', GLOB_ONLYDIR );

	if ( ! is_array( $entries ) ) {
		return $map;
	}

	foreach ( $entries as $entry ) {
		$real = realpath( $entry );
		if ( $real && wp_normalize_path( $real ) !== wp_normalize_path( $entry ) ) {
			$map[ wp_normalize_path( $real ) ] = basename( $entry );
		}
	}

	uksort(
		$map,
		static function ( $a, $b ) {
			return strlen( $b ) <=> strlen( $a );
		}
	);

	return $map;
}

// ---------------------------------------------------------------------------
// PII classification and redaction
// ---------------------------------------------------------------------------

function cmgr_known_services() {
	$services = array(
		'api.mailchimp.com'        => array( 'name' => 'Mailchimp', 'expects_pii' => true, 'category' => 'email_marketing' ),
		'api.klaviyo.com'          => array( 'name' => 'Klaviyo', 'expects_pii' => true, 'category' => 'email_marketing' ),
		'ssapi.shipstation.com'    => array( 'name' => 'ShipStation', 'expects_pii' => true, 'category' => 'shipping' ),
		'maps.googleapis.com'      => array( 'name' => 'Google Maps', 'expects_pii' => false, 'category' => 'mapping' ),
		'www.google-analytics.com' => array( 'name' => 'Google Analytics', 'expects_pii' => false, 'category' => 'analytics' ),
		'public-api.wordpress.com' => array( 'name' => 'WordPress.com', 'expects_pii' => false, 'category' => 'platform' ),
		'jetpack.wordpress.com'    => array( 'name' => 'Jetpack', 'expects_pii' => false, 'category' => 'platform' ),
		'wordpress.com'            => array( 'name' => 'WordPress.com', 'expects_pii' => false, 'category' => 'platform' ),
		'wp.com'                   => array( 'name' => 'WordPress.com', 'expects_pii' => false, 'category' => 'platform' ),
		'pixel.wp.com'             => array( 'name' => 'Jetpack Stats', 'expects_pii' => true, 'category' => 'analytics' ),
		'api.bruteprotect.com'     => array( 'name' => 'Jetpack Protect', 'expects_pii' => true, 'category' => 'security' ),
		'rest.akismet.com'         => array( 'name' => 'Akismet', 'expects_pii' => true, 'category' => 'security' ),
	);

	/**
	 * Filters the list of recognised third-party services.
	 *
	 * @param array $services Map of host => array( name, expects_pii, category ).
	 */
	return apply_filters( 'cmgr_known_services', $services );
}

function cmgr_lookup_host( $host ) {
	$host     = strtolower( (string) $host );
	$services = cmgr_known_services();

	if ( isset( $services[ $host ] ) ) {
		return array_merge( array( 'known' => true ), $services[ $host ] );
	}

	$parts = explode( '.', $host );
	for ( $i = 1, $count = count( $parts ); $i < $count; $i++ ) {
		$parent = implode( '.', array_slice( $parts, $i ) );
		if ( isset( $services[ $parent ] ) ) {
			return array_merge( array( 'known' => true ), $services[ $parent ] );
		}
	}

	return array( 'known' => false );
}

function cmgr_pii_patterns() {
	return array(
		'email'       => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
		// Separators are required so bare 10-digit numbers (timestamps, IDs) are not flagged.
		'phone'       => '/(?:\+\d{1,3}[\s.\-]?)?\(?\b\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}\b/',
		'ssn'         => '/\b\d{3}-\d{2}-\d{4}\b/',
		'credit_card' => '/\b(?:\d{4}[\-\s]?){3}\d{4}\b/',
		'ip_address'  => '/\b(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\b/',
	);
}

function cmgr_secret_key_pattern() {
	return '/(^|[-_])(authorization|cookie|api[-_]?key|apikey|access[-_]?token|refresh[-_]?token|token|secret|client[-_]?secret|password|passwd|signature|session|key)($|[-_])/i';
}

/**
 * Luhn check so arbitrary 16-digit numbers are not reported as card numbers.
 */
function cmgr_passes_luhn( $number ) {
	$digits = preg_replace( '/\D/', '', $number );
	$sum    = 0;
	$alt    = false;

	for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
		$n = (int) $digits[ $i ];
		if ( $alt ) {
			$n *= 2;
			if ( $n > 9 ) {
				$n -= 9;
			}
		}
		$sum += $n;
		$alt  = ! $alt;
	}

	return strlen( $digits ) > 0 && 0 === $sum % 10;
}

function cmgr_normalise_for_scan( $value ) {
	if ( null === $value || '' === $value ) {
		return '';
	}

	if ( is_array( $value ) || is_object( $value ) ) {
		$value = wp_json_encode( $value );
	}

	$value   = substr( (string) $value, 0, CMGR_MAX_SCAN_SIZE );
	$decoded = rawurldecode( str_replace( '+', ' ', $value ) );
	return $decoded ? $decoded : $value;
}

/**
 * Classify outbound data (URL and request body) for personal data. Nothing scanned here is stored;
 * only the detected types are.
 */
function cmgr_classify_text( $text ) {
	$types = array();

	foreach ( cmgr_pii_patterns() as $type => $pattern ) {
		if ( ! preg_match_all( $pattern, $text, $matches ) ) {
			continue;
		}

		if ( 'credit_card' === $type && ! array_filter( $matches[0], 'cmgr_passes_luhn' ) ) {
			continue;
		}

		$types[] = $type;
	}

	return array(
		'detected' => ! empty( $types ),
		'types'    => $types,
	);
}

function cmgr_redact_string( $value, &$redacted ) {
	$output = (string) $value;

	foreach ( cmgr_pii_patterns() as $type => $pattern ) {
		$output = preg_replace( $pattern, '[redacted:' . $type . ']', $output, -1, $count );
		if ( $count > 0 ) {
			$redacted = true;
		}
	}

	return $output;
}

/**
 * Redact secrets and personal data from a URL's query string before it is stored.
 */
function cmgr_redact_url( $url, &$redacted ) {
	$parts = wp_parse_url( $url );
	if ( empty( $parts['query'] ) ) {
		return cmgr_redact_string( $url, $redacted );
	}

	parse_str( $parts['query'], $query );
	foreach ( $query as $key => $value ) {
		if ( preg_match( cmgr_secret_key_pattern(), (string) $key ) ) {
			$redacted      = true;
			$query[ $key ] = '[redacted:secret]';
		} else {
			$query[ $key ] = cmgr_redact_string( is_scalar( $value ) ? $value : wp_json_encode( $value ), $redacted );
		}
	}

	$base = cmgr_redact_string( preg_replace( '/\?.*$/', '', $url ), $redacted );
	return $base . '?' . http_build_query( $query );
}

// ---------------------------------------------------------------------------
// Event storage and alerting
// ---------------------------------------------------------------------------

function cmgr_store_event( $event ) {
	global $wpdb;

	if ( empty( $event['target_url'] ) || empty( $event['target_host'] ) ) {
		return false;
	}

	$classification = cmgr_classify_text(
		cmgr_normalise_for_scan( $event['target_url'] ) . "\n" . cmgr_normalise_for_scan( $event['request_body'] ?? '' )
	);
	$redacted       = false;
	$target_url     = cmgr_redact_url( $event['target_url'], $redacted );
	$is_error       = ! empty( $event['error'] ) || ( null !== $event['response_status'] && (int) $event['response_status'] >= 400 );
	$event_id       = wp_generate_uuid4();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$inserted = $wpdb->insert(
		cmgr_events_table(),
		array(
			'event_id'          => $event_id,
			'source_plugin'     => sanitize_text_field( $event['source_plugin'] ),
			'target_url'        => substr( $target_url, 0, 2048 ),
			'target_host'       => sanitize_text_field( strtolower( $event['target_host'] ) ),
			'method'            => sanitize_text_field( strtoupper( $event['method'] ) ),
			'response_status'   => null === $event['response_status'] ? null : (int) $event['response_status'],
			'response_size'     => (int) $event['response_size'],
			'duration_ms'       => (int) $event['duration_ms'],
			'error'             => $event['error'] ? sanitize_text_field( $event['error'] ) : null,
			'pii_detected'      => $classification['detected'] ? 1 : 0,
			'pii_types'         => wp_json_encode( $classification['types'] ),
			'is_error'          => $is_error ? 1 : 0,
			'redaction_applied' => $redacted ? 1 : 0,
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
		)
	);

	if ( $inserted ) {
		$event['event_id'] = $event_id;
		cmgr_detect_alerts_for_event( $event, $classification, $is_error );
	}

	return (bool) $inserted;
}

function cmgr_has_recent_alert( $type, $host, $source_plugin, $minutes ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE type = %s AND target_host = %s AND source_plugin = %s AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)',
			cmgr_alerts_table(),
			$type,
			$host,
			$source_plugin,
			$minutes
		)
	);
}

function cmgr_insert_alert( $alert ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	return $wpdb->insert(
		cmgr_alerts_table(),
		array(
			'type'          => sanitize_key( $alert['type'] ),
			'severity'      => sanitize_key( $alert['severity'] ),
			'message'       => sanitize_text_field( $alert['message'] ),
			'event_id'      => sanitize_text_field( $alert['event_id'] ),
			'target_host'   => sanitize_text_field( $alert['target_host'] ),
			'source_plugin' => sanitize_text_field( $alert['source_plugin'] ),
			'acknowledged'  => 0,
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		)
	);
}

function cmgr_detect_alerts_for_event( $event, $classification, $is_error ) {
	global $wpdb;

	$host   = strtolower( $event['target_host'] );
	$source = $event['source_plugin'];
	$type   = '';

	if ( ! empty( $classification['detected'] ) ) {
		$host_info = cmgr_lookup_host( $host );
		$types     = implode( ', ', $classification['types'] );

		if ( empty( $host_info['known'] ) ) {
			$type     = 'pii_unknown_endpoint';
			$severity = 'high';
			/* translators: 1: plugin slug, 2: personal data types, 3: host name. */
			$message = sprintf( __( "'%1\$s' sent personal data (%2\$s) to unrecognised host %3\$s", 'wiretrail-connection-manager' ), $source, $types, $host );
		} elseif ( empty( $host_info['expects_pii'] ) ) {
			$type     = 'pii_unexpected';
			$severity = 'medium';
			/* translators: 1: plugin slug, 2: personal data types, 3: service name. */
			$message = sprintf( __( "'%1\$s' sent personal data (%2\$s) to %3\$s", 'wiretrail-connection-manager' ), $source, $types, $host_info['name'] );
		}

		if ( ! empty( $type ) && ! cmgr_has_recent_alert( $type, $host, $source, DAY_IN_SECONDS / MINUTE_IN_SECONDS ) ) {
			cmgr_insert_alert(
				array(
					'type'          => $type,
					'severity'      => $severity,
					'message'       => $message,
					'event_id'      => $event['event_id'],
					'target_host'   => $host,
					'source_plugin' => $source,
				)
			);
		}
	}

	if ( $is_error ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$recent_errors = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE target_host = %s AND is_error = 1 AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)',
				cmgr_events_table(),
				$host
			)
		);

		if ( $recent_errors >= 5 && ! cmgr_has_recent_alert( 'repeated_failures', $host, $source, 30 ) ) {
			cmgr_insert_alert(
				array(
					'type'          => 'repeated_failures',
					'severity'      => 'medium',
					/* translators: 1: host name, 2: error count. */
					'message'       => sprintf( __( '%1$s returned errors %2$d times in the last hour', 'wiretrail-connection-manager' ), $host, $recent_errors ),
					'event_id'      => $event['event_id'],
					'target_host'   => $host,
					'source_plugin' => $source,
				)
			);
		}
	}
}

// ---------------------------------------------------------------------------
// Observation: time each outbound request and record it after it completes.
// Requests are never modified, blocked, or re-sent.
// ---------------------------------------------------------------------------

add_filter( 'pre_http_request', 'cmgr_mark_request_start', PHP_INT_MAX, 3 );

function cmgr_mark_request_start( $preempt, $parsed_args, $url ) {
	if ( false === $preempt ) {
		$GLOBALS['cmgr_request_starts'][ $url ][] = microtime( true );
	}

	return $preempt;
}

add_action( 'http_api_debug', 'cmgr_observe_request', 10, 5 );

function cmgr_observe_request( $response, $context, $transport, $parsed_args, $url ) {
	$started = null;
	if ( ! empty( $GLOBALS['cmgr_request_starts'][ $url ] ) ) {
		$started = array_pop( $GLOBALS['cmgr_request_starts'][ $url ] );
	}

	if ( 'response' !== $context || ! cmgr_is_enabled() ) {
		return;
	}

	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! $host || cmgr_is_ignored( $host ) ) {
		return;
	}

	$body = isset( $parsed_args['body'] ) ? $parsed_args['body'] : null;
	if ( is_array( $body ) ) {
		$body = http_build_query( $body );
	}

	$event = array(
		'source_plugin'   => cmgr_detect_source_plugin(),
		'target_url'      => $url,
		'target_host'     => $host,
		'method'          => isset( $parsed_args['method'] ) ? $parsed_args['method'] : 'GET',
		'request_body'    => $body,
		'response_status' => null,
		'response_size'   => 0,
		'duration_ms'     => $started ? (int) round( ( microtime( true ) - $started ) * 1000 ) : 0,
		'error'           => null,
	);

	if ( is_wp_error( $response ) ) {
		$event['error'] = $response->get_error_message();
	} elseif ( ! isset( $parsed_args['blocking'] ) || $parsed_args['blocking'] ) {
		$event['response_status'] = (int) wp_remote_retrieve_response_code( $response );
		$event['response_size']   = strlen( (string) wp_remote_retrieve_body( $response ) );
	}

	cmgr_store_event( $event );
}

// ---------------------------------------------------------------------------
// Admin screens
// ---------------------------------------------------------------------------

add_action( 'admin_menu', 'cmgr_add_settings_page' );

function cmgr_add_settings_page() {
	add_management_page(
		__( 'Wiretrail Connection Manager', 'wiretrail-connection-manager' ),
		__( 'Connections', 'wiretrail-connection-manager' ),
		'manage_options',
		'wiretrail-connection-manager',
		'cmgr_render_admin_page'
	);
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'cmgr_plugin_action_links' );

function cmgr_plugin_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( cmgr_admin_url() ) . '">' . esc_html__( 'View connections', 'wiretrail-connection-manager' ) . '</a>' );
	return $links;
}

add_action( 'admin_init', 'cmgr_register_settings' );

function cmgr_register_settings() {
	register_setting( 'cmgr_settings', 'cmgr_enabled', array( 'sanitize_callback' => 'cmgr_sanitize_bool' ) );
	register_setting( 'cmgr_settings', 'cmgr_ignored_domains', array( 'sanitize_callback' => 'cmgr_sanitize_domain_lines' ) );
	register_setting( 'cmgr_settings', 'cmgr_retention_days', array( 'sanitize_callback' => 'cmgr_sanitize_retention_days' ) );
}

function cmgr_admin_url( $tab = 'overview' ) {
	return add_query_arg( 'tab', $tab, admin_url( 'tools.php?page=wiretrail-connection-manager' ) );
}

add_action( 'admin_post_cmgr_clear_data', 'cmgr_handle_clear_data' );

function cmgr_handle_clear_data() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wiretrail-connection-manager' ) );
	}

	check_admin_referer( 'cmgr_clear_data' );

	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', cmgr_events_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', cmgr_alerts_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	wp_safe_redirect( add_query_arg( 'cleared', '1', cmgr_admin_url( 'settings' ) ) );
	exit;
}

function cmgr_get_stats() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS total, COUNT(DISTINCT source_plugin) AS plugins, COUNT(DISTINCT target_host) AS services, SUM(pii_detected) AS pii, SUM(is_error) AS errors, AVG(NULLIF(duration_ms, 0)) AS avg_latency FROM %i', cmgr_events_table() ), ARRAY_A );

	return array(
		'total'       => (int) ( $row['total'] ?? 0 ),
		'plugins'     => (int) ( $row['plugins'] ?? 0 ),
		'services'    => (int) ( $row['services'] ?? 0 ),
		'pii'         => (int) ( $row['pii'] ?? 0 ),
		'errors'      => (int) ( $row['errors'] ?? 0 ),
		'avg_latency' => isset( $row['avg_latency'] ) ? (int) round( $row['avg_latency'] ) : 0,
	);
}

function cmgr_get_detected_integrations() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT source_plugin, target_host, COUNT(*) AS requests, SUM(is_error) AS errors, SUM(pii_detected) AS pii, AVG(NULLIF(duration_ms, 0)) AS avg_latency, MAX(created_at) AS last_seen
			FROM %i
			GROUP BY source_plugin, target_host
			ORDER BY requests DESC
			LIMIT 50',
			cmgr_events_table()
		),
		ARRAY_A
	);
}

function cmgr_get_detected_connections() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT target_host, COUNT(*) AS requests, SUM(is_error) AS errors, SUM(pii_detected) AS pii, MAX(created_at) AS last_seen, GROUP_CONCAT(DISTINCT source_plugin) AS source_plugins
			FROM %i
			WHERE source_plugin <> %s
			GROUP BY target_host
			ORDER BY requests DESC
			LIMIT 24',
			cmgr_events_table(),
			'wp-core'
		),
		ARRAY_A
	);
}

function cmgr_get_detected_connections_for_client() {
	$connections = array();

	foreach ( cmgr_get_detected_connections() as $connection ) {
		$host      = (string) $connection['target_host'];
		$host_info = cmgr_lookup_host( $host );
		$sources   = array_values( array_filter( array_map( 'trim', explode( ',', (string) $connection['source_plugins'] ) ) ) );

		$connections[] = array(
			'id'             => sanitize_key( preg_replace( '/[^a-z0-9_-]+/i', '-', $host ) ),
			'name'           => ! empty( $host_info['known'] ) ? $host_info['name'] : $host,
			'host'           => $host,
			'sources'        => $sources,
			'requestCount'   => (int) $connection['requests'],
			'errorCount'     => (int) $connection['errors'],
			'piiCount'       => (int) $connection['pii'],
			'lastSeen'       => $connection['last_seen'] ? get_date_from_gmt( $connection['last_seen'], 'Y-m-d H:i:s' ) : '',
			'isKnown'        => ! empty( $host_info['known'] ),
			'classification' => ! empty( $host_info['known'] ) ? __( 'Known provider', 'wiretrail-connection-manager' ) : __( 'Detected host', 'wiretrail-connection-manager' ),
		);
	}

	return $connections;
}

function cmgr_get_recent_events() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT source_plugin, target_host, method, response_status, duration_ms, pii_detected, is_error, error, created_at
			FROM %i
			ORDER BY id DESC
			LIMIT 25',
			cmgr_events_table()
		),
		ARRAY_A
	);
}

function cmgr_get_recent_alerts() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT severity, message, created_at
			FROM %i
			WHERE acknowledged = 0
			ORDER BY id DESC
			LIMIT 10',
			cmgr_alerts_table()
		),
		ARRAY_A
	);
}

function cmgr_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
	if ( ! in_array( $tab, array( 'overview', 'settings' ), true ) ) {
		$tab = 'overview';
	}

	?>
	<div class="wrap cmgr-admin">
		<h1><?php esc_html_e( 'Wiretrail Connection Manager', 'wiretrail-connection-manager' ); ?></h1>
		<p class="description"><?php esc_html_e( 'Outbound connections made by plugins, themes, and WordPress itself. Everything shown here is recorded and stored on this site only.', 'wiretrail-connection-manager' ); ?></p>

		<nav class="nav-tab-wrapper">
			<a class="nav-tab <?php echo 'overview' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( cmgr_admin_url( 'overview' ) ); ?>"><?php esc_html_e( 'Detected connections', 'wiretrail-connection-manager' ); ?></a>
			<a class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( cmgr_admin_url( 'settings' ) ); ?>"><?php esc_html_e( 'Settings', 'wiretrail-connection-manager' ); ?></a>
		</nav>

		<?php
		if ( 'settings' === $tab ) {
			cmgr_render_settings_tab();
		} else {
			cmgr_render_overview_tab();
		}
		?>
	</div>
	<?php
}

add_action( 'admin_enqueue_scripts', 'cmgr_enqueue_admin_styles' );

function cmgr_enqueue_admin_styles( $hook_suffix ) {
	if ( 'tools_page_wiretrail-connection-manager' !== $hook_suffix ) {
		return;
	}

	wp_register_style( 'wiretrail-connection-manager-admin', false, array(), CMGR_VERSION );
	wp_enqueue_style( 'wiretrail-connection-manager-admin' );
	wp_add_inline_style(
		'wiretrail-connection-manager-admin',
		'.cmgr-admin .cmgr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin: 18px 0; }
		.cmgr-admin .cmgr-card { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; padding: 14px; }
		.cmgr-admin .cmgr-value { font-size: 26px; font-weight: 700; line-height: 1.2; }
		.cmgr-admin .cmgr-label { color: #646970; font-size: 12px; margin-top: 4px; }
		.cmgr-admin .cmgr-status { border-left: 4px solid #d63638; margin-top: 16px; }
		.cmgr-admin .cmgr-pill { display: inline-block; border-radius: 999px; padding: 2px 8px; font-size: 11px; font-weight: 600; background: #f0f0f1; }
		.cmgr-admin .cmgr-pill-danger { background: #fcf0f1; color: #b32d2e; }
		.cmgr-admin .cmgr-pill-warning { background: #fcf9e8; color: #996800; }
		.cmgr-admin .cmgr-pill-success { background: #edfaef; color: #008a20; }
		.cmgr-admin .widefat td { vertical-align: middle; }'
	);
}

function cmgr_render_detected_integrations_table( $integrations ) {
	?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Source', 'wiretrail-connection-manager' ); ?></th>
				<th><?php esc_html_e( 'Service host', 'wiretrail-connection-manager' ); ?></th>
				<th><?php esc_html_e( 'Requests', 'wiretrail-connection-manager' ); ?></th>
				<th><?php esc_html_e( 'Avg latency', 'wiretrail-connection-manager' ); ?></th>
				<th><?php esc_html_e( 'Health', 'wiretrail-connection-manager' ); ?></th>
				<th><?php esc_html_e( 'Personal data', 'wiretrail-connection-manager' ); ?></th>
				<th><?php esc_html_e( 'Last seen', 'wiretrail-connection-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $integrations ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No outbound connections recorded yet. They will appear here as plugins and themes make requests.', 'wiretrail-connection-manager' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $integrations as $integration ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $integration['source_plugin'] ); ?></strong></td>
					<td><?php echo esc_html( $integration['target_host'] ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $integration['requests'] ) ); ?></td>
					<td><?php echo esc_html( $integration['avg_latency'] ? number_format_i18n( round( $integration['avg_latency'] ) ) . 'ms' : '-' ); ?></td>
					<td>
						<?php if ( (int) $integration['errors'] > 0 ) : ?>
							<?php /* translators: %s: number of errors. */ ?>
							<span class="cmgr-pill cmgr-pill-danger"><?php echo esc_html( sprintf( _n( '%s error', '%s errors', (int) $integration['errors'], 'wiretrail-connection-manager' ), number_format_i18n( $integration['errors'] ) ) ); ?></span>
						<?php elseif ( (int) $integration['avg_latency'] >= CMGR_SLOW_REQUEST_MS ) : ?>
							<span class="cmgr-pill cmgr-pill-warning"><?php esc_html_e( 'slow', 'wiretrail-connection-manager' ); ?></span>
						<?php else : ?>
							<span class="cmgr-pill cmgr-pill-success"><?php esc_html_e( 'healthy', 'wiretrail-connection-manager' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( (int) $integration['pii'] > 0 ) : ?>
							<?php /* translators: %s: number of requests containing personal data. */ ?>
							<span class="cmgr-pill cmgr-pill-warning"><?php echo esc_html( sprintf( _n( '%s request', '%s requests', (int) $integration['pii'], 'wiretrail-connection-manager' ), number_format_i18n( $integration['pii'] ) ) ); ?></span>
						<?php else : ?>
							<span class="cmgr-pill"><?php esc_html_e( 'none detected', 'wiretrail-connection-manager' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( get_date_from_gmt( $integration['last_seen'], 'Y-m-d H:i:s' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function cmgr_render_overview_tab() {
	$stats        = cmgr_get_stats();
	$integrations = cmgr_get_detected_integrations();
	$events       = cmgr_get_recent_events();
	$alerts       = cmgr_get_recent_alerts();
	?>
	<?php if ( ! cmgr_is_enabled() ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Recording is paused. Enable it in Settings to capture new connections.', 'wiretrail-connection-manager' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $alerts ) : ?>
		<div class="cmgr-card cmgr-status">
			<strong><?php esc_html_e( 'Needs attention:', 'wiretrail-connection-manager' ); ?></strong>
			<ul>
				<?php foreach ( $alerts as $alert ) : ?>
					<li><span class="cmgr-pill cmgr-pill-danger"><?php echo esc_html( $alert['severity'] ); ?></span> <?php echo esc_html( $alert['message'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<div class="cmgr-grid">
		<?php
		$cards = array(
			__( 'Requests', 'wiretrail-connection-manager' )       => number_format_i18n( $stats['total'] ),
			__( 'Sources', 'wiretrail-connection-manager' )        => number_format_i18n( $stats['plugins'] ),
			__( 'Services', 'wiretrail-connection-manager' )       => number_format_i18n( $stats['services'] ),
			__( 'Personal data', 'wiretrail-connection-manager' )  => number_format_i18n( $stats['pii'] ),
			__( 'Errors', 'wiretrail-connection-manager' )         => number_format_i18n( $stats['errors'] ),
			__( 'Avg latency', 'wiretrail-connection-manager' )    => $stats['avg_latency'] ? number_format_i18n( $stats['avg_latency'] ) . 'ms' : '-',
		);
		foreach ( $cards as $label => $value ) :
			?>
			<div class="cmgr-card">
				<div class="cmgr-value"><?php echo esc_html( $value ); ?></div>
				<div class="cmgr-label"><?php echo esc_html( $label ); ?></div>
			</div>
		<?php endforeach; ?>
	</div>

	<h2><?php esc_html_e( 'Detected connections', 'wiretrail-connection-manager' ); ?></h2>
	<?php cmgr_render_detected_integrations_table( $integrations ); ?>

	<h2><?php esc_html_e( 'Recent activity', 'wiretrail-connection-manager' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Time', 'wiretrail-connection-manager' ); ?></th>
				<th><?php esc_html_e( 'Source', 'wiretrail-connection-manager' ); ?></th>
				<th><?php esc_html_e( 'Service', 'wiretrail-connection-manager' ); ?></th>
				<th><?php esc_html_e( 'Method', 'wiretrail-connection-manager' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wiretrail-connection-manager' ); ?></th>
				<th><?php esc_html_e( 'Duration', 'wiretrail-connection-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $events ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'Waiting for traffic.', 'wiretrail-connection-manager' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $events as $event ) : ?>
				<tr>
					<td><?php echo esc_html( get_date_from_gmt( $event['created_at'], 'Y-m-d H:i:s' ) ); ?></td>
					<td><?php echo esc_html( $event['source_plugin'] ); ?></td>
					<td><?php echo esc_html( $event['target_host'] ); ?></td>
					<td><?php echo esc_html( $event['method'] ); ?></td>
					<td>
						<?php if ( $event['is_error'] ) : ?>
							<span class="cmgr-pill cmgr-pill-danger" title="<?php echo esc_attr( (string) $event['error'] ); ?>"><?php echo esc_html( $event['response_status'] ? $event['response_status'] : __( 'error', 'wiretrail-connection-manager' ) ); ?></span>
						<?php else : ?>
							<?php echo esc_html( $event['response_status'] ? $event['response_status'] : 'n/a' ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $event['duration_ms'] ? $event['duration_ms'] . 'ms' : '-' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function cmgr_render_settings_tab() {
	$enabled        = cmgr_is_enabled();
	$ignored        = get_option( 'cmgr_ignored_domains', '' );
	$retention_days = cmgr_get_retention_days();
	?>
	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
	<?php if ( ! empty( $_GET['cleared'] ) ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'All recorded connections and alerts were deleted.', 'wiretrail-connection-manager' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'cmgr_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Recording', 'wiretrail-connection-manager' ); ?></th>
				<td>
					<input type="hidden" name="cmgr_enabled" value="0" />
					<label><input type="checkbox" name="cmgr_enabled" value="1" <?php checked( $enabled ); ?> /> <?php esc_html_e( 'Record outbound connections made by this site', 'wiretrail-connection-manager' ); ?></label>
					<p class="description"><?php esc_html_e( 'Records the destination, timing, and status of each request. Secrets and personal data are redacted from stored URLs, and request and response bodies are never stored. Nothing is sent off this site.', 'wiretrail-connection-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Retention', 'wiretrail-connection-manager' ); ?></th>
				<td>
					<input type="number" min="1" max="90" name="cmgr_retention_days" value="<?php echo esc_attr( $retention_days ); ?>" class="small-text" /> <?php esc_html_e( 'days', 'wiretrail-connection-manager' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Ignored hosts', 'wiretrail-connection-manager' ); ?></th>
				<td>
					<textarea name="cmgr_ignored_domains" rows="6" cols="50" class="large-text code"><?php echo esc_textarea( $ignored ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One host per line. Supports wildcards such as *.example.com.', 'wiretrail-connection-manager' ); ?></p>
					<p class="description"><?php esc_html_e( 'Always ignored: WordPress.org update checks, requests to this site, and payment gateways (Stripe, PayPal, Braintree).', 'wiretrail-connection-manager' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>

	<h2><?php esc_html_e( 'Delete recorded data', 'wiretrail-connection-manager' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="cmgr_clear_data" />
		<?php wp_nonce_field( 'cmgr_clear_data' ); ?>
		<?php submit_button( __( 'Delete all recorded connections', 'wiretrail-connection-manager' ), 'delete', 'submit', false ); ?>
	</form>
	<?php
}

// ---------------------------------------------------------------------------
// Settings > Connectors integration (WordPress 7.0+)
// ---------------------------------------------------------------------------

add_action( 'admin_enqueue_scripts', 'cmgr_enqueue_connectors_page_module' );

function cmgr_enqueue_connectors_page_module( $hook_suffix ) {
	global $pagenow;

	if ( 'options-connectors.php' !== $pagenow && 'options-connectors.php' !== $hook_suffix ) {
		return;
	}

	if ( ! function_exists( 'wp_enqueue_script_module' ) ) {
		return;
	}

	wp_enqueue_script_module(
		'wiretrail-connection-manager-connectors',
		plugins_url( 'assets/connections-page.js', __FILE__ ),
		array( '@wordpress/connectors' ),
		CMGR_VERSION
	);
}

add_filter( 'script_module_data_wiretrail-connection-manager-connectors', 'cmgr_get_connectors_page_module_data' );

function cmgr_get_connectors_page_module_data( $data ) {
	$data['connections'] = cmgr_get_detected_connections_for_client();
	$data['activityUrl'] = cmgr_admin_url( 'overview' );
	$data['settingsUrl'] = cmgr_admin_url( 'settings' );

	return $data;
}

// ---------------------------------------------------------------------------
// Abilities (agent access)
// ---------------------------------------------------------------------------

add_action( 'wp_abilities_api_categories_init', 'cmgr_register_ability_category' );

function cmgr_register_ability_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	wp_register_ability_category(
		'wiretrail',
		array(
			'label'       => __( 'Wiretrail Connection Manager', 'wiretrail-connection-manager' ),
			'description' => __( 'Outbound connections made by plugins, themes, and WordPress to third-party services.', 'wiretrail-connection-manager' ),
		)
	);
}

add_action( 'wp_abilities_api_init', 'cmgr_register_abilities' );

function cmgr_register_abilities() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$meta = array(
		'annotations' => array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		),
		'public'      => true,
		'mcp'         => array( 'public' => true ),
	);

	$hours_schema = array(
		'type'        => 'integer',
		'minimum'     => 1,
		'maximum'     => 24 * 30,
		'default'     => 24,
		'description' => __( 'How many hours back to look.', 'wiretrail-connection-manager' ),
	);

	wp_register_ability(
		'wiretrail/get-issues',
		array(
			'label'               => __( 'Get connection issues', 'wiretrail-connection-manager' ),
			'description'         => __( 'Reports what needs attention in this site\'s outbound connections to third-party services: unacknowledged alerts (personal data sent to unrecognised or unexpected services, repeated failures), connections that returned errors, and connections that were slow. Use this to answer "is anything wrong with my integrations?" or "is any plugin leaking personal data?".', 'wiretrail-connection-manager' ),
			'category'            => 'wiretrail',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'hours' => $hours_schema,
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'status'              => array(
						'type' => 'string',
						'enum' => array( 'ok', 'attention' ),
					),
					'window_hours'        => array( 'type' => 'integer' ),
					'summary'             => array( 'type' => 'object' ),
					'alerts'              => array( 'type' => 'array' ),
					'failing_connections' => array( 'type' => 'array' ),
					'slow_connections'    => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'cmgr_ability_get_issues',
			'permission_callback' => 'cmgr_ability_permission',
			'meta'                => $meta,
		)
	);

	wp_register_ability(
		'wiretrail/list-connections',
		array(
			'label'               => __( 'List detected connections', 'wiretrail-connection-manager' ),
			'description'         => __( 'Lists every third-party service this site has talked to within the retention period, grouped by the plugin or theme that made the requests, with request counts, errors, average latency, and how many requests contained personal data. Source "wp-core" means WordPress itself or code outside a plugin or theme directory.', 'wiretrail-connection-manager' ),
			'category'            => 'wiretrail',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'source_plugin' => array(
						'type'        => 'string',
						'description' => __( 'Only include connections made by this plugin or theme slug.', 'wiretrail-connection-manager' ),
					),
					'host'          => array(
						'type'        => 'string',
						'description' => __( 'Only include connections to this host.', 'wiretrail-connection-manager' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'connections' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'cmgr_ability_list_connections',
			'permission_callback' => 'cmgr_ability_permission',
			'meta'                => $meta,
		)
	);

	wp_register_ability(
		'wiretrail/get-recent-requests',
		array(
			'label'               => __( 'Get recent outbound requests', 'wiretrail-connection-manager' ),
			'description'         => __( 'Returns individual recent outbound requests, newest first, with status, timing, error message, and the types of personal data detected. URLs have secrets and personal data redacted. Use this to dig into a failing or suspicious connection found by get-issues.', 'wiretrail-connection-manager' ),
			'category'            => 'wiretrail',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'host'               => array(
						'type'        => 'string',
						'description' => __( 'Only include requests to this host.', 'wiretrail-connection-manager' ),
					),
					'source_plugin'      => array(
						'type'        => 'string',
						'description' => __( 'Only include requests made by this plugin or theme slug.', 'wiretrail-connection-manager' ),
					),
					'errors_only'        => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'personal_data_only' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'limit'              => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 25,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'requests' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'cmgr_ability_get_recent_requests',
			'permission_callback' => 'cmgr_ability_permission',
			'meta'                => $meta,
		)
	);
}

function cmgr_ability_permission() {
	return current_user_can( 'manage_options' );
}

function cmgr_ability_time( $gmt_datetime ) {
	return $gmt_datetime ? gmdate( 'c', strtotime( $gmt_datetime . ' UTC' ) ) : null;
}

function cmgr_ability_get_issues( $input = array() ) {
	global $wpdb;

	$hours = isset( $input['hours'] ) ? max( 1, (int) $input['hours'] ) : 24;
	$since = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$alert_rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT type, severity, message, target_host, source_plugin, created_at
			FROM %i
			WHERE acknowledged = 0 AND created_at >= %s
			ORDER BY id DESC
			LIMIT 50',
			cmgr_alerts_table(),
			$since
		),
		ARRAY_A
	);

	$alerts = array();
	foreach ( $alert_rows as $row ) {
		$alerts[] = array(
			'type'          => $row['type'],
			'severity'      => $row['severity'],
			'message'       => $row['message'],
			'host'          => $row['target_host'],
			'source_plugin' => $row['source_plugin'],
			'created_at'    => cmgr_ability_time( $row['created_at'] ),
		);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$connection_rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT source_plugin, target_host, COUNT(*) AS requests, SUM(is_error) AS errors, AVG(NULLIF(duration_ms, 0)) AS avg_latency, MAX(CASE WHEN is_error = 1 THEN created_at END) AS last_error_at
			FROM %i
			WHERE created_at >= %s
			GROUP BY source_plugin, target_host',
			cmgr_events_table(),
			$since
		),
		ARRAY_A
	);

	$failing = array();
	$slow    = array();
	foreach ( $connection_rows as $row ) {
		$requests = (int) $row['requests'];
		$errors   = (int) $row['errors'];
		$latency  = isset( $row['avg_latency'] ) ? (int) round( $row['avg_latency'] ) : 0;

		if ( $errors > 0 ) {
			$failing[] = array(
				'source_plugin' => $row['source_plugin'],
				'host'          => $row['target_host'],
				'requests'      => $requests,
				'errors'        => $errors,
				'error_rate'    => round( $errors / max( 1, $requests ), 3 ),
				'last_error'    => cmgr_ability_last_error( $row['source_plugin'], $row['target_host'] ),
				'last_error_at' => cmgr_ability_time( $row['last_error_at'] ),
			);
		}

		if ( $latency >= CMGR_SLOW_REQUEST_MS ) {
			$slow[] = array(
				'source_plugin'  => $row['source_plugin'],
				'host'           => $row['target_host'],
				'requests'       => $requests,
				'avg_latency_ms' => $latency,
			);
		}
	}

	usort(
		$failing,
		static function ( $a, $b ) {
			return $b['errors'] <=> $a['errors'];
		}
	);
	usort(
		$slow,
		static function ( $a, $b ) {
			return $b['avg_latency_ms'] <=> $a['avg_latency_ms'];
		}
	);

	return array(
		'status'              => ( $alerts || $failing || $slow ) ? 'attention' : 'ok',
		'window_hours'        => $hours,
		'summary'             => cmgr_get_stats(),
		'alerts'              => $alerts,
		'failing_connections' => $failing,
		'slow_connections'    => $slow,
	);
}

function cmgr_ability_last_error( $source_plugin, $host ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT response_status, error FROM %i WHERE source_plugin = %s AND target_host = %s AND is_error = 1 ORDER BY id DESC LIMIT 1',
			cmgr_events_table(),
			$source_plugin,
			$host
		),
		ARRAY_A
	);

	if ( ! $row ) {
		return '';
	}

	return $row['error'] ? $row['error'] : sprintf( 'HTTP %d', (int) $row['response_status'] );
}

function cmgr_ability_list_connections( $input = array() ) {
	$source = isset( $input['source_plugin'] ) ? (string) $input['source_plugin'] : '';
	$host   = isset( $input['host'] ) ? strtolower( (string) $input['host'] ) : '';

	$connections = array();
	foreach ( cmgr_get_detected_integrations() as $row ) {
		if ( ( '' !== $source && $row['source_plugin'] !== $source ) || ( '' !== $host && $row['target_host'] !== $host ) ) {
			continue;
		}

		$host_info     = cmgr_lookup_host( $row['target_host'] );
		$connections[] = array(
			'source_plugin'          => $row['source_plugin'],
			'host'                   => $row['target_host'],
			'service_name'           => ! empty( $host_info['known'] ) ? $host_info['name'] : null,
			'recognised_service'     => ! empty( $host_info['known'] ),
			'expects_personal_data'  => ! empty( $host_info['expects_pii'] ),
			'requests'               => (int) $row['requests'],
			'errors'                 => (int) $row['errors'],
			'personal_data_requests' => (int) $row['pii'],
			'avg_latency_ms'         => isset( $row['avg_latency'] ) ? (int) round( $row['avg_latency'] ) : 0,
			'last_seen'              => cmgr_ability_time( $row['last_seen'] ),
		);
	}

	return array( 'connections' => $connections );
}

function cmgr_ability_get_recent_requests( $input = array() ) {
	global $wpdb;

	$host   = isset( $input['host'] ) ? strtolower( (string) $input['host'] ) : '';
	$source = isset( $input['source_plugin'] ) ? (string) $input['source_plugin'] : '';
	$limit  = isset( $input['limit'] ) ? min( 100, max( 1, (int) $input['limit'] ) ) : 25;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT source_plugin, target_host, target_url, method, response_status, duration_ms, error, pii_types, created_at
			FROM %i
			WHERE ( %s = '' OR target_host = %s )
				AND ( %s = '' OR source_plugin = %s )
				AND ( %d = 0 OR is_error = 1 )
				AND ( %d = 0 OR pii_detected = 1 )
			ORDER BY id DESC
			LIMIT %d",
			cmgr_events_table(),
			$host,
			$host,
			$source,
			$source,
			empty( $input['errors_only'] ) ? 0 : 1,
			empty( $input['personal_data_only'] ) ? 0 : 1,
			$limit
		),
		ARRAY_A
	);

	$requests = array();
	foreach ( $rows as $row ) {
		$pii_types  = json_decode( (string) $row['pii_types'], true );
		$requests[] = array(
			'time'                => cmgr_ability_time( $row['created_at'] ),
			'source_plugin'       => $row['source_plugin'],
			'host'                => $row['target_host'],
			'url'                 => $row['target_url'],
			'method'              => $row['method'],
			'status'              => null === $row['response_status'] ? null : (int) $row['response_status'],
			'duration_ms'         => (int) $row['duration_ms'],
			'error'               => $row['error'] ? $row['error'] : null,
			'personal_data_types' => is_array( $pii_types ) ? $pii_types : array(),
		);
	}

	return array( 'requests' => $requests );
}

// ---------------------------------------------------------------------------
// Privacy policy suggestion
// ---------------------------------------------------------------------------

add_action( 'admin_init', 'cmgr_add_privacy_policy_content' );

function cmgr_add_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	wp_add_privacy_policy_content(
		__( 'Wiretrail Connection Manager', 'wiretrail-connection-manager' ),
		wp_kses_post(
			wpautop(
				__( 'This site records metadata about outbound requests its plugins and themes make to third-party services (destination, timing, status, and whether personal data appeared in the request). Personal data and secrets are redacted before anything is stored, request and response bodies are not stored, and the records stay in this site\'s database and are not sent anywhere. Records are deleted automatically after the configured retention period.', 'wiretrail-connection-manager' )
			)
		)
	);
}

// ---------------------------------------------------------------------------
// Lifecycle
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, 'cmgr_activation' );
register_deactivation_hook( __FILE__, 'cmgr_deactivation' );

function cmgr_activation() {
	cmgr_install();
	set_transient( 'cmgr_activation_notice', true, 30 );
}

function cmgr_deactivation() {
	wp_clear_scheduled_hook( 'cmgr_daily_retention_cleanup' );
}

add_action( 'admin_notices', 'cmgr_show_activation_notice' );

function cmgr_show_activation_notice() {
	if ( ! get_transient( 'cmgr_activation_notice' ) ) {
		return;
	}
	delete_transient( 'cmgr_activation_notice' );
	?>
	<div class="notice notice-info is-dismissible">
		<p>
			<?php esc_html_e( 'Wiretrail Connection Manager is now recording outbound connections. Data stays on this site.', 'wiretrail-connection-manager' ); ?>
			<a href="<?php echo esc_url( cmgr_admin_url() ); ?>"><?php esc_html_e( 'View connections', 'wiretrail-connection-manager' ); ?></a>
		</p>
	</div>
	<?php
}
