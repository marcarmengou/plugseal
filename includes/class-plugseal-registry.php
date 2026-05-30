<?php
/**
 * Central registry of plugin permissions.
 *
 * All permissions are ALLOWED by default.
 * Administrators explicitly deny what they want to restrict.
 *
 * Overrides are stored in wp_options as 'plugseal_overrides'.
 *
 * @package PlugSeal
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Stores and resolves plugin permissions.
 */
final class PlugSeal_Permission_Registry {

	/**
	 * All known permission keys, grouped by API.
	 */
	public const KNOWN_PERMISSIONS = [
		// Database API ($wpdb)
		'db:read',
		'db:write',
		'db:read:users',
		'db:write:users',

		// HTTP API (wp_remote_*)
		'http:outbound',

		// Options API (get_option, update_option)
		'options:read',
		'options:write',

		// Email API (wp_mail)
		'email:send',

		// Cron API (wp_schedule_event)
		'cron:write',

		// Transients API (set_transient, delete_transient)
		'transients:write',

		// User API (wp_create_user)
		// Note: wp_update_user() is covered by db:write:users permission.
		// Note: wp_delete_user() is covered by db:write:users permission.
		'users:create',

		// REST API (register_rest_route)
		'rest:register',

		// Shortcode API (add_shortcode)
		'shortcode:register',

		// Rewrite API (add_rewrite_rule)
		'rewrite:register',

		// Dashboard Widgets API (wp_add_dashboard_widget)
		'dashboard:widget',

		// Admin menu (add_menu_page, add_submenu_page)
		'admin:menu',

		// Hooks — Frontend (wp_head, wp_footer, the_content...)
		'hooks:frontend',

		// Hooks — Admin area (admin_head, admin_notices, admin_init...)
		'hooks:admin',

		// Hooks — Authentication (authenticate, wp_login, user_register...)
		'hooks:auth',

		// Hooks — Content (save_post, delete_post, pre_get_posts...)
		'hooks:content',

		// Hooks — Plugin & theme lifecycle (activated_plugin, switch_theme...)
		'hooks:lifecycle',
	];

	/**
	 * Permission groups for display purposes.
	 *
	 * @var array<string, array<string>>
	 */
	public const PERMISSION_GROUPS = [
		'Database'          => [ 'db:read', 'db:write', 'db:read:users', 'db:write:users' ],
		'HTTP'              => [ 'http:outbound' ],
		'Options'           => [ 'options:read', 'options:write' ],
		'Email'             => [ 'email:send' ],
		'Cron'              => [ 'cron:write' ],
		'Transients'        => [ 'transients:write' ],
		'Users'             => [ 'users:create' ],
		'REST API'          => [ 'rest:register' ],
		'Shortcodes'        => [ 'shortcode:register' ],
		'Rewrite'           => [ 'rewrite:register' ],
		'Dashboard'         => [ 'dashboard:widget' ],
		'Admin'             => [ 'admin:menu' ],
		'Hooks — Frontend'  => [ 'hooks:frontend' ],
		'Hooks — Admin'     => [ 'hooks:admin' ],
		'Hooks — Auth'      => [ 'hooks:auth' ],
		'Hooks — Content'   => [ 'hooks:content' ],
		'Hooks — Lifecycle' => [ 'hooks:lifecycle' ],
	];

	/**
	 * Option key for admin overrides.
	 */
	public const OPTION_OVERRIDES = 'plugseal_overrides';

	/**
	 * Cached overrides (lazy-loaded).
	 *
	 * @var array<string, array<string, bool>>|null
	 */
	private static ?array $overrides = null;

	/**
	 * Checks whether a plugin has a given permission.
	 * Defaults to true (allowed) if no override is set.
	 *
	 * @param string $slug Plugin slug.
	 * @param string $perm Permission key.
	 */
	public static function can( string $slug, string $perm ): bool {
		$overrides = self::get_overrides();

		if ( isset( $overrides[ $slug ][ $perm ] ) ) {
			return (bool) $overrides[ $slug ][ $perm ];
		}

		return true;
	}

	/**
	 * Sets an admin override for a single permission.
	 *
	 * @param string $slug  Plugin slug.
	 * @param string $perm  Permission key.
	 * @param bool   $value True to allow, false to deny.
	 */
	public static function set_override( string $slug, string $perm, bool $value ): void {
		$overrides = self::get_overrides();

		if ( ! isset( $overrides[ $slug ] ) ) {
			$overrides[ $slug ] = [];
		}

		$overrides[ $slug ][ $perm ] = $value;
		update_option( self::OPTION_OVERRIDES, $overrides );
		self::$overrides = $overrides;
	}

	/**
	 * Removes an admin override, restoring the default (allow).
	 *
	 * @param string $slug Plugin slug.
	 * @param string $perm Permission key.
	 */
	public static function remove_override( string $slug, string $perm ): void {
		$overrides = self::get_overrides();
		unset( $overrides[ $slug ][ $perm ] );

		if ( isset( $overrides[ $slug ] ) && [] === $overrides[ $slug ] ) {
			unset( $overrides[ $slug ] );
		}

		update_option( self::OPTION_OVERRIDES, $overrides );
		self::$overrides = $overrides;
	}

	/**
	 * Returns all admin overrides, loading from the database if needed.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function get_overrides(): array {
		if ( null === self::$overrides ) {
			$raw             = get_option( self::OPTION_OVERRIDES, [] );
			self::$overrides = is_array( $raw ) ? $raw : [];
		}
		return self::$overrides;
	}
}
