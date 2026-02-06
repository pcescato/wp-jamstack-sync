<?php
/**
 * Queue Manager Class
 *
 * @package WPJamstack
 */

declare(strict_types=1);

namespace WPJamstack\Core;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Asynchronous queue operations manager
 *
 * Handles background sync tasks using Action Scheduler (preferred)
 * or WordPress cron (fallback). All async operations must use this manager.
 *
 * Architecture rules:
 * - This is the ONLY async entry point
 * - All real sync work delegated to Sync_Runner
 * - No business logic in this class
 * - No direct GitHub API calls
 */
class Queue_Manager {

	/**
	 * Action hook for processing sync tasks
	 */
	public const SYNC_HOOK = 'wpjamstack_sync_post';

	/**
	 * Post meta key for sync status
	 */
	public const META_STATUS = '_jamstack_sync_status';

	/**
	 * Post meta key for sync timestamp
	 */
	public const META_TIMESTAMP = '_jamstack_sync_timestamp';

	/**
	 * Post meta key for retry counter
	 */
	public const META_RETRY_COUNT = '_jamstack_retry_count';

	/**
	 * Transient prefix for processing locks
	 */
	public const LOCK_PREFIX = 'jamstack_lock_';

	/**
	 * Status constants
	 */
	public const STATUS_PENDING    = 'pending';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_SUCCESS    = 'success';
	public const STATUS_ERROR      = 'error';
	public const STATUS_CANCELLED  = 'cancelled';

	/**
	 * Maximum retry attempts
	 */
	public const MAX_RETRIES = 3;

	/**
	 * Lock expiration time in seconds
	 */
	public const LOCK_EXPIRATION = 60;

	/**
	 * Initialize queue manager hooks
	 *
	 * @return void
	 */
	public static function init(): void {
		// Register sync processing hook
		add_action( self::SYNC_HOOK, array( __CLASS__, 'process_sync' ), 10, 1 );
	}

	/**
	 * Enqueue a post for synchronization
	 *
	 * Production-safe enqueue with:
	 * - Duplicate job prevention
	 * - Race condition protection
	 * - Retry limit enforcement
	 * - Status validation
	 *
	 * @param int $post_id  Post ID to enqueue.
	 * @param int $priority Priority level (lower = higher priority). Default 10.
	 *
	 * @return void
	 */
	public static function enqueue( int $post_id, int $priority = 10 ): void {
		// Verify post exists
		if ( ! get_post( $post_id ) ) {
			Logger::error(
				'Cannot enqueue non-existent post',
				array( 'post_id' => $post_id )
			);
			return;
		}

		// Check current status - prevent duplicate jobs
		$current_status = self::get_status( $post_id );
		if ( in_array( $current_status, array( self::STATUS_PENDING, self::STATUS_PROCESSING ), true ) ) {
			Logger::info(
				'Skipping duplicate sync job',
				array(
					'post_id' => $post_id,
					'status'  => $current_status,
				)
			);
			return;
		}

		// Check retry limit
		$retry_count = (int) get_post_meta( $post_id, self::META_RETRY_COUNT, true );
		if ( $retry_count >= self::MAX_RETRIES ) {
			Logger::warning(
				'Max retries reached, not enqueuing',
				array(
					'post_id'     => $post_id,
					'retry_count' => $retry_count,
				)
			);
			return;
		}

		// Increment retry counter if this is a retry (status was error)
		if ( self::STATUS_ERROR === $current_status ) {
			$retry_count++;
			update_post_meta( $post_id, self::META_RETRY_COUNT, $retry_count );

			Logger::info(
				'Retry attempt',
				array(
					'post_id' => $post_id,
					'attempt' => $retry_count,
				)
			);
		} else {
			// Reset retry counter for fresh enqueue
			delete_post_meta( $post_id, self::META_RETRY_COUNT );
		}

		// Check if already scheduled (additional safety)
		if ( self::is_scheduled( $post_id ) ) {
			Logger::info(
				'Post already scheduled, skipping',
				array( 'post_id' => $post_id )
			);
			return;
		}

		// Update post meta to pending status
		update_post_meta( $post_id, self::META_STATUS, self::STATUS_PENDING );
		update_post_meta( $post_id, self::META_TIMESTAMP, time() );

		// Enqueue async job
		if ( self::has_action_scheduler() ) {
			// Use Action Scheduler (preferred)
			as_enqueue_async_action(
				self::SYNC_HOOK,
				array( 'post_id' => $post_id ),
				'',
				false,
				$priority
			);

			Logger::info(
				'Post enqueued via Action Scheduler',
				array(
					'post_id'  => $post_id,
					'priority' => $priority,
					'retry'    => $retry_count,
				)
			);
		} else {
			// Fallback to WordPress cron
			wp_schedule_single_event(
				time(),
				self::SYNC_HOOK,
				array( 'post_id' => $post_id )
			);

			Logger::info(
				'Post enqueued via WP Cron (fallback)',
				array(
					'post_id' => $post_id,
					'retry'   => $retry_count,
				)
			);
		}
	}

	/**
	 * Cancel pending sync task for a post
	 *
	 * Removes scheduled action, clears lock, and resets all meta.
	 * Production-safe with complete cleanup.
	 *
	 * @param int $post_id Post ID to cancel sync for.
	 *
	 * @return void
	 */
	public static function cancel( int $post_id ): void {
		// Remove scheduled actions
		if ( self::has_action_scheduler() ) {
			as_unschedule_all_actions( self::SYNC_HOOK, array( 'post_id' => $post_id ) );
		} else {
			$timestamp = wp_next_scheduled( self::SYNC_HOOK, array( 'post_id' => $post_id ) );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::SYNC_HOOK, array( 'post_id' => $post_id ) );
			}
		}

		// Release lock if held
		self::release_lock( $post_id );

		// Update status to cancelled
		update_post_meta( $post_id, self::META_STATUS, self::STATUS_CANCELLED );
		update_post_meta( $post_id, self::META_TIMESTAMP, time() );

		// Reset retry counter
		delete_post_meta( $post_id, self::META_RETRY_COUNT );

		Logger::info( 'Sync cancelled', array( 'post_id' => $post_id ) );
	}

	/**
	 * Get sync status for post(s)
	 *
	 * Returns current sync status from post meta.
	 * If post_id is null, returns array of all posts with sync status.
	 *
	 * @param int|null $post_id Optional post ID. If null, return all statuses.
	 *
	 * @return string|array Single status string or array of statuses.
	 */
	public static function get_status( ?int $post_id = null ): string|array {
		if ( null === $post_id ) {
			// Return all posts with sync status
			global $wpdb;

			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value as status 
					FROM {$wpdb->postmeta} 
					WHERE meta_key = %s 
					ORDER BY post_id DESC",
					self::META_STATUS
				),
				ARRAY_A
			);

			$statuses = array();
			foreach ( $results as $row ) {
				$statuses[ (int) $row['post_id'] ] = $row['status'];
			}

			return $statuses;
		}

		// Return single post status
		$status = get_post_meta( $post_id, self::META_STATUS, true );

		return ! empty( $status ) ? (string) $status : 'unknown';
	}

	/**
	 * Retry all failed sync tasks
	 *
	 * Queries all posts with error status and re-enqueues them.
	 * Respects retry limits. Useful for bulk retry after fixing issues.
	 *
	 * @return void
	 */
	public static function retry_failed(): void {
		global $wpdb;

		// Find all posts with error status
		$failed_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_id, p.meta_value as retry_count
				FROM {$wpdb->postmeta} p
				WHERE p.meta_key = %s 
				AND p.meta_value = %s
				AND p.post_id IN (
					SELECT post_id FROM {$wpdb->postmeta}
					WHERE meta_key = %s
				)",
				self::META_STATUS,
				self::STATUS_ERROR,
				self::META_RETRY_COUNT
			),
			ARRAY_A
		);

		if ( empty( $failed_posts ) ) {
			Logger::info( 'No failed posts to retry' );
			return;
		}

		$count         = 0;
		$skipped_count = 0;

		foreach ( $failed_posts as $row ) {
			$post_id     = (int) $row['post_id'];
			$retry_count = (int) ( $row['retry_count'] ?? 0 );

			// Skip if max retries reached
			if ( $retry_count >= self::MAX_RETRIES ) {
				$skipped_count++;
				continue;
			}

			// Re-enqueue (will increment retry counter)
			self::enqueue( $post_id );
			$count++;
		}

		Logger::info(
			'Retry initiated for failed posts',
			array(
				'retried' => $count,
				'skipped' => $skipped_count,
			)
		);
	}

	/**
	 * Process sync task (callback for async hook)
	 *
	 * Production-hardened processing with:
	 * - Lock acquisition/release
	 * - Retry limit enforcement
	 * - Graceful error handling
	 * - Status lifecycle management
	 *
	 * @param int $post_id Post ID to sync.
	 *
	 * @return void
	 */
	public static function process_sync( int $post_id ): void {
		// Verify post exists
		$post = get_post( $post_id );
		if ( ! $post ) {
			Logger::error(
				'Cannot sync non-existent post',
				array( 'post_id' => $post_id )
			);
			return;
		}

		// Acquire lock to prevent concurrent processing
		if ( ! self::acquire_lock( $post_id ) ) {
			Logger::warning(
				'Could not acquire lock, sync already in progress',
				array( 'post_id' => $post_id )
			);
			return;
		}

		// Check retry limit
		$retry_count = (int) get_post_meta( $post_id, self::META_RETRY_COUNT, true );
		if ( $retry_count >= self::MAX_RETRIES ) {
			Logger::error(
				'Max retries exceeded',
				array(
					'post_id'     => $post_id,
					'retry_count' => $retry_count,
				)
			);

			// Update to error status and release lock
			update_post_meta( $post_id, self::META_STATUS, self::STATUS_ERROR );
			self::release_lock( $post_id );
			return;
		}

		// Update status to processing
		update_post_meta( $post_id, self::META_STATUS, self::STATUS_PROCESSING );

		Logger::info(
			'Starting sync process',
			array(
				'post_id' => $post_id,
				'title'   => $post->post_title,
				'attempt' => $retry_count + 1,
			)
		);

		// Delegate to Sync_Runner (all real work happens there)
		$result = Sync_Runner::run( $post_id );

		// Update status based on result
		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, self::META_STATUS, self::STATUS_ERROR );

			Logger::error(
				'Sync failed',
				array(
					'post_id' => $post_id,
					'error'   => $result->get_error_message(),
					'code'    => $result->get_error_code(),
					'attempt' => $retry_count + 1,
				)
			);
		} else {
			// Success - reset retry counter
			update_post_meta( $post_id, self::META_STATUS, self::STATUS_SUCCESS );
			update_post_meta( $post_id, self::META_TIMESTAMP, time() );
			delete_post_meta( $post_id, self::META_RETRY_COUNT );

			Logger::success(
				'Sync completed',
				array(
					'post_id' => $post_id,
					'result'  => $result,
				)
			);
		}

		// Always release lock
		self::release_lock( $post_id );
	}

	/**
	 * Check if post already has scheduled action
	 *
	 * @param int $post_id Post ID to check.
	 *
	 * @return bool True if action is scheduled.
	 */
	private static function is_scheduled( int $post_id ): bool {
		if ( self::has_action_scheduler() ) {
			return as_has_scheduled_action( self::SYNC_HOOK, array( 'post_id' => $post_id ) );
		}

		return (bool) wp_next_scheduled( self::SYNC_HOOK, array( 'post_id' => $post_id ) );
	}

	/**
	 * Acquire processing lock for post
	 *
	 * Uses transient with auto-expiration to prevent deadlocks.
	 * Returns false if lock is already held.
	 *
	 * @param int $post_id Post ID to lock.
	 *
	 * @return bool True if lock acquired, false if already locked.
	 */
	private static function acquire_lock( int $post_id ): bool {
		$lock_key = self::LOCK_PREFIX . $post_id;

		// Try to set transient (returns false if already exists)
		$acquired = set_transient( $lock_key, time(), self::LOCK_EXPIRATION );

		if ( $acquired ) {
			Logger::info(
				'Lock acquired',
				array( 'post_id' => $post_id )
			);
		}

		return $acquired;
	}

	/**
	 * Release processing lock for post
	 *
	 * @param int $post_id Post ID to unlock.
	 *
	 * @return void
	 */
	private static function release_lock( int $post_id ): void {
		$lock_key = self::LOCK_PREFIX . $post_id;
		delete_transient( $lock_key );

		Logger::info(
			'Lock released',
			array( 'post_id' => $post_id )
		);
	}

	/**
	 * Check if Action Scheduler is available
	 *
	 * @return bool True if Action Scheduler functions exist.
	 */
	private static function has_action_scheduler(): bool {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& function_exists( 'as_has_scheduled_action' );
	}
}

