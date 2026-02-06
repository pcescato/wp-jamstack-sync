<?php
/**
 * Posts List Columns Class
 *
 * @package WPJamstack
 */

declare(strict_types=1);

namespace WPJamstack\Admin;

use WPJamstack\Core\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Manage custom columns in Posts list
 *
 * Displays sync status with visual indicators in the WordPress admin.
 */
class Columns {

	/**
	 * Initialize columns
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'manage_posts_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'admin_head', array( __CLASS__, 'add_column_styles' ) );
	}

	/**
	 * Add Jamstack Sync column to posts list
	 *
	 * @param array $columns Existing columns.
	 *
	 * @return array Modified columns.
	 */
	public static function add_column( array $columns ): array {
		// Insert after title column
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'title' === $key ) {
				$new_columns['jamstack_sync'] = __( 'Jamstack Sync', 'wp-jamstack-sync' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render column content
	 *
	 * @param string $column_name Column identifier.
	 * @param int    $post_id     Post ID.
	 *
	 * @return void
	 */
	public static function render_column( string $column_name, int $post_id ): void {
		if ( 'jamstack_sync' !== $column_name ) {
			return;
		}

		$status = Queue_Manager::get_status( $post_id );
		$icon   = self::get_status_icon( $status );
		$label  = self::get_status_label( $status );
		$class  = 'jamstack-status jamstack-status-' . esc_attr( $status );

		printf(
			'<span class="%s" title="%s">%s %s</span>',
			esc_attr( $class ),
			esc_attr( $label ),
			$icon, // Already escaped in get_status_icon
			esc_html( $label )
		);

		// Show timestamp if available
		$timestamp = get_post_meta( $post_id, Queue_Manager::META_TIMESTAMP, true );
		if ( $timestamp ) {
			$time_diff = human_time_diff( (int) $timestamp, current_time( 'timestamp' ) );
			printf(
				'<br><small class="jamstack-timestamp">%s %s</small>',
				esc_html( $label === __( 'Success', 'wp-jamstack-sync' ) ? __( 'Synced', 'wp-jamstack-sync' ) : __( 'Updated', 'wp-jamstack-sync' ) ),
				esc_html( sprintf( __( '%s ago', 'wp-jamstack-sync' ), $time_diff ) )
			);
		}
	}

	/**
	 * Get status icon (dashicon or emoji)
	 *
	 * @param string $status Sync status.
	 *
	 * @return string Icon HTML.
	 */
	private static function get_status_icon( string $status ): string {
		$icons = array(
			Queue_Manager::STATUS_PENDING    => '<span class="dashicons dashicons-clock"></span>',
			Queue_Manager::STATUS_PROCESSING => '<span class="dashicons dashicons-update spin"></span>',
			Queue_Manager::STATUS_SUCCESS    => '<span class="dashicons dashicons-yes-alt"></span>',
			Queue_Manager::STATUS_ERROR      => '<span class="dashicons dashicons-warning"></span>',
			Queue_Manager::STATUS_CANCELLED  => '<span class="dashicons dashicons-dismiss"></span>',
			'unknown'                        => '<span class="dashicons dashicons-minus"></span>',
		);

		return $icons[ $status ] ?? $icons['unknown'];
	}

	/**
	 * Get status label
	 *
	 * @param string $status Sync status.
	 *
	 * @return string Human-readable label.
	 */
	private static function get_status_label( string $status ): string {
		$labels = array(
			Queue_Manager::STATUS_PENDING    => __( 'Pending', 'wp-jamstack-sync' ),
			Queue_Manager::STATUS_PROCESSING => __( 'Processing', 'wp-jamstack-sync' ),
			Queue_Manager::STATUS_SUCCESS    => __( 'Success', 'wp-jamstack-sync' ),
			Queue_Manager::STATUS_ERROR      => __( 'Error', 'wp-jamstack-sync' ),
			Queue_Manager::STATUS_CANCELLED  => __( 'Cancelled', 'wp-jamstack-sync' ),
			'unknown'                        => __( 'Not Synced', 'wp-jamstack-sync' ),
		);

		return $labels[ $status ] ?? $labels['unknown'];
	}

	/**
	 * Add inline styles for status column
	 *
	 * @return void
	 */
	public static function add_column_styles(): void {
		?>
		<style>
			.jamstack-status {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				font-weight: 500;
			}
			.jamstack-status .dashicons {
				font-size: 18px;
				width: 18px;
				height: 18px;
			}
			.jamstack-status-pending .dashicons {
				color: #f0b849;
			}
			.jamstack-status-processing .dashicons {
				color: #0073aa;
			}
			.jamstack-status-success .dashicons {
				color: #46b450;
			}
			.jamstack-status-error .dashicons {
				color: #dc3232;
			}
			.jamstack-status-cancelled .dashicons {
				color: #82878c;
			}
			.jamstack-status-unknown .dashicons {
				color: #dcdcde;
			}
			.jamstack-timestamp {
				color: #646970;
			}
			@keyframes spin {
				from { transform: rotate(0deg); }
				to { transform: rotate(360deg); }
			}
			.jamstack-status .spin {
				animation: spin 1s linear infinite;
			}
		</style>
		<?php
	}
}
