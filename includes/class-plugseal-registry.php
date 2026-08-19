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
		'Frontend Hooks'    => [ 'hooks:frontend' ],
		'Admin Hooks'       => [ 'hooks:admin' ],
		'Auth Hooks'        => [ 'hooks:auth' ],
		'Content Hooks'     => [ 'hooks:content' ],
		'Lifecycle Hooks'   => [ 'hooks:lifecycle' ],
	];

	/**
	 * Returns translated permission descriptions.
	 *
	 * @return array<string, string>
	 */
	public static function get_permission_descriptions(): array {
		return [
			'db:read'            => __( 'Read data from the database.', 'plugseal' ),
			'db:write'           => __( 'Write or delete data in the database.', 'plugseal' ),
			'db:read:users'      => __( 'Read user accounts and profile data.', 'plugseal' ),
			'db:write:users'     => __( 'Modify or delete user accounts.', 'plugseal' ),
			'http:outbound'      => __( 'Send requests to external servers.', 'plugseal' ),
			'options:read'       => __( 'Read settings stored in the database.', 'plugseal' ),
			'options:write'      => __( 'Save or delete settings in the database.', 'plugseal' ),
			'email:send'         => __( 'Send emails to any address.', 'plugseal' ),
			'cron:write'         => __( 'Schedule background tasks.', 'plugseal' ),
			'transients:write'   => __( 'Store temporary data in the cache.', 'plugseal' ),
			'users:create'       => __( 'Create new user accounts.', 'plugseal' ),
			'rest:register'      => __( 'Register public REST API endpoints.', 'plugseal' ),
			'shortcode:register' => __( 'Register shortcodes for use in content.', 'plugseal' ),
			'rewrite:register'   => __( 'Add custom URL rules to the site.', 'plugseal' ),
			'admin:menu'         => __( 'Add pages to the admin navigation menu.', 'plugseal' ),
			'dashboard:widget'   => __( 'Add widgets to the admin dashboard.', 'plugseal' ),
		];
	}

	/**
	 * Returns translated group labels.
	 * Cannot use __() in constants, so we use a method instead.
	 *
	 * @return array<string, string>
	 */
	public static function get_group_labels(): array {
		return [
			'Database'          => __( 'Database', 'plugseal' ),
			'HTTP'              => __( 'HTTP', 'plugseal' ),
			'Options'           => __( 'Options', 'plugseal' ),
			'Email'             => __( 'Email', 'plugseal' ),
			'Cron'              => __( 'Cron', 'plugseal' ),
			'Transients'        => __( 'Transients', 'plugseal' ),
			'Users'             => __( 'Users', 'plugseal' ),
			'REST API'          => __( 'REST API', 'plugseal' ),
			'Shortcodes'        => __( 'Shortcodes', 'plugseal' ),
			'Rewrite'           => __( 'Rewrite', 'plugseal' ),
			'Dashboard'         => __( 'Dashboard', 'plugseal' ),
			'Admin'             => __( 'Admin', 'plugseal' ),
			'Frontend Hooks'    => __( 'Frontend Hooks', 'plugseal' ),
			'Admin Hooks'       => __( 'Admin Hooks', 'plugseal' ),
			'Auth Hooks'        => __( 'Auth Hooks', 'plugseal' ),
			'Content Hooks'     => __( 'Content Hooks', 'plugseal' ),
			'Lifecycle Hooks'   => __( 'Lifecycle Hooks', 'plugseal' ),
		];
	}

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
	 * Removes all admin overrides for a plugin, restoring all defaults.
	 *
	 * @param string $slug Plugin slug.
	 */
	public static function remove_all_overrides( string $slug ): void {
		$overrides = self::get_overrides();
		unset( $overrides[ $slug ] );
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
