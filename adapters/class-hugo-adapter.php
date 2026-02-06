<?php
/**
 * Hugo Adapter Class
 *
 * @package WPJamstack
 */

declare(strict_types=1);

namespace WPJamstack\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Hugo static site generator adapter
 *
 * Converts WordPress posts to Hugo-compatible Markdown format
 * with YAML front matter following Hugo conventions.
 */
class Hugo_Adapter implements Adapter_Interface {

	/**
	 * Convert WordPress post to Hugo Markdown format
	 *
	 * @param \WP_Post $post WordPress post object.
	 *
	 * @return string Complete Markdown content with YAML front matter.
	 */
	public function convert( \WP_Post $post ): string {
		$front_matter = $this->get_front_matter( $post );
		$content      = $this->convert_content( $post->post_content );

		// Build YAML front matter
		$yaml = "---\n";
		foreach ( $front_matter as $key => $value ) {
			$yaml .= $this->format_yaml_field( $key, $value );
		}
		$yaml .= "---\n\n";

		return $yaml . $content;
	}

	/**
	 * Get repository file path for Hugo post
	 *
	 * Hugo convention: content/posts/YYYY-MM-DD-slug.md
	 *
	 * @param \WP_Post $post WordPress post object.
	 *
	 * @return string Relative file path in repository.
	 */
	public function get_file_path( \WP_Post $post ): string {
		$date = get_the_date( 'Y-m-d', $post );
		$slug = $post->post_name;

		return sprintf( 'content/posts/%s-%s.md', $date, $slug );
	}

	/**
	 * Get Hugo front matter metadata
	 *
	 * @param \WP_Post $post WordPress post object.
	 *
	 * @return array Associative array of front matter fields.
	 */
	public function get_front_matter( \WP_Post $post ): array {
		$front_matter = array(
			'title'       => $post->post_title,
			'date'        => get_the_date( 'c', $post ),
			'lastmod'     => get_the_modified_date( 'c', $post ),
			'draft'       => 'publish' !== $post->post_status,
			'description' => $this->get_excerpt( $post ),
		);

		// Add tags
		$tags = $this->get_terms( $post->ID, 'post_tag' );
		if ( ! empty( $tags ) ) {
			$front_matter['tags'] = $tags;
		}

		// Add categories
		$categories = $this->get_terms( $post->ID, 'category' );
		if ( ! empty( $categories ) ) {
			$front_matter['categories'] = $categories;
		}

		// Add author
		$author = get_userdata( $post->post_author );
		if ( $author ) {
			$front_matter['author'] = $author->display_name;
		}

		// Add featured image
		$featured_image = $this->get_featured_image( $post->ID );
		if ( $featured_image ) {
			$front_matter['featured_image'] = $featured_image;
		}

		return $front_matter;
	}

	/**
	 * Convert WordPress content to Markdown
	 *
	 * Basic HTML to Markdown conversion using strip_tags.
	 * TODO: Replace with league/html-to-markdown for production.
	 *
	 * @param string $content WordPress post content (HTML).
	 *
	 * @return string Markdown content.
	 */
	private function convert_content( string $content ): string {
		// Apply WordPress content filters
		$content = apply_filters( 'the_content', $content );

		// Basic HTML to text conversion
		// TODO: Implement proper HTML to Markdown conversion
		$content = strip_tags( $content, '<p><br><strong><em><a><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><code><pre>' );

		// Basic formatting replacements
		$content = str_replace( array( '<br>', '<br/>', '<br />' ), "\n", $content );
		$content = preg_replace( '/<p>(.*?)<\/p>/s', "$1\n\n", $content );
		$content = preg_replace( '/<strong>(.*?)<\/strong>/', '**$1**', $content );
		$content = preg_replace( '/<em>(.*?)<\/em>/', '*$1*', $content );
		$content = preg_replace( '/<h1>(.*?)<\/h1>/', "# $1\n", $content );
		$content = preg_replace( '/<h2>(.*?)<\/h2>/', "## $1\n", $content );
		$content = preg_replace( '/<h3>(.*?)<\/h3>/', "### $1\n", $content );
		$content = preg_replace( '/<h4>(.*?)<\/h4>/', "#### $1\n", $content );
		$content = preg_replace( '/<h5>(.*?)<\/h5>/', "##### $1\n", $content );
		$content = preg_replace( '/<h6>(.*?)<\/h6>/', "###### $1\n", $content );
		$content = preg_replace( '/<a href="(.*?)">(.*?)<\/a>/', '[$2]($1)', $content );
		$content = preg_replace( '/<blockquote>(.*?)<\/blockquote>/s', "> $1\n", $content );

		// Clean up extra whitespace
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );
		$content = trim( $content );

		return $content;
	}

	/**
	 * Get post excerpt or generate from content
	 *
	 * @param \WP_Post $post WordPress post object.
	 *
	 * @return string Post description/excerpt.
	 */
	private function get_excerpt( \WP_Post $post ): string {
		if ( ! empty( $post->post_excerpt ) ) {
			return $post->post_excerpt;
		}

		// Generate excerpt from content
		$excerpt = wp_strip_all_tags( $post->post_content );
		$excerpt = wp_trim_words( $excerpt, 30, '...' );

		return $excerpt;
	}

	/**
	 * Get taxonomy terms for post
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return array Array of term names.
	 */
	private function get_terms( int $post_id, string $taxonomy ): array {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}

		return wp_list_pluck( $terms, 'name' );
	}

	/**
	 * Get featured image URL
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string|null Featured image URL or null if not set.
	 */
	private function get_featured_image( int $post_id ): ?string {
		$thumbnail_id = get_post_thumbnail_id( $post_id );

		if ( ! $thumbnail_id ) {
			return null;
		}

		$image_url = wp_get_attachment_url( $thumbnail_id );

		return $image_url ? $image_url : null;
	}

	/**
	 * Format YAML field
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Field value.
	 *
	 * @return string Formatted YAML line.
	 */
	private function format_yaml_field( string $key, mixed $value ): string {
		if ( is_array( $value ) ) {
			// Array format
			$yaml = "$key:\n";
			foreach ( $value as $item ) {
				$yaml .= "  - " . $this->escape_yaml_value( $item ) . "\n";
			}
			return $yaml;
		}

		if ( is_bool( $value ) ) {
			return sprintf( "%s: %s\n", $key, $value ? 'true' : 'false' );
		}

		if ( is_numeric( $value ) ) {
			return sprintf( "%s: %s\n", $key, $value );
		}

		// String value - quote if contains special characters
		$escaped = $this->escape_yaml_value( $value );
		return sprintf( "%s: %s\n", $key, $escaped );
	}

	/**
	 * Escape YAML value
	 *
	 * @param mixed $value Value to escape.
	 *
	 * @return string Escaped value.
	 */
	private function escape_yaml_value( mixed $value ): string {
		$value = (string) $value;

		// Quote if contains special characters
		if ( preg_match( '/[:\[\]{},&*#?|\-<>=!%@`"]/', $value ) || strpos( $value, "\n" ) !== false ) {
			$value = '"' . str_replace( '"', '\"', $value ) . '"';
		}

		return $value;
	}
}
