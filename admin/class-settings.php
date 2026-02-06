<?php
/**
 * Settings Page Class
 *
 * @package WPJamstack
 */

declare(strict_types=1);

namespace WPJamstack\Admin;

use WPJamstack\Core\Git_API;
use WPJamstack\Core\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Settings page management
 *
 * Handles plugin settings registration, rendering, and validation.
 */
class Settings {

	/**
	 * Option name for settings
	 */
	public const OPTION_NAME = 'wpjamstack_settings';

	/**
	 * Settings page slug
	 */
	public const PAGE_SLUG = 'wpjamstack-settings';

	/**
	 * Initialize settings
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_wpjamstack_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
	}

	/**
	 * Register settings fields
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::PAGE_SLUG,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);

		// GitHub Settings Section
		add_settings_section(
			'wpjamstack_github_section',
			__( 'GitHub Configuration', 'wp-jamstack-sync' ),
			array( __CLASS__, 'render_github_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'github_repo',
			__( 'Repository', 'wp-jamstack-sync' ),
			array( __CLASS__, 'render_repo_field' ),
			self::PAGE_SLUG,
			'wpjamstack_github_section'
		);

		add_settings_field(
			'github_branch',
			__( 'Branch', 'wp-jamstack-sync' ),
			array( __CLASS__, 'render_branch_field' ),
			self::PAGE_SLUG,
			'wpjamstack_github_section'
		);

		add_settings_field(
			'github_token',
			__( 'Personal Access Token', 'wp-jamstack-sync' ),
			array( __CLASS__, 'render_token_field' ),
			self::PAGE_SLUG,
			'wpjamstack_github_section'
		);

		// Debug Settings Section
		add_settings_section(
			'wpjamstack_debug_section',
			__( 'Debug Settings', 'wp-jamstack-sync' ),
			array( __CLASS__, 'render_debug_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'debug_mode',
			__( 'Enable Debug Logging', 'wp-jamstack-sync' ),
			array( __CLASS__, 'render_debug_field' ),
			self::PAGE_SLUG,
			'wpjamstack_debug_section'
		);
	}

	/**
	 * Sanitize settings before saving
	 *
	 * @param array $input Raw input values.
	 *
	 * @return array Sanitized values.
	 */
	public static function sanitize_settings( array $input ): array {
		$sanitized = array();

		// Sanitize repository (owner/repo format)
		if ( ! empty( $input['github_repo'] ) ) {
			$sanitized['github_repo'] = sanitize_text_field( $input['github_repo'] );

			// Validate format
			if ( substr_count( $sanitized['github_repo'], '/' ) !== 1 ) {
				add_settings_error(
					self::OPTION_NAME,
					'invalid_repo',
					__( 'Repository must be in format: owner/repo', 'wp-jamstack-sync' ),
					'error'
				);
			}
		}

		// Sanitize branch
		if ( ! empty( $input['github_branch'] ) ) {
			$sanitized['github_branch'] = sanitize_text_field( $input['github_branch'] );
		} else {
			$sanitized['github_branch'] = 'main';
		}

		// Sanitize and encrypt token
		if ( ! empty( $input['github_token'] ) ) {
			$token = sanitize_text_field( trim( $input['github_token'] ) );
			$sanitized['github_token'] = self::encrypt_token( $token );
		}

		// Sanitize debug mode checkbox
		$sanitized['debug_mode'] = ! empty( $input['debug_mode'] );

		return $sanitized;
	}

	/**
	 * Encrypt GitHub token using AES-256-CBC
	 *
	 * Uses WordPress salts for encryption key and IV.
	 *
	 * @param string $token Plain text token.
	 *
	 * @return string Encrypted token (base64 encoded).
	 */
	private static function encrypt_token( string $token ): string {
		$method = 'AES-256-CBC';
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv     = substr( hash( 'sha256', wp_salt( 'nonce' ), true ), 0, 16 );

		$encrypted = openssl_encrypt( $token, $method, $key, 0, $iv );

		return base64_encode( $encrypted );
	}

	/**
	 * Decrypt GitHub token
	 *
	 * @param string $encrypted_token Encrypted token (base64 encoded).
	 *
	 * @return string Plain text token.
	 */
	public static function decrypt_token( string $encrypted_token ): string {
		$method = 'AES-256-CBC';
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv     = substr( hash( 'sha256', wp_salt( 'nonce' ), true ), 0, 16 );

		$decoded   = base64_decode( $encrypted_token );
		$decrypted = openssl_decrypt( $decoded, $method, $key, 0, $iv );

		return $decrypted ? $decrypted : '';
	}

	/**
	 * Render GitHub section description
	 *
	 * @return void
	 */
	public static function render_github_section(): void {
		echo '<p>';
		esc_html_e( 'Configure your GitHub repository connection. You will need a Personal Access Token with repository write permissions.', 'wp-jamstack-sync' );
		echo '</p>';
	}

	/**
	 * Render repository field
	 *
	 * @return void
	 */
	public static function render_repo_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		$value    = $settings['github_repo'] ?? '';
		?>
		<input 
			type="text" 
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[github_repo]" 
			value="<?php echo esc_attr( $value ); ?>" 
			class="regular-text" 
			placeholder="owner/repository"
			required
		/>
		<p class="description">
			<?php esc_html_e( 'Format: owner/repository (e.g., johndoe/my-hugo-site)', 'wp-jamstack-sync' ); ?>
		</p>
		<?php
	}

	/**
	 * Render branch field
	 *
	 * @return void
	 */
	public static function render_branch_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		$value    = $settings['github_branch'] ?? 'main';
		?>
		<input 
			type="text" 
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[github_branch]" 
			value="<?php echo esc_attr( $value ); ?>" 
			class="regular-text" 
			placeholder="main"
		/>
		<p class="description">
			<?php esc_html_e( 'Target branch for commits (default: main)', 'wp-jamstack-sync' ); ?>
		</p>
		<?php
	}

	/**
	 * Render token field
	 *
	 * @return void
	 */
	public static function render_token_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		$value    = $settings['github_token'] ?? '';
		?>
		<input 
			type="password" 
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[github_token]" 
			value="<?php echo esc_attr( $value ); ?>" 
			class="regular-text" 
			placeholder="ghp_xxxxxxxxxxxx"
			required
		/>
		<p class="description">
			<?php
			printf(
				/* translators: %s: GitHub tokens URL */
				esc_html__( 'Create a token at %s with repo permissions.', 'wp-jamstack-sync' ),
				'<a href="https://github.com/settings/tokens" target="_blank" rel="noopener">github.com/settings/tokens</a>'
			);
			?>
		</p>
		<p>
			<button type="button" id="wpjamstack-test-connection" class="button button-secondary">
				<?php esc_html_e( 'Test Connection', 'wp-jamstack-sync' ); ?>
			</button>
			<span id="wpjamstack-test-result"></span>
		</p>
		<?php
	}

	/**
	 * Render debug section description
	 *
	 * @return void
	 */
	public static function render_debug_section(): void {
		echo '<p>';
		esc_html_e( 'Enable debug logging to troubleshoot sync issues.', 'wp-jamstack-sync' );
		echo '</p>';
	}

	/**
	 * Render debug field
	 *
	 * @return void
	 */
	public static function render_debug_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! empty( $settings['debug_mode'] );
		?>
		<label>
			<input 
				type="checkbox" 
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[debug_mode]" 
				value="1"
				<?php checked( $checked ); ?>
			/>
			<?php esc_html_e( 'Enable detailed logging for debugging', 'wp-jamstack-sync' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Logs will be written to wp-content/uploads/wpjamstack-logs/', 'wp-jamstack-sync' ); ?>
		</p>
		<?php
	}

	/**
	 * Render settings page
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<?php settings_errors( self::OPTION_NAME ); ?>
			
			<form method="post" action="options.php">
				<?php
				settings_fields( self::PAGE_SLUG );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * AJAX handler for connection test
	 *
	 * @return void
	 */
	public static function ajax_test_connection(): void {
		check_ajax_referer( 'wpjamstack-test-connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'wp-jamstack-sync' ) ) );
		}

		$git_api = new Git_API();
		$result  = $git_api->test_connection();

		if ( is_wp_error( $result ) ) {
			Logger::error( 'Connection test failed', array( 'error' => $result->get_error_message() ) );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		Logger::success( 'Connection test successful' );
		wp_send_json_success( array( 'message' => __( 'Connection successful!', 'wp-jamstack-sync' ) ) );
	}
}
