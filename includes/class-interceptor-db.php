<?php
/**
 * Intercepts $wpdb queries and enforces db permissions.
 *
 * Honest limitation: only intercepts queries via $wpdb.
 * Direct mysqli/PDO connections bypass this interceptor.
 *
 * @package PlugSeal
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Database query interceptor.
 */
final class PlugSeal_Interceptor_DB {

	public function __construct() {
		add_filter( 'query', [ $this, 'check_query' ], PHP_INT_MIN );
	}

	/**
	 * Checks a $wpdb query against the calling plugin's permissions.
	 *
	 * @param string $query SQL query.
	 * @return string Unmodified query, or no-op SELECT if blocked.
	 */
	public function check_query( string $query ): string {
		$slug = PlugSeal_Interceptor_Helper::get_calling_plugin_slug();

		if ( null === $slug ) {
			return $query;
		}

		$is_write      = $this->is_write_query( $query );
		$perm_needed   = $is_write ? 'db:write' : 'db:read';
		$touches_users = $this->touches_users_table( $query );

		// Check user-specific permissions first.
		if ( $touches_users ) {
			$user_perm = $is_write ? 'db:write:users' : 'db:read:users';
			if ( ! PlugSeal_Permission_Registry::can( $slug, $user_perm ) ) {
				return $is_write ? 'DO 0' : 'SELECT NULL';
			}
		}

		if ( ! PlugSeal_Permission_Registry::can( $slug, $perm_needed ) ) {
			return $is_write ? 'DO 0' : 'SELECT NULL';
		}

		return $query;
	}

	/**
	 * Returns true if the query modifies data.
	 *
	 * @param string $query SQL query.
	 */
	private function is_write_query( string $query ): bool {
		$upper = strtoupper( ltrim( $query ) );

		foreach ( [ 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE' ] as $keyword ) {
			if ( str_starts_with( $upper, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns true if the query touches the users or usermeta tables.
	 *
	 * @param string $query SQL query.
	 */
	private function touches_users_table( string $query ): bool {
		global $wpdb;

		return str_contains( $query, $wpdb->users )
			|| str_contains( $query, $wpdb->usermeta );
	}
}
