<?php
/**
 * Sync Runner Class
 *
 * @package WPJamstack
 */

declare(strict_types=1);

namespace WPJamstack\Core;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Central sync orchestrator
 *
 * This class is the ONLY entry point for sync operations.
 * All sync logic must flow through this runner.
 */
class Sync_Runner {

	/**
	 * Run synchronization for a specific post
	 *
	 * Pipeline:
	 * 1. Validate post exists and is publishable
	 * 2. Fetch post data from database
	 * 3. Convert to Markdown via Hugo adapter
	 * 4. Upload to GitHub via API
	 * 5. Update post meta with sync status and timestamp
	 * 6. Return success metadata or WP_Error
	 *
	 * @param int $post_id Post ID to synchronize.
	 *
	 * @return array|\WP_Error Success array with metadata or WP_Error on failure.
	 */
	public static function run( int $post_id ): array|\WP_Error {
		Logger::info( 'Sync runner started', array( 'post_id' => $post_id ) );

		// Validate post
		$post = get_post( $post_id );
		if ( ! $post ) {
			Logger::error( 'Post not found', array( 'post_id' => $post_id ) );
			self::update_sync_meta( $post_id, 'error' );
			return new \WP_Error( 'post_not_found', __( 'Post not found', 'wp-jamstack-sync' ) );
		}

		// Only sync published posts
		if ( 'publish' !== $post->post_status ) {
			Logger::warning( 'Post not published, skipping sync', array( 'post_id' => $post_id, 'status' => $post->post_status ) );
			self::update_sync_meta( $post_id, 'error' );
			return new \WP_Error( 'post_not_published', __( 'Only published posts can be synced', 'wp-jamstack-sync' ) );
		}

		// Load adapter
		require_once WPJAMSTACK_PATH . 'adapters/interface-adapter.php';
		require_once WPJAMSTACK_PATH . 'adapters/class-hugo-adapter.php';

		$adapter = new \WPJamstack\Adapters\Hugo_Adapter();

		// Convert to Markdown
		try {
			$content   = $adapter->convert( $post );
			$file_path = $adapter->get_file_path( $post );
		} catch ( \Exception $e ) {
			Logger::error(
				'Adapter conversion failed',
				array(
					'post_id' => $post_id,
					'error'   => $e->getMessage(),
				)
			);
			self::update_sync_meta( $post_id, 'error' );
			return new \WP_Error( 'conversion_failed', $e->getMessage() );
		}

		// Upload to GitHub
		$result = self::upload_to_github( $post, $content, $file_path );

		if ( is_wp_error( $result ) ) {
			Logger::error( 'Sync failed', array( 'post_id' => $post_id, 'error' => $result->get_error_message() ) );
			self::update_sync_meta( $post_id, 'error' );
			return $result;
		}

		// Update success meta
		self::update_sync_meta( $post_id, 'success' );

		Logger::success( 'Sync completed', array( 'post_id' => $post_id ) );

		return array(
			'post_id'   => $post_id,
			'success'   => true,
			'commit'    => $result,
			'file_path' => $file_path,
		);
	}

	/**
	 * Upload content to GitHub
	 *
	 * @param \WP_Post $post      Post object.
	 * @param string   $content   Markdown content.
	 * @param string   $file_path Repository file path.
	 *
	 * @return array|\WP_Error Commit data or WP_Error.
	 */
	private static function upload_to_github( \WP_Post $post, string $content, string $file_path ): array|\WP_Error {
		$git_api = new Git_API();

		// Check if file exists (to get SHA for update)
		$existing_file = $git_api->get_file( $file_path );
		$sha = $existing_file['sha'] ?? null;

		// Create commit message
		$commit_message = sprintf(
			'%s: %s',
			null === $sha ? 'Create' : 'Update',
			$post->post_title
		);

		// Upload file
		$result = $git_api->create_or_update_file( $file_path, $content, $commit_message, $sha );

		return $result;
	}

	/**
	 * Update sync meta for post
	 *
	 * Updates both status and timestamp. Maintains single source of truth.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Sync status.
	 *
	 * @return void
	 */
	private static function update_sync_meta( int $post_id, string $status ): void {
		update_post_meta( $post_id, '_jamstack_sync_status', $status );
		update_post_meta( $post_id, '_jamstack_sync_last', current_time( 'mysql' ) );
	}
}
