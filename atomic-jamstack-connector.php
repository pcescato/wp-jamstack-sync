<?php
/**
 * Plugin Name: Atomic Jamstack Connector
 * Plugin URI: https://github.com/pcescato/atomic-jamstack-connector
 * Description: Automated WordPress to Hugo publishing system with async GitHub API integration.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Pascal CESCATO
 * License: GPL 3+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: atomic-jamstack-connector
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

// Plugin constants
define( 'ATOMIC_JAMSTACK_VERSION', '1.0.0' );
define( 'ATOMIC_JAMSTACK_PATH', plugin_dir_path( __FILE__ ) );
define( 'ATOMIC_JAMSTACK_URL', plugin_dir_url( __FILE__ ) );

// Load Action Scheduler library before anything else
if ( file_exists( ATOMIC_JAMSTACK_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once ATOMIC_JAMSTACK_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

// Load Composer autoloader if available
if ( file_exists( ATOMIC_JAMSTACK_PATH . 'vendor/autoload.php' ) ) {
	require_once ATOMIC_JAMSTACK_PATH . 'vendor/autoload.php';
}

// Development .env loader (only in development environment)
if ( function_exists( 'wp_get_environment_type' ) && 'development' === wp_get_environment_type() ) {
	atomic_jamstack_load_env();
}

/**
 * Load environment variables from .env file (development only)
 *
 * @return void
 */
function atomic_jamstack_load_env() {
	$env_file = ATOMIC_JAMSTACK_PATH . '.env';
	
	if ( ! file_exists( $env_file ) ) {
		return;
	}
	
	$lines = @file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	
	if ( false === $lines ) {
		return;
	}
	
	foreach ( $lines as $line ) {
		// Skip comments
		if ( strpos( trim( $line ), '#' ) === 0 ) {
			continue;
		}
		
		// Parse KEY=VALUE pairs
		if ( strpos( $line, '=' ) !== false ) {
			list( $key, $value ) = explode( '=', $line, 2 );
			$key   = trim( $key );
			$value = trim( $value );
			
			// Remove quotes if present
			$value = trim( $value, '"' );
			$value = trim( $value, "'" );
			
			// Set in $_ENV and putenv
			$_ENV[ $key ] = $value;
			putenv( "$key=$value" );
		}
	}
}

/**
 * Activation hook - verify system requirements
 *
 * @return void
 */
function atomic_jamstack_activate() {
	global $wp_version;
	
	$errors = array();
	
	// Check WordPress version
	if ( version_compare( $wp_version, '6.9', '<' ) ) {
		$errors[] = sprintf(
			/* translators: %s: Required WordPress version */
			__( 'Atomic Jamstack Connector requires WordPress %s or higher.', 'atomic-jamstack-connector' ),
			'6.9'
		);
	}
	
	// Check PHP version
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		$errors[] = sprintf(
			/* translators: %s: Required PHP version */
			__( 'Atomic Jamstack Connector requires PHP %s or higher.', 'atomic-jamstack-connector' ),
			'8.1'
		);
	}
	
	// If errors exist, deactivate and show message
	if ( ! empty( $errors ) ) {
		// Deactivate the plugin
		deactivate_plugins( plugin_basename( __FILE__ ) );
		
		// Show error message
		wp_die(
			'<h1>' . esc_html__( 'Plugin Activation Failed', 'atomic-jamstack-connector' ) . '</h1>' .
			'<p>' . implode( '</p><p>', array_map( 'esc_html', $errors ) ) . '</p>' .
			'<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' .
			esc_html__( 'Return to Plugins', 'atomic-jamstack-connector' ) . '</a></p>',
			esc_html__( 'Plugin Activation Error', 'atomic-jamstack-connector' ),
			array( 'back_link' => true )
		);
	}
	
	// Activation successful - future tasks go here
	// (e.g., create tables, set default options, schedule cron)
}

/**
 * Deactivation hook - cleanup tasks
 *
 * @return void
 */
function atomic_jamstack_deactivate() {
	// Future cleanup tasks go here
	// (e.g., clear scheduled tasks, flush caches)
}

/**
 * Load plugin text domain for translations
 *
 * @return void
 */
function atomic_jamstack_load_textdomain() {
	load_plugin_textdomain(
		'atomic-jamstack-connector',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'plugins_loaded', 'atomic_jamstack_load_textdomain' );

// Register activation/deactivation hooks
register_activation_hook( __FILE__, 'atomic_jamstack_activate' );
register_deactivation_hook( __FILE__, 'atomic_jamstack_deactivate' );

// Load main plugin class
require_once ATOMIC_JAMSTACK_PATH . 'core/class-plugin.php';

// Initialize plugin
\AtomicJamstack\Core\Plugin::get_instance();
