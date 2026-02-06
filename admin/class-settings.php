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
		add_action( 'wp_ajax_wpjamstack_bulk_sync', array( __CLASS__, 'ajax_bulk_sync' ) );
		add_action( 'wp_ajax_wpjamstack_get_stats', array( __CLASS__, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_wpjamstack_sync_single', array( __CLASS__, 'ajax_sync_single' ) );
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

		// Post Types Section
		add_settings_section(
			'wpjamstack_posttypes_section',
			__( 'Content Types', 'wp-jamstack-sync' ),
			array( __CLASS__, 'render_posttypes_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'enabled_post_types',
			__( 'Synchronize', 'wp-jamstack-sync' ),
			array( __CLASS__, 'render_posttypes_field' ),
			self::PAGE_SLUG,
			'wpjamstack_posttypes_section'
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

		// Sanitize enabled post types
		if ( ! empty( $input['enabled_post_types'] ) && is_array( $input['enabled_post_types'] ) ) {
			// Only allow 'post' and 'page'
			$sanitized['enabled_post_types'] = array_intersect(
				$input['enabled_post_types'],
				array( 'post', 'page' )
			);
		} else {
			// Default to posts only
			$sanitized['enabled_post_types'] = array( 'post' );
		}

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
	 * Render post types section description
	 *
	 * @return void
	 */
	public static function render_posttypes_section(): void {
		echo '<p>';
		esc_html_e( 'Choose which content types should be synchronized to your Hugo site.', 'wp-jamstack-sync' );
		echo '</p>';
	}

	/**
	 * Render post types checkboxes
	 *
	 * @return void
	 */
	public static function render_posttypes_field(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		$enabled = $settings['enabled_post_types'] ?? array( 'post' );

		$post_types = array(
			'post' => array(
				'label' => __( 'Posts', 'wp-jamstack-sync' ),
				'description' => __( 'Standard blog posts (synced to content/posts/)', 'wp-jamstack-sync' ),
			),
			'page' => array(
				'label' => __( 'Pages', 'wp-jamstack-sync' ),
				'description' => __( 'Static pages (synced to content/)', 'wp-jamstack-sync' ),
			),
		);

		foreach ( $post_types as $type => $info ) :
			?>
			<label style="display: block; margin-bottom: 10px;">
				<input
					type="checkbox"
					name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled_post_types][]"
					value="<?php echo esc_attr( $type ); ?>"
					<?php checked( in_array( $type, $enabled, true ) ); ?>
				/>
				<strong><?php echo esc_html( $info['label'] ); ?></strong>
				<br />
				<span class="description" style="margin-left: 20px;">
					<?php echo esc_html( $info['description'] ); ?>
				</span>
			</label>
			<?php
		endforeach;
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

		// Get active tab
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<!-- Tab Navigation -->
			<h2 class="nav-tab-wrapper">
				<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=settings" 
				   class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'wp-jamstack-sync' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=bulk" 
				   class="nav-tab <?php echo $active_tab === 'bulk' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Bulk Operations', 'wp-jamstack-sync' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=monitor" 
				   class="nav-tab <?php echo $active_tab === 'monitor' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Sync History', 'wp-jamstack-sync' ); ?>
				</a>
			</h2>

			<?php
			// Render active tab content
			switch ( $active_tab ) {
				case 'bulk':
					self::render_bulk_tab();
					break;
				case 'monitor':
					self::render_monitor_tab();
					break;
				case 'settings':
				default:
					self::render_settings_tab();
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render settings tab content
	 *
	 * @return void
	 */
	private static function render_settings_tab(): void {
		?>
		<?php settings_errors( self::OPTION_NAME ); ?>
		
		<form method="post" action="options.php">
			<?php
			settings_fields( self::PAGE_SLUG );
			do_settings_sections( self::PAGE_SLUG );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Render bulk operations tab
	 *
	 * @return void
	 */
	private static function render_bulk_tab(): void {
		?>
			
			<div id="wpjamstack-bulk-sync-section">
				<button type="button" id="wpjamstack-bulk-sync-button" class="button button-secondary">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Synchronize All Posts', 'wp-jamstack-sync' ); ?>
				</button>
				
				<div id="wpjamstack-bulk-status" style="margin-top: 15px; display: none;">
					<p>
						<strong><?php esc_html_e( 'Bulk Sync Status:', 'wp-jamstack-sync' ); ?></strong>
						<span id="wpjamstack-bulk-message"></span>
					</p>
					<div class="wpjamstack-progress-bar" style="background: #f0f0f1; height: 30px; border-radius: 3px; overflow: hidden; position: relative;">
						<div id="wpjamstack-progress-fill" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>
						<div id="wpjamstack-progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #2c3338; font-weight: 600;"></div>
					</div>
				</div>

				<div id="wpjamstack-queue-stats" style="margin-top: 20px;">
					<h3><?php esc_html_e( 'Queue Statistics', 'wp-jamstack-sync' ); ?></h3>
					<table class="widefat" style="max-width: 600px;">
						<tbody>
							<tr>
								<td><?php esc_html_e( 'Total Posts:', 'wp-jamstack-sync' ); ?></td>
								<td><strong id="stat-total">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Successfully Synced:', 'wp-jamstack-sync' ); ?></td>
								<td><strong id="stat-success" style="color: #46b450;">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Pending:', 'wp-jamstack-sync' ); ?></td>
								<td><strong id="stat-pending" style="color: #f0ad4e;">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Processing:', 'wp-jamstack-sync' ); ?></td>
								<td><strong id="stat-processing" style="color: #0073aa;">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Errors:', 'wp-jamstack-sync' ); ?></td>
								<td><strong id="stat-error" style="color: #dc3232;">-</strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Not Synced:', 'wp-jamstack-sync' ); ?></td>
								<td><strong id="stat-not-synced">-</strong></td>
							</tr>
						</tbody>
					</table>
					<button type="button" id="wpjamstack-refresh-stats" class="button button-small" style="margin-top: 10px;">
						<?php esc_html_e( 'Refresh Stats', 'wp-jamstack-sync' ); ?>
					</button>
				</div>
			</div>

			<script>
			jQuery(document).ready(function($) {
				// Load initial stats
				loadStats();

				// Bulk sync button
				$('#wpjamstack-bulk-sync-button').on('click', function() {
					if (!confirm('<?php echo esc_js( __( 'Are you sure you want to synchronize all published posts? This may take several minutes.', 'wp-jamstack-sync' ) ); ?>')) {
						return;
					}

					var $button = $(this);
					var $status = $('#wpjamstack-bulk-status');
					var $message = $('#wpjamstack-bulk-message');

					$button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> <?php esc_html_e( 'Starting...', 'wp-jamstack-sync' ); ?>');
					$status.show();

					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wpjamstack_bulk_sync',
							nonce: '<?php echo esc_js( wp_create_nonce( 'wpjamstack-bulk-sync' ) ); ?>'
						},
						success: function(response) {
							if (response.success) {
								$message.html('✓ ' + response.data.message);
								$('#wpjamstack-progress-text').text(response.data.enqueued + ' / ' + response.data.total + ' posts enqueued');
								$('#wpjamstack-progress-fill').css('width', '100%');
								
								// Start polling
								startPolling();
							} else {
								$message.html('✗ ' + response.data.message);
							}
							$button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Synchronize All Posts', 'wp-jamstack-sync' ); ?>');
						},
						error: function() {
							$message.html('✗ <?php echo esc_js( __( 'Request failed', 'wp-jamstack-sync' ) ); ?>');
							$button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Synchronize All Posts', 'wp-jamstack-sync' ); ?>');
						}
					});
				});

				// Refresh stats button
				$('#wpjamstack-refresh-stats').on('click', loadStats);

				// Load stats function
				function loadStats() {
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wpjamstack_get_stats',
							nonce: '<?php echo esc_js( wp_create_nonce( 'wpjamstack-get-stats' ) ); ?>'
						},
						success: function(response) {
							if (response.success) {
								var stats = response.data;
								$('#stat-total').text(stats.total);
								$('#stat-success').text(stats.success);
								$('#stat-pending').text(stats.pending);
								$('#stat-processing').text(stats.processing);
								$('#stat-error').text(stats.error);
								$('#stat-not-synced').text(stats.not_synced);
							}
						}
					});
				}

				// Polling function to update progress
				var pollInterval;
				function startPolling() {
					pollInterval = setInterval(function() {
						loadStats();
						
						// Check if done
						var pending = parseInt($('#stat-pending').text());
						var processing = parseInt($('#stat-processing').text());
						
						if (pending === 0 && processing === 0) {
							clearInterval(pollInterval);
							$('#wpjamstack-bulk-message').html('✓ <?php echo esc_js( __( 'Bulk sync completed!', 'wp-jamstack-sync' ) ); ?>');
						}
					}, 3000); // Poll every 3 seconds
				}
			});
			</script>

			<style>
			.dashicons.spin {
				animation: spin 1s linear infinite;
			}
			@keyframes spin {
				0% { transform: rotate(0deg); }
				100% { transform: rotate(360deg); }
			}
			</style>
		<?php
	}

	/**
	 * Render sync history monitor tab
	 *
	 * @return void
	 */
	private static function render_monitor_tab(): void {
		?>
		<h2><?php esc_html_e( 'Sync History', 'wp-jamstack-sync' ); ?></h2>
		<p><?php esc_html_e( 'View the most recent sync operations and their status.', 'wp-jamstack-sync' ); ?></p>

		<?php
		// Query posts with sync meta
		$query = new \WP_Query( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'any',
			'posts_per_page' => 20,
			'orderby'        => 'meta_value',
			'order'          => 'DESC',
			'meta_key'       => '_jamstack_sync_last',
			'meta_query'     => array(
				array(
					'key'     => '_jamstack_sync_status',
					'compare' => 'EXISTS',
				),
			),
		) );

		if ( ! $query->have_posts() ) {
			?>
			<div class="notice notice-info">
				<p><?php esc_html_e( 'No sync history found. Sync a post to see it appear here.', 'wp-jamstack-sync' ); ?></p>
			</div>
			<?php
			return;
		}
		?>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" class="manage-column column-primary" style="width: 40%;">
						<?php esc_html_e( 'Post Title', 'wp-jamstack-sync' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 80px;">
						<?php esc_html_e( 'ID', 'wp-jamstack-sync' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 100px;">
						<?php esc_html_e( 'Type', 'wp-jamstack-sync' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 120px;">
						<?php esc_html_e( 'Status', 'wp-jamstack-sync' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 180px;">
						<?php esc_html_e( 'Last Sync', 'wp-jamstack-sync' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 120px;">
						<?php esc_html_e( 'Commit', 'wp-jamstack-sync' ); ?>
					</th>
					<th scope="col" class="manage-column" style="width: 120px;">
						<?php esc_html_e( 'Actions', 'wp-jamstack-sync' ); ?>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php
				while ( $query->have_posts() ) :
					$query->the_post();
					$post_id = get_the_ID();
					$status = get_post_meta( $post_id, '_jamstack_sync_status', true );
					$last_sync = get_post_meta( $post_id, '_jamstack_sync_last', true );
					$commit_url = get_post_meta( $post_id, '_jamstack_last_commit_url', true );
					$post_type = get_post_type( $post_id );

					// Status icon and color
					$status_icon = '';
					$status_color = '';
					$status_label = '';

					switch ( $status ) {
						case 'success':
							$status_icon = '●';
							$status_color = '#46b450';
							$status_label = __( 'Success', 'wp-jamstack-sync' );
							break;
						case 'error':
							$status_icon = '●';
							$status_color = '#dc3232';
							$status_label = __( 'Error', 'wp-jamstack-sync' );
							break;
						case 'processing':
							$status_icon = '◐';
							$status_color = '#0073aa';
							$status_label = __( 'Processing', 'wp-jamstack-sync' );
							break;
						case 'pending':
							$status_icon = '○';
							$status_color = '#f0ad4e';
							$status_label = __( 'Pending', 'wp-jamstack-sync' );
							break;
						default:
							$status_icon = '○';
							$status_color = '#999';
							$status_label = ucfirst( $status );
							break;
					}

					// Format last sync time
					$time_ago = $last_sync ? human_time_diff( strtotime( $last_sync ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'wp-jamstack-sync' ) : __( 'Never', 'wp-jamstack-sync' );
					?>
					<tr>
						<td class="column-primary">
							<strong>
								<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>">
									<?php echo esc_html( get_the_title() ); ?>
								</a>
							</strong>
						</td>
						<td><?php echo esc_html( $post_id ); ?></td>
						<td><?php echo esc_html( ucfirst( $post_type ) ); ?></td>
						<td>
							<span style="color: <?php echo esc_attr( $status_color ); ?>; font-size: 20px;" title="<?php echo esc_attr( $status_label ); ?>">
								<?php echo esc_html( $status_icon ); ?>
							</span>
							<?php echo esc_html( $status_label ); ?>
						</td>
						<td><?php echo esc_html( $time_ago ); ?></td>
						<td>
							<?php if ( $commit_url ) : ?>
								<a href="<?php echo esc_url( $commit_url ); ?>" target="_blank" class="button button-small">
									<span class="dashicons dashicons-external" style="font-size: 13px; width: 13px; height: 13px;"></span>
									<?php esc_html_e( 'View Commit', 'wp-jamstack-sync' ); ?>
								</a>
							<?php else : ?>
								<span style="color: #999;">—</span>
							<?php endif; ?>
						</td>
						<td>
							<button type="button" 
									class="button button-small wpjamstack-sync-now" 
									data-post-id="<?php echo esc_attr( $post_id ); ?>"
									<?php echo $status === 'processing' ? 'disabled' : ''; ?>>
								<span class="dashicons dashicons-update" style="font-size: 13px; width: 13px; height: 13px;"></span>
								<?php esc_html_e( 'Sync Now', 'wp-jamstack-sync' ); ?>
							</button>
						</td>
					</tr>
				<?php endwhile; ?>
			</tbody>
		</table>

		<?php wp_reset_postdata(); ?>

		<script>
		jQuery(document).ready(function($) {
			$('.wpjamstack-sync-now').on('click', function() {
				var $button = $(this);
				var postId = $button.data('post-id');
				
				$button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span> <?php esc_html_e( 'Syncing...', 'wp-jamstack-sync' ); ?>');
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'wpjamstack_sync_single',
						nonce: '<?php echo esc_js( wp_create_nonce( 'wpjamstack-sync-single' ) ); ?>',
						post_id: postId
					},
					success: function(response) {
						if (response.success) {
							$button.html('<span class="dashicons dashicons-yes" style="color: #46b450;"></span> <?php esc_html_e( 'Synced!', 'wp-jamstack-sync' ); ?>');
							// Reload page after 2 seconds
							setTimeout(function() {
								location.reload();
							}, 2000);
						} else {
							$button.html('<span class="dashicons dashicons-no" style="color: #dc3232;"></span> ' + response.data.message);
							$button.prop('disabled', false);
							setTimeout(function() {
								$button.html('<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync Now', 'wp-jamstack-sync' ); ?>');
							}, 3000);
						}
					},
					error: function() {
						$button.html('<span class="dashicons dashicons-no"></span> <?php esc_html_e( 'Error', 'wp-jamstack-sync' ); ?>');
						$button.prop('disabled', false);
						setTimeout(function() {
							$button.html('<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync Now', 'wp-jamstack-sync' ); ?>');
						}, 3000);
					}
				});
			});
		});
		</script>

		<style>
		.dashicons-spin {
			animation: wpjamstack-spin 1s linear infinite;
		}
		@keyframes wpjamstack-spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}
		</style>
		<?php
	}

	/**
	 * AJAX handler for bulk sync
	 *
	 * @return void
	 */
	public static function ajax_bulk_sync(): void {
		check_ajax_referer( 'wpjamstack-bulk-sync', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'wp-jamstack-sync' ) ) );
		}

		$result = \WPJamstack\Core\Queue_Manager::bulk_enqueue();

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: 1: Number of posts enqueued, 2: Total posts, 3: Number skipped */
					__( '%1$d of %2$d posts enqueued for sync (%3$d already in queue).', 'wp-jamstack-sync' ),
					$result['enqueued'],
					$result['total'],
					$result['skipped']
				),
				'total'    => $result['total'],
				'enqueued' => $result['enqueued'],
				'skipped'  => $result['skipped'],
			)
		);
	}

	/**
	 * AJAX handler for queue statistics
	 *
	 * @return void
	 */
	public static function ajax_get_stats(): void {
		check_ajax_referer( 'wpjamstack-get-stats', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'wp-jamstack-sync' ) ) );
		}

		$stats = \WPJamstack\Core\Queue_Manager::get_queue_stats();

		wp_send_json_success( $stats );
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

	/**
	 * AJAX handler for single post sync
	 *
	 * @return void
	 */
	public static function ajax_sync_single(): void {
		check_ajax_referer( 'wpjamstack-sync-single', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'wp-jamstack-sync' ) ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID', 'wp-jamstack-sync' ) ) );
		}

		// Enqueue the post for sync
		require_once WPJAMSTACK_PATH . 'core/class-queue-manager.php';
		\WPJamstack\Core\Queue_Manager::enqueue( $post_id, 5 ); // High priority

		wp_send_json_success( array(
			'message' => __( 'Post enqueued for synchronization', 'wp-jamstack-sync' ),
			'post_id' => $post_id,
		) );
	}
}
