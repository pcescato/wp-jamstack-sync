<?php
/**
 * Adapter Interface
 *
 * @package WPJamstack
 */

declare(strict_types=1);

namespace WPJamstack\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Adapter interface for static site generators
 *
 * Defines the contract for converting WordPress content
 * to static site generator formats (Hugo, Astro, etc.).
 */
interface Adapter_Interface {

	/**
	 * Convert WordPress post to Markdown format
	 *
	 * Transforms post content, metadata, and structure into
	 * Markdown with appropriate front matter for the target SSG.
	 *
	 * @param \WP_Post $post WordPress post object.
	 *
	 * @return string Complete Markdown content with front matter.
	 */
	public function convert( \WP_Post $post ): string;

	/**
	 * Get repository file path for post
	 *
	 * Generates the target file path in the repository
	 * following SSG conventions (e.g., Hugo's content structure).
	 *
	 * @param \WP_Post $post WordPress post object.
	 *
	 * @return string Relative file path in repository (e.g., "content/posts/2024-01-15-my-post.md").
	 */
	public function get_file_path( \WP_Post $post ): string;

	/**
	 * Get front matter metadata for post
	 *
	 * Extracts and formats post metadata according to SSG requirements.
	 * Returns associative array ready for YAML/TOML serialization.
	 *
	 * @param \WP_Post $post WordPress post object.
	 *
	 * @return array Associative array of front matter fields.
	 */
	public function get_front_matter( \WP_Post $post ): array;
}
