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
	 * Improved HTML to Markdown conversion with better handling
	 * of common WordPress content patterns.
	 *
	 * TODO: Replace with league/html-to-markdown for production.
	 *
	 * @param string $content WordPress post content (HTML).
	 *
	 * @return string Markdown content.
	 */
	private function convert_content( string $content ): string {
		// Apply WordPress content filters
		$content = apply_filters( 'the_content', $content );

		// Convert WordPress shortcodes to readable text
		$content = strip_shortcodes( $content );

		// Handle common WordPress blocks (Gutenberg)
		$content = $this->convert_gutenberg_blocks( $content );

		// Convert HTML to Markdown
		$content = $this->html_to_markdown( $content );

		// Clean up extra whitespace
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );
		$content = trim( $content );

		return $content;
	}

	/**
	 * Convert Gutenberg blocks to Markdown
	 *
	 * @param string $content Content with Gutenberg blocks.
	 *
	 * @return string Content with blocks converted.
	 */
	private function convert_gutenberg_blocks( string $content ): string {
		// Remove block comments but keep content
		$content = preg_replace( '/<!-- wp:.*?-->/s', '', $content );
		$content = preg_replace( '/<!-- \/wp:.*?-->/s', '', $content );

		return $content;
	}

	/**
	 * Convert HTML to Markdown
	 *
	 * @param string $html HTML content.
	 *
	 * @return string Markdown content.
	 */
	private function html_to_markdown( string $html ): string {
		// Convert headings
		$html = preg_replace( '/<h1[^>]*>(.*?)<\/h1>/is', "\n\n# $1\n\n", $html );
		$html = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/is', "\n\n## $1\n\n", $html );
		$html = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/is', "\n\n### $1\n\n", $html );
		$html = preg_replace( '/<h4[^>]*>(.*?)<\/h4>/is', "\n\n#### $1\n\n", $html );
		$html = preg_replace( '/<h5[^>]*>(.*?)<\/h5>/is', "\n\n##### $1\n\n", $html );
		$html = preg_replace( '/<h6[^>]*>(.*?)<\/h6>/is', "\n\n###### $1\n\n", $html );

		// Convert bold and italic
		$html = preg_replace( '/<(strong|b)[^>]*>(.*?)<\/\1>/is', '**$2**', $html );
		$html = preg_replace( '/<(em|i)[^>]*>(.*?)<\/\1>/is', '*$2*', $html );

		// Convert links
		$html = preg_replace_callback(
			'/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
			function( $matches ) {
				return sprintf( '[%s](%s)', $matches[2], $matches[1] );
			},
			$html
		);

		// Convert images
		$html = preg_replace_callback(
			'/<img[^>]+src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*\/?>/is',
			function( $matches ) {
				return sprintf( '![%s](%s)', $matches[2], $matches[1] );
			},
			$html
		);
		$html = preg_replace_callback(
			'/<img[^>]+src=["\']([^"\']+)["\'][^>]*\/?>/is',
			function( $matches ) {
				return sprintf( '![](%s)', $matches[1] );
			},
			$html
		);

		// Convert lists
		$html = preg_replace( '/<ul[^>]*>/is', "\n", $html );
		$html = preg_replace( '/<\/ul>/is', "\n", $html );
		$html = preg_replace( '/<ol[^>]*>/is', "\n", $html );
		$html = preg_replace( '/<\/ol>/is', "\n", $html );
		$html = preg_replace( '/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $html );

		// Convert blockquotes
		$html = preg_replace( '/<blockquote[^>]*>(.*?)<\/blockquote>/is', "\n> $1\n", $html );

		// Convert code blocks
		$html = preg_replace( '/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/is', "\n```\n$1\n```\n", $html );
		$html = preg_replace( '/<code[^>]*>(.*?)<\/code>/is', '`$1`', $html );

		// Convert line breaks and paragraphs
		$html = preg_replace( '/<br\s*\/?>/is', "\n", $html );
		$html = preg_replace( '/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $html );

		// Remove remaining HTML tags
		$html = strip_tags( $html );

		// Decode HTML entities
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return $html;
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
