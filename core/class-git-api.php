<?php
/**
 * Git API Client Class
 *
 * @package WPJamstack
 */

declare(strict_types=1);

namespace WPJamstack\Core;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not permitted.' );
}

/**
 * Remote Git API abstraction layer
 *
 * Generic interface for Git operations via HTTPS API.
 * Primary implementation: GitHub REST API
 * Future: GitLab, Bitbucket support possible
 *
 * Uses WordPress native wp_remote_* functions exclusively.
 * No shell commands, no Git CLI.
 */
class Git_API {

	/**
	 * API authentication token
	 *
	 * @var string|null
	 */
	private ?string $token = null;

	/**
	 * Repository identifier (e.g., "owner/repo")
	 *
	 * @var string|null
	 */
	private ?string $repo = null;

	/**
	 * Target branch name
	 *
	 * @var string
	 */
	private string $branch = 'main';

	/**
	 * GitHub API base URL
	 *
	 * @var string
	 */
	private string $api_base = 'https://api.github.com';

	/**
	 * Constructor
	 *
	 * Loads configuration from WordPress options.
	 * Decrypts GitHub token for use in API requests.
	 */
	public function __construct() {
		$settings = get_option( 'wpjamstack_settings', array() );

		// Decrypt token if present
		if ( ! empty( $settings['github_token'] ) ) {
			$this->token = $this->decrypt_token( $settings['github_token'] );
		}

		$this->repo   = $settings['github_repo'] ?? null;
		$this->branch = $settings['github_branch'] ?? 'main';

		// Allow custom API base URL for future multi-provider support
		if ( ! empty( $settings['api_base_url'] ) ) {
			$this->api_base = $settings['api_base_url'];
		}
	}

	/**
	 * Decrypt GitHub token
	 *
	 * @param string $encrypted_token Encrypted token.
	 *
	 * @return string Decrypted token.
	 */
	private function decrypt_token( string $encrypted_token ): string {
		$method = 'AES-256-CBC';
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv     = substr( hash( 'sha256', wp_salt( 'nonce' ), true ), 0, 16 );

		$decoded   = base64_decode( $encrypted_token );
		$decrypted = openssl_decrypt( $decoded, $method, $key, 0, $iv );

		return $decrypted ? $decrypted : '';
	}

	/**
	 * Test API connection and credentials
	 *
	 * Verifies:
	 * - Token is valid
	 * - Repository exists
	 * - User has access to repository
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function test_connection(): bool|\WP_Error {
		// Validate required settings
		if ( empty( $this->token ) ) {
			Logger::error( 'GitHub token not configured' );
			return new \WP_Error(
				'missing_token',
				__( 'GitHub token is not configured. Please add your token in plugin settings.', 'wp-jamstack-sync' )
			);
		}

		if ( empty( $this->repo ) ) {
			Logger::error( 'GitHub repository not configured' );
			return new \WP_Error(
				'missing_repo',
				__( 'GitHub repository is not configured. Please add repository in format: owner/repo', 'wp-jamstack-sync' )
			);
		}

		// Validate repository format (owner/repo)
		if ( substr_count( $this->repo, '/' ) !== 1 ) {
			Logger::error(
				'Invalid repository format',
				array( 'repo' => $this->repo )
			);
			return new \WP_Error(
				'invalid_repo_format',
				__( 'Repository must be in format: owner/repo', 'wp-jamstack-sync' )
			);
		}

		// Build API URL
		$url = sprintf( '%s/repos/%s', $this->api_base, $this->repo );

		Logger::info(
			'Testing GitHub connection',
			array(
				'repo' => $this->repo,
				'url'  => $url,
			)
		);

		// Make API request
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/vnd.github+json',
					'User-Agent'    => 'WP-Jamstack-Sync/' . WPJAMSTACK_VERSION,
				),
				'timeout' => 15,
			)
		);

		// Handle network errors
		if ( is_wp_error( $response ) ) {
			Logger::error(
				'GitHub API request failed',
				array(
					'error' => $response->get_error_message(),
					'code'  => $response->get_error_code(),
				)
			);
			return new \WP_Error(
				'network_error',
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to connect to GitHub: %s', 'wp-jamstack-sync' ),
					$response->get_error_message()
				)
			);
		}

		// Get response code
		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		// Handle HTTP errors
		switch ( $status_code ) {
			case 200:
				// Success
				Logger::success(
					'GitHub connection test successful',
					array(
						'repo'        => $this->repo,
						'full_name'   => $data['full_name'] ?? '',
						'private'     => $data['private'] ?? false,
						'permissions' => $data['permissions'] ?? array(),
					)
				);

				// Check if we have push permissions
				if ( isset( $data['permissions']['push'] ) && false === $data['permissions']['push'] ) {
					Logger::warning(
						'No push permission to repository',
						array( 'repo' => $this->repo )
					);
					return new \WP_Error(
						'no_push_permission',
						__( 'GitHub token does not have push permission to this repository. Please check token permissions.', 'wp-jamstack-sync' )
					);
				}

				return true;

			case 401:
				// Unauthorized - invalid token
				Logger::error(
					'GitHub authentication failed',
					array(
						'status' => $status_code,
						'error'  => $data['message'] ?? 'Unauthorized',
					)
				);
				return new \WP_Error(
					'invalid_token',
					__( 'GitHub token is invalid or expired. Please check your token.', 'wp-jamstack-sync' )
				);

			case 403:
				// Forbidden - could be rate limit or token permissions
				$error_message = $data['message'] ?? 'Forbidden';

				// Check if rate limited
				$rate_limit_remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
				if ( '0' === $rate_limit_remaining ) {
					$rate_limit_reset = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
					$reset_time       = ! empty( $rate_limit_reset ) ? gmdate( 'Y-m-d H:i:s', (int) $rate_limit_reset ) : 'unknown';

					Logger::error(
						'GitHub rate limit exceeded',
						array(
							'reset_time' => $reset_time,
						)
					);
					return new \WP_Error(
						'rate_limit_exceeded',
						sprintf(
							/* translators: %s: Reset time */
							__( 'GitHub API rate limit exceeded. Resets at: %s UTC', 'wp-jamstack-sync' ),
							$reset_time
						)
					);
				}

				Logger::error(
					'GitHub access forbidden',
					array(
						'status' => $status_code,
						'error'  => $error_message,
					)
				);
				return new \WP_Error(
					'access_forbidden',
					sprintf(
						/* translators: %s: Error message */
						__( 'Access forbidden: %s', 'wp-jamstack-sync' ),
						$error_message
					)
				);

			case 404:
				// Repository not found
				Logger::error(
					'GitHub repository not found',
					array(
						'repo'   => $this->repo,
						'status' => $status_code,
					)
				);
				return new \WP_Error(
					'repo_not_found',
					sprintf(
						/* translators: %s: Repository name */
						__( 'Repository not found: %s. Please check the repository name and your access permissions.', 'wp-jamstack-sync' ),
						$this->repo
					)
				);

			default:
				// Other errors
				$error_message = $data['message'] ?? 'Unknown error';

				Logger::error(
					'GitHub API error',
					array(
						'status'  => $status_code,
						'message' => $error_message,
					)
				);
				return new \WP_Error(
					'api_error',
					sprintf(
						/* translators: 1: HTTP status code, 2: Error message */
						__( 'GitHub API error (HTTP %1$d): %2$s', 'wp-jamstack-sync' ),
						$status_code,
						$error_message
					)
				);
		}
	}

	/**
	 * Get current SHA hash of branch HEAD
	 *
	 * @return string|\WP_Error SHA hash or WP_Error on failure.
	 */
	public function get_branch_sha(): string|\WP_Error {
		if ( empty( $this->token ) || empty( $this->repo ) ) {
			return new \WP_Error( 'missing_config', __( 'GitHub configuration missing', 'wp-jamstack-sync' ) );
		}

		$url = sprintf( '%s/repos/%s/git/ref/heads/%s', $this->api_base, $this->repo, $this->branch );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/vnd.github+json',
					'User-Agent'    => 'WP-Jamstack-Sync/' . WPJAMSTACK_VERSION,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			return new \WP_Error( 'api_error', $data['message'] ?? 'Failed to get branch SHA' );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return $data['object']['sha'] ?? new \WP_Error( 'invalid_response', __( 'Invalid API response', 'wp-jamstack-sync' ) );
	}

	/**
	 * Create or update a file in the repository
	 *
	 * @param string      $path    File path in repository.
	 * @param string      $content File content (plain text or binary).
	 * @param string      $message Commit message.
	 * @param string|null $sha     Existing file SHA (null for new file).
	 *
	 * @return array|\WP_Error Commit metadata or WP_Error on failure.
	 */
	public function create_or_update_file( string $path, string $content, string $message, ?string $sha = null ): array|\WP_Error {
		if ( empty( $this->token ) || empty( $this->repo ) ) {
			return new \WP_Error( 'missing_config', __( 'GitHub configuration missing', 'wp-jamstack-sync' ) );
		}

		$url = sprintf( '%s/repos/%s/contents/%s', $this->api_base, $this->repo, ltrim( $path, '/' ) );

		$body = array(
			'message' => $message,
			'content' => base64_encode( $content ),
			'branch'  => $this->branch,
		);

		if ( null !== $sha ) {
			$body['sha'] = $sha;
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PUT',
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/vnd.github+json',
					'Content-Type'  => 'application/json',
					'User-Agent'    => 'WP-Jamstack-Sync/' . WPJAMSTACK_VERSION,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'Failed to create/update file', array( 'path' => $path, 'error' => $response->get_error_message() ) );
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! in_array( $status_code, array( 200, 201 ), true ) ) {
			Logger::error( 'GitHub API error on file create/update', array( 'path' => $path, 'status' => $status_code ) );
			return new \WP_Error( 'api_error', $body_data['message'] ?? 'Failed to create/update file' );
		}

		Logger::success( 'File created/updated on GitHub', array( 'path' => $path ) );
		return $body_data;
	}

	/**
	 * Get file contents from repository
	 *
	 * @param string $path File path in repository.
	 *
	 * @return array|null File data array or null if not found.
	 */
	public function get_file( string $path ): ?array {
		if ( empty( $this->token ) || empty( $this->repo ) ) {
			return null;
		}

		$url = sprintf( '%s/repos/%s/contents/%s', $this->api_base, $this->repo, ltrim( $path, '/' ) );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/vnd.github+json',
					'User-Agent'    => 'WP-Jamstack-Sync/' . WPJAMSTACK_VERSION,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! empty( $data['content'] ) ) {
			$data['content'] = base64_decode( $data['content'] );
		}

		return $data;
	}

	/**
	 * Delete a file from repository
	 *
	 * @param string $path    File path in repository.
	 * @param string $message Commit message.
	 * @param string $sha     Current file SHA.
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function delete_file( string $path, string $message, string $sha ): bool|\WP_Error {
		if ( empty( $this->token ) || empty( $this->repo ) ) {
			return new \WP_Error( 'missing_config', __( 'GitHub configuration missing', 'wp-jamstack-sync' ) );
		}

		$url = sprintf( '%s/repos/%s/contents/%s', $this->api_base, $this->repo, ltrim( $path, '/' ) );

		$body = array(
			'message' => $message,
			'sha'     => $sha,
			'branch'  => $this->branch,
		);

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'DELETE',
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/vnd.github+json',
					'Content-Type'  => 'application/json',
					'User-Agent'    => 'WP-Jamstack-Sync/' . WPJAMSTACK_VERSION,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code && 204 !== $status_code ) {
			$body_data = json_decode( wp_remote_retrieve_body( $response ), true );
			return new \WP_Error( 'api_error', $body_data['message'] ?? 'Failed to delete file' );
		}

		return true;
	}

	/**
	 * Get API rate limit status
	 *
	 * @return array|\WP_Error Rate limit data or WP_Error on failure.
	 */
	public function get_rate_limit(): array|\WP_Error {
		if ( empty( $this->token ) ) {
			return new \WP_Error( 'missing_token', __( 'GitHub token missing', 'wp-jamstack-sync' ) );
		}

		$url = $this->api_base . '/rate_limit';

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/vnd.github+json',
					'User-Agent'    => 'WP-Jamstack-Sync/' . WPJAMSTACK_VERSION,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new \WP_Error( 'api_error', __( 'Failed to get rate limit', 'wp-jamstack-sync' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true ) ?? array();
	}
}
