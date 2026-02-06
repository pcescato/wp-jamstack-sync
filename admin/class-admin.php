<?php
/**
 * Admin UI Class
 *
 * @package WPJamstack
 */

declare(strict_types=1);

namespace WPJamstack\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Admin interface coordinator
 *
 * Manages admin menus, scripts, and settings registration.
 */
class Admin {

	/**
	 * Initialize admin hooks
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_pages' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		// Initialize settings and columns
		Settings::init();
		Columns::init();
	}

	/**
	 * Add admin menu pages
	 *
	 * @return void
	 */
	public static function add_menu_pages(): void {
		add_options_page(
			__( 'Jamstack Sync Settings', 'wp-jamstack-sync' ),
			__( 'Jamstack Sync', 'wp-jamstack-sync' ),
			'manage_options',
			Settings::PAGE_SLUG,
			array( Settings::class, 'render_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 */
	public static function enqueue_scripts( string $hook ): void {
		// Only load on plugin settings page
		if ( 'settings_page_' . Settings::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue admin styles
		wp_enqueue_style(
			'wpjamstack-admin',
			WPJAMSTACK_URL . 'assets/css/admin.css',
			array(),
			WPJAMSTACK_VERSION
		);

		// Enqueue admin scripts
		wp_enqueue_script(
			'wpjamstack-admin',
			WPJAMSTACK_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			WPJAMSTACK_VERSION,
			true
		);

		// Localize script for AJAX
		wp_localize_script(
			'wpjamstack-admin',
			'wpjamstackAdmin',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'testConnectionNonce' => wp_create_nonce( 'wpjamstack-test-connection' ),
				'strings'            => array(
					'testing'  => __( 'Testing connection...', 'wp-jamstack-sync' ),
					'success'  => __( 'Connection successful!', 'wp-jamstack-sync' ),
					'error'    => __( 'Connection failed:', 'wp-jamstack-sync' ),
				),
			)
		);
	}
}
