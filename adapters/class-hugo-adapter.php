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
	 * @param \WP_Post $post                WordPress post object.
	 * @param array    $image_mapping       Optional. Array mapping original URLs to new paths.
	 * @param string   $featured_image_path Optional. Processed featured image path.
	 *
	 * @return string Complete Markdown content with YAML front matter.
	 */
	public function convert( \WP_Post $post, array $image_mapping = array(), string $featured_image_path = '' ): string {
		$front_matter = $this->get_front_matter( $post, $featured_image_path );
		$content      = $this->convert_content( $post->post_content, $image_mapping );

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
	 * @param \WP_Post $post                 WordPress post object.
	 * @param string   $featured_image_path  Optional. Processed featured image path.
	 *
	 * @return array Associative array of front matter fields.
	 */
	public function get_front_matter( \WP_Post $post, string $featured_image_path = '' ): array {
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

		// Add featured image (use processed path if provided, otherwise get original)
		if ( ! empty( $featured_image_path ) ) {
			$front_matter['image'] = $featured_image_path;
		} else {
			$featured_image = $this->get_featured_image( $post->ID );
			if ( $featured_image ) {
				$front_matter['image'] = $featured_image;
			}
		}

		return $front_matter;
	}

	/**
	 * Convert WordPress content to Markdown
	 *
	 * Uses League\HTMLToMarkdown for professional HTML to Markdown conversion.
	 * Falls back to basic conversion if library not available.
	 *
	 * @param string $content       WordPress post content (HTML).
	 * @param array  $image_mapping Optional. Array mapping original URLs to new paths.
	 *
	 * @return string Markdown content.
	 */
	private function convert_content( string $content, array $image_mapping = array() ): string {
		// Apply WordPress content filters
		$content = apply_filters( 'the_content', $content );

		// Replace image URLs if mapping provided
		if ( ! empty( $image_mapping ) ) {
			$content = $this->replace_image_urls( $content, $image_mapping );
		}

		// Convert WordPress shortcodes to readable text
		$content = strip_shortcodes( $content );

		// Handle common WordPress blocks (Gutenberg)
		$content = $this->convert_gutenberg_blocks( $content );

		// Use League\HTMLToMarkdown if available
		if ( class_exists( '\League\HTMLToMarkdown\HtmlConverter' ) ) {
			try {
				$converter = new \League\HTMLToMarkdown\HtmlConverter( array(
					'strip_tags'         => false,
					'remove_nodes'       => 'script style',
					'hard_break'         => true,
					'header_style'       => 'atx',
					'bold_style'         => '**',
					'italic_style'       => '*',
					'suppress_errors'    => true,
				) );

				$markdown = $converter->convert( $content );
			} catch ( \Exception $e ) {
				// Fall back to basic conversion on error
				$markdown = $this->basic_html_to_markdown( $content );
			}
		} else {
			// Fall back to basic conversion if library not available
			$markdown = $this->basic_html_to_markdown( $content );
		}

		// Clean up extra whitespace
		$markdown = preg_replace( '/\n{3,}/', "\n\n", $markdown );
		$markdown = trim( $markdown );

		return $markdown;
	}

	/**
	 * Replace image URLs in content with relative paths
	 *
	 * @param string $content       HTML content.
	 * @param array  $image_mapping Array mapping original URLs to new paths.
	 *
	 * @return string Content with replaced URLs.
	 */
	private function replace_image_urls( string $content, array $image_mapping ): string {
		foreach ( $image_mapping as $original_url => $new_path ) {
			// Replace in img src attributes
			$content = str_replace(
				'src="' . $original_url . '"',
				'src="' . $new_path . '"',
				$content
			);
			$content = str_replace(
				"src='" . $original_url . "'",
				"src='" . $new_path . "'",
				$content
			);

			// Replace in markdown-style image links
			$content = str_replace(
				'](' . $original_url . ')',
				'](' . $new_path . ')',
				$content
			);

			// Replace srcset attributes (for responsive images)
			$content = preg_replace(
				'/' . preg_quote( $original_url, '/' ) . '\s+\d+w/',
				$new_path . ' $1w',
				$content
			);
		}

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
	 * Basic HTML to Markdown conversion (fallback)
	 *
	 * @param string $html HTML content.
	 *
	 * @return string Markdown content.
	 */
	private function basic_html_to_markdown( string $html ): string {
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
