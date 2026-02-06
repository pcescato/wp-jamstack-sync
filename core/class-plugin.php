<?php
/**
 * Main Plugin Bootstrap Class
 *
 * @package WPJamstack
 */

declare(strict_types=1);

namespace WPJamstack\Core;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Singleton plugin bootstrap class
 *
 * Orchestrates plugin initialization following WordPress lifecycle.
 * Controls when and how components are loaded.
 */
class Plugin {

	/**
	 * Single instance of the class
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Private constructor to prevent direct instantiation
	 */
	private function __construct() {
		// Initialization happens on plugins_loaded hook
		add_action( 'plugins_loaded', array( $this, 'init' ), 10 );
	}

	/**
	 * Get singleton instance
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize plugin components
	 *
	 * Called on plugins_loaded hook to ensure WordPress is fully loaded.
	 * Initializes core systems and loads context-specific components.
	 *
	 * @return void
	 */
	public function init(): void {
		// Load core dependencies
		$this->load_core_classes();

		// Initialize core systems
		$this->init_core_systems();

		// Load context-specific components
		if ( is_admin() ) {
			$this->load_admin();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->load_cli();
		}

		// Register WordPress hooks
		$this->register_hooks();
	}

	/**
	 * Load core class files
	 *
	 * @return void
	 */
	private function load_core_classes(): void {
		require_once WPJAMSTACK_PATH . 'core/class-logger.php';
		require_once WPJAMSTACK_PATH . 'core/class-queue-manager.php';
		require_once WPJAMSTACK_PATH . 'core/class-sync-runner.php';
		require_once WPJAMSTACK_PATH . 'core/class-git-api.php';
	}

	/**
	 * Initialize core systems
	 *
	 * Calls init() methods on core components that need to register
	 * WordPress hooks or perform early setup.
	 *
	 * @return void
	 */
	private function init_core_systems(): void {
		// Initialize queue manager (registers async processing hooks)
		Queue_Manager::init();

		// TODO: Initialize logger when persistence is implemented
		// Logger::init();
	}

	/**
	 * Load admin-related classes
	 *
	 * Only loaded in admin context for performance.
	 *
	 * @return void
	 */
	private function load_admin(): void {
		require_once WPJAMSTACK_PATH . 'admin/class-settings.php';
		require_once WPJAMSTACK_PATH . 'admin/class-columns.php';
		require_once WPJAMSTACK_PATH . 'admin/class-admin.php';
		
		\WPJamstack\Admin\Admin::init();
	}

	/**
	 * Load WP-CLI command classes
	 *
	 * Only loaded when WP-CLI is available.
	 *
	 * @return void
	 */
	private function load_cli(): void {
		// TODO: Load CLI classes when implemented
		// require_once WPJAMSTACK_PATH . 'cli/class-cli.php';
		// WP_CLI::add_command( 'jamstack', 'WPJamstack\CLI\CLI' );
	}

	/**
	 * Register WordPress hooks
	 *
	 * Registers action/filter hooks for core plugin functionality.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// TODO: Register hooks when business logic is implemented
		// add_action( 'save_post', [ $this, 'handle_post_save' ], 10, 2 );
		// add_action( 'transition_post_status', [ $this, 'handle_status_transition' ], 10, 3 );
	}

	/**
	 * Prevent cloning of singleton
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization of singleton
	 *
	 * @return void
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}
}
