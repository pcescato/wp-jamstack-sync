<?php
/**
 * Media Processor Class
 *
 * @package WPJamstack
 */

declare(strict_types=1);

namespace WPJamstack\Core;

use Intervention\Image\ImageManager;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Process and optimize images for Hugo static site
 *
 * Downloads images from WordPress media library, generates optimized
 * WebP and AVIF versions, and uploads to GitHub repository.
 */
class Media_Processor {

	/**
	 * Git API instance
	 *
	 * @var Git_API
	 */
	private Git_API $git_api;

	/**
	 * Image manager instance
	 *
	 * @var ImageManager|null
	 */
	private ?ImageManager $image_manager = null;

	/**
	 * Temporary directory for image processing
	 *
	 * @var string
	 */
	private string $temp_dir;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->git_api = new Git_API();
		$this->temp_dir = sys_get_temp_dir() . '/wpjamstack-images';

		// Initialize Intervention Image with optimal driver
		if ( class_exists( 'Intervention\Image\ImageManager' ) ) {
			$driver = $this->detect_optimal_driver();
			
			try {
				$this->image_manager = new ImageManager( array( 'driver' => $driver ) );
				
				Logger::info(
					'Media Processor initialized',
					array(
						'driver'           => $driver,
						'imagick_version'  => extension_loaded( 'imagick' ) ? phpversion( 'imagick' ) : 'N/A',
						'gd_version'       => extension_loaded( 'gd' ) ? 'available' : 'N/A',
					)
				);
			} catch ( \Exception $e ) {
				// Fallback to GD if Imagick initialization fails
				$this->image_manager = new ImageManager( array( 'driver' => 'gd' ) );
				
				Logger::warning(
					'Imagick driver failed, falling back to GD',
					array(
						'error'  => $e->getMessage(),
						'driver' => 'gd',
					)
				);
			}
		} else {
			Logger::error( 'Intervention Image library not available' );
		}

		// Ensure temp directory exists
		if ( ! file_exists( $this->temp_dir ) ) {
			wp_mkdir_p( $this->temp_dir );
		}
	}

	/**
	 * Detect optimal image processing driver
	 *
	 * Prefers Imagick for better performance and memory efficiency.
	 * Falls back to GD if Imagick is not available.
	 *
	 * @return string Driver name ('imagick' or 'gd').
	 */
	private function detect_optimal_driver(): string {
		// Check if Imagick extension is loaded
		if ( extension_loaded( 'imagick' ) ) {
			// Verify Imagick class exists
			if ( class_exists( 'Imagick' ) ) {
				Logger::info(
					'Imagick extension detected',
					array(
						'version'      => phpversion( 'imagick' ),
						'imagemagick'  => defined( 'Imagick::IMAGICK_EXTNUM' ) ? Imagick::getVersion()['versionString'] : 'unknown',
					)
				);
				return 'imagick';
			}
		}

		// Fallback to GD
		Logger::info(
			'Using GD driver',
			array(
				'reason' => 'Imagick extension not available',
				'gd_available' => extension_loaded( 'gd' ),
			)
		);
		
		return 'gd';
	}

	/**
	 * Process all images in post content
	 *
	 * Extracts image URLs, downloads, optimizes, and uploads to GitHub.
	 * Returns array mapping original URLs to new relative paths.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Post content HTML.
	 *
	 * @return array|\WP_Error Array of URL mappings or WP_Error on failure.
	 */
	public function process_post_images( int $post_id, string $content ): array|\WP_Error {
		Logger::info(
			'Starting image processing for post',
			array( 'post_id' => $post_id )
		);

		// Extract image URLs from content
		$image_urls = $this->extract_image_urls( $content );

		if ( empty( $image_urls ) ) {
			Logger::info(
				'No images found in post content',
				array( 'post_id' => $post_id )
			);
			return array();
		}

		Logger::info(
			'Found images to process',
			array(
				'post_id' => $post_id,
				'count'   => count( $image_urls ),
			)
		);

		$url_mappings = array();

		foreach ( $image_urls as $original_url ) {
			$result = $this->process_single_image( $post_id, $original_url );

			if ( is_wp_error( $result ) ) {
				Logger::error(
					'Failed to process image',
					array(
						'post_id' => $post_id,
						'url'     => $original_url,
						'error'   => $result->get_error_message(),
					)
				);
				// Continue processing other images instead of failing completely
				continue;
			}

			$url_mappings[ $original_url ] = $result;
		}

		// Cleanup temp files
		$this->cleanup_temp_files( $post_id );

		Logger::success(
			'Image processing complete',
			array(
				'post_id'   => $post_id,
				'processed' => count( $url_mappings ),
				'total'     => count( $image_urls ),
			)
		);

		return $url_mappings;
	}

	/**
	 * Process post's featured image
	 *
	 * Downloads, optimizes, and uploads featured image to GitHub.
	 * Returns relative path for Hugo front matter.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string|null Relative path to featured image or null if none.
	 */
	public function process_featured_image( int $post_id ): ?string {
		// Check if post has featured image
		$thumbnail_id = get_post_thumbnail_id( $post_id );

		if ( ! $thumbnail_id ) {
			Logger::info(
				'No featured image set for post',
				array( 'post_id' => $post_id )
			);
			return null;
		}

		Logger::info(
			'Processing featured image',
			array(
				'post_id'      => $post_id,
				'thumbnail_id' => $thumbnail_id,
			)
		);

		// Get full-size image URL
		$image_data = wp_get_attachment_image_src( $thumbnail_id, 'full' );

		if ( ! $image_data || ! isset( $image_data[0] ) ) {
			Logger::error(
				'Failed to get featured image URL',
				array(
					'post_id'      => $post_id,
					'thumbnail_id' => $thumbnail_id,
				)
			);
			return null;
		}

		$image_url = $image_data[0];

		// Download image
		$local_path = $this->download_image( $image_url, $post_id );

		if ( is_wp_error( $local_path ) ) {
			Logger::error(
				'Failed to download featured image',
				array(
					'post_id' => $post_id,
					'error'   => $local_path->get_error_message(),
				)
			);
			return null;
		}

		// Generate WebP version with specific filename
		$webp_path = $this->generate_featured_webp( $local_path, $post_id );

		if ( is_wp_error( $webp_path ) ) {
			Logger::error(
				'Failed to generate featured WebP',
				array(
					'post_id' => $post_id,
					'error'   => $webp_path->get_error_message(),
				)
			);
			return null;
		}

		// Upload to GitHub
		$github_path = sprintf( 'static/images/%d/featured.webp', $post_id );

		// Check if file already exists
		$existing_file = $this->git_api->get_file( $github_path );
		$sha           = null;

		if ( ! is_wp_error( $existing_file ) && isset( $existing_file['sha'] ) ) {
			$sha = $existing_file['sha'];
		}

		// Read file content
		$content = file_get_contents( $webp_path );
		if ( false === $content ) {
			Logger::error(
				'Failed to read featured image file',
				array( 'post_id' => $post_id )
			);
			return null;
		}

		// Upload to GitHub
		$commit_message = sprintf( 'Upload featured image for post #%d', $post_id );
		$result         = $this->git_api->create_or_update_file(
			$github_path,
			$content,
			$commit_message,
			$sha
		);

		if ( is_wp_error( $result ) ) {
			Logger::error(
				'Failed to upload featured image to GitHub',
				array(
					'post_id' => $post_id,
					'error'   => $result->get_error_message(),
				)
			);
			return null;
		}

		Logger::success(
			'Featured image uploaded to GitHub',
			array(
				'post_id' => $post_id,
				'path'    => $github_path,
			)
		);

		// Return relative path for Hugo
		return sprintf( '/images/%d/featured.webp', $post_id );
	}

	/**
	 * Extract image URLs from HTML content
	 *
	 * @param string $content HTML content.
	 *
	 * @return array Array of image URLs.
	 */
	private function extract_image_urls( string $content ): array {
		$urls = array();

		// Match img tags and extract src attributes
		preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );

		if ( ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $url ) {
				// Only process images from this WordPress site
				if ( $this->is_local_image( $url ) ) {
					$urls[] = $url;
				}
			}
		}

		// Remove duplicates
		return array_unique( $urls );
	}

	/**
	 * Check if image URL is from this WordPress site
	 *
	 * @param string $url Image URL.
	 *
	 * @return bool True if local image.
	 */
	private function is_local_image( string $url ): bool {
		$site_url = site_url();
		return strpos( $url, $site_url ) === 0;
	}

	/**
	 * Process single image
	 *
	 * Downloads, optimizes, and uploads image to GitHub.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $original_url Original image URL.
	 *
	 * @return string|\WP_Error Relative path to image or WP_Error on failure.
	 */
	private function process_single_image( int $post_id, string $original_url ): string|\WP_Error {
		// Download image to temp directory
		$local_path = $this->download_image( $original_url, $post_id );

		if ( is_wp_error( $local_path ) ) {
			return $local_path;
		}

		// Get filename without extension
		$filename = basename( $local_path );
		$pathinfo = pathinfo( $filename );
		$basename = $pathinfo['filename'];

		// Generate optimized versions
		$optimized_files = array();

		// Generate WebP version
		$webp_path = $this->generate_webp( $local_path, $post_id, $basename );
		if ( ! is_wp_error( $webp_path ) ) {
			$optimized_files['webp'] = $webp_path;
		}

		// Generate AVIF version (if supported)
		$avif_path = $this->generate_avif( $local_path, $post_id, $basename );
		if ( ! is_wp_error( $avif_path ) ) {
			$optimized_files['avif'] = $avif_path;
		}

		// Keep original if optimizations failed
		if ( empty( $optimized_files ) ) {
			$optimized_files['original'] = $local_path;
		}

		// Upload to GitHub
		$upload_result = $this->upload_images_to_github( $post_id, $optimized_files );

		if ( is_wp_error( $upload_result ) ) {
			return $upload_result;
		}

		// Return relative path (prefer WebP)
		$preferred_format = isset( $optimized_files['webp'] ) ? 'webp' : ( isset( $optimized_files['avif'] ) ? 'avif' : 'original' );
		$preferred_file   = basename( $optimized_files[ $preferred_format ] );

		return sprintf( '/images/%d/%s', $post_id, $preferred_file );
	}

	/**
	 * Download image from URL
	 *
	 * @param string $url     Image URL.
	 * @param int    $post_id Post ID.
	 *
	 * @return string|\WP_Error Local file path or WP_Error on failure.
	 */
	private function download_image( string $url, int $post_id ): string|\WP_Error {
		// Create post-specific temp directory
		$post_temp_dir = $this->temp_dir . '/' . $post_id;
		if ( ! file_exists( $post_temp_dir ) ) {
			wp_mkdir_p( $post_temp_dir );
		}

		$filename = basename( parse_url( $url, PHP_URL_PATH ) );
		$filepath = $post_temp_dir . '/' . $filename;

		// Download using wp_remote_get
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'stream'  => true,
				'filename' => $filepath,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'download_failed',
				sprintf(
					'Failed to download image: %s',
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new \WP_Error(
				'download_failed',
				sprintf( 'Image download failed with HTTP %d', $status_code )
			);
		}

		if ( ! file_exists( $filepath ) ) {
			return new \WP_Error( 'file_not_created', 'Downloaded file not found' );
		}

		return $filepath;
	}

	/**
	 * Generate WebP version of image
	 *
	 * @param string $source_path Source image path.
	 * @param int    $post_id     Post ID.
	 * @param string $basename    Base filename.
	 *
	 * @return string|\WP_Error WebP file path or WP_Error on failure.
	 */
	private function generate_webp( string $source_path, int $post_id, string $basename ): string|\WP_Error {
		if ( null === $this->image_manager ) {
			return new \WP_Error( 'intervention_unavailable', 'Intervention Image not available' );
		}

		try {
			$output_path = $this->temp_dir . '/' . $post_id . '/' . $basename . '.webp';

			$image = $this->image_manager->make( $source_path );
			$image->encode( 'webp', 85 ); // 85% quality
			$image->save( $output_path );

			Logger::info(
				'Generated WebP image',
				array(
					'post_id' => $post_id,
					'file'    => basename( $output_path ),
					'size'    => filesize( $output_path ),
				)
			);

			return $output_path;

		} catch ( \Exception $e ) {
			return new \WP_Error(
				'webp_generation_failed',
				sprintf( 'WebP generation failed: %s', $e->getMessage() )
			);
		}
	}

	/**
	 * Generate WebP version of featured image
	 *
	 * Similar to generate_webp but uses fixed filename "featured.webp"
	 *
	 * @param string $source_path Source image path.
	 * @param int    $post_id     Post ID.
	 *
	 * @return string|\WP_Error WebP file path or WP_Error on failure.
	 */
	private function generate_featured_webp( string $source_path, int $post_id ): string|\WP_Error {
		if ( null === $this->image_manager ) {
			return new \WP_Error( 'intervention_unavailable', 'Intervention Image not available' );
		}

		try {
			$output_path = $this->temp_dir . '/' . $post_id . '/featured.webp';

			$image = $this->image_manager->make( $source_path );
			$image->encode( 'webp', 85 ); // 85% quality
			$image->save( $output_path );

			Logger::info(
				'Generated featured WebP image',
				array(
					'post_id' => $post_id,
					'file'    => 'featured.webp',
					'size'    => filesize( $output_path ),
				)
			);

			return $output_path;

		} catch ( \Exception $e ) {
			return new \WP_Error(
				'webp_generation_failed',
				sprintf( 'Featured WebP generation failed: %s', $e->getMessage() )
			);
		}
	}

	/**
	 * Generate AVIF version of image
	 *
	 * @param string $source_path Source image path.
	 * @param int    $post_id     Post ID.
	 * @param string $basename    Base filename.
	 *
	 * @return string|\WP_Error AVIF file path or WP_Error on failure.
	 */
	private function generate_avif( string $source_path, int $post_id, string $basename ): string|\WP_Error {
		// AVIF support depends on GD/Imagick version
		// For now, log that it's not implemented
		Logger::info(
			'AVIF generation not yet implemented',
			array( 'post_id' => $post_id )
		);

		return new \WP_Error( 'avif_not_supported', 'AVIF generation not yet implemented' );
	}

	/**
	 * Upload optimized images to GitHub
	 *
	 * @param int   $post_id Post ID.
	 * @param array $files   Array of file paths to upload.
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	private function upload_images_to_github( int $post_id, array $files ): bool|\WP_Error {
		foreach ( $files as $format => $filepath ) {
			if ( ! file_exists( $filepath ) ) {
				continue;
			}

			$filename    = basename( $filepath );
			$github_path = sprintf( 'static/images/%d/%s', $post_id, $filename );

			// Read file content
			$content = file_get_contents( $filepath );
			if ( false === $content ) {
				Logger::error(
					'Failed to read image file',
					array(
						'post_id' => $post_id,
						'file'    => $filepath,
					)
				);
				continue;
			}

			// Check if file already exists on GitHub
			$existing_file = $this->git_api->get_file( $github_path );
			$sha           = null;

			if ( ! is_wp_error( $existing_file ) && isset( $existing_file['sha'] ) ) {
				$sha = $existing_file['sha'];
			}

			// Upload to GitHub
			$commit_message = sprintf(
				'Upload %s image for post #%d',
				strtoupper( $format ),
				$post_id
			);

			$result = $this->git_api->create_or_update_file(
				$github_path,
				$content,
				$commit_message,
				$sha
			);

			if ( is_wp_error( $result ) ) {
				Logger::error(
					'Failed to upload image to GitHub',
					array(
						'post_id' => $post_id,
						'file'    => $filename,
						'error'   => $result->get_error_message(),
					)
				);
				return $result;
			}

			Logger::success(
				'Uploaded image to GitHub',
				array(
					'post_id' => $post_id,
					'file'    => $filename,
					'path'    => $github_path,
				)
			);
		}

		return true;
	}

	/**
	 * Cleanup temporary files for post
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	private function cleanup_temp_files( int $post_id ): void {
		$post_temp_dir = $this->temp_dir . '/' . $post_id;

		if ( ! file_exists( $post_temp_dir ) ) {
			return;
		}

		// Remove all files in directory
		$files = glob( $post_temp_dir . '/*' );
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}

		// Remove directory
		rmdir( $post_temp_dir );

		Logger::info(
			'Cleaned up temporary files',
			array( 'post_id' => $post_id )
		);
	}
}
