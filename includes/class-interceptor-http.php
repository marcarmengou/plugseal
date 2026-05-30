<?php
/**
 * Intercepts outbound HTTP requests and enforces http:outbound permission.
 *
 * @package PlugSeal
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Outbound HTTP request interceptor.
 */
final class PlugSeal_Interceptor_HTTP {

	public function __construct() {
		add_filter( 'pre_http_request', [ $this, 'check_request' ], PHP_INT_MIN, 3 );
	}

	/**
	 * Validates an outbound HTTP request against the calling plugin's permissions.
	 *
	 * @param false|array<string, mixed>|\WP_Error $preempt Existing preempt value.
	 * @param array<string, mixed>                 $args    Request arguments.
	 * @param string                               $url     Request URL.
	 * @return false|array<string, mixed>|\WP_Error
	 */
	public function check_request( false|array|\WP_Error $preempt, array $args, string $url ): false|array|\WP_Error {
		if ( false !== $preempt ) {
			return $preempt;
		}

		$slug = PlugSeal_Interceptor_Helper::get_calling_plugin_slug();

		if ( null === $slug || PlugSeal_Permission_Registry::can( $slug, 'http:outbound' ) ) {
			return $preempt;
		}

		return new \WP_Error(
			'plugseal_denied',
			sprintf(
				/* translators: 1: plugin slug, 2: URL */
				__( 'PlugSeal: plugin %1$s does not have http:outbound permission for %2$s.', 'plugseal' ),
				$slug,
				$url
			)
		);
	}
}
